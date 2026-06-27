<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class ConnectionTestService
{
    protected IaHttpClient $http;

    protected ModuleSettings $settings;

    public function __construct(IaHttpClient $http, ModuleSettings $settings)
    {
        $this->http = $http;
        $this->settings = $settings;
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function test(): array
    {
        if (!$this->settings->hasCredentials()) {
            return ['success' => false, 'message' => 'IA-S3 credentials are not configured.'];
        }
        try {
            $auth = $this->http->authHeader();
            $this->http->headOk('https://s3.us.archive.org/', 30, $auth);
            $this->settings->setConnectionTestPassed(true);
            return ['success' => true, 'message' => 'Connection test succeeded.'];
        } catch (\Throwable $e) {
            $this->settings->setConnectionTestPassed(false);
            return ['success' => false, 'message' => 'Connection test failed: ' . $e->getMessage()];
        }
    }
}
