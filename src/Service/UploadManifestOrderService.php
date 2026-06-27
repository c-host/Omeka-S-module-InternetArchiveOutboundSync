<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class UploadManifestOrderService
{
    public const SORT_OMEKA_POSITION = 'omeka_position';

    public const SORT_FILENAME_PREFIX = 'filename_prefix';

    public const SORT_ADMIN_ORDER = 'admin_order';

    /**
     * @param array<int, array{media_id: int, filename: string, size: int, sha256: string, mime: ?string}> $files
     * @param int[]|null $adminMediaIds
     * @return array{
     *   files: array<int, array{media_id: int, filename: string, size: int, sha256: string, mime: ?string}>,
     *   sort_method: string,
     *   warning: ?string
     * }
     */
    public function orderManifest(array $files, ?array $adminMediaIds = null): array
    {
        if ($files === []) {
            return [
                'files' => [],
                'sort_method' => self::SORT_OMEKA_POSITION,
                'warning' => null,
            ];
        }

        $indexed = $this->attachOmekaIndex($files);

        if ($adminMediaIds !== null && $adminMediaIds !== []) {
            return $this->orderByAdminMediaIds($indexed, $adminMediaIds);
        }

        return $this->orderAutomatically($indexed);
    }

    public function parseNumericPrefix(string $filename): ?int
    {
        $basename = basename(str_replace('\\', '/', trim($filename)));
        if ($basename === '') {
            return null;
        }
        if (preg_match('/^(\d+)[\-_.]+/u', $basename, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param array<int, array<string, mixed>> $indexed
     * @return array{files: array<int, array<string, mixed>>, sort_method: string, warning: ?string}
     */
    protected function orderAutomatically(array $indexed): array
    {
        if (count($indexed) === 1) {
            return [
                'files' => $this->stripInternal($indexed),
                'sort_method' => self::SORT_OMEKA_POSITION,
                'warning' => null,
            ];
        }

        $prefixes = [];
        foreach ($indexed as $file) {
            $prefixes[] = $this->parseNumericPrefix((string) ($file['filename'] ?? ''));
        }

        $allHavePrefix = !in_array(null, $prefixes, true);
        if ($allHavePrefix) {
            usort($indexed, function (array $a, array $b): int {
                $prefixCompare = ((int) $a['_numeric_prefix']) <=> ((int) $b['_numeric_prefix']);
                if ($prefixCompare !== 0) {
                    return $prefixCompare;
                }

                return ((int) $a['_omeka_index']) <=> ((int) $b['_omeka_index']);
            });

            return [
                'files' => $this->stripInternal($indexed),
                'sort_method' => self::SORT_FILENAME_PREFIX,
                'warning' => null,
            ];
        }

        $hasAnyPrefix = count(array_filter($prefixes, static fn (?int $prefix): bool => $prefix !== null)) > 0;
        $warning = $hasAnyPrefix
            ? 'Only some files have number prefixes (01-name, 02-name) — using Omeka media order. Drag files below to set upload order before publishing.'
            : 'No numbered filenames detected on all files — using Omeka media order. Drag files below to set upload order, or ask contributors to prefix filenames (01-name, 02-name).';

        return [
            'files' => $this->stripInternal($indexed),
            'sort_method' => self::SORT_OMEKA_POSITION,
            'warning' => $warning,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $indexed
     * @param int[] $adminMediaIds
     * @return array{files: array<int, array<string, mixed>>, sort_method: string, warning: ?string}
     */
    protected function orderByAdminMediaIds(array $indexed, array $adminMediaIds): array
    {
        $byId = [];
        foreach ($indexed as $file) {
            $byId[(int) $file['media_id']] = $file;
        }

        $adminMediaIds = array_values(array_unique(array_map('intval', $adminMediaIds)));
        $expectedIds = array_map('intval', array_column($indexed, 'media_id'));
        sort($expectedIds);
        $postedSorted = $adminMediaIds;
        sort($postedSorted);

        if ($adminMediaIds === [] || $postedSorted !== $expectedIds) {
            return $this->orderAutomatically($indexed);
        }

        $ordered = [];
        foreach ($adminMediaIds as $mediaId) {
            $ordered[] = $byId[$mediaId];
        }

        return [
            'files' => $this->stripInternal($ordered),
            'sort_method' => self::SORT_ADMIN_ORDER,
            'warning' => null,
        ];
    }

    /**
     * @param array<int, array{media_id: int, filename: string, size: int, sha256: string, mime: ?string}> $files
     * @return array<int, array<string, mixed>>
     */
    protected function attachOmekaIndex(array $files): array
    {
        $indexed = [];
        foreach (array_values($files) as $index => $file) {
            $filename = (string) ($file['filename'] ?? '');
            $indexed[] = $file + [
                '_omeka_index' => $index,
                '_numeric_prefix' => $this->parseNumericPrefix($filename),
            ];
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array{media_id: int, filename: string, size: int, sha256: string, mime: ?string}>
     */
    protected function stripInternal(array $files): array
    {
        $clean = [];
        foreach ($files as $file) {
            unset($file['_omeka_index'], $file['_numeric_prefix']);
            $clean[] = $file;
        }

        return $clean;
    }

    public function sortMethodLabel(string $sortMethod): string
    {
        return match ($sortMethod) {
            self::SORT_FILENAME_PREFIX => 'Ordered by filename number prefix',
            self::SORT_ADMIN_ORDER => 'Custom order set in preview',
            default => 'Ordered by Omeka media position',
        };
    }
}
