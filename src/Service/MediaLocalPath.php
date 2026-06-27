<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

/**
 * Resolve on-disk paths for upload media via Omeka API representations.
 */
class MediaLocalPath
{
    /**
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     */
    public function fromRepresentation($media): ?string
    {
        if (method_exists($media, 'hasOriginal') && !$media->hasOriginal()) {
            return null;
        }
        $filename = method_exists($media, 'filename') ? $media->filename() : null;
        if (!$filename) {
            return null;
        }

        return $this->fromStoredFilename((string) $filename);
    }

    /**
     * Original upload filename for Internet Archive (o:source), not Omeka's storage hash.
     *
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     */
    public function iaUploadFilename($media): ?string
    {
        if (method_exists($media, 'source')) {
            $source = trim((string) $media->source());
            if ($source !== '' && !preg_match('~^https?://~i', $source)) {
                $name = basename(str_replace('\\', '/', $source));
                if ($name !== '' && $name !== '.' && $name !== '..') {
                    return $name;
                }
            }
        }
        if (!method_exists($media, 'filename')) {
            return null;
        }
        $stored = trim((string) $media->filename());

        return $stored !== '' ? $stored : null;
    }

    public function fromStoredFilename(string $filename): ?string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return null;
        }
        $base = defined('OMEKA_PATH') ? OMEKA_PATH : (string) getcwd();
        $path = $base . '/files/original/' . $filename;

        return is_readable($path) ? $path : null;
    }
}
