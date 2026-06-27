<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Laminas\Session\Container;

class PublishRevisionsPageSession
{
    private const CONTAINER = 'InternetArchiveOutboundSyncPublishRevisions';

    /**
     * @param int[] $queueIds
     */
    public function storePreview(array $queueIds): string
    {
        $token = bin2hex(random_bytes(16));
        $container = new Container(self::CONTAINER);
        $container->preview = [
            'token' => $token,
            'queue_ids' => $this->sortIds($queueIds),
            'at' => time(),
        ];

        return $token;
    }

    /**
     * @param int[] $queueIds
     */
    public function validatePreview(string $token, array $queueIds): bool
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

        return $preview['queue_ids'] === $this->sortIds($queueIds);
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
