<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ElpFileServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');
        $logger = $services->get('Omeka\Logger');

        $filesPath = FilesPath::resolve($services);

        // Extracted eXeLearning content goes in <files>/exelearning/
        $basePath = $filesPath . '/exelearning';

        // TempFileFactory is required to generate thumbnail derivatives from
        // the bundled screenshot.png; missing in lightweight test environments.
        $tempFileFactory = null;
        try {
            $tempFileFactory = $services->get('Omeka\File\TempFileFactory');
        } catch (\Throwable $e) {
            // ignore - thumbnail generation will be silently skipped
        }

        return new ElpFileService(
            $api,
            $entityManager,
            $basePath,
            $filesPath,
            $logger,
            $tempFileFactory
        );
    }
}
