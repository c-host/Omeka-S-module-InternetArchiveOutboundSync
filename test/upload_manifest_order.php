<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Service/UploadManifestOrderService.php';

$order = new InternetArchiveOutboundSync\Service\UploadManifestOrderService();

$files = static function (array $rows): array {
    $manifest = [];
    foreach ($rows as $row) {
        $manifest[] = [
            'media_id' => $row[0],
            'filename' => $row[1],
            'size' => 100,
            'sha256' => 'abc',
            'mime' => 'image/png',
        ];
    }

    return $manifest;
};

$prefix = $order->orderManifest($files([
    [10, '03-third.png'],
    [11, '01-first.png'],
    [12, '02-second.png'],
]));
assert($prefix['sort_method'] === InternetArchiveOutboundSync\Service\UploadManifestOrderService::SORT_FILENAME_PREFIX);
assert(array_column($prefix['files'], 'filename') === ['01-first.png', '02-second.png', '03-third.png']);

$omeka = $order->orderManifest($files([
    [1, 'third.png'],
    [2, 'first.png'],
    [3, 'second.png'],
]));
assert($omeka['sort_method'] === InternetArchiveOutboundSync\Service\UploadManifestOrderService::SORT_OMEKA_POSITION);
assert($omeka['warning'] !== null);
assert(array_column($omeka['files'], 'media_id') === [1, 2, 3]);

$mixed = $order->orderManifest($files([
    [1, '01-first.png'],
    [2, 'second.png'],
]));
assert($mixed['sort_method'] === InternetArchiveOutboundSync\Service\UploadManifestOrderService::SORT_OMEKA_POSITION);
assert(str_contains((string) $mixed['warning'], 'Only some files'));

$admin = $order->orderManifest($files([
    [5, '01-a.png'],
    [6, '02-b.png'],
    [7, '03-c.png'],
]), [7, 5, 6]);
assert($admin['sort_method'] === InternetArchiveOutboundSync\Service\UploadManifestOrderService::SORT_ADMIN_ORDER);
assert(array_column($admin['files'], 'media_id') === [7, 5, 6]);

$dupPrefix = $order->orderManifest($files([
    [20, '01-a.png'],
    [21, '01-b.png'],
    [22, '02-c.png'],
]));
assert(array_column($dupPrefix['files'], 'media_id') === [20, 21, 22]);

assert($order->parseNumericPrefix('01-file-name.png') === 1);
assert($order->parseNumericPrefix('12_file.png') === 12);
assert($order->parseNumericPrefix('file.png') === null);

echo "OK: upload manifest order\n";
