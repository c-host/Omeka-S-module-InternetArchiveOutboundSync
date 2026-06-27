<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class IaTaskPoller
{
    protected IaHttpClient $http;

    protected int $maxAttempts = 180;

    protected int $sleepMicroseconds = 2000000;

    public function __construct(IaHttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * @return array{success: bool, status: string, log: ?string, raw: array<string, mixed>}
     */
    public function waitForTask(?string $taskId): array
    {
        if ($taskId === null || $taskId === '') {
            return ['success' => true, 'status' => 'no_task', 'log' => null, 'raw' => []];
        }

        $lastRaw = [];
        for ($i = 0; $i < $this->maxAttempts; $i++) {
            try {
                $raw = $this->http->getJson(IaPath::taskUrl($taskId), 60, $this->http->authHeader());
                $lastRaw = $raw;
                $status = strtolower((string) ($raw['status'] ?? $raw['state'] ?? ''));
                if (in_array($status, ['success', 'succeeded', 'done', 'complete', 'completed'], true)) {
                    return [
                        'success' => true,
                        'status' => $status,
                        'log' => isset($raw['log']) ? (string) $raw['log'] : null,
                        'raw' => $raw,
                    ];
                }
                if (in_array($status, ['failed', 'error', 'cancelled', 'canceled'], true)) {
                    return [
                        'success' => false,
                        'status' => $status,
                        'log' => isset($raw['log']) ? (string) $raw['log'] : null,
                        'raw' => $raw,
                    ];
                }
            } catch (\Throwable $e) {
                $lastRaw = ['error' => $e->getMessage()];
            }
            usleep($this->sleepMicroseconds);
        }

        return [
            'success' => false,
            'status' => 'timeout',
            'uncertain' => true,
            'log' => null,
            'raw' => $lastRaw,
        ];
    }
}
