<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class IaS3UploadClient
{
    protected IaHttpClient $http;

    public function __construct(IaHttpClient $http)
    {
        $this->http = $http;
    }

    /**
     * @param array<string, string> $metaHeaders x-archive-meta-* headers (without prefix)
     */
    public function putFile(
        string $identifier,
        string $filename,
        string $localPath,
        array $metaHeaders = [],
        bool $autoMakeBucket = false
    ): void {
        $identifier = IaPath::normalize($identifier);
        $url = IaPath::s3UploadUrl($identifier, $filename);
        $headers = [];
        if ($autoMakeBucket) {
            $headers['x-archive-auto-make-bucket'] = '1';
        }
        foreach ($metaHeaders as $key => $value) {
            if ($value === '') {
                continue;
            }
            $headers[self::formatMetaHeaderName($key)] = $value;
        }
        $this->http->putFile($url, $localPath, $headers, 3600, $this->http->authHeader());
    }

    /**
     * IA numbers repeatable metadata on the meta prefix, not the field name.
     * e.g. 01-collection → x-archive-meta01-collection (not x-archive-meta-01-collection).
     */
    public static function formatMetaHeaderName(string $key): string
    {
        if (str_starts_with($key, 'x-archive-')) {
            return $key;
        }
        if (preg_match('/^(\d{2})-(.+)$/', $key, $matches)) {
            return 'x-archive-meta' . $matches[1] . '-' . $matches[2];
        }
        if (str_starts_with($key, 'x-archive-meta')) {
            return $key;
        }

        return 'x-archive-meta-' . $key;
    }
}
