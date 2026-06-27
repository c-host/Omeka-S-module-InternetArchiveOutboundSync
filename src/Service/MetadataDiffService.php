<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class MetadataDiffService
{
    /** Internet Archive rejects individual subject values longer than this. */
    public const IA_SUBJECT_MAX_LENGTH = 255;

    /** Always shown in push preview so reviewers can confirm mapped metadata. */
    public const PREVIEW_FIELDS = ['title', 'description'];

    protected OutboundMetadataBuilder $builder;

    public function __construct(OutboundMetadataBuilder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * Build a safe diff: only whitelisted Omeka-mapped fields; never remove IA metadata.
     *
     * @param array<string, mixed> $iaResponse Full IA metadata API response
     * @param array<string, mixed> $projected Projected IA metadata from Omeka
     * @return array{
     *   before: array<string, string>,
     *   after: array<string, string>,
     *   changes: array<int, array{field: string, before: string, after: string, op: string}>,
     *   skipped: array<int, array{field: string, before: string, reason: string}>,
     *   patch: array<int, array<string, mixed>>,
     *   has_changes: bool
     * }
     */
    public function diff(array $iaResponse, array $projected): array
    {
        $rawIaMeta = $iaResponse['metadata'] ?? [];
        $beforeMeta = $this->normalizeIaMetadata($rawIaMeta);
        $afterMeta = $projected;
        unset($afterMeta['_subject_values'], $afterMeta['_language_values']);
        $changes = [];
        $skipped = [];
        $patch = [];

        foreach ($this->builder->pushableFields() as $field) {
            if ($field === 'subject') {
                $subjectDiff = $this->diffSubjects(
                    $this->subjectValuesFromIa($rawIaMeta),
                    (array) ($projected['_subject_values'] ?? [])
                );
                $changes = array_merge($changes, $subjectDiff['changes']);
                $skipped = array_merge($skipped, $subjectDiff['skipped']);
                $patch = array_merge($patch, $subjectDiff['patch']);
                continue;
            }

            if ($field === 'language') {
                $languageDiff = $this->diffLanguages(
                    $this->languageValuesFromIa($rawIaMeta),
                    (array) ($projected['_language_values'] ?? [])
                );
                $changes = array_merge($changes, $languageDiff['changes']);
                $skipped = array_merge($skipped, $languageDiff['skipped']);
                $patch = array_merge($patch, $languageDiff['patch']);
                continue;
            }

            $before = (string) ($beforeMeta[$field] ?? '');
            $after = (string) ($afterMeta[$field] ?? '');

            if ($after === '') {
                if ($before !== '') {
                    $skipped[] = [
                        'field' => $field,
                        'before' => $before,
                        'reason' => 'Omeka has no value; IA field left unchanged (removes are never pushed).',
                    ];
                }
                continue;
            }

            $textDiff = $this->diffTextField($field, $before, $after);
            if ($textDiff === null) {
                continue;
            }
            $changes[] = $textDiff['change'];
            if ($textDiff['patch'] !== null) {
                $patch[] = $textDiff['patch'];
            }
        }

        foreach ($skipped as &$row) {
            $field = (string) ($row['field'] ?? '');
            $row['before'] = $this->formatForDisplay($field, (string) ($row['before'] ?? ''), true);
        }
        unset($row);

        return [
            'before' => $this->filterFields($beforeMeta, $this->builder->pushableFields()),
            'after' => array_map('strval', $afterMeta),
            'changes' => $changes,
            'skipped' => $skipped,
            'patch' => $patch,
            'has_changes' => $this->hasPushChanges($changes, $patch),
        ];
    }

    /**
     * @return array{change: array<string, mixed>, patch: ?array<string, mixed>}|null
     */
    protected function diffTextField(string $field, string $before, string $after): ?array
    {
        $willPatch = $before !== $after;
        $showInPreview = $willPatch || in_array($field, self::PREVIEW_FIELDS, true);
        if (!$showInPreview) {
            return null;
        }

        $change = [
            'field' => $field,
            'before' => $this->formatForDisplay($field, $before, true),
            'after' => $this->formatForDisplay($field, $after, false),
            'after_value' => $after,
            'op' => $willPatch ? ($before === '' ? 'add' : 'replace') : 'unchanged',
        ];

        $patch = null;
        if ($willPatch) {
            $patch = [
                'op' => $before === '' ? 'add' : 'replace',
                'path' => '/' . $field,
                'value' => $after,
            ];
        }

        return ['change' => $change, 'patch' => $patch];
    }

    protected function hasPushChanges(array $changes, array $patch): bool
    {
        if ($patch !== []) {
            return true;
        }
        foreach ($changes as $change) {
            $op = (string) ($change['op'] ?? '');
            if ($op !== '' && $op !== 'unchanged') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $before
     * @param string[] $after
     * @return array{
     *   changes: array<int, array{field: string, before: string, after: string, op: string}>,
     *   skipped: array<int, array{field: string, before: string, reason: string}>,
     *   patch: array<int, array<string, mixed>>
     * }
     */
    protected function diffSubjects(array $before, array $after): array
    {
        $changes = [];
        $skipped = [];
        $patch = [];

        $usableAfter = [];
        foreach ($after as $subject) {
            $subject = trim($subject);
            if ($subject === '') {
                continue;
            }
            if (mb_strlen($subject) > self::IA_SUBJECT_MAX_LENGTH) {
                $skipped[] = [
                    'field' => 'subject',
                    'before' => $subject,
                    'reason' => sprintf(
                        'Subject exceeds Internet Archive limit of %d characters and was not pushed.',
                        self::IA_SUBJECT_MAX_LENGTH
                    ),
                ];
                continue;
            }
            $usableAfter[] = $subject;
        }
        $usableAfter = array_values(array_unique($usableAfter));

        if ($usableAfter === []) {
            if ($before !== []) {
                $skipped[] = [
                    'field' => 'subject',
                    'before' => implode('; ', $before),
                    'reason' => 'Omeka has no usable subject values; IA subjects left unchanged.',
                ];
            }
            return ['changes' => $changes, 'skipped' => $skipped, 'patch' => $patch];
        }

        $willPatch = $before !== $usableAfter;
        if ($willPatch) {
            $patch = $this->buildRepeatableFieldReplacePatch('/subject', $before, $usableAfter);
        }

        $changes[] = $this->buildRepeatableChangeEntry(
            'subject',
            $before,
            $willPatch ? $usableAfter : $before,
            $willPatch ? 'replace' : 'unchanged'
        );

        return ['changes' => $changes, 'skipped' => $skipped, 'patch' => $patch];
    }

    /**
     * @param string[] $before
     * @param string[] $after
     * @return array{
     *   changes: array<int, array{field: string, before: string, after: string, op: string}>,
     *   skipped: array<int, array{field: string, before: string, reason: string}>,
     *   patch: array<int, array<string, mixed>>
     * }
     */
    protected function diffLanguages(array $before, array $after): array
    {
        $changes = [];
        $skipped = [];
        $patch = [];

        $usableAfter = [];
        foreach ($after as $code) {
            $code = strtolower(trim($code));
            if ($code === '' || $code === 'mul') {
                continue;
            }
            $usableAfter[] = $code;
        }

        if ($usableAfter === []) {
            if ($before !== []) {
                $skipped[] = [
                    'field' => 'language',
                    'before' => implode(', ', $before),
                    'reason' => 'Omeka has no usable language values; IA languages left unchanged.',
                ];
            }
            return ['changes' => $changes, 'skipped' => $skipped, 'patch' => $patch];
        }

        $willPatch = $before !== $usableAfter;
        if ($willPatch) {
            $patch = $this->buildRepeatableFieldReplacePatch('/language', $before, $usableAfter);
        }

        $changes[] = $this->buildRepeatableChangeEntry(
            'language',
            $before,
            $willPatch ? $usableAfter : $before,
            $willPatch ? 'replace' : 'unchanged'
        );

        return ['changes' => $changes, 'skipped' => $skipped, 'patch' => $patch];
    }

    /**
     * @param string[] $before
     * @param string[] $afterOnIa
     * @return array<string, mixed>
     */
    protected function buildRepeatableChangeEntry(string $field, array $before, array $afterOnIa, string $op): array
    {
        return [
            'field' => $field,
            'before_display_values' => $this->formatListValuesForDisplay($before),
            'after_display_values' => $this->formatListValuesForDisplay($afterOnIa),
            'after_values' => $afterOnIa,
            'op' => $op,
        ];
    }

    /**
     * Replace a full repeatable IA metadata field (handles add, remove, and reorder).
     *
     * @param string[] $before
     * @param string[] $after
     * @return array<int, array<string, mixed>>
     */
    protected function buildRepeatableFieldReplacePatch(string $path, array $before, array $after): array
    {
        if ($before === []) {
            $patch = [];
            foreach ($after as $value) {
                $patch[] = [
                    'op' => 'add',
                    'path' => $path . '/-',
                    'value' => $value,
                ];
            }

            return $patch;
        }

        return [
            [
                'op' => 'replace',
                'path' => $path,
                'value' => array_values($after),
            ],
        ];
    }

    /**
     * @param string[] $subjects
     * @return string[]
     */
    protected function formatSubjectValuesForDisplay(array $subjects): array
    {
        return $this->formatListValuesForDisplay($subjects);
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    protected function formatListValuesForDisplay(array $values): array
    {
        return array_map(
            fn (string $value): string => $this->formatForDisplay('subject', $value),
            $values
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return string[]
     */
    protected function languageValuesFromIa(array $metadata): array
    {
        $raw = $metadata['language'] ?? [];
        if (!is_array($raw)) {
            $raw = ($raw === null || $raw === '') ? [] : [(string) $raw];
        }
        $values = [];
        foreach ($raw as $value) {
            $text = strtolower(trim((string) $value));
            if ($text !== '' && $text !== 'mul') {
                $values[] = $text;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return string[]
     */
    protected function subjectValuesFromIa(array $metadata): array
    {
        $raw = $metadata['subject'] ?? [];
        if (!is_array($raw)) {
            $raw = ($raw === null || $raw === '') ? [] : [(string) $raw];
        }
        $values = [];
        foreach ($raw as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $values[] = $text;
            }
        }
        return array_values(array_unique($values));
    }

    /**
     * @param array<string, string> $meta
     * @param string[] $fields
     * @return array<string, string>
     */
    protected function filterFields(array $meta, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (isset($meta[$field])) {
                $out[$field] = $meta[$field];
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, string>
     */
    protected function normalizeIaMetadata(array $meta): array
    {
        $out = [];
        foreach ($meta as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_array($value)) {
                $value = implode('; ', array_map('strval', $value));
            }
            $out[$key] = trim((string) $value);
        }
        return $out;
    }

    /**
     * Confirm IA metadata reflects the values we attempted to push.
     *
     * @param array<string, mixed> $iaResponse
     * @param array<string, mixed> $diff
     */
    public function patchApplied(array $iaResponse, array $diff): bool
    {
        $changes = $diff['changes'] ?? [];
        if ($changes === []) {
            return false;
        }

        $rawMeta = $iaResponse['metadata'] ?? [];
        $meta = $this->normalizeIaMetadata($rawMeta);
        foreach ($changes as $change) {
            $field = (string) ($change['field'] ?? '');
            $op = (string) ($change['op'] ?? '');
            if ($op === 'unchanged') {
                continue;
            }
            if ($field === 'subject' || $field === 'language') {
                $expected = (array) ($change['after_values'] ?? []);
                $actual = $field === 'subject'
                    ? $this->subjectValuesFromIa($rawMeta)
                    : $this->languageValuesFromIa($rawMeta);
                if ($this->repeatableListsMatch($expected, $actual)) {
                    continue;
                }
                return false;
            }

            $expected = (string) ($change['after_value'] ?? $change['after'] ?? '');
            $actual = (string) ($meta[$field] ?? '');
            if (!$this->valuesMatch($field, $expected, $actual)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    protected function subjectListsMatch(array $a, array $b): bool
    {
        return $this->repeatableListsMatch($a, $b);
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    protected function repeatableListsMatch(array $a, array $b): bool
    {
        return $a === $b;
    }

    /**
     * @return string[]
     */
    protected function subjectValuesFromList(string $joined): array
    {
        if ($joined === '') {
            return [];
        }
        $parts = array_map('trim', explode(';', $joined));
        return array_values(array_unique(array_filter($parts, fn (string $p): bool => $p !== '')));
    }

    protected function valuesMatch(string $field, string $expected, string $actual): bool
    {
        return $this->normalizeForCompare($field, $expected)
            === $this->normalizeForCompare($field, $actual);
    }

    public function formatForDisplay(string $field, string $value, bool $asStoredOnIa = false): string
    {
        return $this->normalizeTextValue($field, $value, !$asStoredOnIa, !$asStoredOnIa);
    }

    protected function decodeEmbeddedUnicodeEscapes(string $value): string
    {
        if ($value === '' || !str_contains($value, '\\u')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            static function (array $matches): string {
                return mb_chr((int) hexdec($matches[1]), 'UTF-8');
            },
            $value
        );
    }

    protected function normalizeForCompare(string $field, string $value): string
    {
        return $this->normalizeTextValue($field, $value, true, true);
    }

    protected function normalizeTextValue(
        string $field,
        string $value,
        bool $decodeLiteralEscapes = true,
        bool $decodeUnicodeEscapes = true
    ): string {
        if ($decodeUnicodeEscapes) {
            $value = $this->decodeEmbeddedUnicodeEscapes($value);
        }
        if ($decodeLiteralEscapes) {
            $value = $this->decodeLiteralEscapeSequences($value);
        }
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);
        if ($field === 'description') {
            $value = preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;
            $value = preg_replace('/<\/p\s*>/i', "\n\n", $value) ?? $value;
            $value = strip_tags($value);
            $value = preg_replace("/\r\n?/", "\n", $value) ?? $value;
            $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
            $value = $this->trimDescriptionLines($value);
        } else {
            $value = strip_tags($value);
        }

        return trim($value);
    }

    protected function trimDescriptionLines(string $value): string
    {
        $lines = explode("\n", $value);

        return implode("\n", array_map('trim', $lines));
    }

    /**
     * Internet Archive occasionally stores paragraph breaks as literal "\n" text.
     */
    protected function decodeLiteralEscapeSequences(string $value): string
    {
        if ($value === '' || !str_contains($value, '\\')) {
            return $value;
        }

        return str_replace(
            ['\\r\\n', '\\n', '\\r', '\\t'],
            ["\n", "\n", "\n", "\t"],
            $value
        );
    }

    /**
     * Build a diff-shaped preview for new IA uploads (no existing IA metadata).
     *
     * @param array<string, mixed> $projected
     * @return array{changes: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, has_changes: bool}
     */
    public function projectedPublishPreview(array $projected): array
    {
        $afterMeta = $projected;
        unset($afterMeta['_subject_values'], $afterMeta['_language_values']);
        $changes = [];
        $newItemLabel = '(new item)';

        foreach ($this->builder->pushableFields() as $field) {
            if ($field === 'subject') {
                $subjects = (array) ($projected['_subject_values'] ?? []);
                if ($subjects === []) {
                    continue;
                }
                $entry = $this->buildRepeatableChangeEntry('subject', [], $subjects, 'add');
                $entry['before'] = $newItemLabel;
                $entry['before_display_values'] = [$newItemLabel];
                $changes[] = $entry;
                continue;
            }

            if ($field === 'language') {
                $languages = (array) ($projected['_language_values'] ?? []);
                if ($languages === []) {
                    continue;
                }
                $entry = $this->buildRepeatableChangeEntry('language', [], $languages, 'add');
                $entry['before'] = $newItemLabel;
                $entry['before_display_values'] = [$newItemLabel];
                $changes[] = $entry;
                continue;
            }

            $after = (string) ($afterMeta[$field] ?? '');
            if ($after === '') {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'before' => $newItemLabel,
                'after' => $this->formatForDisplay($field, $after, false),
                'after_value' => $after,
                'op' => 'add',
            ];
        }

        return [
            'changes' => $changes,
            'skipped' => [],
            'has_changes' => $changes !== [],
        ];
    }
}
