<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class ItemSubmissionSelectionService
{
    protected $api;

    protected ModuleSettings $settings;

    protected IaIdentifierParser $parser;

    protected OutboundQueueService $queue;

    protected IaIdentifierGenerator $idGenerator;

    public function __construct(
        $api,
        ModuleSettings $settings,
        IaIdentifierParser $parser,
        OutboundQueueService $queue,
        IaIdentifierGenerator $idGenerator
    ) {
        $this->api = $api;
        $this->settings = $settings;
        $this->parser = $parser;
        $this->queue = $queue;
        $this->idGenerator = $idGenerator;
    }

    /**
     * @return array<int, array{item: \Omeka\Api\Representation\ItemRepresentation, ia_identifier: string}>
     */
    public function listPublishableItems(int $limit = 200, int $offset = 0): array
    {
        $itemSetId = $this->settings->contributionsItemSetId();
        if ($itemSetId <= 0) {
            return [];
        }

        $query = [
            'limit' => $limit,
            'offset' => $offset,
            'sort_by' => 'created',
            'sort_order' => 'asc',
            'item_set_id' => $itemSetId,
        ];

        $out = [];
        foreach ($this->api->search('items', $query)->getContent() as $item) {
            if ($this->parser->fromItem($item)) {
                continue;
            }
            if ($this->queue->uploadMediaManifest($item) === []) {
                continue;
            }
            if ($this->queue->hasActiveQueueRow((int) $item->id(), OutboundQueueService::QUEUE_TYPE_ITEM_UPLOAD)) {
                continue;
            }
            $title = $this->firstTitle($item);
            $out[] = [
                'item' => $item,
                'ia_identifier' => $this->projectedIdentifier($title, (int) $item->id()),
            ];
        }

        return $out;
    }

    public function contributionsItemSetTitle(): string
    {
        $itemSetId = $this->settings->contributionsItemSetId();
        if ($itemSetId <= 0) {
            return '';
        }
        try {
            $set = $this->api->read('item_sets', $itemSetId)->getContent();

            return (string) $set->title();
        } catch (\Throwable $e) {
            return (string) $itemSetId;
        }
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    protected function firstTitle($item): string
    {
        foreach ($item->value('dcterms:title', ['all' => true]) ?: [] as $vo) {
            $t = trim((string) $vo->value());
            if ($t !== '') {
                return $t;
            }
        }

        return 'untitled-item-' . $item->id();
    }

    protected function projectedIdentifier(string $title, int $itemId): string
    {
        return $this->idGenerator->fromTitle($title, $itemId);
    }
}
