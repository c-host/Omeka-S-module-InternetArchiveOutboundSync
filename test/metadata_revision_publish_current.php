<?php
declare(strict_types=1);

/**
 * Documents when a published metadata revision should block re-queueing.
 * Mirrors hasCurrentMetadataRevisionPublish() timestamp comparison.
 */
$publishStillCurrent = static function (string $modified, string $publishedAt): bool {
    $modifiedTs = strtotime($modified);
    $publishedTs = strtotime($publishedAt);
    if ($modifiedTs === false || $publishedTs === false) {
        return true;
    }

    return $modifiedTs <= $publishedTs;
};

assert($publishStillCurrent('2026-06-26 19:33:00', '2026-06-26 19:36:35'));
assert(!$publishStillCurrent('2026-06-26 19:40:00', '2026-06-26 19:36:35'));
assert($publishStillCurrent('2026-06-26 19:36:35', '2026-06-26 19:36:35'));

echo "metadata_revision_publish_current: ok\n";
