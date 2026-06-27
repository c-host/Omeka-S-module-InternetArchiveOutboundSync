<?php declare(strict_types=1);

namespace InternetArchiveOutboundSyncTest;

use PHPUnit\Framework\TestCase;

class MetadataRevisionPublishCurrentTest extends TestCase
{
    private static function publishStillCurrent(string $modified, string $publishedAt): bool
    {
        $modifiedTs = strtotime($modified);
        $publishedTs = strtotime($publishedAt);
        if ($modifiedTs === false || $publishedTs === false) {
            return true;
        }

        return $modifiedTs <= $publishedTs;
    }

    public function testPublishedRevisionBlocksRequeue(): void
    {
        $this->assertTrue(self::publishStillCurrent('2026-06-26 19:33:00', '2026-06-26 19:36:35'));
        $this->assertFalse(self::publishStillCurrent('2026-06-26 19:40:00', '2026-06-26 19:36:35'));
        $this->assertTrue(self::publishStillCurrent('2026-06-26 19:36:35', '2026-06-26 19:36:35'));
    }
}
