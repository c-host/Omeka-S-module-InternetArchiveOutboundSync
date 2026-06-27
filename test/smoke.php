<?php declare(strict_types=1);
/**
 * Quick smoke tests (no PHPUnit required).
 * Run: php test/smoke.php
 */

$root = dirname(__DIR__);
$scripts = [
    'ia_identifier_suffix.php',
    'marc_language_resolver.php',
    'metadata_revision_publish_current.php',
    'upload_manifest_order.php',
    'ia_upload_filename.php',
    's3_meta_headers.php',
    'ia_s3_meta_header_names.php',
    'audio_publish_cover.php',
    'languages_from_item.php',
    'metadata_merge_and_subjects.php',
];

$failures = 0;
foreach ($scripts as $script) {
    $path = __DIR__ . '/' . $script;
    if (!is_file($path)) {
        echo "FAIL missing $script\n";
        ++$failures;
        continue;
    }
    passthru('php ' . escapeshellarg($path), $code);
    if ($code !== 0) {
        ++$failures;
    }
}

exit($failures ? 1 : 0);
