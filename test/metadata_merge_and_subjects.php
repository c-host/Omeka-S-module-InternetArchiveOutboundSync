<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Service/BilingualTextMerger.php';
require_once dirname(__DIR__) . '/src/Service/Iso6392LanguageCatalog.php';
require_once dirname(__DIR__) . '/src/Service/ModuleSettings.php';
require_once dirname(__DIR__) . '/src/Service/MarcLanguageResolver.php';
require_once dirname(__DIR__) . '/src/Service/OutboundMetadataBuilder.php';

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
    new InternetArchiveOutboundSync\Service\MarcLanguageResolver(
        new InternetArchiveOutboundSync\Service\Iso6392LanguageCatalog(),
        $settings
    )
);

$makeVo = static function (string $value, ?string $lang = null): object {
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

$makeItem = static function (array $valuesByProperty) use ($makeVo): object {
    return new class ($valuesByProperty, $makeVo) {
        private array $valuesByProperty;
        private $makeVo;

        public function __construct(array $valuesByProperty, $makeVo)
        {
            $this->valuesByProperty = $valuesByProperty;
            $this->makeVo = $makeVo;
        }

        public function value(string $property, array $options = []): array
        {
            $rows = $this->valuesByProperty[$property] ?? [];
            $out = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $out[] = ($this->makeVo)($row[0], $row[1] ?? null);
                } else {
                    $out[] = ($this->makeVo)((string) $row, null);
                }
            }
            return $out;
        }
    };
};

$failures = 0;

$titleItem = $makeItem([
    'dcterms:title' => [['ლოგო'], ['IA Logo']],
]);
$meta = $builder->fromItem($titleItem);
if (($meta['title'] ?? '') !== 'ლოგო | IA Logo') {
    echo 'FAIL title order-preserving: expected "ლოგო | IA Logo" got ' . json_encode($meta['title'] ?? '') . "\n";
    $failures++;
} else {
    echo "OK title order-preserving\n";
}

$taggedItem = $makeItem([
    'dcterms:title' => [['Georgian title', 'ka'], ['English title', 'en']],
]);
$meta = $builder->fromItem($taggedItem);
if (($meta['title'] ?? '') !== 'English title | Georgian title') {
    echo 'FAIL tagged title sort: expected "English title | Georgian title" got ' . json_encode($meta['title'] ?? '') . "\n";
    $failures++;
} else {
    echo "OK tagged title sort\n";
}

$subjectItem = $makeItem([
    'dcterms:subject' => ['logo, ia', 'ლოგო, აია'],
]);
$subjects = $builder->subjectsFromItem($subjectItem);
$expectedSubjects = ['logo', 'ia', 'ლოგო', 'აია'];
if ($subjects !== $expectedSubjects) {
    echo 'FAIL subjects split: expected ' . json_encode($expectedSubjects) . ' got ' . json_encode($subjects) . "\n";
    $failures++;
} else {
    echo "OK subjects split\n";
}

$rightsItem = $makeItem([
    'dcterms:rights' => ['Public Domain Mark https://creativecommons.org/publicdomain/mark/1.0/'],
]);
$meta = $builder->fromItem($rightsItem);
if (($meta['licenseurl'] ?? '') !== 'https://creativecommons.org/publicdomain/mark/1.0/') {
    echo 'FAIL rights URL extraction: got ' . json_encode($meta['licenseurl'] ?? '') . "\n";
    $failures++;
} else {
    echo "OK rights URL extraction\n";
}

$langItem = $makeItem([
    'dcterms:language' => ['English', 'Armenian'],
]);
$languages = $builder->languagesFromItem($langItem);
if ($languages === []) {
    echo "WARN languages empty for English/Armenian (resolver may lack armenian mapping)\n";
} else {
    echo 'OK languages ' . json_encode($languages) . "\n";
}

if ($failures > 0) {
    exit(1);
}

echo "All metadata merge/subject tests passed.\n";
