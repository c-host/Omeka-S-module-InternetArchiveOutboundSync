<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Service/IaS3UploadClient.php';

$format = InternetArchiveOutboundSync\Service\IaS3UploadClient::formatMetaHeaderName(...);

assert($format('01-collection') === 'x-archive-meta01-collection');
assert($format('02-collection') === 'x-archive-meta02-collection');
assert($format('01-subject') === 'x-archive-meta01-subject');
assert($format('02-subject') === 'x-archive-meta02-subject');
assert($format('mediatype') === 'x-archive-meta-mediatype');
assert($format('title') === 'x-archive-meta-title');
assert($format('x-archive-meta01-collection') === 'x-archive-meta01-collection');
assert($format('x-archive-auto-make-bucket') === 'x-archive-auto-make-bucket');

echo "OK: IA S3 meta header names formatted correctly\n";
