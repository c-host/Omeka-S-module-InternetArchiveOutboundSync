<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Job;

use InternetArchiveOutboundSync\Service\ContributionPublishService;
use InternetArchiveOutboundSync\Service\ModuleSettings;
use InternetArchiveOutboundSync\Service\OutboundQueueService;
use InternetArchiveOutboundSync\Service\OutboundRunService;

class PublishContributionBatch extends AbstractIaOutboundJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $publish = $services->get(ContributionPublishService::class);
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
            $result = $publish->publishOne($queueId, $dryRun);
            $status = $result['status'];
            if (!isset($stats[$status])) {
                $stats[$status] = 0;
            }
            $stats[$status]++;
            $stats['results'][] = ['queue_id' => $queueId] + $result;

            if ($runId) {
                $runs->addRunItem($runId, [
                    'item_id' => $row['item_id'] ?? null,
                    'ia_identifier' => $result['ia_identifier'] ?? ($row['ia_identifier'] ?? null),
                    'status' => $status,
                    'local_files_deleted' => !empty($result['local_files_deleted']),
                    'message' => $result['message'] ?? null,
                ]);
            }

            $line = sprintf("queue:%d %s — %s\n", $queueId, $status, $result['message'] ?? '');
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
