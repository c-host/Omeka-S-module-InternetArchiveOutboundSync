<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ItemSubmissionSelectionServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new ItemSubmissionSelectionService(
            $services->get('Omeka\ApiManager'),
            $services->get(ModuleSettings::class),
            $services->get(IaIdentifierParser::class),
            $services->get(OutboundQueueService::class),
            $services->get(IaIdentifierGenerator::class)
        );
    }
}
