<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync;

use InternetArchiveOutboundSync\Form\ConfigForm;
use InternetArchiveOutboundSync\Listener\ContributeValidatedListener;
use InternetArchiveOutboundSync\Service\InstallDefaultsService;
use InternetArchiveOutboundSync\Service\ModuleSettings;
use InternetArchiveOutboundSync\Service\SetupStatusService;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Acl;

class Module extends AbstractModule
{
    protected function registerModuleAutoloader(): void
    {
        if (class_exists(ModuleSettings::class, false)) {
            return;
        }
        $loader = new \Laminas\Loader\StandardAutoloader([
            'namespaces' => [
                'InternetArchiveOutboundSync' => __DIR__ . '/src',
            ],
        ]);
        $loader->register();
    }

    public function getConfig()
    {
        $this->registerModuleAutoloader();

        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $services = $this->getServiceLocator();
        $this->registerModuleAutoloader();

        $acl = $services->get('Omeka\Acl');
        $acl->allow(
            [Acl::ROLE_GLOBAL_ADMIN],
            [
                'InternetArchiveOutboundSync\Controller\Admin\Push',
                'InternetArchiveOutboundSync\Controller\Admin\PublishItems',
                'InternetArchiveOutboundSync\Controller\Admin\PublishRevisions',
                'InternetArchiveOutboundSync\Controller\Admin\History',
                'InternetArchiveOutboundSync\Controller\Admin\Config',
            ]
        );

        $this->attachContributeListener($event->getApplication()->getEventManager(), $services);
    }

    protected function attachContributeListener(EventManagerInterface $eventManager, ServiceLocatorInterface $services): void
    {
        if (!class_exists(\Contribute\Api\Adapter\ContributionAdapter::class)) {
            return;
        }
        $listener = $services->get(ContributeValidatedListener::class);
        $listener->attach($eventManager);
    }

