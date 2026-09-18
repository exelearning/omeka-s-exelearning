<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\FilesPath;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ContentControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $basePath = FilesPath::resolve($container) . '/exelearning';

        return new ContentController($basePath);
    }
}
