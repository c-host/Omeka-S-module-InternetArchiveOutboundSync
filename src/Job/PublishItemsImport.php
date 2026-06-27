<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Job;

use InternetArchiveOutboundSync\Service\ModuleSettings;
use InternetArchiveOutboundSync\Service\OutboundRunService;
use Omeka\Job\Dispatcher;

class PublishItemsImport extends AbstractIaOutboundJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get(ModuleSettings::class);
        $runs = $services->get(OutboundRunService::class);
        $dispatcher = $services->get(Dispatcher::class);

        $queueIds = array_map('intval', $this->getArg('queue_ids') ?? []);
        $dryRun = !empty($this->getArg('dry_run'));
        $ownerId = (int) ($this->getArg('owner_id') ?? 0) ?: null;

        $runId = $runs->createRun($this->job->getId(), $ownerId, 'item_upload_publish', [
            'queue_ids' => $queueIds,
            'dry_run' => $dryRun,
        ]);

        $chunkSize = (int) ($this->getArg('chunk_size') ?? $settings->chunkSize());
        foreach (array_chunk($queueIds, max(1, $chunkSize)) as $chunk) {
            $dispatcher->dispatch(PublishContributionBatch::class, [
                'queue_ids' => $chunk,
                'run_id' => $runId,
                'dry_run' => $dryRun,
            ]);
        }
    }
}
