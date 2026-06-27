<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Doctrine\DBAL\Connection;

class OutboundQueueService
{
    public const QUEUE_TYPE_ITEM_UPLOAD = 'item_upload';

    public const QUEUE_TYPE_METADATA_REVISION = 'metadata_revision';

    protected Connection $connection;

    protected $api;

    protected OutboundMetadataBuilder $metadataBuilder;

    protected IaIdentifierGenerator $idGenerator;

    protected IaIdentifierParser $idParser;

    protected MediaLocalPath $mediaLocalPath;

    protected UploadManifestOrderService $manifestOrder;

    protected AudioPublishService $audioPublish;

    public function __construct(
        Connection $connection,
        $api,
        OutboundMetadataBuilder $metadataBuilder,
        IaIdentifierGenerator $idGenerator,
        IaIdentifierParser $idParser,
        MediaLocalPath $mediaLocalPath,
        UploadManifestOrderService $manifestOrder,
        AudioPublishService $audioPublish
    ) {
        $this->connection = $connection;
        $this->api = $api;
        $this->metadataBuilder = $metadataBuilder;
        $this->idGenerator = $idGenerator;
        $this->idParser = $idParser;
        $this->mediaLocalPath = $mediaLocalPath;
        $this->manifestOrder = $manifestOrder;
        $this->audioPublish = $audioPublish;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enqueueFromContribution(int $itemId, ?int $contributionId = null): ?array
    {
        return $this->enqueueItemUpload($itemId, $contributionId);
    }

    /**
     * @param int[]|null $adminMediaIds
     * @return array<string, mixed>|null
     */
    public function enqueueItemUpload(int $itemId, ?int $contributionId = null, ?array $adminMediaIds = null): ?array
    {
        if ($this->hasActiveQueueRow($itemId, self::QUEUE_TYPE_ITEM_UPLOAD)) {
            return null;
        }
        $item = $this->api->read('items', $itemId)->getContent();
        if ($this->idParser->fromItem($item)) {
            return null;
        }
        $manifest = $this->resolveUploadManifest($item, $adminMediaIds);
        if ($manifest['files'] === []) {
            return null;
        }
        $title = $this->firstTitle($item);
        $iaIdentifier = $this->idGenerator->fromTitle($title, $itemId);
        $snapshot = [
            'metadata' => $this->metadataBuilder->fromItem($item),
            'files' => $manifest['files'],
            'file_sort_method' => $manifest['sort_method'],
            'title' => $title,
        ];

        return $this->insertQueueRow(
            $itemId,
            $iaIdentifier,
            self::QUEUE_TYPE_ITEM_UPLOAD,
            $snapshot,
            $contributionId
        );
    }

    /**
     * Enqueue metadata revisions for validated+undertaken contributions that were
     * never queued (e.g. listener error during admin validation).
     *
     * @return int Number of rows newly queued
     */
    public function syncValidatedContributionRevisions(int $limit = 100): int
    {
        $contributions = $this->api
            ->search('contributions', [
                'validated' => '1',
                'undertaken' => '1',
            ], ['limit' => $limit])
            ->getContent();

        $enqueued = 0;
        foreach ($contributions as $contribution) {
            $resource = $contribution->resource();
            if (!$resource || $resource->resourceName() !== 'items') {
                continue;
            }
            $itemId = (int) $resource->id();
            if ($this->enqueueMetadataRevision($itemId, (int) $contribution->id())) {
                ++$enqueued;
            }
        }

        return $enqueued;
    }

    /**
     * Cancel queued metadata revisions already published for the same contribution
     * (unless the contribution was modified after the last publish).
     */
    public function pruneSupersededMetadataRevisionQueue(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT q.id
             FROM internet_archive_outbound_queue q
             INNER JOIN contribution c ON c.id = q.contribution_id
             INNER JOIN (
               SELECT contribution_id, MAX(published_at) AS last_published_at
               FROM internet_archive_outbound_queue
               WHERE queue_type = ? AND status = ? AND contribution_id IS NOT NULL
               GROUP BY contribution_id
             ) lp ON lp.contribution_id = q.contribution_id
             WHERE q.status = ?
               AND q.queue_type = ?
               AND c.modified <= lp.last_published_at',
            [
                self::QUEUE_TYPE_METADATA_REVISION,
                'published',
                'queued',
                self::QUEUE_TYPE_METADATA_REVISION,
            ]
        );

        $cancelled = 0;
        foreach ($rows as $row) {
            $this->updateStatus((int) $row['id'], 'cancelled', 'Metadata revision already published.');
            ++$cancelled;
        }

        return $cancelled;
    }

