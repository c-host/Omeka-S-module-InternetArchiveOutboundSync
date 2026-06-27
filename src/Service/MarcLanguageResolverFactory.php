<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MarcLanguageResolverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new MarcLanguageResolver(
            $services->get(Iso6392LanguageCatalog::class),
            $services->get(ModuleSettings::class)
        );
    }
}
