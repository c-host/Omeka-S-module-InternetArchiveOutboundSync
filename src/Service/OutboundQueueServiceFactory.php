<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class OutboundQueueServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new OutboundQueueService(
            $services->get('Omeka\Connection'),
            $services->get('Omeka\ApiManager'),
            $services->get(OutboundMetadataBuilder::class),
            $services->get(IaIdentifierGenerator::class),
            $services->get(IaIdentifierParser::class),
            $services->get(MediaLocalPath::class),
            $services->get(UploadManifestOrderService::class),
            $services->get(AudioPublishService::class)
        );
    }
}
