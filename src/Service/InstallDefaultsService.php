<?php declare(strict_types=1);

namespace InternetArchiveOutboundSync\Service;

use Laminas\ServiceManager\ServiceLocatorInterface;

class InstallDefaultsService
{
    public static function seed(ServiceLocatorInterface $services): void
    {
        // Use Omeka\Settings directly — module service factories are not
        // registered in the container during install().
        $omekaSettings = $services->get('Omeka\Settings');
        foreach (ModuleSettings::defaultInstallSettings() as $key => $value) {
            if ($omekaSettings->get($key) === null) {
                $omekaSettings->set($key, $value);
            }
        }
    }
}
