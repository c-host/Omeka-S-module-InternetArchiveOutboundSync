<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ItemPushServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ItemPushService(
            $services->get('Omeka\ApiManager'),
            $services->get(IaMetadataReadClient::class),
            $services->get(IaMetadataWriteClient::class),
            $services->get(OutboundMetadataBuilder::class),
            $services->get(MetadataDiffService::class),
            $services->get(IaIdentifierParser::class),
            $services->get(ItemSelectionService::class),
            $services->get(IaPushPreflightService::class),
            $services->get(ModuleSettings::class)
        );
    }
}
