<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Laminas\Http\Client;
use Laminas\Http\Request;
use RuntimeException;

class IaHttpClient
{
    protected ModuleSettings $settings;

    public function __construct(ModuleSettings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function getJson(string $url, int $timeoutSeconds = 60, ?string $authHeader = null): array
    {
        return $this->requestJson(Request::METHOD_GET, $url, null, $timeoutSeconds, $authHeader);
    }

    /**
     * @param array<string, string>|null $formData
     * @return array<string, mixed>
     */
    public function postForm(string $url, ?array $formData, int $timeoutSeconds = 120, ?string $authHeader = null): array
    {
        return $this->requestJson(Request::METHOD_POST, $url, $formData, $timeoutSeconds, $authHeader);
    }

    public function putFile(
        string $url,
        string $filePath,
        array $headers = [],
        int $timeoutSeconds = 3600,
        ?string $authHeader = null
    ): int {
        if (!is_readable($filePath)) {
            throw new RuntimeException('Upload file not readable: ' . $filePath);
        }
        $client = new Client($url, ['timeout' => $timeoutSeconds]);
        $client->setMethod(Request::METHOD_PUT);
        $client->setRawBody(file_get_contents($filePath) ?: '');
        $allHeaders = array_merge([
            'User-Agent' => $this->settings->userAgent(),
        ], $headers);
        if ($authHeader) {
            $allHeaders['Authorization'] = $authHeader;
        }
        $client->setHeaders($allHeaders);
        $response = $this->sendWithRetry($client);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf(
                'Internet Archive PUT failed (%d): %s — %s',
                $status,
                $url,
                substr((string) $response->getBody(), 0, 500)
            ));
        }
        return $status;
    }

    public function headOk(string $url, int $timeoutSeconds = 20, ?string $authHeader = null): bool
    {
        $client = new Client($url, ['timeout' => $timeoutSeconds]);
        $client->setMethod(Request::METHOD_HEAD);
        $headers = [
            'User-Agent' => $this->settings->userAgent(),
        ];
        if ($authHeader) {
            $headers['Authorization'] = $authHeader;
        }
        $client->setHeaders($headers);
        try {
            $response = $this->sendWithRetry($client);
            $status = $response->getStatusCode();
            return $status >= 200 && $status < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function authHeader(): string
    {
        $access = $this->settings->iaS3AccessKey();
        $secret = $this->settings->iaS3SecretKey();
        if ($access === '' || $secret === '') {
            throw new RuntimeException('Internet Archive S3 credentials are not configured.');
        }
        return 'LOW ' . $access . ':' . $secret;
    }

    /**
     * @param array<string, string>|null $formData
     * @return array<string, mixed>
     */
    protected function requestJson(
        string $method,
        string $url,
        ?array $formData,
        int $timeoutSeconds,
        ?string $authHeader
    ): array {
        $client = new Client($url, ['timeout' => $timeoutSeconds]);
        $client->setMethod($method);
        $headers = [
            'User-Agent' => $this->settings->userAgent(),
            'Accept' => 'application/json',
        ];
        if ($authHeader) {
            $headers['Authorization'] = $authHeader;
        }
        if ($formData !== null) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $client->setRawBody(http_build_query($formData));
        }
        $client->setHeaders($headers);
        $response = $this->sendWithRetry($client);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $detail = trim(substr((string) $response->getBody(), 0, 500));
            $message = sprintf('Internet Archive request failed (%d): %s', $status, $url);
            if ($detail !== '') {
                $message .= ' — ' . $detail;
            }
            throw new RuntimeException($message);
        }
        $body = $response->getBody();
        if ($body === '') {
            return [];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON from Internet Archive: ' . $url);
        }
        return $data;
    }

    protected function sendWithRetry(Client $client): \Laminas\Http\Response
    {
        $response = $client->send();
        $status = $response->getStatusCode();
        if ($status === 429 || $status === 503) {
            usleep(500000);
            $response = $client->send();
        }
        return $response;
    }
}
