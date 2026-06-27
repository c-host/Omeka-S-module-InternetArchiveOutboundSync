<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class IaIdentifierGenerator
{
    protected ModuleSettings $settings;

    protected IaMetadataReadClient $metadata;

    public function __construct(ModuleSettings $settings, IaMetadataReadClient $metadata)
    {
        $this->settings = $settings;
        $this->metadata = $metadata;
    }

    public function fromTitle(string $title, ?int $omekaItemId = null): string
    {
        $slug = $this->slugify($title);
        if ($slug === '') {
            $slug = 'item';
        }
        $candidate = $this->applySuffix($slug);
        if ($omekaItemId !== null && $this->identifierTaken($candidate)) {
            $candidate = $this->applySuffix($slug . '-' . $omekaItemId);
        }
        if ($this->identifierTaken($candidate) && $omekaItemId !== null) {
            $candidate = $this->applySuffix(
                $slug . '-' . $omekaItemId . '-' . substr(sha1($title), 0, 6)
            );
        }

        return $candidate;
    }

    protected function applySuffix(string $base): string
    {
        $suffix = trim($this->settings->identifierSuffix(), '-');
        if ($suffix === '') {
            return $base;
        }

        return $base . '-' . $suffix;
    }

    protected function identifierTaken(string $identifier): bool
    {
        $result = $this->metadata->checkExists($identifier);
        if ($result['error'] !== null) {
            throw new \RuntimeException(
                'Cannot verify Internet Archive identifier availability: ' . $result['error']
            );
        }
        return $result['exists'];
    }

    public function slugify(string $title): string
    {
        $text = trim($title);
        if ($text === '') {
            return '';
        }
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text) ?: $text;
        } else {
            $text = mb_strtolower($text, 'UTF-8');
        }
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?: $text;
        $text = trim($text, '-');
        if (strlen($text) > 80) {
            $text = rtrim(substr($text, 0, 80), '-');
        }
        return $text;
    }
}
