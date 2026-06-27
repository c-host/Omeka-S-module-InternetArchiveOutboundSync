<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Laminas\Session\Container;

class PushPageSession
{
    private const CONTAINER = 'InternetArchiveOutboundSyncPush';

    public function getItemSetId(): ?int
    {
        $container = new Container(self::CONTAINER);
        $id = $container->item_set_id ?? null;

        return $id !== null ? (int) $id : null;
    }

    public function setItemSetId(int $id): void
    {
        $container = new Container(self::CONTAINER);
        $container->item_set_id = $id;
    }

    /**
     * @param int[] $itemIds
     */
    public function storePreview(array $itemIds): string
    {
        $token = bin2hex(random_bytes(16));
        $container = new Container(self::CONTAINER);
        $container->preview = [
            'token' => $token,
            'item_ids' => $this->sortIds($itemIds),
            'at' => time(),
        ];

        return $token;
    }

    /**
     * @param int[] $itemIds
     */
    public function validatePreview(string $token, array $itemIds): bool
    {
        if ($token === '') {
            return false;
        }
        $container = new Container(self::CONTAINER);
        $preview = $container->preview ?? null;
        if (!is_array($preview)) {
            return false;
        }
        if (!hash_equals((string) ($preview['token'] ?? ''), $token)) {
            return false;
        }

        return $preview['item_ids'] === $this->sortIds($itemIds);
    }

    public function clearPreview(): void
    {
        $container = new Container(self::CONTAINER);
        unset($container->preview);
    }

    /**
     * @param int[] $ids
     * @return int[]
     */
    private function sortIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids);

        return $ids;
    }
}
