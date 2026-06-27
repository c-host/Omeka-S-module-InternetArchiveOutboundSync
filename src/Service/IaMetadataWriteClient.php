<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class IaMetadataWriteClient
{
    protected IaHttpClient $http;

    public function __construct(IaHttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * @param array<int, array<string, mixed>> $patch RFC 6902 JSON Patch operations
     * @return array{task_id: ?string, log: ?string, raw: array<string, mixed>}
     */
    public function patchMetadata(string $identifier, array $patch): array
    {
        $identifier = IaPath::normalize($identifier);
        if ($identifier === '' || $patch === []) {
            throw new \InvalidArgumentException('Identifier and patch are required');
        }

        $url = IaPath::metadataUrl($identifier);
        $response = $this->http->postForm($url, [
            '-target' => 'metadata',
            '-patch' => json_encode($patch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ], 120, $this->http->authHeader());

        if (empty($response['success'])) {
            $message = (string) ($response['error'] ?? $response['message'] ?? 'Metadata write failed');
            throw new \RuntimeException($message);
        }

        return [
            'task_id' => isset($response['task_id']) ? (string) $response['task_id'] : null,
            'log' => isset($response['log']) ? (string) $response['log'] : null,
            'raw' => $response,
        ];
    }
}
