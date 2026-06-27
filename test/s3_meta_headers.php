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

$headers = $builder->s3MetaHeaders([
    'title' => "Line one\r\nLine two",
    'description' => "Paragraph one\n\nParagraph two",
    'creator' => "Artist\rName",
    '_subject_values' => ['logo', 'ia', 'ლოგო'],
], 'test_collection', 'image');

assert(!str_contains($headers['title'], "\n"));
assert(!str_contains($headers['title'], "\r"));
assert($headers['title'] === 'Line one Line two');

assert(!str_contains($headers['description'], "\n"));
assert($headers['description'] === 'Paragraph one Paragraph two');

assert($headers['creator'] === 'Artist Name');
assert($headers['01-collection'] === 'test_collection');
assert($headers['mediatype'] === 'image');
assert($headers['01-subject'] === 'logo');
assert($headers['02-subject'] === 'ia');
assert($headers['03-subject'] === 'ლოგო');

$patch = $builder->publishMetadataPatch([
    'title' => "Line one\r\nLine two",
    'description' => "Paragraph one\n\nParagraph two",
    'creator' => "Artist\rName",
    'date' => '2020',
], true);
assert(count($patch) === 3);

$recoveryPatch = $builder->publishMetadataPatch([
    'description' => "Paragraph one\n\nParagraph two",
    '_subject_values' => ['logo', 'ia'],
], false);
assert(count($recoveryPatch) === 3);
assert($recoveryPatch[0]['path'] === '/description');
assert($recoveryPatch[1]['path'] === '/subject/-');
assert($recoveryPatch[1]['value'] === 'logo');
assert($recoveryPatch[2]['path'] === '/subject/-');
assert($recoveryPatch[2]['value'] === 'ia');
assert($patch[0]['path'] === '/title');
assert($patch[0]['value'] === "Line one\r\nLine two");
assert($patch[1]['path'] === '/creator');
assert($patch[1]['value'] === "Artist\rName");
assert($patch[2]['path'] === '/description');
assert($patch[2]['value'] === "Paragraph one\n\nParagraph two");

echo "OK: s3 meta headers are single-line\n";
echo "OK: header correction patch restores original formatting\n";
echo "OK: publish metadata patch adds subjects on recovery uploads\n";
