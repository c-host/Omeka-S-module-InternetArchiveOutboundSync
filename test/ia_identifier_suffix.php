<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Service/IaIdentifierGenerator.php';
require_once dirname(__DIR__) . '/src/Service/ModuleSettings.php';
require_once dirname(__DIR__) . '/src/Service/IaHttpClient.php';
require_once dirname(__DIR__) . '/src/Service/IaMetadataReadClient.php';

$settings = new InternetArchiveOutboundSync\Service\ModuleSettings(
    new class {
        public function get($k, $d = null)
        {
            if ($k === 'internet_archive_outbound_identifier_suffix') {
                return 'mysite';
            }
            return $d;
        }
    }
);

$metadata = new class ($settings) extends InternetArchiveOutboundSync\Service\IaMetadataReadClient {
    public function __construct(InternetArchiveOutboundSync\Service\ModuleSettings $settings)
    {
        parent::__construct(new InternetArchiveOutboundSync\Service\IaHttpClient($settings));
    }

    public function checkExists(string $identifier): array
    {
        return ['exists' => false, 'error' => null, 'ia' => null];
    }
};

$gen = new InternetArchiveOutboundSync\Service\IaIdentifierGenerator($settings, $metadata);
$id = $gen->fromTitle('IA Logo', 1945);
if ($id !== 'ia-logo-mysite') {
    echo 'FAIL expected ia-logo-mysite got ' . json_encode($id) . "\n";
    exit(1);
}

echo "OK identifier suffix ia-logo-mysite\n";
