<?php
declare(strict_types=1);

namespace ExeLearning\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Factory for {@see StylesService}.
 *
 * Uses the shared {@see FilesPath} resolver, so uploaded styles end up in a
 * sibling of the ELP extraction dir.
 */
class StylesServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');

        $filesPath = FilesPath::resolve($services);

        $modulePath = dirname(__DIR__, 2);
        return new StylesService($settings, $filesPath, $modulePath, $logger);
    }
}
