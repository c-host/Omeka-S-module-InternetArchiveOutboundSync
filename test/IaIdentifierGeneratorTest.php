<?php declare(strict_types=1);

namespace InternetArchiveOutboundSyncTest;

use InternetArchiveOutboundSync\Service\IaHttpClient;
use InternetArchiveOutboundSync\Service\IaIdentifierGenerator;
use InternetArchiveOutboundSync\Service\IaMetadataReadClient;
use InternetArchiveOutboundSync\Service\ModuleSettings;
use PHPUnit\Framework\TestCase;

class IaIdentifierGeneratorTest extends TestCase
{
    public function testFromTitleAppendsConfiguredSuffix(): void
    {
        $settings = new ModuleSettings(new class {
            public function get($k, $d = null)
            {
                if ($k === 'internet_archive_outbound_identifier_suffix') {
                    return 'mysite';
                }

                return $d;
            }
        });
        $metadata = new class ($settings) extends IaMetadataReadClient {
            public function __construct(ModuleSettings $settings)
            {
                parent::__construct(new IaHttpClient($settings));
            }

            public function checkExists(string $identifier): array
            {
                return ['exists' => false, 'error' => null, 'ia' => null];
            }
        };

        $gen = new IaIdentifierGenerator($settings, $metadata);
        $this->assertSame('ia-logo-mysite', $gen->fromTitle('IA Logo', 1945));
    }
}
