<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

class IaPushPreflightService
{
    /**
     * @param array<string, mixed> $ia Full IA metadata API response
     * @return string[] Collection identifiers on the IA item
     */
    public function extractCollections(array $ia): array
    {
        $raw = ($ia['metadata'] ?? [])['collection'] ?? [];
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn ($v) => trim((string) $v),
                $raw
            )));
        }
        $parts = preg_split('/\s*;\s*/', (string) $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts)));
    }

    /**
     * @return string|null Error message when the IA item is not in the expected collection
     */
    public function verifyCollection(array $ia, string $expectedCollection): ?string
    {
        $expectedCollection = trim($expectedCollection);
        if ($expectedCollection === '') {
            return 'No IA collection is mapped for this Omeka item set.';
        }

        $collections = $this->extractCollections($ia);
        if ($collections === []) {
            return sprintf(
                'Internet Archive item has no collection metadata; expected "%s".',
                $expectedCollection
            );
        }

        foreach ($collections as $collection) {
            if (strcasecmp($collection, $expectedCollection) === 0) {
                return null;
            }
        }

        return sprintf(
            'Internet Archive item collections (%s) do not include expected collection "%s".',
            implode(', ', $collections),
            $expectedCollection
        );
    }
}
