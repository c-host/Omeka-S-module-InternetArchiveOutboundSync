<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Controller\Admin;

use InternetArchiveOutboundSync\Form\PublishItemsForm;
use InternetArchiveOutboundSync\Job\PublishItemsImport;
use InternetArchiveOutboundSync\Service\IaIdentifierGenerator;
use InternetArchiveOutboundSync\Service\ItemSubmissionSelectionService;
use InternetArchiveOutboundSync\Service\MetadataDiffService;
use InternetArchiveOutboundSync\Service\ModuleSettings;
use InternetArchiveOutboundSync\Service\OutboundMetadataBuilder;
use InternetArchiveOutboundSync\Service\OutboundQueueService;
use InternetArchiveOutboundSync\Service\PublishItemsPageSession;
use InternetArchiveOutboundSync\Service\SetupStatusService;
use InternetArchiveOutboundSync\Service\UploadManifestOrderService;
use Laminas\Form\FormInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class PublishItemsController extends AbstractActionController
{
    public function indexAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $settings = $services->get(ModuleSettings::class);
        $selection = $services->get(ItemSubmissionSelectionService::class);
        $queue = $services->get(OutboundQueueService::class);
        $metadata = $services->get(OutboundMetadataBuilder::class);
        $diffService = $services->get(MetadataDiffService::class);
        $idGenerator = $services->get(IaIdentifierGenerator::class);
        $session = $services->get(PublishItemsPageSession::class);
        $manifestOrder = $services->get(UploadManifestOrderService::class);
        $api = $services->get('Omeka\ApiManager');

        $formManager = $services->get('FormElementManager');
        /** @var PublishItemsForm $form */
        $form = $formManager->get(PublishItemsForm::class);
        $form->init();

        $publishable = $selection->listPublishableItems(200);
        $checkboxOptions = [];
        foreach ($publishable as $row) {
            $item = $row['item'];
            $checkboxOptions[$item->id()] = $item->displayTitle();
        }
        $form->get('item_ids')->setValueOptions($checkboxOptions);

        $previews = [];
        $previewToken = '';
        $previewReady = false;
        $selectedItemCount = 0;
        $setupStatus = $services->get(SetupStatusService::class)->getStatus($services);
        $contributionsItemSetId = $settings->contributionsItemSetId();

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);

            if (!$form->isValid()) {
                $this->addFormErrors($form);
            } else {
                $isPreview = array_key_exists('submit_preview', $post);
                $isPublish = array_key_exists('submit_publish', $post);
                $itemIds = $this->normalizeItemIds($post['item_ids'] ?? null);

                if ($isPreview) {
                    if (!$itemIds) {
                        $this->messenger()->addWarning('Select at least one item.');
                    } else {
                        foreach ($itemIds as $itemId) {
                            $previews[$itemId] = $this->buildPreview(
                                $itemId,
                                $api,
                                $metadata,
                                $diffService,
                                $idGenerator,
                                $queue,
                                $settings,
                                $manifestOrder
                            );
                        }
                        $previewToken = $session->storePreview($itemIds);
                        $form->get('preview_token')->setValue($previewToken);
                        $previewReady = true;
                        $this->messenger()->addSuccess(sprintf(
                            'Previewed %d item(s). Review the projected metadata below, then publish when ready.',
                            count($previews)
                        ));
                    }
                } elseif ($isPublish) {
                    $submittedToken = trim((string) ($post['preview_token'] ?? ''));
                    $previewValid = $session->validatePreview($submittedToken, $itemIds);
                    if (!$itemIds) {
                        $this->messenger()->addWarning('Select at least one item.');
                    } elseif (!$previewValid) {
                        $this->messenger()->addError(
                            'Preview is missing or out of date. Preview your selection again before publishing.'
                        );
                    } elseif (empty($post['acknowledge'])) {
                        $this->messenger()->addError('You must acknowledge permanent publication to Internet Archive.');
                    } elseif (count($itemIds) >= $settings->typeConfirmThreshold()
                        && strtoupper(trim((string) ($post['confirm_text'] ?? ''))) !== 'PUBLISH'
                    ) {
                        $this->messenger()->addError(sprintf(
                            'Type PUBLISH to confirm publishing %d items.',
                            count($itemIds)
                        ));
                    } else {
                        $fileOrders = $this->parseFileOrdersFromPost($post);
                        $queueIds = [];
                        foreach ($itemIds as $itemId) {
                            $row = $queue->enqueueItemUpload(
                                $itemId,
                                null,
                                $fileOrders[$itemId] ?? null
                            );
                            if ($row) {
                                $queueIds[] = (int) $row['id'];
                            }
                        }
                        if ($queueIds === []) {
                            $this->messenger()->addError('No items could be queued for publishing.');
                        } else {
                            $identity = $this->identity();
                            $job = $this->jobDispatcher()->dispatch(PublishItemsImport::class, [
                                'queue_ids' => $queueIds,
                                'dry_run' => false,
                                'chunk_size' => $settings->chunkSize(),
                                'owner_id' => $identity ? $identity->getId() : null,
                            ]);
                            $session->clearPreview();
                            $this->messenger()->addSuccess(sprintf(
                                'Publish queued as job #%d (%d items). Track progress and find Internet Archive links under IA Outbound → History.',
                                $job->getId(),
                                count($queueIds)
                            ));
                        }
                    }

                    if ($previewValid && $itemIds) {
                        $fileOrders = $this->parseFileOrdersFromPost($post);
                        foreach ($itemIds as $itemId) {
                            $previews[$itemId] = $this->buildPreview(
                                $itemId,
                                $api,
                                $metadata,
                                $diffService,
                                $idGenerator,
                                $queue,
                                $settings,
                                $manifestOrder,
                                $fileOrders[$itemId] ?? null
                            );
                        }
                        $previewToken = $submittedToken;
                        $form->get('preview_token')->setValue($previewToken);
                        $previewReady = true;
                    }
                }
            }
        }

        if ($this->getRequest()->isPost()) {
            $postedIds = $this->normalizeItemIds($this->params()->fromPost('item_ids'));
            if ($postedIds) {
                $form->get('item_ids')->setValue($postedIds);
                $selectedItemCount = count($postedIds);
            }
        }

        $view = new ViewModel([
            'form' => $form,
            'setupStatus' => $setupStatus,
            'previews' => $previews,
            'previewReady' => $previewReady,
            'previewToken' => $previewToken,
            'publishableCount' => count($publishable),
            'contributionsItemSetId' => $contributionsItemSetId,
            'contributionsItemSetTitle' => $selection->contributionsItemSetTitle(),
            'publishCollection' => $settings->publishIaCollection(),
            'publishTestCollectionActive' => $settings->publishTestCollectionActive(),
            'defaultIaCollection' => $settings->defaultIaCollection(),
            'deleteStagingItemAfterPublish' => $settings->deleteStagingItemAfterPublish(),
            'typeConfirmThreshold' => $settings->typeConfirmThreshold(),
            'selectedItemCount' => $selectedItemCount,
        ]);
        $view->setTemplate('internet-archive-outbound-sync/admin/publish-items/index');
        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPreview(
        int $itemId,
        $api,
        OutboundMetadataBuilder $metadata,
        MetadataDiffService $diffService,
        IaIdentifierGenerator $idGenerator,
        OutboundQueueService $queue,
        ModuleSettings $settings,
        UploadManifestOrderService $manifestOrder,
        ?array $adminMediaIds = null
    ): array {
        try {
            $item = $api->read('items', $itemId)->getContent();
            $projected = $metadata->fromItem($item);
            $title = $this->firstTitle($item);
            $iaIdentifier = $idGenerator->fromTitle($title, $itemId);
            $manifest = $queue->resolveUploadManifest($item, $adminMediaIds);

            return [
                'status' => 'preview',
                'message' => 'Projected metadata for a new Internet Archive item.',
                'item_id' => $itemId,
                'item_title' => $item->displayTitle(),
                'item_admin_url' => $this->url()->fromRoute('admin/id', [
                    'controller' => 'item',
                    'action' => 'show',
                    'id' => $itemId,
                ]),
                'ia_identifier' => $iaIdentifier,
                'publish_collection' => $settings->publishIaCollection(),
                'image_url' => $this->previewImageUrl($item, $manifest['files']),
                'diff' => $diffService->projectedPublishPreview($projected),
                'files' => $manifest['files'],
                'file_sort_method' => $manifest['sort_method'],
                'file_sort_method_label' => $manifestOrder->sortMethodLabel($manifest['sort_method']),
                'file_sort_warning' => $manifest['warning'],
                'file_media_ids' => $this->uploadableMediaIds($manifest['files']),
                'audio_cover' => !empty($manifest['audio_cover']),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    protected function firstTitle($item): string
    {
        foreach ($item->value('dcterms:title', ['all' => true]) ?: [] as $vo) {
            $t = trim((string) $vo->value());
            if ($t !== '') {
                return $t;
            }
        }

        return 'untitled-item-' . $item->id();
    }

    /**
     * @param array<int, array{media_id: int, filename: string, mime: ?string}> $orderedFiles
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     */
    protected function previewImageUrl($item, array $orderedFiles = []): ?string
    {
        if ($orderedFiles !== []) {
            $firstMediaId = (int) ($orderedFiles[0]['media_id'] ?? 0);
            foreach ($item->media() as $candidate) {
                if ((int) $candidate->id() === $firstMediaId) {
                    return $this->mediaPreviewUrl($candidate);
                }
            }
        }

        $media = $item->primaryMedia();
        if (!$media) {
            foreach ($item->media() as $candidate) {
                if ($candidate->ingester() === 'upload') {
                    $media = $candidate;
                    break;
                }
            }
        }
        if (!$media) {
            return null;
        }

        return $this->mediaPreviewUrl($media);
    }

    /**
     * @param array<int, array{media_id?: int, synthetic?: bool}> $files
     * @return int[]
     */
    protected function uploadableMediaIds(array $files): array
    {
        $ids = [];
        foreach ($files as $file) {
            if (!empty($file['synthetic'])) {
                continue;
            }
            $mediaId = (int) ($file['media_id'] ?? 0);
            if ($mediaId > 0) {
                $ids[] = $mediaId;
            }
        }

        return $ids;
    }

    /**
     * @param \Omeka\Api\Representation\MediaRepresentation $media
     */
    protected function mediaPreviewUrl($media): ?string
    {
        $mime = strtolower((string) $media->mediaType());
        if (str_starts_with($mime, 'image/')) {
            return $media->thumbnailUrl('medium') ?: $media->originalUrl();
        }

        return $media->thumbnailUrl('square') ?: null;
    }

    /**
     * @return array<int, int[]>
     */
    protected function parseFileOrdersFromPost(array $post): array
    {
        $raw = $post['file_order'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $orders = [];
        foreach ($raw as $itemId => $value) {
            $itemId = (int) $itemId;
            if ($itemId <= 0) {
                continue;
            }
            $mediaIds = $this->parseMediaIdList((string) $value);
            if ($mediaIds !== []) {
                $orders[$itemId] = $mediaIds;
            }
        }

        return $orders;
    }

    /**
     * @return int[]
     */
    protected function parseMediaIdList(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', $value) ?: [];

        return array_values(array_filter(array_map('intval', $parts)));
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
