<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IaS3UploadClientFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IaS3UploadClient($services->get(IaHttpClient::class));
    }
}
