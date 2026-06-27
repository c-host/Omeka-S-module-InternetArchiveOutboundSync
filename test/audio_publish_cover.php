<?php declare(strict_types=1);

require dirname(__DIR__) . '/src/Service/AudioPublishService.php';
require dirname(__DIR__) . '/src/Service/UploadManifestOrderService.php';

use InternetArchiveOutboundSync\Service\AudioPublishService;
use InternetArchiveOutboundSync\Service\UploadManifestOrderService;

$audioPublish = new AudioPublishService(dirname(__DIR__));

$audioOnly = [
    'files' => [
        [
            'media_id' => 10,
            'filename' => '01-test-audio-1.wav',
            'size' => 1000,
            'sha256' => 'abc',
            'mime' => 'audio/wav',
        ],
        [
            'media_id' => 11,
            'filename' => '02-test-audio-2.wav',
            'size' => 1000,
            'sha256' => 'def',
            'mime' => 'audio/wav',
        ],
    ],
    'sort_method' => UploadManifestOrderService::SORT_FILENAME_PREFIX,
    'warning' => null,
];

$adjusted = $audioPublish->adjustManifest($audioOnly);
assert(count($adjusted['files']) === 3);
assert($adjusted['files'][0]['filename'] === '01-test-audio-1.wav');
assert($adjusted['files'][2]['filename'] === AudioPublishService::COVER_FILENAME);
assert(!empty($adjusted['files'][2]['synthetic']));
assert(!empty($adjusted['audio_cover']));

$mixed = [
    'files' => [
        [
            'media_id' => 20,
            'filename' => 'cover.jpg',
            'size' => 500,
            'sha256' => 'img',
            'mime' => 'image/jpeg',
        ],
        [
            'media_id' => 21,
            'filename' => 'track.wav',
            'size' => 1000,
            'sha256' => 'wav',
            'mime' => 'audio/wav',
        ],
    ],
    'sort_method' => UploadManifestOrderService::SORT_OMEKA_POSITION,
    'warning' => null,
];

$adjustedMixed = $audioPublish->adjustManifest($mixed);
assert(count($adjustedMixed['files']) === 2);
assert($adjustedMixed['files'][0]['filename'] === 'track.wav');
assert($adjustedMixed['files'][1]['filename'] === AudioPublishService::COVER_FILENAME);
assert(str_contains((string) $adjustedMixed['warning'], 'Non-audio files are not uploaded'));

$documentOnly = [
    'files' => [
        [
            'media_id' => 30,
            'filename' => 'notes.pdf',
            'size' => 1000,
            'sha256' => 'pdf',
            'mime' => 'application/pdf',
        ],
    ],
    'sort_method' => UploadManifestOrderService::SORT_OMEKA_POSITION,
    'warning' => null,
];

$adjustedDocument = $audioPublish->adjustManifest($documentOnly);
assert(count($adjustedDocument['files']) === 1);
assert(empty($adjustedDocument['audio_cover'] ?? null));

$patch = $audioPublish->thumbnailMetadataPatch();
assert($patch[0]['path'] === '/thumbnail');
assert($patch[0]['value'] === AudioPublishService::COVER_FILENAME);

echo "OK: audio publish manifest adds default cover and excludes contributor images\n";
