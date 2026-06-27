<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync;

use InternetArchiveOutboundSync\Listener;
use InternetArchiveOutboundSync\Service;

return [
    'service_manager' => [
        'factories' => [
            Service\ModuleSettings::class => Service\ModuleSettingsFactory::class,
            Service\IaHttpClient::class => Service\IaHttpClientFactory::class,
            Service\IaMetadataReadClient::class => Service\IaMetadataReadClientFactory::class,
            Service\IaMetadataWriteClient::class => Service\IaMetadataWriteClientFactory::class,
            Service\IaS3UploadClient::class => Service\IaS3UploadClientFactory::class,
            Service\IaTaskPoller::class => Service\IaTaskPollerFactory::class,
            Service\IaIdentifierGenerator::class => Service\IaIdentifierGeneratorFactory::class,
            Service\BilingualTextMerger::class => Service\BilingualTextMergerFactory::class,
            Service\Iso6392LanguageCatalog::class => Service\Iso6392LanguageCatalogFactory::class,
            Service\MarcLanguageResolver::class => Service\MarcLanguageResolverFactory::class,
            Service\OutboundMetadataBuilder::class => Service\OutboundMetadataBuilderFactory::class,
            Service\MetadataDiffService::class => Service\MetadataDiffServiceFactory::class,
            Service\IaPushPreflightService::class => Service\IaPushPreflightServiceFactory::class,
            Service\ItemSelectionService::class => Service\ItemSelectionServiceFactory::class,
            Service\ItemSubmissionSelectionService::class => Service\ItemSubmissionSelectionServiceFactory::class,
            Service\ItemPushService::class => Service\ItemPushServiceFactory::class,
            Service\OutboundRunService::class => Service\OutboundRunServiceFactory::class,
            Service\UploadManifestOrderService::class => Service\UploadManifestOrderServiceFactory::class,
            Service\AudioPublishService::class => Service\AudioPublishServiceFactory::class,
            Service\OutboundQueueService::class => Service\OutboundQueueServiceFactory::class,
            Service\ContributionPublishService::class => Service\ContributionPublishServiceFactory::class,
            Service\IaMediaLinkService::class => Service\IaMediaLinkServiceFactory::class,
            Service\LocalMediaCleanupService::class => Service\LocalMediaCleanupServiceFactory::class,
            Service\ConnectionTestService::class => Service\ConnectionTestServiceFactory::class,
            Service\SetupStatusService::class => Service\SetupStatusServiceFactory::class,
            Service\PushProgressService::class => Service\PushProgressServiceFactory::class,
            Listener\ContributeValidatedListener::class => Listener\ContributeValidatedListenerFactory::class,
        ],
        'invokables' => [
            Service\IaIdentifierParser::class => Service\IaIdentifierParser::class,
            Service\MediaLocalPath::class => Service\MediaLocalPath::class,
            Service\PublishItemsPageSession::class => Service\PublishItemsPageSession::class,
            Service\PublishRevisionsPageSession::class => Service\PublishRevisionsPageSession::class,
            Service\PushPageSession::class => Service\PushPageSession::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'InternetArchiveOutboundSync\Controller\Admin\Push' => Controller\Admin\PushController::class,
            'InternetArchiveOutboundSync\Controller\Admin\PublishItems' => Controller\Admin\PublishItemsController::class,
            'InternetArchiveOutboundSync\Controller\Admin\PublishRevisions' => Controller\Admin\PublishRevisionsController::class,
            'InternetArchiveOutboundSync\Controller\Admin\History' => Controller\Admin\HistoryController::class,
            'InternetArchiveOutboundSync\Controller\Admin\Config' => Controller\Admin\ConfigController::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\PushForm::class => Form\PushForm::class,
            Form\PublishItemsForm::class => Form\PublishItemsForm::class,
            Form\PublishRevisionsForm::class => Form\PublishRevisionsForm::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'internet-archive-outbound-sync' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/internet-archive-outbound-sync',
                            'defaults' => [
                                '__NAMESPACE__' => 'InternetArchiveOutboundSync\Controller\Admin',
                                '__ADMIN__' => true,
                                'controller' => 'Push',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:controller[/:action[/:id]]',
                                    'constraints' => [
                                        'controller' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'controller' => 'Push',
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            'internet-archive-outbound-sync' => [
                'label' => 'IA Outbound', // @translate
                'route' => 'admin/internet-archive-outbound-sync/default',
                'controller' => 'push',
                'action' => 'index',
                'resource' => 'InternetArchiveOutboundSync\Controller\Admin\Push',
                'class' => 'o-icon- fa-cloud-upload-alt',
                'pages' => [
                    [
                        'label' => 'Push metadata', // @translate
                        'route' => 'admin/internet-archive-outbound-sync/default',
                        'controller' => 'push',
                        'action' => 'index',
                        'resource' => 'InternetArchiveOutboundSync\Controller\Admin\Push',
                    ],
                    [
                        'label' => 'Publish user-submitted items', // @translate
                        'route' => 'admin/internet-archive-outbound-sync/default',
                        'controller' => 'publish-items',
                        'action' => 'index',
                        'resource' => 'InternetArchiveOutboundSync\Controller\Admin\PublishItems',
                    ],
                    [
                        'label' => 'Publish metadata revisions', // @translate
                        'route' => 'admin/internet-archive-outbound-sync/default',
                        'controller' => 'publish-revisions',
                        'action' => 'index',
                        'resource' => 'InternetArchiveOutboundSync\Controller\Admin\PublishRevisions',
                    ],
                    [
                        'label' => 'History', // @translate
                        'route' => 'admin/internet-archive-outbound-sync/default',
                        'controller' => 'history',
                        'action' => 'browse',
                        'resource' => 'InternetArchiveOutboundSync\Controller\Admin\History',
                    ],
                ],
            ],
        ],
    ],
];
