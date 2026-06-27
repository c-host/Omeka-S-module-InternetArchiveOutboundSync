<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Controller\Admin;

use InternetArchiveOutboundSync\Form\PushForm;
use InternetArchiveOutboundSync\Job\PushMetadataImport;
use InternetArchiveOutboundSync\Service\ItemPushService;
use InternetArchiveOutboundSync\Service\ItemSelectionService;
use InternetArchiveOutboundSync\Service\ModuleSettings;
use InternetArchiveOutboundSync\Service\PushPageSession;
use InternetArchiveOutboundSync\Service\PushProgressService;
use InternetArchiveOutboundSync\Service\SetupStatusService;
use Laminas\Form\FormInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

class PushController extends AbstractActionController
{
    public function indexAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $settings = $services->get(ModuleSettings::class);
        $selection = $services->get(ItemSelectionService::class);
        $push = $services->get(ItemPushService::class);
        $api = $services->get('Omeka\ApiManager');
        $session = $services->get(PushPageSession::class);
        $progress = $services->get(PushProgressService::class);

        $formManager = $services->get('FormElementManager');
        /** @var PushForm $form */
        $form = $formManager->get(PushForm::class);
        $form->init();

        $map = $settings->itemSetCollectionMap();
        $itemSetOptions = $this->buildMappedItemSetOptions($api, $map);
        $multipleItemSets = count($itemSetOptions) > 1;

        $requestedSetId = $this->params()->fromQuery('item_set_id');
        if ($requestedSetId === null || $requestedSetId === '') {
            $requestedSetId = $this->params()->fromPost('item_set_id');
        }
        $itemSetFilter = $this->resolveItemSetId($map, $session, $requestedSetId);

        if ($itemSetFilter !== null) {
            $form->get('item_set_id')->setValueOptions($itemSetOptions);
            $form->get('item_set_id')->setValue((string) $itemSetFilter);
        }

        $pushable = $itemSetFilter !== null
            ? $selection->listPushableItems($itemSetFilter, 200)
            : [];
        $itemSetTitle = $itemSetFilter !== null && isset($itemSetOptions[$itemSetFilter])
            ? $itemSetOptions[$itemSetFilter]
            : '';

        $checkboxOptions = [];
        foreach ($pushable as $row) {
            $item = $row['item'];
            $checkboxOptions[$item->id()] = sprintf(
                '%s [%s]',
                $item->displayTitle(),
                $row['ia_identifier']
            );
        }
        $form->get('item_ids')->setValueOptions($checkboxOptions);

