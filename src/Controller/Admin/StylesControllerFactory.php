<?php
declare(strict_types=1);

namespace ExeLearning\Controller\Admin;

use ExeLearning\Service\StylesService;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class StylesControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new StylesController($services->get(StylesService::class));
    }
}
