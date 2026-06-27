<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Job;

use InternetArchiveOutboundSync\Service\ItemPushService;
use InternetArchiveOutboundSync\Service\ModuleSettings;
use InternetArchiveOutboundSync\Service\OutboundQueueService;
use InternetArchiveOutboundSync\Service\OutboundRunService;

class PublishRevisionBatch extends AbstractIaOutboundJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $push = $services->get(ItemPushService::class);
        $queue = $services->get(OutboundQueueService::class);
        $settings = $services->get(ModuleSettings::class);
        $runs = $services->get(OutboundRunService::class);

        $queueIds = array_map('intval', $this->getArg('queue_ids') ?? []);
        $runId = (int) ($this->getArg('run_id') ?? 0);
        $dryRun = !empty($this->getArg('dry_run'));

        $stats = ['success' => 0, 'skipped' => 0, 'failed' => 0, 'results' => []];
        $log = '';

        foreach ($queueIds as $queueId) {
            if ($this->shouldStop()) {
                break;
            }
            $row = $queue->getQueueRow($queueId);
            if (!$row || !$queue->isEligibleMetadataRevisionQueueRow($row)) {
                if ($row && !$dryRun) {
                    $queue->updateStatus($queueId, 'cancelled', 'Contribution no longer validated.');
                }
                continue;
            }
            $itemId = (int) $row['item_id'];
            if (!$dryRun) {
                $queue->updateStatus($queueId, 'publishing');
            }
            $result = $push->pushOne($itemId, $dryRun);
            $status = $result['status'];
            if (!isset($stats[$status])) {
                $stats[$status] = 0;
            }
            $stats[$status]++;
            $stats['results'][] = ['queue_id' => $queueId, 'item_id' => $itemId] + $result;

            if (!$dryRun) {
                if ($status === 'success') {
                    $queue->updateStatus($queueId, 'published');
                } elseif ($status === 'failed') {
                    $queue->updateStatus($queueId, 'failed', $result['message'] ?? null);
                } else {
                    $queue->updateStatus($queueId, 'published', $result['message'] ?? null);
                }
            }

            if ($runId) {
                $diff = $result['diff'] ?? [];
                $runs->addRunItem($runId, [
                    'item_id' => $itemId,
                    'ia_identifier' => $result['ia_identifier'] ?? ($row['ia_identifier'] ?? null),
                    'status' => $status,
                    'task_id' => $result['task_id'] ?? null,
                    'before_snapshot' => $diff['before'] ?? null,
                    'after_snapshot' => $diff['after'] ?? null,
                    'patch_json' => $diff['patch'] ?? null,
                    'message' => $result['message'] ?? null,
                ]);
            }

            $line = sprintf("queue:%d item:%d %s — %s\n", $queueId, $itemId, $status, $result['message'] ?? '');
            $log .= $line;
            $this->getJobLogger()->notice($line);
            usleep((int) ($settings->requestDelaySeconds() * 1000000));
        }

        if ($runId) {
            $existing = $runs->getRun($runId);
            $merged = $existing['stats'] ?? [];
            foreach (['success', 'skipped', 'failed'] as $key) {
                $merged[$key] = ($merged[$key] ?? 0) + ($stats[$key] ?? 0);
            }
            $merged['results'] = array_merge($merged['results'] ?? [], $stats['results']);
            $runs->updateRun($runId, $merged, $log);
        }
    }
}