    public function install(ServiceLocatorInterface $services): void
    {
        $this->registerModuleAutoloader();
        $conn = $services->get('Omeka\Connection');

        $conn->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS internet_archive_outbound_run (
    id INT AUTO_INCREMENT NOT NULL,
    job_id INT DEFAULT NULL,
    owner_id INT DEFAULT NULL,
    run_type VARCHAR(32) NOT NULL DEFAULT 'metadata_push',
    started DATETIME NOT NULL,
    parameters LONGTEXT DEFAULT NULL COMMENT "(DC2Type:json)",
    stats LONGTEXT DEFAULT NULL COMMENT "(DC2Type:json)",
    log LONGTEXT DEFAULT NULL,
    INDEX IDX_iaos_run_job (job_id),
    INDEX IDX_iaos_run_owner (owner_id),
    INDEX IDX_iaos_run_type (run_type),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);

        $conn->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS internet_archive_outbound_run_item (
    id INT AUTO_INCREMENT NOT NULL,
    run_id INT NOT NULL,
    item_id INT DEFAULT NULL,
    ia_identifier VARCHAR(255) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'preview',
    task_id VARCHAR(64) DEFAULT NULL,
    before_snapshot LONGTEXT DEFAULT NULL COMMENT "(DC2Type:json)",
    after_snapshot LONGTEXT DEFAULT NULL COMMENT "(DC2Type:json)",
    patch_json LONGTEXT DEFAULT NULL COMMENT "(DC2Type:json)",
    local_files_deleted TINYINT(1) NOT NULL DEFAULT 0,
    message LONGTEXT DEFAULT NULL,
    INDEX IDX_iaos_run_item_run (run_id),
    INDEX IDX_iaos_run_item_item (item_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);

        $conn->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS internet_archive_outbound_queue (
    id INT AUTO_INCREMENT NOT NULL,
    item_id INT NOT NULL,
    contribution_id INT DEFAULT NULL,
    queue_type VARCHAR(32) NOT NULL DEFAULT 'item_upload',
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    ia_identifier VARCHAR(255) NOT NULL,
    queued_at DATETIME NOT NULL,
    published_at DATETIME DEFAULT NULL,
    snapshot LONGTEXT DEFAULT NULL COMMENT "(DC2Type:json)",
    message LONGTEXT DEFAULT NULL,
    INDEX IDX_iaos_queue_item (item_id),
    INDEX IDX_iaos_queue_status (status),
    INDEX IDX_iaos_queue_type_status (queue_type, status),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
SQL);

        $settings = $services->get('Omeka\Settings');
        foreach (ModuleSettings::defaultInstallSettings() as $key => $value) {
            $settings->set($key, $value);
        }
        InstallDefaultsService::seed($services);
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        $this->registerModuleAutoloader();
        InstallDefaultsService::seed($services);
    }

    public function uninstall(ServiceLocatorInterface $services): void
    {
        $this->registerModuleAutoloader();
        $conn = $services->get('Omeka\Connection');
        $conn->exec('SET FOREIGN_KEY_CHECKS=0;');
        $conn->exec('DROP TABLE IF EXISTS internet_archive_outbound_run_item');
        $conn->exec('DROP TABLE IF EXISTS internet_archive_outbound_queue');
        $conn->exec('DROP TABLE IF EXISTS internet_archive_outbound_run');
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');

        $settings = $services->get('Omeka\Settings');
        foreach (array_keys(ModuleSettings::defaultInstallSettings()) as $key) {
            $settings->delete($key);
        }
        $settings->delete(ModuleSettings::KEY_PREFIX . 'connection_test_passed');
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        /** @var ConfigForm $form */
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->setModuleSettings($services->get(ModuleSettings::class));
        $form->init();

        $setupStatus = $services->get(SetupStatusService::class)->getStatus($services);
        $checklist = $renderer->partial(
            'internet-archive-outbound-sync/admin/partials/setup-checklist',
            ['setupStatus' => $setupStatus]
        );

        $testUrl = $renderer->url('admin/internet-archive-outbound-sync/default', [
            'controller' => 'config',
            'action' => 'test-connection',
        ]);
        $intro = '<p>' . $renderer->escapeHtml(
            'Configure IA-S3 credentials and collection mapping. Test your connection before pushing or publishing.'
        ) . '</p>'
        . '<p>' . $renderer->escapeHtml(
            'IA Outbound requires an Internet Archive collection and S3 API access. If you do not have a collection yet, see the Internet Archive collections guide.'
        ) . ' <a href="https://help.archive.org/help/collections-a-basic-guide/" target="_blank" rel="noopener">'
        . $renderer->escapeHtml('Collections: a basic guide') . '</a>.</p>'
        . '<p>' . $renderer->escapeHtml(
            'This module works on its own. Install IA Inbound separately if you also want to import items from Internet Archive into Omeka.'
        ) . '</p>';
        $testBtn = '<p><a class="button" href="' . $renderer->escapeHtmlAttr($testUrl) . '">'
            . $renderer->escapeHtml('Test connection') . '</a></p>';

        $itemSets = [];
        $itemSetOptions = $this->itemSetSelectOptions($services, $itemSets);
        $form->setItemSetOptions($itemSetOptions);

        return $intro . $testBtn . $checklist . $renderer->partial(
            'internet-archive-outbound-sync/admin/config/form',
            [
                'form' => $form,
                'moduleSettings' => $services->get(ModuleSettings::class),
                'itemSets' => $itemSets,
            ]
        );
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        /** @var ConfigForm $form */
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->setModuleSettings($services->get(ModuleSettings::class));
        $form->setItemSetOptions($this->itemSetSelectOptions($services));
        $form->init();
        $form->setData($this->normalizeConfigPost($controller->params()->fromPost()));
        if (!$form->isValid()) {
            $messages = [];
            foreach ($form->getMessages() as $field => $fieldMessages) {
                if (is_array($fieldMessages)) {
                    foreach ($fieldMessages as $msg) {
                        $messages[] = is_string($field) ? "$field: $msg" : (string) $msg;
                    }
                }
            }
            if ($messages) {
                $controller->messenger()->addError(implode(' ', $messages));
            }
            return false;
        }
        try {
            $form->save();
        } catch (\Throwable $e) {
            $controller->messenger()->addError($e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    protected function normalizeConfigPost(array $post): array
    {
        if (isset($post['credentials']) || isset($post['collections']) || isset($post['defaults'])) {
            return array_merge(
                $post,
                (array) ($post['credentials'] ?? []),
                (array) ($post['collections'] ?? []),
                (array) ($post['defaults'] ?? [])
            );
        }
        return $post;
    }

    /**
     * @param array<int, array{id: int, title: string}>|null $itemSetsOut
     * @return array<string, string>
     */
    protected function itemSetSelectOptions(ServiceLocatorInterface $services, ?array &$itemSetsOut = null): array
    {
        $options = [];
        $api = $services->get('Omeka\ApiManager');
        foreach ($api->search('item_sets', ['limit' => 500, 'sort_by' => 'title'])->getContent() as $set) {
            $id = (int) $set->id();
            $title = (string) $set->title();
            $options[(string) $id] = $title;
            if ($itemSetsOut !== null) {
                $itemSetsOut[] = ['id' => $id, 'title' => $title];
            }
        }

        return $options;
    }
}
