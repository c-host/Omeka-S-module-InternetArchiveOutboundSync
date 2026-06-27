<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class OutboundMetadataBuilder
{
    /** Internet Archive rejects individual subject values longer than this. */
    public const IA_SUBJECT_MAX_LENGTH = 255;

    /** Fields Omeka may push to IA (whitelist only). */
    protected const PUSHABLE_IA_FIELDS = [
        'title',
        'creator',
        'subject',
        'description',
        'date',
        'language',
        'licenseurl',
    ];

    /** @var string[] IA fields never included in outbound patches. */
    protected const EXCLUDED_IA_FIELDS = [
        'mediatype',
        'collection',
        'uploader',
        'addeddate',
        'publicdate',
        'curation',
        'identifier',
    ];

    protected BilingualTextMerger $merger;

    protected ModuleSettings $settings;

    protected MarcLanguageResolver $languageResolver;

    public function __construct(
        BilingualTextMerger $merger,
        ModuleSettings $settings,
        MarcLanguageResolver $languageResolver
    ) {
        $this->merger = $merger;
        $this->settings = $settings;
        $this->languageResolver = $languageResolver;
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @return array<string, string>
     */
    public function fromItem($item): array
    {
        $meta = [];

        $title = $this->mergeProperty($item, 'dcterms:title', BilingualTextMerger::SEPARATOR_PIPE);
        if ($title !== '') {
            $meta['title'] = $title;
        }
        $creator = $this->mergeProperty($item, 'dcterms:creator', BilingualTextMerger::SEPARATOR_COMMA);
        if ($creator !== '') {
            $meta['creator'] = $creator;
        }
        $subjects = $this->subjectsFromItem($item);
        if ($subjects !== []) {
            $meta['subject'] = implode('; ', $subjects);
            $meta['_subject_values'] = $subjects;
        }
        $description = $this->mergeDescription($item);
        if ($description !== '') {
            $meta['description'] = $description;
        }
        $date = $this->firstLiteral($item, 'dcterms:date');
        if ($date !== '') {
            $meta['date'] = $date;
        }
        $languages = $this->languagesFromItem($item);
        if ($languages !== []) {
            $meta['_language_values'] = $languages;
            $meta['language'] = $languages[0];
        }
        $license = $this->firstUri($item, 'dcterms:rights');
        if ($license !== '') {
            $meta['licenseurl'] = $license;
        }

        return $meta;
    }

    /**
     * @return string[]
     */
    public function pushableFields(): array
    {
        return self::PUSHABLE_IA_FIELDS;
    }

    /**
     * @return string[]
     */
    public function excludedFields(): array
    {
        return self::EXCLUDED_IA_FIELDS;
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    protected function mergeProperty(
        $item,
        string $property,
        string $separator = BilingualTextMerger::SEPARATOR_PIPE
    ): string {
        $valueObjects = $item->value($property, ['all' => true]) ?: [];
        $parts = $this->partsFromValueObjects($valueObjects, false);
        return $this->mergeParts($parts, $separator);
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    protected function mergeDescription($item): string
    {
        $valueObjects = $item->value('dcterms:description', ['all' => true]) ?: [];
        $parts = [];
        foreach ($valueObjects as $vo) {
            $raw = (string) $vo->value();
            $plain = $this->merger->stripHtml($raw);
            if ($plain === '') {
                continue;
            }
            $parts[] = [
                'value' => $plain,
                'language' => method_exists($vo, 'lang') ? ($vo->lang() ?: null) : null,
            ];
        }
        return $this->mergeParts($parts, BilingualTextMerger::SEPARATOR_PARAGRAPH);
    }

    /**
     * @param iterable<mixed> $valueObjects
     * @return array<int, array{value: string, language: ?string}>
     */
    protected function partsFromValueObjects(iterable $valueObjects, bool $stripHtml = false): array
    {
        $parts = [];
        foreach ($valueObjects as $vo) {
            if (is_object($vo) && method_exists($vo, 'value')) {
                $value = trim((string) $vo->value());
                $lang = method_exists($vo, 'lang') ? ($vo->lang() ?: null) : null;
            } elseif (is_array($vo)) {
                $value = trim((string) ($vo['@value'] ?? ''));
                $lang = isset($vo['@language']) ? (string) $vo['@language'] : null;
            } else {
                continue;
            }
            if ($value === '') {
                continue;
            }
            if ($stripHtml) {
                $value = $this->merger->stripHtml($value);
                if ($value === '') {
                    continue;
                }
            }
            $parts[] = ['value' => $value, 'language' => $lang];
        }
        return $parts;
    }

    /**
     * @param array<int, array{value: string, language: ?string}> $parts
     */
    protected function mergeParts(array $parts, string $separator): string
    {
        if ($parts === []) {
            return '';
        }
        $hasExplicitLanguage = false;
        foreach ($parts as $part) {
            if (!empty($part['language'])) {
                $hasExplicitLanguage = true;
                break;
            }
        }
        if ($hasExplicitLanguage) {
            $sorted = [];
            foreach ($parts as $part) {
                $sorted[] = [
                    'value' => $part['value'],
                    'language' => $part['language'] ?: $this->merger->detectLanguage($part['value']),
                ];
            }
            return $this->merger->merge($sorted, $separator);
        }
        return $this->merger->mergeInOrder($parts, $separator);
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @return string[]
     */
    public function subjectsFromItem($item): array
    {
        $subjects = [];
        $seen = [];
        foreach ($item->value('dcterms:subject', ['all' => true]) ?: [] as $vo) {
            $text = trim((string) $vo->value());
            if ($text === '') {
                continue;
            }
            foreach ($this->splitSubjectSegments($text) as $segment) {
                if (!isset($seen[$segment])) {
                    $seen[$segment] = true;
                    $subjects[] = $segment;
                }
            }
        }
        return $subjects;
    }

    /**
     * @return string[]
     */
    protected function splitSubjectSegments(string $text): array
    {
        $segments = preg_split('/\s*[,;]\s*/', $text) ?: [];
        $out = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment !== '') {
                $out[] = $segment;
            }
        }
        return $out;
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    protected function firstLiteral($item, string $property): string
    {
        foreach ($item->value($property, ['all' => true]) ?: [] as $vo) {
            $v = trim((string) $vo->value());
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    protected function firstUri($item, string $property): string
    {
        foreach ($item->value($property, ['all' => true]) ?: [] as $vo) {
            if (method_exists($vo, 'uri') && $vo->uri()) {
                return (string) $vo->uri();
            }
            $v = trim((string) $vo->value());
            if (preg_match('~^https?://~i', $v)) {
                return $v;
            }
            if (preg_match('~https?://[^\s<>"\']+~i', $v, $matches)) {
                return rtrim($matches[0], '.,;)]');
            }
        }
        return '';
    }

    /**
     * Distinct MARC codes from Omeka dcterms:language, in value order (first occurrence wins).
     *
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @return string[]
     */
    public function languagesFromItem($item): array
    {
        $codes = [];
        $seen = [];
        foreach ($item->value('dcterms:language', ['all' => true]) ?: [] as $vo) {
            $literal = trim((string) $vo->value());
            $tag = method_exists($vo, 'lang') ? ($vo->lang() ?: null) : null;
            $code = $this->languageResolver->resolve($literal, $tag);
            if ($code === null || strtolower($code) === 'mul') {
                continue;
            }
            $code = strtolower($code);
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * @param array<string, string> $meta
     * @return array<string, string>
     */
    public function s3MetaHeaders(array $meta, string $collectionId, string $mediatype): array
    {
        $headers = [
            '01-collection' => $this->sanitizeHeaderValue($collectionId),
            'mediatype' => $this->sanitizeHeaderValue($mediatype),
        ];
        foreach (['title', 'creator', 'description', 'date', 'language', 'licenseurl'] as $field) {
            if ($field === 'language' && !empty($meta['_language_values']) && is_array($meta['_language_values'])) {
                $headers[$field] = $this->sanitizeHeaderValue(implode(', ', $meta['_language_values']));
            } elseif (!empty($meta[$field])) {
                $headers[$field] = $this->sanitizeHeaderValue($meta[$field]);
            }
        }
        foreach ($this->usableSubjectValues($meta) as $index => $subject) {
            $headers[sprintf('%02d-subject', $index + 1)] = $this->sanitizeHeaderValue($subject);
        }

        return $headers;
    }

    /**
     * Post-upload patch for publish: restore header-flattened text and add subjects on recovery.
     *
     * @param array<string, mixed> $meta
     * @return array<int, array<string, mixed>>
     */
    public function publishMetadataPatch(array $meta, bool $subjectsSentViaS3Headers): array
    {
        $patch = $this->headerCorrectionPatch($meta);
        if ($subjectsSentViaS3Headers) {
            return $patch;
        }

        return array_merge($patch, $this->subjectInitialPatch($meta));
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<int, array<string, mixed>>
     */
    public function subjectInitialPatch(array $meta): array
    {
        $patch = [];
        foreach ($this->usableSubjectValues($meta) as $subject) {
            $patch[] = [
                'op' => 'add',
                'path' => '/subject/-',
                'value' => $subject,
            ];
        }

        return $patch;
    }

    /**
     * @param array<string, mixed> $meta
     * @return string[]
     */
    public function usableSubjectValues(array $meta): array
    {
        $subjects = [];
        $seen = [];
        foreach ((array) ($meta['_subject_values'] ?? []) as $subject) {
            $subject = trim((string) $subject);
            if ($subject === '' || mb_strlen($subject) > self::IA_SUBJECT_MAX_LENGTH) {
                continue;
            }
            if (isset($seen[$subject])) {
                continue;
            }
            $seen[$subject] = true;
            $subjects[] = $subject;
        }

        return $subjects;
    }

    /**
     * RFC 6902 patch to restore metadata values altered for S3 header safety.
     *
     * @param array<string, mixed> $meta
     * @return array<int, array<string, mixed>>
     */
    public function headerCorrectionPatch(array $meta): array
    {
        $patch = [];
        foreach (['title', 'creator', 'description', 'date', 'licenseurl'] as $field) {
            if (empty($meta[$field])) {
                continue;
            }
            $original = (string) $meta[$field];
            if ($original === $this->sanitizeHeaderValue($original)) {
                continue;
            }
            $patch[] = [
                'op' => 'replace',
                'path' => '/' . $field,
                'value' => $original,
            ];
        }

        return $patch;
    }

    protected function sanitizeHeaderValue(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public function inferMediatypeFromFile(string $filename, ?string $mime = null): string
    {
        $mime = strtolower((string) $mime);
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'movies';
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff', 'webp'], true)) {
            return 'image';
        }
        if (in_array($ext, ['mp3', 'wav', 'ogg', 'flac'], true)) {
            return 'audio';
        }
        if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'], true)) {
            return 'movies';
        }
        return $this->settings->defaultMediatype();
    }
}
