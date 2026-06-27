<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class ContributionPublishService
{
    protected $api;

    protected OutboundQueueService $queue;

    protected OutboundMetadataBuilder $metadataBuilder;

    protected IaS3UploadClient $s3;

    protected IaMetadataReadClient $readClient;

    protected IaMetadataWriteClient $writeClient;

    protected IaMediaLinkService $mediaLink;

    protected LocalMediaCleanupService $cleanup;

    protected ModuleSettings $settings;

    protected MediaLocalPath $mediaLocalPath;

    protected AudioPublishService $audioPublish;

    public function __construct(
        $api,
        OutboundQueueService $queue,
        OutboundMetadataBuilder $metadataBuilder,
        IaS3UploadClient $s3,
        IaMetadataReadClient $readClient,
        IaMetadataWriteClient $writeClient,
        IaMediaLinkService $mediaLink,
        LocalMediaCleanupService $cleanup,
        ModuleSettings $settings,
        MediaLocalPath $mediaLocalPath,
        AudioPublishService $audioPublish
    ) {
        $this->api = $api;
        $this->queue = $queue;
        $this->metadataBuilder = $metadataBuilder;
        $this->s3 = $s3;
        $this->readClient = $readClient;
        $this->writeClient = $writeClient;
        $this->mediaLink = $mediaLink;
        $this->cleanup = $cleanup;
        $this->settings = $settings;
        $this->mediaLocalPath = $mediaLocalPath;
        $this->audioPublish = $audioPublish;
    }

    /**
     * @return array{status: string, message: string, ia_identifier?: string, local_files_deleted?: bool}
     */
    public function publishOne(int $queueId, bool $dryRun = false): array
    {
        $row = $this->queue->getQueueRow($queueId);
        if (!$row) {
            return ['status' => 'failed', 'message' => 'Queue row not found.'];
        }
        if ($row['status'] !== 'queued') {
            return ['status' => 'failed', 'message' => 'Queue row is not in queued status.'];
        }

        $itemId = (int) $row['item_id'];
        $iaId = (string) $row['ia_identifier'];
        $collection = $this->settings->publishIaCollection();
        if ($collection === '') {
            return [
                'status' => 'failed',
                'message' => 'No IA collection configured for publishing. Set Default IA collection or a publish test collection override.',
            ];
        }

        $item = $this->api->read('items', $itemId)->getContent();
        $snapshot = is_array($row['snapshot'] ?? null) ? $row['snapshot'] : [];
        $files = $snapshot['files'] ?? [];
        if ($files === []) {
            $files = $this->queue->buildUploadManifest($item)['files'];
        }
        if ($files === []) {
            return ['status' => 'failed', 'message' => 'No upload media found on item.'];
        }

        $meta = $this->metadataBuilder->fromItem($item);
        $mediatype = $this->metadataBuilder->inferMediatypeFromFile(
            $files[0]['filename'],
            $files[0]['mime'] ?? null
        );

        if ($dryRun) {
            return [
                'status' => 'skipped',
                'message' => 'Dry run: no upload performed.',
                'ia_identifier' => $iaId,
            ];
        }

        $this->queue->updateStatus($queueId, 'publishing');

        try {
            $bucketExists = false;
            try {
                $this->readClient->assertIdentifierAvailable($iaId);
            } catch (\RuntimeException $e) {
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
                $bucketExists = true;
            }

            $filesToUpload = $bucketExists ? $this->missingFiles($iaId, $files) : $files;
            $subjectsSentViaS3Headers = false;
            if ($filesToUpload !== []) {
                $s3Headers = $this->metadataBuilder->s3MetaHeaders($meta, $collection, $mediatype);
                $itemCreatedThisRun = false;
                foreach ($filesToUpload as $file) {
                    if ($this->audioPublish->isSyntheticCover($file)) {
                        $this->s3->putFile(
                            $iaId,
                            (string) $file['filename'],
                            $this->audioPublish->coverLocalPath()
                        );
                        continue;
                    }

                    $media = $this->api->read('media', $file['media_id'])->getContent();
                    $path = $this->mediaLocalPath->fromRepresentation($media);
                    if ($path === null) {
                        throw new \RuntimeException('Upload media file is not readable on disk.');
                    }
                    $sendItemHeaders = !$bucketExists && !$itemCreatedThisRun;
                    $this->s3->putFile(
                        $iaId,
                        $file['filename'],
                        $path,
                        $sendItemHeaders ? $s3Headers : [],
                        $sendItemHeaders
                    );
                    if ($sendItemHeaders) {
                        $subjectsSentViaS3Headers = true;
                    }
                    $itemCreatedThisRun = true;
                }
            }

            $verify = $this->verifyUploadWithRetry($iaId, $files, $mediatype);
            if (!$verify['success']) {
                $this->queue->updateStatus($queueId, 'failed', $verify['message']);
                return ['status' => 'failed', 'message' => $verify['message'], 'ia_identifier' => $iaId];
            }

            $patch = $this->metadataBuilder->publishMetadataPatch($meta, $subjectsSentViaS3Headers);
            if ($this->manifestIncludesAudioCover($files)) {
                $patch = array_merge($patch, $this->audioPublish->thumbnailMetadataPatch());
            }
            if ($patch !== []) {
                $this->writeClient->patchMetadata($iaId, $patch);
            }

            if ($this->settings->deleteStagingItemAfterPublish()) {
                $this->api->delete('items', $itemId);
                $this->queue->updateStatus($queueId, 'published');

                return [
                    'status' => 'success',
                    'message' => 'Published to Internet Archive and staging item deleted.',
                    'ia_identifier' => $iaId,
                    'staging_item_deleted' => true,
                ];
            }

            $this->api->update('items', $itemId, [
                'dcterms:identifier' => [['type' => 'literal', 'property_id' => 'auto', '@value' => $iaId]],
                'dcterms:source' => [['type' => 'uri', 'property_id' => 'auto', '@id' => IaPath::detailsUrl($iaId)]],
            ], [], ['isPartial' => true]);

            $this->mediaLink->replaceItemMedia($itemId, $iaId);

            $deleted = false;
            if ($this->settings->allowLocalFileDeletion()) {
                $deleted = $this->cleanup->deleteUploadMedia($itemId);
            }

            $this->queue->updateStatus($queueId, 'published');
            return [
                'status' => 'success',
                'message' => 'Published to Internet Archive.',
                'ia_identifier' => $iaId,
                'local_files_deleted' => $deleted,
            ];
        } catch (\Throwable $e) {
            $this->queue->updateStatus($queueId, 'failed', $e->getMessage());
            return ['status' => 'failed', 'message' => $e->getMessage(), 'ia_identifier' => $iaId];
        }
    }

    /**
     * @param array<int, array{filename: string, size: int}> $expected
     * @return array{success: bool, message: string}
     */
    protected function verifyUploadOnce(string $iaId, array $expected): array
    {
        try {
            $ia = $this->readClient->fetch($iaId);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Cannot verify IA upload: ' . $e->getMessage(),
            ];
        }

        $names = $this->iaFileNames($ia);
        $missing = [];
        foreach ($expected as $file) {
            $filename = strtolower((string) ($file['filename'] ?? ''));
            if ($filename === '' || !isset($names[$filename])) {
                $missing[] = (string) ($file['filename'] ?? '');
            }
        }
        if ($missing !== []) {
            return [
                'success' => false,
                'message' => 'Upload verification failed: missing on Internet Archive: '
                    . implode(', ', array_filter($missing)),
            ];
        }

        return [
            'success' => true,
            'message' => 'Upload verified on Internet Archive.',
        ];
    }

    /**
     * @param array<int, array{filename: string, size: int}> $expected
     * @return array{success: bool, message: string}
     */
    protected function verifyUploadWithRetry(string $iaId, array $expected, string $mediatype = 'image'): array
    {
        $last = [
            'success' => false,
            'message' => 'Upload verification failed: files not yet visible in Internet Archive metadata.',
        ];
        foreach ($this->verifyRetryDelays($expected, $mediatype) as $delay) {
            if ($delay > 0) {
                sleep($delay);
            }
            $result = $this->verifyUploadOnce($iaId, $expected);
            if ($result['success']) {
                return $result;
            }
            $last = $result;
        }

        $last['message'] .= ' If the item already appears on Internet Archive, publish it again to finish metadata and staging cleanup.';

        return $last;
    }

    /**
     * @param array<int, array{filename: string, size?: int}> $files
     * @return int[]
     */
    protected function verifyRetryDelays(array $files, string $mediatype): array
    {
        $base = [0, 2, 5, 10, 15, 20];
        $totalBytes = array_sum(array_map(
            static fn (array $file): int => (int) ($file['size'] ?? 0),
            $files
        ));
        $fileCount = count($files);

        if ($mediatype === 'movies' || $totalBytes > 50_000_000) {
            return array_merge($base, [30, 45, 60, 90, 120, 180]);
        }
        if ($fileCount > 1 || $totalBytes > 10_000_000) {
            return array_merge($base, [30, 45, 60, 90]);
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $ia
     * @return array<string, true>
     */
    protected function iaFileNames(array $ia): array
    {
        $names = [];
        foreach ($ia['files'] ?? [] as $file) {
            if (is_array($file) && !empty($file['name'])) {
                $names[strtolower((string) $file['name'])] = true;
            }
        }

        return $names;
    }

    /**
     * @param array<int, array{filename: string, size?: int}> $expected
     * @return array<int, array{filename: string, size?: int, media_id?: int, synthetic?: bool}>
     */
    protected function missingFiles(string $iaId, array $expected): array
    {
        try {
            $ia = $this->readClient->fetch($iaId);
        } catch (\Throwable $e) {
            return $expected;
        }

        $names = $this->iaFileNames($ia);
        $missing = [];
        foreach ($expected as $file) {
            $filename = strtolower((string) ($file['filename'] ?? ''));
            if ($filename === '' || !isset($names[$filename])) {
                $missing[] = $file;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    protected function manifestIncludesAudioCover(array $files): bool
    {
        foreach ($files as $file) {
            if ($this->audioPublish->isSyntheticCover($file)) {
                return true;
            }
        }

        return false;
    }
}
