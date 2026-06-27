<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Doctrine\DBAL\Connection;

class PushProgressService
{
    public const SECONDS_PER_ITEM_MIN = 5;

    public const SECONDS_PER_ITEM_TYPICAL = 15;

    public const SECONDS_PER_ITEM_MAX = 30;

    protected Connection $connection;

    protected OutboundRunService $runs;

    protected ModuleSettings $settings;

    public function __construct(Connection $connection, OutboundRunService $runs, ModuleSettings $settings)
    {
        $this->connection = $connection;
        $this->runs = $runs;
        $this->settings = $settings;
    }

    /**
     * @return array{
     *   per_item_min_seconds: int,
     *   per_item_typical_seconds: int,
     *   per_item_max_seconds: int,
     *   chunk_size: int,
     *   request_delay_seconds: float
     * }
     */
    public function timingDefaults(): array
    {
        return [
            'per_item_min_seconds' => self::SECONDS_PER_ITEM_MIN,
            'per_item_typical_seconds' => self::SECONDS_PER_ITEM_TYPICAL,
            'per_item_max_seconds' => self::SECONDS_PER_ITEM_MAX,
            'chunk_size' => $this->settings->chunkSize(),
            'request_delay_seconds' => $this->settings->requestDelaySeconds(),
        ];
    }

    /**
     * @return array{
     *   item_count: int,
     *   batch_count: int,
     *   min_seconds: int,
     *   typical_seconds: int,
     *   max_seconds: int
     * }
     */
    public function estimateDuration(int $itemCount): array
    {
        $itemCount = max(0, $itemCount);
        if ($itemCount === 0) {
            return [
                'item_count' => 0,
                'batch_count' => 0,
                'min_seconds' => 0,
                'typical_seconds' => 0,
                'max_seconds' => 0,
            ];
        }

        $chunkSize = max(1, $this->settings->chunkSize());
        $batchCount = (int) ceil($itemCount / $chunkSize);

        return [
            'item_count' => $itemCount,
            'batch_count' => $batchCount,
            'min_seconds' => $itemCount * self::SECONDS_PER_ITEM_MIN,
            'typical_seconds' => $itemCount * self::SECONDS_PER_ITEM_TYPICAL,
            'max_seconds' => $itemCount * self::SECONDS_PER_ITEM_MAX,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActivePushes(): array
    {
        $batchJobs = $this->connection->fetchAllAssociative(
            "SELECT id, status, started, args FROM job
             WHERE status = 'in_progress'
             AND class LIKE '%PushMetadataBatch%'
             ORDER BY id ASC"
        );

        $runIds = [];
        foreach ($batchJobs as $job) {
            $args = $this->decodeArgs($job['args'] ?? null);
            $runId = (int) ($args['run_id'] ?? 0);
            if ($runId > 0) {
                $runIds[$runId] = true;
            }
        }

        $active = [];
        foreach (array_keys($runIds) as $runId) {
            $snapshot = $this->buildRunSnapshot((int) $runId, true);
            if ($snapshot !== null) {
                $active[] = $snapshot;
            }
        }

        usort($active, static function (array $a, array $b): int {
            return strcmp((string) $b['started'], (string) $a['started']);
        });

        return $active;
    }

    public function isRunActive(int $runId): bool
    {
        foreach ($this->listActivePushes() as $push) {
            if ((int) ($push['run_id'] ?? 0) === $runId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRunSnapshot(int $runId): ?array
    {
        return $this->buildRunSnapshot($runId, $this->isRunActive($runId));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildRunSnapshot(int $runId, bool $isActive): ?array
    {
        $run = $this->runs->getRun($runId);
        if (!$run) {
            return null;
        }

        $itemIds = array_map('intval', $run['parameters']['item_ids'] ?? []);
        $total = count($itemIds);
        $items = $run['items'] ?? [];
        $completed = count($items);
        $success = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === 'success') {
                $success++;
            } elseif ($status === 'failed') {
                $failed++;
            } elseif ($status === 'skipped') {
                $skipped++;
            }
        }

        $remaining = max(0, $total - $completed);
        $estimate = $this->estimateDuration($remaining);
        $elapsedSeconds = max(0, time() - strtotime((string) $run['started']));
        if ($completed >= $total) {
            $isActive = false;
        }

        return [
            'run_id' => $runId,
            'job_id' => (int) ($run['job_id'] ?? 0),
            'started' => $run['started'],
            'is_active' => $isActive,
            'total' => $total,
            'completed' => $completed,
            'remaining' => $remaining,
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'elapsed_seconds' => $elapsedSeconds,
            'elapsed_label' => $this->formatDuration($elapsedSeconds),
            'estimate_remaining' => $estimate,
            'history_url' => null,
        ];
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    protected function decodeArgs($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $hours = (int) floor($minutes / 60);
        $minutes = $minutes % 60;

        return $hours . 'h ' . $minutes . 'm';
    }
}
