<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Laminas\ServiceManager\ServiceLocatorInterface;

class SetupStatusService
{
    protected ModuleSettings $moduleSettings;

    public function __construct(ModuleSettings $moduleSettings)
    {
        $this->moduleSettings = $moduleSettings;
    }

    /**
     * @return array{ready: bool, open_count: int, required_open_count: int, items: array<int, array<string, mixed>>}
     */
    public function getStatus(ServiceLocatorInterface $services): array
    {
        $items = [
            $this->checkCredentials(),
            $this->checkConnectionTest(),
            $this->checkDefaultCollection(),
            $this->checkPublishTestCollection(),
            $this->checkContributionsItemSet(),
            $this->checkItemSetMapping(),
            $this->checkMetadataPushEnabled(),
            $this->checkContribute($services),
            $this->checkJobs($services),
            $this->checkCompanionInbound($services),
        ];

        $blocking = array_filter(
            $items,
            fn (array $item): bool => !$item['ok'] && $item['severity'] === 'error'
        );
        $open = array_filter($items, fn (array $item): bool => !$item['ok']);

        return [
            'ready' => $blocking === [],
            'open_count' => count($open),
            'required_open_count' => count($blocking),
            'items' => $items,
        ];
    }

    protected function checkCredentials(): array
    {
        $ok = $this->moduleSettings->hasCredentials();
        return [
            'id' => 'credentials',
            'ok' => $ok,
            'severity' => 'error',
            'label' => 'IA-S3 credentials configured',
            'detail' => $ok
                ? ($this->moduleSettings->credentialsFromEnv() ? 'Loaded from environment variables.' : 'Stored in module settings.')
                : 'Access key and secret are required for pushes and uploads.',
            'action' => 'Set IA_S3_ACCESS_KEY and IA_S3_SECRET_KEY in environment, or Modules → Configure.',
        ];
    }

    protected function checkConnectionTest(): array
    {
        $ok = $this->moduleSettings->connectionTestPassed();
        return [
            'id' => 'connection_test',
            'ok' => $ok,
            'severity' => 'error',
            'label' => 'Connection test passed',
            'detail' => $ok ? 'Last connection test succeeded.' : 'Run Test connection on the Configure form.',
            'action' => 'Modules → IA Outbound → Configure → Test connection',
        ];
    }

    protected function checkDefaultCollection(): array
    {
        $default = $this->moduleSettings->defaultIaCollection();
        $publish = $this->moduleSettings->publishIaCollection();
        $ok = $publish !== '';
        $detail = $ok
            ? 'Publish collection: ' . $publish
            : 'Required for user-submitted item publishing.';
        if ($ok && $default !== '' && $default !== $publish) {
            $detail .= ' Default collection for metadata push: ' . $default . '.';
        }

        return [
            'id' => 'default_collection',
            'ok' => $ok,
            'severity' => 'error',
            'label' => 'IA collection configured for publishing',
            'detail' => $detail,
            'action' => 'Modules → Configure → Default IA collection (or publish test collection override)',
        ];
    }

    protected function checkPublishTestCollection(): array
    {
        if (!$this->moduleSettings->publishTestCollectionActive()) {
            return [
                'id' => 'publish_test_collection',
                'ok' => true,
                'severity' => 'info',
                'label' => 'Publish test collection override',
                'detail' => 'Not set. User-submitted item uploads use the default IA collection.',
                'action' => 'To test publishes safely, set IA_PUBLISH_TEST_COLLECTION=test_collection in the environment.',
            ];
        }

        $override = $this->moduleSettings->publishTestCollectionOverride();
        $source = $this->moduleSettings->publishTestCollectionFromEnv()
            ? 'environment variable IA_PUBLISH_TEST_COLLECTION'
            : 'module settings';

        return [
            'id' => 'publish_test_collection',
            'ok' => true,
            'severity' => 'warning',
            'label' => 'Publish test collection override is active',
            'detail' => sprintf(
                'User-submitted item uploads go to %s (from %s). Metadata push still uses mapped/default collections. Items in test_collection are removed by Internet Archive after about 30 days.',
                $override,
                $source
            ),
            'action' => 'Clear IA_PUBLISH_TEST_COLLECTION or the Configure override before production publishing.',
        ];
    }

    protected function checkContributionsItemSet(): array
    {
        $id = $this->moduleSettings->contributionsItemSetId();
        $ok = $id > 0;

        return [
            'id' => 'contributions_item_set',
            'ok' => $ok,
            'severity' => 'warning',
            'label' => 'Contributions item set configured',
            'detail' => $ok
                ? sprintf('Publish user-submitted items lists members of item set #%d.', $id)
                : 'Required for the Publish user-submitted items page (Collecting staging workflow).',
            'action' => 'Modules → Configure → Contributions item set',
        ];
    }

    protected function checkItemSetMapping(): array
    {
        $ok = $this->moduleSettings->itemSetCollectionMap() !== [];
        return [
            'id' => 'item_set_map',
            'ok' => $ok,
            'severity' => 'error',
            'label' => 'Item set ↔ IA collection mapping',
            'detail' => $ok ? 'At least one mapping configured.' : 'Required for metadata push item selection.',
            'action' => 'Modules → Configure → Item set mapping',
        ];
    }

    protected function checkMetadataPushEnabled(): array
    {
        $ok = $this->moduleSettings->metadataPushEnabled();
        return [
            'id' => 'metadata_push_enabled',
            'ok' => $ok,
            'severity' => 'warning',
            'label' => 'Metadata push enabled',
            'detail' => $ok
                ? 'Live metadata pushes are allowed (preview-only safety checks still apply).'
                : 'Metadata push is disabled; use Preview to inspect changes without writing to IA.',
            'action' => 'Modules → Configure → Enable metadata push to Internet Archive',
        ];
    }

    protected function checkContribute(ServiceLocatorInterface $services): array
    {
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Contribute');
        $active = $module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
        return [
            'id' => 'contribute',
            'ok' => $active,
            'severity' => 'warning',
            'label' => 'Contribute module active',
            'detail' => $active
                ? 'Contribution metadata revisions auto-queue when validated and undertaken.'
                : 'Only needed for Publish metadata revisions (Contribute workflow).',
            'action' => 'Admin → Modules → install Contribute',
        ];
    }

    protected function checkJobs(ServiceLocatorInterface $services): array
    {
        $conn = $services->get('Omeka\Connection');
        $pending = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM job WHERE status IN ('starting', 'in_progress', 'stopping')"
        );
        $ok = $pending === 0;
        return [
            'id' => 'jobs',
            'ok' => $ok,
            'severity' => 'warning',
            'label' => 'Background jobs idle',
            'detail' => $ok ? 'No jobs currently running.' : sprintf('%d job(s) in progress.', $pending),
            'action' => 'Admin → Jobs',
        ];
    }

    protected function checkCompanionInbound(ServiceLocatorInterface $services): array
    {
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('InternetArchiveInboundSync');
        $active = $module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;

        return [
            'id' => 'companion_inbound',
            'ok' => true,
            'severity' => 'info',
            'label' => 'Optional: IA Inbound module',
            'detail' => $active
                ? 'IA Inbound is installed for importing items from Internet Archive.'
                : 'This module works on its own. Install IA Inbound separately if you also want to import items from Internet Archive into Omeka.',
            'action' => 'Admin → Modules → IA Inbound',
        ];
    }
}
