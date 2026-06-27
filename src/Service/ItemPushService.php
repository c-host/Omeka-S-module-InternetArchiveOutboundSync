<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class ItemPushService
{
    protected $api;

    protected IaMetadataReadClient $readClient;

    protected IaMetadataWriteClient $writeClient;

    protected OutboundMetadataBuilder $metadataBuilder;

    protected MetadataDiffService $diffService;

    protected IaIdentifierParser $idParser;

    protected ItemSelectionService $selection;

    protected IaPushPreflightService $preflight;

    protected ModuleSettings $settings;

    public function __construct(
        $api,
        IaMetadataReadClient $readClient,
        IaMetadataWriteClient $writeClient,
        OutboundMetadataBuilder $metadataBuilder,
        MetadataDiffService $diffService,
        IaIdentifierParser $idParser,
        ItemSelectionService $selection,
        IaPushPreflightService $preflight,
        ModuleSettings $settings
    ) {
        $this->api = $api;
        $this->readClient = $readClient;
        $this->writeClient = $writeClient;
        $this->metadataBuilder = $metadataBuilder;
        $this->diffService = $diffService;
        $this->idParser = $idParser;
        $this->selection = $selection;
        $this->preflight = $preflight;
        $this->settings = $settings;
    }

    /**
     * @return array{status: string, message: string, ia_identifier?: string, diff?: array<string, mixed>, task_id?: ?string}
     */
    public function preview(int $itemId): array
    {
        $preflight = $this->runPreflight($itemId);
        if ($preflight['status'] === 'failed') {
            return $preflight;
        }

        $ia = $preflight['ia'];
        $iaId = $preflight['ia_identifier'];
        $item = $preflight['item'];
        $projected = $this->metadataBuilder->fromItem($item);
        $diff = $this->diffService->diff($ia, $projected);

        $message = $diff['has_changes'] ? 'Changes detected.' : 'No metadata changes to push.';
        if (!empty($diff['skipped'])) {
            $message .= ' Some IA fields were left unchanged because Omeka has no value.';
        }

        return [
            'status' => $diff['has_changes'] ? 'preview' : 'skipped',
            'message' => $message,
            'ia_identifier' => $iaId,
            'diff' => $diff,
        ];
    }

    /**
     * @return array{status: string, message: string, ia_identifier?: string, task_id?: ?string, diff?: array<string, mixed>}
     */
    public function pushOne(int $itemId, bool $dryRun = false): array
    {
        if (!$dryRun && !$this->settings->metadataPushEnabled()) {
            return [
                'status' => 'failed',
                'message' => 'Metadata push is disabled in module settings.',
            ];
        }

        $preview = $this->preview($itemId);
        if ($preview['status'] === 'failed') {
            return $preview;
        }
        if ($preview['status'] === 'skipped') {
            return $preview;
        }
        $diff = $preview['diff'] ?? [];
        $iaId = (string) $preview['ia_identifier'];
        if ($dryRun) {
            return [
                'status' => 'skipped',
                'message' => 'Dry run: no write performed.',
                'ia_identifier' => $iaId,
                'diff' => $diff,
            ];
        }
        if (empty($diff['patch'])) {
            return [
                'status' => 'skipped',
                'message' => 'No patch to apply.',
                'ia_identifier' => $iaId,
                'diff' => $diff,
            ];
        }
        try {
            $write = $this->writeClient->patchMetadata($iaId, $diff['patch']);
            $verified = $this->verifyPushOnIa($iaId, $diff);
            if ($verified['success']) {
                return [
                    'status' => 'success',
                    'message' => $verified['message'],
                    'ia_identifier' => $iaId,
                    'task_id' => $write['task_id'] ?? null,
                    'diff' => $diff,
                ];
            }

            return [
                'status' => 'failed',
                'message' => $verified['message'],
                'ia_identifier' => $iaId,
                'task_id' => $write['task_id'] ?? null,
                'diff' => $diff,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
                'ia_identifier' => $iaId,
                'diff' => $diff,
            ];
        }
    }

    /**
     * @return array{
     *   status: string,
     *   message: string,
     *   ia_identifier?: string,
     *   item?: \Omeka\Api\Representation\ItemRepresentation,
     *   ia?: array<string, mixed>
     * }
     */
    protected function runPreflight(int $itemId): array
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $iaId = $this->idParser->fromItem($item);
        if (!$iaId) {
            return ['status' => 'failed', 'message' => 'No Internet Archive identifier on item.'];
        }

        $itemSetId = $this->selection->mappedItemSetIdForItem($item);
        if ($itemSetId === null) {
            return [
                'status' => 'failed',
                'message' => 'Item is not in a mapped Omeka item set.',
                'ia_identifier' => $iaId,
            ];
        }

        $expectedCollection = $this->selection->collectionForItemSet($itemSetId);
        if ($expectedCollection === null || $expectedCollection === '') {
            return [
                'status' => 'failed',
                'message' => 'No IA collection mapped for this item set.',
                'ia_identifier' => $iaId,
            ];
        }

        $check = $this->readClient->checkExists($iaId);
        if ($check['error'] !== null) {
            return [
                'status' => 'failed',
                'message' => 'Cannot verify IA item: ' . $check['error'],
                'ia_identifier' => $iaId,
            ];
        }
        if (!$check['exists'] || $check['ia'] === null) {
            return [
                'status' => 'failed',
                'message' => 'IA item not found: ' . $iaId,
                'ia_identifier' => $iaId,
            ];
        }

        $collectionError = $this->preflight->verifyCollection($check['ia'], $expectedCollection);
        if ($collectionError !== null) {
            return [
                'status' => 'failed',
                'message' => $collectionError,
                'ia_identifier' => $iaId,
            ];
        }

        return [
            'status' => 'ok',
            'message' => '',
            'ia_identifier' => $iaId,
            'item' => $item,
            'ia' => $check['ia'],
        ];
    }

    /**
     * @param array<string, mixed> $diff
     * @return array{success: bool, message: string}
     */
    protected function verifyPushOnIa(string $iaId, array $diff): array
    {
        $lastMessage = 'IA metadata does not yet match the expected values.';
        $retryDelaysSeconds = [0, 1, 2, 3, 5];

        foreach ($retryDelaysSeconds as $delay) {
            if ($delay > 0) {
                sleep($delay);
            }
            try {
                $ia = $this->readClient->fetch($iaId);
                if ($this->diffService->patchApplied($ia, $diff)) {
                    return [
                        'success' => true,
                        'message' => 'Metadata verified on Internet Archive.',
                    ];
                }
            } catch (\Throwable $e) {
                $lastMessage = 'Cannot verify IA metadata: ' . $e->getMessage();
            }
        }

        return [
            'success' => false,
            'message' => $lastMessage,
        ];
    }
}
