<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Doctrine\DBAL\Connection;

class OutboundRunService
{
    protected Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function createRun(?int $jobId, ?int $ownerId, string $runType, array $parameters): int
    {
        $this->connection->insert('internet_archive_outbound_run', [
            'job_id' => $jobId,
            'owner_id' => $ownerId,
            'run_type' => $runType,
            'started' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'stats' => json_encode([], JSON_THROW_ON_ERROR),
            'log' => '',
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function updateRun(int $runId, array $stats, string $logAppend = ''): void
    {
        $row = $this->getRun($runId);
        if (!$row) {
            return;
        }
        $log = ($row['log'] ?? '') . $logAppend;
        $this->connection->update(
            'internet_archive_outbound_run',
            [
                'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
                'log' => $log,
            ],
            ['id' => $runId]
        );
    }

    public function addRunItem(int $runId, array $data): int
    {
        $this->connection->insert('internet_archive_outbound_run_item', [
            'run_id' => $runId,
            'item_id' => $data['item_id'] ?? null,
            'ia_identifier' => $data['ia_identifier'] ?? null,
            'status' => $data['status'] ?? 'preview',
            'task_id' => $data['task_id'] ?? null,
            'before_snapshot' => isset($data['before_snapshot'])
                ? json_encode($data['before_snapshot'], JSON_THROW_ON_ERROR) : null,
            'after_snapshot' => isset($data['after_snapshot'])
                ? json_encode($data['after_snapshot'], JSON_THROW_ON_ERROR) : null,
            'patch_json' => isset($data['patch_json'])
                ? json_encode($data['patch_json'], JSON_THROW_ON_ERROR) : null,
            'local_files_deleted' => !empty($data['local_files_deleted']) ? 1 : 0,
            'message' => $data['message'] ?? null,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRun(int $runId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM internet_archive_outbound_run WHERE id = ?',
            [$runId]
        );
        if (!$row) {
            return null;
        }
        $row['parameters'] = json_decode((string) $row['parameters'], true) ?: [];
        $row['stats'] = json_decode((string) $row['stats'], true) ?: [];
        $row['items'] = $this->listRunItems($runId);
        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRunItems(int $runId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM internet_archive_outbound_run_item WHERE run_id = ? ORDER BY id ASC',
            [$runId]
        );
        foreach ($rows as &$row) {
            $row['before_snapshot'] = json_decode((string) ($row['before_snapshot'] ?? ''), true) ?: [];
            $row['after_snapshot'] = json_decode((string) ($row['after_snapshot'] ?? ''), true) ?: [];
            $row['patch_json'] = json_decode((string) ($row['patch_json'] ?? ''), true) ?: [];
            $row['local_files_deleted'] = (bool) $row['local_files_deleted'];
        }
        unset($row);
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRuns(int $limit = 100, ?string $runType = null): array
    {
        $sql = 'SELECT * FROM internet_archive_outbound_run';
        $params = [];
        if ($runType) {
            $sql .= ' WHERE run_type = ?';
            $params[] = $runType;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        foreach ($rows as &$row) {
            $row['parameters'] = json_decode((string) $row['parameters'], true) ?: [];
            $row['stats'] = json_decode((string) $row['stats'], true) ?: [];
        }
        unset($row);
        return $rows;
    }
}
