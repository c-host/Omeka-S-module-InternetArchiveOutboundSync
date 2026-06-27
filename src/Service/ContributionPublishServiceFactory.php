<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ContributionPublishServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ContributionPublishService(
            $services->get('Omeka\ApiManager'),
            $services->get(OutboundQueueService::class),
            $services->get(OutboundMetadataBuilder::class),
            $services->get(IaS3UploadClient::class),
            $services->get(IaMetadataReadClient::class),
            $services->get(IaMetadataWriteClient::class),
            $services->get(IaMediaLinkService::class),
            $services->get(LocalMediaCleanupService::class),
            $services->get(ModuleSettings::class),
            $services->get(MediaLocalPath::class),
            $services->get(AudioPublishService::class)
        );
    }
}
