<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IaMediaLinkServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IaMediaLinkService(
            $services->get('Omeka\ApiManager'),
            $services->get(IaMetadataReadClient::class),
            $services->get(IaHttpClient::class)
        );
    }
}
