<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class LocalMediaCleanupServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new LocalMediaCleanupService(
            $services->get('Omeka\ApiManager'),
            $services->get(MediaLocalPath::class)
        );
    }
}
