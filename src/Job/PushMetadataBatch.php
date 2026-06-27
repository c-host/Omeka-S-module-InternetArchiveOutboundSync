<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Job;

use InternetArchiveOutboundSync\Service\ItemPushService;
use InternetArchiveOutboundSync\Service\ModuleSettings;
use InternetArchiveOutboundSync\Service\OutboundRunService;

class PushMetadataBatch extends AbstractIaOutboundJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $push = $services->get(ItemPushService::class);
        $settings = $services->get(ModuleSettings::class);
        $runs = $services->get(OutboundRunService::class);

        $itemIds = array_map('intval', $this->getArg('item_ids') ?? []);
        $runId = (int) ($this->getArg('run_id') ?? 0);
        $dryRun = !empty($this->getArg('dry_run'));

        $stats = ['success' => 0, 'skipped' => 0, 'failed' => 0, 'results' => []];
        $log = '';

        foreach ($itemIds as $itemId) {
            if ($this->shouldStop()) {
                break;
            }
            $result = $push->pushOne($itemId, $dryRun);
            $status = $result['status'];
            if (!isset($stats[$status])) {
                $stats[$status] = 0;
            }
            $stats[$status]++;
            $stats['results'][] = ['item_id' => $itemId] + $result;

            if ($runId) {
                $diff = $result['diff'] ?? [];
                $runs->addRunItem($runId, [
                    'item_id' => $itemId,
                    'ia_identifier' => $result['ia_identifier'] ?? null,
                    'status' => $status,
                    'task_id' => $result['task_id'] ?? null,
                    'before_snapshot' => $diff['before'] ?? null,
                    'after_snapshot' => $diff['after'] ?? null,
                    'patch_json' => $diff['patch'] ?? null,
                    'message' => $result['message'] ?? null,
                ]);
            }

            $line = sprintf("item:%d %s — %s\n", $itemId, $status, $result['message'] ?? '');
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
