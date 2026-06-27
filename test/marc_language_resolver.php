<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Service/Iso6392LanguageCatalog.php';
require_once dirname(__DIR__) . '/src/Service/ModuleSettings.php';
require_once dirname(__DIR__) . '/src/Service/MarcLanguageResolver.php';

$catalog = new InternetArchiveOutboundSync\Service\Iso6392LanguageCatalog();
$settings = new InternetArchiveOutboundSync\Service\ModuleSettings(
    new class {
        public function get($k, $d = null)
        {
            return $d;
        }
    }
);
$resolver = new InternetArchiveOutboundSync\Service\MarcLanguageResolver($catalog, $settings);

$cases = [
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

$failed = 0;
foreach ($cases as [$literal, $tag, $expected]) {
    $got = $resolver->resolve($literal, $tag);
    if ($got !== $expected) {
        echo "FAIL literal=" . json_encode($literal) . " tag=" . json_encode($tag)
            . " expected=$expected got=" . json_encode($got) . "\n";
        $failed++;
    }
}

echo $failed === 0 ? "OK all " . count($cases) . " cases\n" : "FAIL $failed cases\n";
exit($failed === 0 ? 0 : 1);
