<?php declare(strict_types=1);

namespace InternetArchiveOutboundSyncTest;

use InternetArchiveOutboundSync\Service\Iso6392LanguageCatalog;
use InternetArchiveOutboundSync\Service\MarcLanguageResolver;
use InternetArchiveOutboundSync\Service\ModuleSettings;
use PHPUnit\Framework\TestCase;

class MarcLanguageResolverTest extends TestCase
{
    /** @dataProvider resolveCases */
    public function testResolve(string $literal, ?string $tag, string $expected): void
    {
        $settings = new ModuleSettings(new class {
            public function get($k, $d = null)
            {
                return $d;
            }
        });
        $resolver = new MarcLanguageResolver(new Iso6392LanguageCatalog(), $settings);

        $this->assertSame($expected, $resolver->resolve($literal, $tag));
    }

    public static function resolveCases(): array
    {
        return [
            ['russian', null, 'rus'],
            ['რუსული', null, 'rus'],
            ['english', null, 'eng'],
            ['Georgian', null, 'geo'],
            ['', 'ru', 'rus'],
            ['', 'ka', 'geo'],
            ['', 'en', 'eng'],
            ['fra', null, 'fre'],
            ['fr', null, 'fre'],
            ['French', null, 'fre'],
            ['deu', null, 'ger'],
            ['German', null, 'ger'],
        ];
    }
}
