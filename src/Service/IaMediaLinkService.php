<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class IaMediaLinkService
{
    public const IA_THUMB_FILENAME = '__ia_thumb.jpg';

    protected $api;

    protected IaMetadataReadClient $readClient;

    protected IaHttpClient $http;

    public function __construct($api, IaMetadataReadClient $readClient, IaHttpClient $http)
    {
        $this->api = $api;
        $this->readClient = $readClient;
        $this->http = $http;
    }

    public function replaceItemMedia(int $itemId, string $iaIdentifier): void
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $iaId = IaPath::normalize($iaIdentifier);
        $thumbUrl = $this->resolveThumbUrl($iaId);
        $mediaKey = 'ia-media:' . $iaId;

        foreach ($item->media() as $media) {
            if ($media->ingester() === 'upload') {
                $this->api->delete('media', $media->id());
            }
        }

        $rows = [
            [
                'o:ingester' => 'url',
                'ingest_url' => $thumbUrl,
                'o:source' => $thumbUrl,
                'o:position' => 0,
                'dcterms:title' => [['type' => 'literal', 'property_id' => 'auto', '@value' => 'Thumbnail']],
                'dcterms:identifier' => [['type' => 'literal', 'property_id' => 'auto', '@value' => $mediaKey . ':thumb']],
            ],
            [
                'o:ingester' => 'html',
                'html' => $this->embedIframeHtml($iaId),
                'o:position' => 1,
                'dcterms:title' => [['type' => 'literal', 'property_id' => 'auto', '@value' => 'Internet Archive viewer']],
                'dcterms:identifier' => [['type' => 'literal', 'property_id' => 'auto', '@value' => $mediaKey . ':embed']],
            ],
        ];

        if ($this->shouldIncludeIiif($iaId)) {
            array_splice($rows, 1, 0, [[
                'o:ingester' => 'iiif_presentation',
                'ingest_url' => IaPath::iiifManifestUrl($iaId),
                'o:position' => 1,
                'dcterms:title' => [['type' => 'literal', 'property_id' => 'auto', '@value' => 'IIIF Presentation']],
                'dcterms:identifier' => [['type' => 'literal', 'property_id' => 'auto', '@value' => $mediaKey . ':iiif']],
            ]]);
            $rows[2]['o:position'] = 2;
        }

        foreach ($rows as $row) {
            $row['item_id'] = $itemId;
            $this->api->create('media', $row);
        }
    }

    protected function resolveThumbUrl(string $identifier): string
    {
        try {
            $ia = $this->readClient->fetch($identifier);
            $locs = $ia['alternate_locations'] ?? [];
            foreach (['workable', 'servers'] as $key) {
                $entries = $locs[$key] ?? [];
                if ($entries) {
                    $entry = $entries[0];
                    $server = rtrim((string) ($entry['server'] ?? ''), '/');
                    $dir = (string) ($entry['dir'] ?? '');
                    if ($server && $dir) {
                        return 'https://' . $server . $dir . '/' . self::IA_THUMB_FILENAME;
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return 'https://archive.org/download/' . IaPath::encode($identifier) . '/' . self::IA_THUMB_FILENAME;
    }

    protected function shouldIncludeIiif(string $identifier): bool
    {
        if (str_contains($identifier, '/')) {
            return false;
        }
        return $this->http->headOk(IaPath::iiifManifestUrl($identifier));
    }

    protected function embedIframeHtml(string $identifier): string
    {
        $url = IaPath::embedUrl($identifier);
        return sprintf(
            '<div class="internet-archive-embed"><iframe src="%s" width="560" height="384" frameborder="0" webkitallowfullscreen="true" mozallowfullscreen="true" allowfullscreen></iframe></div>',
            htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
    }
}
