<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class OutboundMetadataBuilderFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new OutboundMetadataBuilder(
            $services->get(BilingualTextMerger::class),
            $services->get(ModuleSettings::class),
            $services->get(MarcLanguageResolver::class)
        );
    }
}