    /**
     * Cancel queued metadata revisions whose contribution is no longer validated.
     */
    public function pruneInactiveMetadataRevisionQueue(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT q.id FROM internet_archive_outbound_queue q
             LEFT JOIN contribution c ON c.id = q.contribution_id
             WHERE q.status = ?
               AND q.queue_type = ?
               AND (c.id IS NULL OR c.validated IS NULL OR c.validated != 1 OR c.undertaken != 1)',
            ['queued', self::QUEUE_TYPE_METADATA_REVISION]
        );

        $cancelled = 0;
        foreach ($rows as $row) {
            $this->updateStatus((int) $row['id'], 'cancelled', 'Contribution no longer validated.');
            ++$cancelled;
        }

        return $cancelled;
    }

    /**
     * Cancel active metadata revision queue rows for a contribution.
     */
    public function cancelMetadataRevisionForContribution(int $contributionId): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id FROM internet_archive_outbound_queue
             WHERE contribution_id = ? AND status = ? AND queue_type = ?',
            [$contributionId, 'queued', self::QUEUE_TYPE_METADATA_REVISION]
        );

        $cancelled = 0;
        foreach ($rows as $row) {
            $this->updateStatus((int) $row['id'], 'cancelled', 'Contribution no longer validated.');
            ++$cancelled;
        }

        return $cancelled;
    }

    public function isEligibleMetadataRevisionQueueRow(array $row): bool
    {
        if (($row['status'] ?? '') !== 'queued') {
            return false;
        }
        if (($row['queue_type'] ?? '') !== self::QUEUE_TYPE_METADATA_REVISION) {
            return false;
        }

        $contributionId = (int) ($row['contribution_id'] ?? 0);
        if ($contributionId <= 0) {
            return false;
        }

        try {
            $contribution = $this->api->read('contributions', $contributionId)->getContent();
        } catch (\Throwable $e) {
            return false;
        }

        return $contribution->isValidated() === true && $contribution->isUndertaken();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQueuedValidatedMetadataRevisions(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT q.*, c.modified AS validated_at FROM internet_archive_outbound_queue q
             INNER JOIN contribution c ON c.id = q.contribution_id
             WHERE q.status = ?
               AND q.queue_type = ?
               AND c.validated = 1
               AND c.undertaken = 1
             ORDER BY c.modified ASC, q.id ASC
             LIMIT ' . (int) $limit,
            ['queued', self::QUEUE_TYPE_METADATA_REVISION]
        );

        return array_map([$this, 'decodeRow'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enqueueMetadataRevision(int $itemId, int $contributionId): ?array
    {
        if ($this->hasActiveQueueRow($itemId, self::QUEUE_TYPE_METADATA_REVISION)) {
            return null;
        }
        if ($this->hasCurrentMetadataRevisionPublish($contributionId)) {
            return null;
        }
        $item = $this->api->read('items', $itemId)->getContent();
        $iaIdentifier = $this->idParser->fromItem($item);
        if (!$iaIdentifier) {
            return null;
        }
        $snapshot = [
            'metadata' => $this->metadataBuilder->fromItem($item),
            'title' => $this->firstTitle($item),
        ];

        return $this->insertQueueRow(
            $itemId,
            $iaIdentifier,
            self::QUEUE_TYPE_METADATA_REVISION,
            $snapshot,
            $contributionId
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    protected function insertQueueRow(
        int $itemId,
        string $iaIdentifier,
        string $queueType,
        array $snapshot,
        ?int $contributionId = null
    ): array {
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->connection->insert('internet_archive_outbound_queue', [
            'item_id' => $itemId,
            'contribution_id' => $contributionId,
            'queue_type' => $queueType,
            'status' => 'queued',
            'ia_identifier' => $iaIdentifier,
            'queued_at' => $now,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'message' => null,
        ]);

        return $this->getQueueRow((int) $this->connection->lastInsertId());
    }

    public function hasActiveQueueRow(int $itemId, ?string $queueType = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM internet_archive_outbound_queue WHERE item_id = ? AND status IN (?, ?)';
        $params = [$itemId, 'queued', 'publishing'];
        if ($queueType !== null) {
            $sql .= ' AND queue_type = ?';
            $params[] = $queueType;
        }
        $count = (int) $this->connection->fetchOne($sql, $params);

        return $count > 0;
    }

    /**
     * Whether this contribution's metadata revision was already pushed to IA and
     * the contribution has not been modified since that publish.
     */
    public function hasCurrentMetadataRevisionPublish(int $contributionId): bool
    {
        if ($contributionId <= 0) {
            return false;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT c.modified, lp.last_published_at
             FROM contribution c
             INNER JOIN (
               SELECT contribution_id, MAX(published_at) AS last_published_at
               FROM internet_archive_outbound_queue
               WHERE queue_type = ? AND status = ? AND contribution_id = ?
               GROUP BY contribution_id
             ) lp ON lp.contribution_id = c.id
             WHERE c.id = ?',
            [
                self::QUEUE_TYPE_METADATA_REVISION,
                'published',
                $contributionId,
                $contributionId,
            ]
        );

        if (!$row || empty($row['last_published_at'])) {
            return false;
        }

        $modified = strtotime((string) $row['modified']);
        $published = strtotime((string) $row['last_published_at']);
        if ($modified === false || $published === false) {
            return true;
        }

        return $modified <= $published;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQueued(int $limit = 100, ?string $queueType = null): array
    {
        if ($queueType !== null) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT * FROM internet_archive_outbound_queue WHERE status = ? AND queue_type = ? ORDER BY queued_at ASC LIMIT '
                . (int) $limit,
                ['queued', $queueType]
            );
        } else {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT * FROM internet_archive_outbound_queue WHERE status = ? ORDER BY queued_at ASC LIMIT '
                . (int) $limit,
                ['queued']
            );
        }

        return array_map([$this, 'decodeRow'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getQueueRow(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM internet_archive_outbound_queue WHERE id = ?',
            [$id]
        );

        return $row ? $this->decodeRow($row) : null;
    }

    public function updateStatus(int $id, string $status, ?string $message = null): void
    {
        $data = ['status' => $status, 'message' => $message];
        if ($status === 'published') {
            $data['published_at'] = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        }
        $this->connection->update('internet_archive_outbound_queue', $data, ['id' => $id]);
    }

    public function updateIaIdentifier(int $id, string $iaIdentifier): void
    {
        $this->connection->update(
            'internet_archive_outbound_queue',
            ['ia_identifier' => $iaIdentifier],
            ['id' => $id]
        );
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param int[]|null $adminMediaIds
     * @return array{
     *   files: array<int, array{media_id: int, filename: string, size: int, sha256: string, mime: ?string}>,
     *   sort_method: string,
     *   warning: ?string
     * }
     */
    public function buildUploadManifest($item, ?array $adminMediaIds = null): array
    {
        $manifest = $this->manifestOrder->orderManifest(
            $this->collectRawUploadMediaManifest($item),
            $adminMediaIds
        );

        return $this->audioPublish->adjustManifest($manifest);
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param int[]|null $adminMediaIds
     * @return array{
     *   files: array<int, array{media_id: int, filename: string, size: int, sha256: string, mime: ?string}>,
     *   sort_method: string,
     *   warning: ?string
     * }
     */
    public function resolveUploadManifest($item, ?array $adminMediaIds = null): array
    {
        $auto = $this->buildUploadManifest($item);
        if ($adminMediaIds === null || $adminMediaIds === []) {
            return $auto;
        }

        $autoIds = array_map('intval', array_column($auto['files'], 'media_id'));
        $postedIds = array_values(array_unique(array_map('intval', $adminMediaIds)));
        if ($postedIds === $autoIds) {
            return $auto;
        }

        return $this->buildUploadManifest($item, $postedIds);
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @return array<int, array{media_id: int, filename: string, size: int, sha256: string, mime: ?string}>
     */
    public function uploadMediaManifest($item): array
    {
        return $this->buildUploadManifest($item)['files'];
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @return array<int, array{media_id: int, filename: string, size: int, sha256: string, mime: ?string}>
     */
    protected function collectRawUploadMediaManifest($item): array
    {
        $manifest = [];
        foreach ($item->media() as $media) {
            if ($media->ingester() !== 'upload') {
                continue;
            }
            $path = $this->mediaLocalPath->fromRepresentation($media);
            if ($path === null) {
                continue;
            }
            $uploadFilename = $this->mediaLocalPath->iaUploadFilename($media);
            if ($uploadFilename === null) {
                continue;
            }
            $manifest[] = [
                'media_id' => (int) $media->id(),
                'filename' => $uploadFilename,
                'size' => filesize($path) ?: 0,
                'sha256' => hash_file('sha256', $path) ?: '',
                'mime' => $media->mediaType(),
            ];
        }

        return $manifest;
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decodeRow(array $row): array
    {
        $row['snapshot'] = json_decode((string) ($row['snapshot'] ?? ''), true) ?: [];
        if (!isset($row['queue_type']) || $row['queue_type'] === '') {
            $row['queue_type'] = self::QUEUE_TYPE_ITEM_UPLOAD;
        }

        return $row;
    }
}
