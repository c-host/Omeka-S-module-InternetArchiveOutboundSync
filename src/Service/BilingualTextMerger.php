<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class BilingualTextMerger
{
    protected const LANGUAGE_ORDER = ['en', 'ka'];

    public const SEPARATOR_PIPE = ' | ';

    public const SEPARATOR_COMMA = ', ';

    public const SEPARATOR_PARAGRAPH = "\n\n";

    /**
     * @param array<int, array{value: string, language: ?string}> $parts
     */
    public function merge(array $parts, string $separator = self::SEPARATOR_PIPE): string
    {
        if ($parts === []) {
            return '';
        }
        if (count($parts) === 1) {
            return trim($parts[0]['value']);
        }
        usort($parts, function ($a, $b) {
            $order = array_flip(self::LANGUAGE_ORDER);
            $la = $a['language'] ? ($order[$a['language']] ?? 99) : 100;
            $lb = $b['language'] ? ($order[$b['language']] ?? 99) : 100;
            if ($la !== $lb) {
                return $la <=> $lb;
            }
            return strcmp($a['value'], $b['value']);
        });
        $values = [];
        $seen = [];
        foreach ($parts as $part) {
            $v = trim($part['value']);
            if ($v === '' || isset($seen[$v])) {
                continue;
            }
            $seen[$v] = true;
            $values[] = $v;
        }
        return implode($separator, $values);
    }

    /**
     * Join values in input order (no language sort). Deduplicates identical trimmed values.
     *
     * @param array<int, array{value: string, language: ?string}> $parts
     */
    public function mergeInOrder(array $parts, string $separator = self::SEPARATOR_PIPE): string
    {
        if ($parts === []) {
            return '';
        }
        if (count($parts) === 1) {
            return trim($parts[0]['value']);
        }
        $values = [];
        $seen = [];
        foreach ($parts as $part) {
            $v = trim($part['value']);
            if ($v === '' || isset($seen[$v])) {
                continue;
            }
            $seen[$v] = true;
            $values[] = $v;
        }
        return implode($separator, $values);
    }

    /**
     * @param iterable<mixed> $valueObjects Omeka value representations or arrays
     * @return array<int, array{value: string, language: ?string}>
     */
    public function literalsFromValues(iterable $valueObjects): array
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
            if (!$lang) {
                $lang = $this->detectLanguage($value);
            }
            $parts[] = ['value' => $value, 'language' => $lang];
        }
        return $parts;
    }

    public function detectLanguage(string $segment): ?string
    {
        $text = trim($segment);
        if ($text === '') {
            return null;
        }
        $hasKa = (bool) preg_match('/[\x{10A0}-\x{10FF}]/u', $text);
        $hasEn = (bool) preg_match('/[A-Za-z]/', $text);
        if ($hasKa && !$hasEn) {
            return 'ka';
        }
        if ($hasEn && !$hasKa) {
            return 'en';
        }
        if ($hasKa && $hasEn) {
            preg_match_all('/[\x{10A0}-\x{10FF}]/u', $text, $kaM);
            preg_match_all('/[A-Za-z]/', $text, $enM);
            return count($kaM[0] ?? []) >= count($enM[0] ?? []) ? 'ka' : 'en';
        }
        return null;
    }

    public function stripHtml(string $raw): string
    {
        $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/p\s*>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = str_replace("\xc2\xa0", ' ', $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
    }
}
