<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class LocalMediaCleanupService
{
    protected $api;

    protected MediaLocalPath $mediaLocalPath;

    public function __construct($api, MediaLocalPath $mediaLocalPath)
    {
        $this->api = $api;
        $this->mediaLocalPath = $mediaLocalPath;
    }

    public function deleteUploadMedia(int $itemId): bool
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $deleted = false;
        foreach ($item->media() as $media) {
            if ($media->ingester() !== 'upload') {
                continue;
            }
            $path = $this->mediaLocalPath->fromRepresentation($media);
            if ($path !== null && is_file($path)) {
                @unlink($path);
                $deleted = true;
            }
        }
        return $deleted;
    }
}
