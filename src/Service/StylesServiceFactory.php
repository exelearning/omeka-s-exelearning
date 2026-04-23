<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Factory for {@see StylesService}.
 *
 * Resolves Omeka's files directory the same way ElpFileServiceFactory does,
 * so uploaded styles end up in a sibling of the ELP extraction dir.
 */
class StylesServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');

        $filesPath = null;
        try {
            $config = $services->get('Config');
            $filesPath = $config['file_store']['local']['base_path'] ?? null;
        } catch (\Throwable $e) {
            // ignore.
        }
        if (!$filesPath) {
            try {
                $fileStore = $services->get('Omeka\File\Store');
                if (method_exists($fileStore, 'getLocalPath')) {
                    $filesPath = dirname($fileStore->getLocalPath(''));
                }
            } catch (\Throwable $e) {
                // ignore.
            }
        }
        if (!$filesPath) {
            $filesPath = defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : '/var/www/html/files';
        }
        $volumePath = '/var/www/html/volume/files';
        if (is_dir($volumePath)) {
            $filesPath = $volumePath;
        }

        $modulePath = dirname(__DIR__, 2);
        return new StylesService($settings, $filesPath, $modulePath, $logger);
    }
}
