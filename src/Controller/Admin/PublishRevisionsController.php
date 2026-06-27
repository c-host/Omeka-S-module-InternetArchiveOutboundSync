<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Controller\Admin;

use InternetArchiveOutboundSync\Form\PublishRevisionsForm;
use InternetArchiveOutboundSync\Job\PublishRevisionImport;
use InternetArchiveOutboundSync\Service\ItemPushService;
use InternetArchiveOutboundSync\Service\ModuleSettings;
use InternetArchiveOutboundSync\Service\OutboundQueueService;
use InternetArchiveOutboundSync\Service\PublishRevisionsPageSession;
use InternetArchiveOutboundSync\Service\SetupStatusService;
use Laminas\Form\FormInterface;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class PublishRevisionsController extends AbstractActionController
{
    public function indexAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $settings = $services->get(ModuleSettings::class);
        $queue = $services->get(OutboundQueueService::class);
        $push = $services->get(ItemPushService::class);
        $api = $services->get('Omeka\ApiManager');
        $session = $services->get(PublishRevisionsPageSession::class);

        $formManager = $services->get('FormElementManager');
        /** @var PublishRevisionsForm $form */
        $form = $formManager->get(PublishRevisionsForm::class);
        $form->init();

        $queue->pruneInactiveMetadataRevisionQueue();
        $queue->pruneSupersededMetadataRevisionQueue();
        $queue->syncValidatedContributionRevisions();
        $queued = $queue->listQueuedValidatedMetadataRevisions(200);
        $checkboxOptions = [];
        foreach ($queued as $row) {
            $itemId = (int) $row['item_id'];
            $title = 'Item #' . $itemId;
            try {
                $item = $api->read('items', $itemId)->getContent();
                $title = $item->displayTitle();
            } catch (\Throwable $e) {
                // keep fallback
            }
            $validatedAt = $this->formatValidatedAt($row['validated_at'] ?? null);
            $label = sprintf('%s [%s]', $title, $row['ia_identifier']);
            if ($validatedAt !== '') {
                $label .= sprintf(' — validated %s', $validatedAt);
            }
            $checkboxOptions[$row['id']] = $label;
        }
        $form->get('queue_ids')->setValueOptions($checkboxOptions);

        $previews = [];
        $previewToken = '';
        $previewReady = false;
        $selectedQueueCount = 0;
        $setupStatus = $services->get(SetupStatusService::class)->getStatus($services);

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);

            if (!$form->isValid()) {
                $this->addFormErrors($form);
            } else {
                $isPreview = array_key_exists('submit_preview', $post);
                $isPush = array_key_exists('submit_push', $post);
                $queueIds = $this->normalizeQueueIds($post['queue_ids'] ?? null);
                $queueIds = array_values(array_filter(
                    $queueIds,
                    function (int $queueId) use ($queue): bool {
                        $row = $queue->getQueueRow($queueId);
                        return $row && $queue->isEligibleMetadataRevisionQueueRow($row);
                    }
                ));

                if ($isPreview) {
                    if (!$queueIds) {
                        $this->messenger()->addWarning('Select at least one queued revision.');
                    } else {
                        foreach ($queueIds as $queueId) {
                            $row = $queue->getQueueRow($queueId);
                            if (!$row) {
                                continue;
                            }
                            $itemId = (int) $row['item_id'];
                            $previews[$queueId] = $push->preview($itemId) + ['queue' => $row];
                        }
                        $previewToken = $session->storePreview($queueIds);
                        $form->get('preview_token')->setValue($previewToken);
                        $previewReady = true;
                        $this->messenger()->addSuccess(sprintf(
                            'Previewed %d revision(s). Review the changes below, then push when ready.',
                            count($previews)
                        ));
                    }
                } elseif ($isPush) {
                    $submittedToken = trim((string) ($post['preview_token'] ?? ''));
                    $previewValid = $session->validatePreview($submittedToken, $queueIds);
                    if (!$queueIds) {
                        $this->messenger()->addWarning('Select at least one queued revision.');
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
                    } elseif (count($queueIds) >= $settings->typeConfirmThreshold()
                        && strtoupper(trim((string) ($post['confirm_text'] ?? ''))) !== 'PUSH'
                    ) {
                        $this->messenger()->addError(sprintf(
                            'Type PUSH to confirm pushing %d revisions.',
                            count($queueIds)
                        ));
                    } else {
                        $identity = $this->identity();
                        $job = $this->jobDispatcher()->dispatch(PublishRevisionImport::class, [
                            'queue_ids' => $queueIds,
                            'dry_run' => false,
                            'chunk_size' => $settings->chunkSize(),
                            'owner_id' => $identity ? $identity->getId() : null,
                        ]);
                        $session->clearPreview();
                        $this->messenger()->addSuccess(sprintf(
                            'Metadata revision push queued as job #%d (%d items). Track progress under IA Outbound → History.',
                            $job->getId(),
                            count($queueIds)
                        ));
                    }

                    if ($previewValid && $queueIds) {
                        foreach ($queueIds as $queueId) {
                            $row = $queue->getQueueRow($queueId);
                            if (!$row) {
                                continue;
                            }
                            $itemId = (int) $row['item_id'];
                            $previews[$queueId] = $push->preview($itemId) + ['queue' => $row];
                        }
                        $previewToken = $submittedToken;
                        $form->get('preview_token')->setValue($previewToken);
                        $previewReady = true;
                    }
                }
            }
        }

        if ($this->getRequest()->isPost()) {
            $postedIds = $this->normalizeQueueIds($this->params()->fromPost('queue_ids'));
            if ($postedIds) {
                $form->get('queue_ids')->setValue($postedIds);
                $selectedQueueCount = count($postedIds);
            }
        }

        $view = new ViewModel([
            'form' => $form,
            'setupStatus' => $setupStatus,
            'previews' => $previews,
            'previewReady' => $previewReady,
            'queued' => $queued,
            'metadataPushEnabled' => $settings->metadataPushEnabled(),
            'typeConfirmThreshold' => $settings->typeConfirmThreshold(),
            'selectedQueueCount' => $selectedQueueCount,
        ]);
        $view->setTemplate('internet-archive-outbound-sync/admin/publish-revisions/index');
        return $view;
    }

    public function cancelAction()
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        $queue = $services->get(OutboundQueueService::class);
        $id = (int) $this->params('id');
        $row = $queue->getQueueRow($id);
        if ($row
            && $row['status'] === 'queued'
            && ($row['queue_type'] ?? '') === OutboundQueueService::QUEUE_TYPE_METADATA_REVISION
        ) {
            $queue->updateStatus($id, 'cancelled');
            $this->messenger()->addSuccess('Revision queue item cancelled.');
        }
        return $this->redirect()->toRoute(
            'admin/internet-archive-outbound-sync/default',
            ['controller' => 'publish-revisions', 'action' => 'index']
        );
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    protected function normalizeQueueIds($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map('intval', $raw)));
    }

    protected function formatValidatedAt($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            return '';
        }
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
