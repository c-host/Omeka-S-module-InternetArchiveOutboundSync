<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Job;

use InternetArchiveOutboundSync\Service\ModuleSettings;
use InternetArchiveOutboundSync\Service\OutboundRunService;
use Omeka\Job\Dispatcher;

class PushMetadataImport extends AbstractIaOutboundJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get(ModuleSettings::class);
        $runs = $services->get(OutboundRunService::class);
        $dispatcher = $services->get(Dispatcher::class);

        $itemIds = array_map('intval', $this->getArg('item_ids') ?? []);
        $dryRun = !empty($this->getArg('dry_run'));
        if (!$dryRun && !$settings->metadataPushEnabled()) {
            throw new \RuntimeException('Metadata push is disabled in module settings.');
        }
        $ownerId = (int) ($this->getArg('owner_id') ?? 0) ?: null;

        $runId = $runs->createRun($this->job->getId(), $ownerId, 'metadata_push', [
            'item_ids' => $itemIds,
            'dry_run' => $dryRun,
        ]);

        $chunkSize = (int) ($this->getArg('chunk_size') ?? $settings->chunkSize());
        $chunks = array_chunk($itemIds, max(1, $chunkSize));

        foreach ($chunks as $chunk) {
            $dispatcher->dispatch(PushMetadataBatch::class, [
                'item_ids' => $chunk,
                'run_id' => $runId,
                'dry_run' => $dryRun,
            ]);
        }
    }
}