        $previews = [];
        $previewToken = '';
        $previewReady = false;
        $setupStatus = $services->get(SetupStatusService::class)->getStatus($services);

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);

            if (!$form->isValid()) {
                $this->addFormErrors($form);
            } else {
                $isPreview = array_key_exists('submit_preview', $post);
                $isPush = array_key_exists('submit_push', $post);
                $itemIds = $this->normalizeItemIds($post['item_ids'] ?? null);

                if ($isPreview) {
                    if (!$itemIds) {
                        $this->messenger()->addWarning('Select at least one item.');
                    } else {
                        foreach ($itemIds as $itemId) {
                            $previews[$itemId] = $push->preview($itemId);
                        }
                        $previewToken = $session->storePreview($itemIds);
                        $form->get('preview_token')->setValue($previewToken);
                        $previewReady = true;
                        $this->messenger()->addSuccess(sprintf(
                            'Previewed %d item(s). Review the changes below, then push when ready.',
                            count($previews)
                        ));
                    }
                } elseif ($isPush) {
                    $submittedToken = trim((string) ($post['preview_token'] ?? ''));
                    $previewValid = $session->validatePreview($submittedToken, $itemIds);
                    if (!$itemIds) {
                        $this->messenger()->addWarning('Select at least one item.');
                    } elseif (!$previewValid) {
                        $this->messenger()->addError(
                            'Preview is missing or out of date. Preview your selection again before pushing.'
                        );
                    } elseif (!$settings->metadataPushEnabled()) {
                        $this->messenger()->addError(
                            'Metadata push is disabled. Enable it under Modules → Configure.'
                        );
                    } elseif (empty($post['acknowledge'])) {
                        $this->messenger()->addError('You must acknowledge the irreversible overwrite.');
                    } elseif (count($itemIds) >= $settings->typeConfirmThreshold()
                        && strtoupper(trim((string) ($post['confirm_text'] ?? ''))) !== 'PUSH'
                    ) {
                        $this->messenger()->addError(sprintf(
                            'Type PUSH to confirm pushing %d items.',
                            count($itemIds)
                        ));
                    } else {
                        $identity = $this->identity();
                        $job = $this->jobDispatcher()->dispatch(PushMetadataImport::class, [
                            'item_ids' => $itemIds,
                            'dry_run' => false,
                            'chunk_size' => $settings->chunkSize(),
                            'owner_id' => $identity ? $identity->getId() : null,
                        ]);
                        $session->clearPreview();
                        $estimate = $progress->estimateDuration(count($itemIds));
                        $this->messenger()->addSuccess(sprintf(
                            'Metadata push queued as job #%d (%d items). Allow about %d–%d seconds; track progress under IA Outbound → History.',
                            $job->getId(),
                            count($itemIds),
                            $estimate['min_seconds'],
                            $estimate['max_seconds']
                        ));
                    }

                    if ($previewValid && $itemIds) {
                        foreach ($itemIds as $itemId) {
                            $previews[$itemId] = $push->preview($itemId);
                        }
                        $previewToken = $submittedToken;
                        $form->get('preview_token')->setValue($previewToken);
                        $previewReady = true;
                    }
                }
            }
        }

        $selectedItemCount = 0;
        if ($this->getRequest()->isPost()) {
            $postedIds = $this->normalizeItemIds($this->params()->fromPost('item_ids'));
            if ($postedIds) {
                $form->get('item_ids')->setValue($postedIds);
                $selectedItemCount = count($postedIds);
            }
        }

        $statusUrl = $this->url()->fromRoute('admin/internet-archive-outbound-sync/default', [
            'controller' => 'push',
            'action' => 'status',
        ]);

        $view = new ViewModel([
            'form' => $form,
            'setupStatus' => $setupStatus,
            'previews' => $previews,
            'previewReady' => $previewReady,
            'previewToken' => $previewToken,
            'pushableCount' => count($pushable),
            'itemSetTitle' => $itemSetTitle,
            'multipleItemSets' => $multipleItemSets,
            'hasMappedItemSets' => $map !== [],
            'metadataPushEnabled' => $settings->metadataPushEnabled(),
            'typeConfirmThreshold' => $settings->typeConfirmThreshold(),
            'selectedItemCount' => $selectedItemCount,
            'activePushes' => $progress->listActivePushes(),
            'timingDefaults' => $progress->timingDefaults(),
            'statusUrl' => $statusUrl,
            'itemsUrl' => $this->url()->fromRoute('admin/internet-archive-outbound-sync/default', [
                'controller' => 'push',
                'action' => 'items',
            ]),
        ]);
        $view->setTemplate('internet-archive-outbound-sync/admin/push/index');
        return $view;
    }

    public function statusAction()
    {
        $progress = $this->getEvent()->getApplication()->getServiceManager()
            ->get(PushProgressService::class);

        return new JsonModel([
            'active' => $progress->listActivePushes(),
        ]);
    }

    public function itemsAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $settings = $services->get(ModuleSettings::class);
        $selection = $services->get(ItemSelectionService::class);
        $api = $services->get('Omeka\ApiManager');
        $session = $services->get(PushPageSession::class);

        $map = $settings->itemSetCollectionMap();
        $itemSetId = (int) $this->params()->fromQuery('item_set_id', 0);
        if ($itemSetId <= 0 || !isset($map[$itemSetId])) {
            $this->getResponse()->setStatusCode(400);

            return new JsonModel(['error' => 'Invalid item set.']);
        }

        $session->setItemSetId($itemSetId);
        $session->clearPreview();

        $itemSetOptions = $this->buildMappedItemSetOptions($api, $map);
        $pushable = $selection->listPushableItems($itemSetId, 200);
        $items = [];
        foreach ($pushable as $row) {
            $item = $row['item'];
            $items[] = [
                'id' => (int) $item->id(),
                'label' => sprintf('%s [%s]', $item->displayTitle(), $row['ia_identifier']),
            ];
        }

        return new JsonModel([
            'item_set_id' => $itemSetId,
            'item_set_title' => $itemSetOptions[$itemSetId] ?? (string) $itemSetId,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    /**
     * @param array<int, string> $map
     * @return array<int, string>
     */
    protected function buildMappedItemSetOptions($api, array $map): array
    {
        if ($map === []) {
            return [];
        }
        $ids = array_keys($map);
        $titles = [];
        foreach ($api->search('item_sets', ['id' => $ids, 'limit' => count($ids)])->getContent() as $set) {
            $titles[$set->id()] = $set->title();
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($titles[$id])) {
                $ordered[$id] = $titles[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param array<int, string> $map
     */
    protected function resolveItemSetId(array $map, PushPageSession $session, $requested): ?int
    {
        if ($map === []) {
            return null;
        }
        $mappedIds = array_keys($map);

        if ($requested !== null && $requested !== '') {
            $requestedId = (int) $requested;
            if (in_array($requestedId, $mappedIds, true)) {
                $session->setItemSetId($requestedId);

                return $requestedId;
            }
        }

        $stored = $session->getItemSetId();
        if ($stored !== null && in_array($stored, $mappedIds, true)) {
            return $stored;
        }

        $first = $mappedIds[0];
        $session->setItemSetId($first);

        return $first;
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    protected function normalizeItemIds($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map('intval', $raw)));
    }

    protected function addFormErrors(FormInterface $form): void
    {
        $messages = [];
        foreach ($form->getMessages() as $field => $fieldMessages) {
            if (!is_array($fieldMessages)) {
                continue;
            }
            foreach ($fieldMessages as $msg) {
                $messages[] = is_string($field) ? "$field: $msg" : (string) $msg;
            }
        }
        if ($messages) {
            $this->messenger()->addError(implode(' ', $messages));
        }
    }
}
