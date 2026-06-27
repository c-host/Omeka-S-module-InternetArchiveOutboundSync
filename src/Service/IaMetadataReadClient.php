<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class IaMetadataReadClient
{
    protected IaHttpClient $http;

    public function __construct(IaHttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(string $identifier): array
    {
        $identifier = IaPath::normalize($identifier);
        if ($identifier === '') {
            throw new \InvalidArgumentException('IA identifier is empty');
        }

        if (!str_contains($identifier, '/')) {
            return $this->http->getJson(IaPath::metadataUrl($identifier), 60);
        }

        [$parent, $subpath] = explode('/', $identifier, 2);
        $ia = $this->http->getJson(IaPath::metadataUrl($parent), 60);
        if (!isset($ia['metadata']) || !is_array($ia['metadata'])) {
            $ia['metadata'] = [];
        }
        $ia['metadata']['identifier'] = $identifier;
        $ia['metadata']['ia_parent_identifier'] = $parent;
        $ia['metadata']['ia_subpath'] = $subpath;

        return $ia;
    }

    /**
     * @return array{exists: bool, error: ?string, ia: ?array<string, mixed>}
     */
    public function checkExists(string $identifier): array
    {
        try {
            $ia = $this->fetch($identifier);
            return [
                'exists' => !empty($ia['metadata']['identifier']),
                'error' => null,
                'ia' => $ia,
            ];
        } catch (\Throwable $e) {
            return [
                'exists' => false,
                'error' => $e->getMessage(),
                'ia' => null,
            ];
        }
    }

    /**
     * Fail closed when IA cannot be reached.
     */
    public function assertIdentifierAvailable(string $identifier): void
    {
        $result = $this->checkExists($identifier);
        if ($result['error'] !== null) {
            throw new \RuntimeException(
                'Cannot verify Internet Archive identifier availability: ' . $result['error']
            );
        }
        if ($result['exists']) {
            throw new \RuntimeException(
                'Internet Archive identifier already exists: ' . $identifier
            );
        }
    }

    public function exists(string $identifier): bool
    {
        $result = $this->checkExists($identifier);
        if ($result['error'] !== null) {
            return false;
        }
        return $result['exists'];
    }
}
