<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class ItemSelectionService
{
    protected $api;

    protected ModuleSettings $settings;

    protected IaIdentifierParser $parser;

    public function __construct($api, ModuleSettings $settings, IaIdentifierParser $parser)
    {
        $this->api = $api;
        $this->settings = $settings;
        $this->parser = $parser;
    }

    /**
     * @return array<int, array{item: \Omeka\Api\Representation\ItemRepresentation, ia_identifier: string, item_set_id: ?int}>
     */
    public function listPushableItems(int $itemSetId, int $limit = 200, int $offset = 0): array
    {
        $map = $this->settings->itemSetCollectionMap();
        if ($map === [] || $itemSetId <= 0 || !isset($map[$itemSetId])) {
            return [];
        }

        $query = [
            'limit' => $limit,
            'offset' => $offset,
            'sort_by' => 'id',
            'sort_order' => 'desc',
            'item_set_id' => $itemSetId,
        ];

        $out = [];
        foreach ($this->api->search('items', $query)->getContent() as $item) {
            $iaId = $this->parser->fromItem($item);
            if (!$iaId) {
                continue;
            }
            $setId = $this->matchingItemSetId($item, $map);
            if ($setId === null) {
                continue;
            }
            $out[] = [
                'item' => $item,
                'ia_identifier' => $iaId,
                'item_set_id' => $setId,
            ];
        }
        return $out;
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param array<int, string> $map
     */
    protected function matchingItemSetId($item, array $map): ?int
    {
        foreach ($item->itemSets() as $set) {
            $id = (int) $set->id();
            if (isset($map[$id])) {
                return $id;
            }
        }
        return null;
    }

    public function collectionForItemSet(int $itemSetId): ?string
    {
        $map = $this->settings->itemSetCollectionMap();
        return $map[$itemSetId] ?? null;
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    public function mappedItemSetIdForItem($item): ?int
    {
        return $this->matchingItemSetId($item, $this->settings->itemSetCollectionMap());
    }
}
