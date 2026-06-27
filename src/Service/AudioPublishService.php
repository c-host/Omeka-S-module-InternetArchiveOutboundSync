<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class AudioPublishService
{
    public const COVER_FILENAME = '00-cover.png';

    public const SYNTHETIC_MEDIA_ID = 0;

    public const SYNTHETIC_TYPE_AUDIO_COVER = 'audio_cover';

    protected string $moduleRoot;

    public function __construct(?string $moduleRoot = null)
    {
        $this->moduleRoot = $moduleRoot ?? dirname(__DIR__, 2);
    }

    public function coverFilename(): string
    {
        return self::COVER_FILENAME;
    }

    public function coverLocalPath(): string
    {
        $path = $this->moduleRoot . '/asset/img/audio-cover.png';
        if (!is_readable($path)) {
            throw new \RuntimeException('Default audio cover image is missing from the module.');
        }

        return $path;
    }

    /**
     * @param array{files: array<int, array<string, mixed>>, sort_method: string, warning: ?string} $manifest
     * @return array{files: array<int, array<string, mixed>>, sort_method: string, warning: ?string, audio_cover?: bool}
     */
    public function adjustManifest(array $manifest): array
    {
        $files = $manifest['files'] ?? [];
        if ($files === []) {
            return $manifest;
        }

        $audioFiles = [];
        $excluded = 0;
        foreach ($files as $file) {
            if ($this->isSyntheticCover($file) || $this->isAudioFile($file)) {
                if (!$this->isSyntheticCover($file)) {
                    $audioFiles[] = $file;
                }
                continue;
            }
            $excluded++;
        }

        if ($audioFiles === []) {
            return $manifest;
        }

        $warning = $manifest['warning'] ?? null;
        if ($excluded > 0) {
            $message = 'Non-audio files are not uploaded for audio items; a default module cover image is added instead.';
            $warning = $warning ? trim($warning . ' ' . $message) : $message;
        }

        return [
            'files' => array_merge($audioFiles, [$this->coverManifestEntry()]),
            'sort_method' => (string) ($manifest['sort_method'] ?? UploadManifestOrderService::SORT_OMEKA_POSITION),
            'warning' => $warning,
            'audio_cover' => true,
        ];
    }

    /**
     * @param array<string, mixed> $file
     */
    public function isSyntheticCover(array $file): bool
    {
        return !empty($file['synthetic'])
            && (string) ($file['synthetic_type'] ?? '') === self::SYNTHETIC_TYPE_AUDIO_COVER;
    }

    /**
     * @param array<string, mixed> $file
     */
    public function isAudioFile(array $file): bool
    {
        $mime = strtolower((string) ($file['mime'] ?? ''));
        if (str_starts_with($mime, 'audio/')) {
            return true;
        }

        $ext = strtolower(pathinfo((string) ($file['filename'] ?? ''), PATHINFO_EXTENSION));

        return in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'wma'], true);
    }

    /**
     * @return array{media_id: int, filename: string, size: int, sha256: string, mime: string, synthetic: bool, synthetic_type: string}
     */
    public function coverManifestEntry(): array
    {
        $path = $this->coverLocalPath();

        return [
            'media_id' => self::SYNTHETIC_MEDIA_ID,
            'filename' => self::COVER_FILENAME,
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
            'mime' => 'image/png',
            'synthetic' => true,
            'synthetic_type' => self::SYNTHETIC_TYPE_AUDIO_COVER,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function thumbnailMetadataPatch(): array
    {
        return [
            [
                'op' => 'add',
                'path' => '/thumbnail',
                'value' => self::COVER_FILENAME,
            ],
        ];
    }
}
