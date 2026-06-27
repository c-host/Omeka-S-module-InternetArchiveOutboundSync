<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

/**
 * Map Omeka dcterms:language values to Internet Archive / MARC ISO 639-2 bibliographic codes.
 */
class MarcLanguageResolver
{
    protected Iso6392LanguageCatalog $catalog;

    protected ModuleSettings $settings;

    public function __construct(Iso6392LanguageCatalog $catalog, ModuleSettings $settings)
    {
        $this->catalog = $catalog;
        $this->settings = $settings;
    }

    /**
     * Resolve a language literal and/or Omeka value @language tag to a MARC code (e.g. eng, rus, geo).
     */
    public function resolve(?string $literal, ?string $languageTag = null): ?string
    {
        $literal = $literal !== null ? trim($literal) : '';
        if ($literal !== '') {
            $fromCustom = $this->resolveFromCustomMap($literal);
            if ($fromCustom !== null) {
                return $fromCustom;
            }

            $fromCatalog = $this->catalog->resolveBibliographic($literal);
            if ($fromCatalog !== null) {
                return $fromCatalog;
            }
        }

        if ($languageTag !== null && trim($languageTag) !== '') {
            $fromTag = $this->resolveTag(trim($languageTag));
            if ($fromTag !== null) {
                return $fromTag;
            }
        }

        return null;
    }

    protected function resolveTag(string $tag): ?string
    {
        $tag = strtolower(str_replace('_', '-', $tag));
        if ($tag === '') {
            return null;
        }

        $fromCustom = $this->resolveFromCustomMap($tag);
        if ($fromCustom !== null) {
            return $fromCustom;
        }

        $primary = explode('-', $tag)[0];
        return $this->catalog->resolveBibliographic($primary);
    }

    protected function resolveFromCustomMap(string $key): ?string
    {
        $key = strtolower(trim($key));
        $map = $this->settings->iaLanguageMap();
        if (!isset($map[$key])) {
            return null;
        }

        $entry = $map[$key];
        if (!empty($entry['marc']) && is_string($entry['marc'])) {
            $marc = strtolower(trim($entry['marc']));
            return strlen($marc) === 3 ? $marc : null;
        }

        if (!empty($entry['bcp47']) && is_string($entry['bcp47'])) {
            return $this->catalog->resolveBibliographic($entry['bcp47']);
        }

        return null;
    }
}
