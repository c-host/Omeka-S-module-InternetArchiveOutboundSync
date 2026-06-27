<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Service/BilingualTextMerger.php';
require_once dirname(__DIR__) . '/src/Service/Iso6392LanguageCatalog.php';
require_once dirname(__DIR__) . '/src/Service/ModuleSettings.php';
require_once dirname(__DIR__) . '/src/Service/MarcLanguageResolver.php';
require_once dirname(__DIR__) . '/src/Service/OutboundMetadataBuilder.php';

$catalog = new InternetArchiveOutboundSync\Service\Iso6392LanguageCatalog();
$settings = new InternetArchiveOutboundSync\Service\ModuleSettings(
    new class {
        public function get($k, $d = null)
        {
            return $d;
        }
    }
);
$builder = new InternetArchiveOutboundSync\Service\OutboundMetadataBuilder(
    new InternetArchiveOutboundSync\Service\BilingualTextMerger(),
    $settings,
    new InternetArchiveOutboundSync\Service\MarcLanguageResolver($catalog, $settings)
);

$makeVo = static function (string $value, ?string $lang): object {
    return new class ($value, $lang) {
        private string $value;
        private ?string $lang;

        public function __construct(string $value, ?string $lang)
        {
            $this->value = $value;
            $this->lang = $lang;
        }

        public function value(): string
        {
            return $this->value;
        }

        public function lang(): ?string
        {
            return $this->lang;
        }
    };
};

$item = new class ($makeVo) {
    private $makeVo;

    public function __construct($makeVo)
    {
        $this->makeVo = $makeVo;
    }

    public function value(string $property, array $options = []): array
    {
        if ($property !== 'dcterms:language') {
            return [];
        }

        return [
            ($this->makeVo)('georgian', 'en'),
            ($this->makeVo)('russian', 'en'),
            ($this->makeVo)('ქართული', 'ka'),
            ($this->makeVo)('რუსული', 'ka'),
        ];
    }
};

$languages = $builder->languagesFromItem($item);
$expected = ['geo', 'rus'];
if ($languages !== $expected) {
    echo 'FAIL expected ' . json_encode($expected) . ' got ' . json_encode($languages) . "\n";
    exit(1);
}

echo "OK languages " . json_encode($languages) . "\n";
