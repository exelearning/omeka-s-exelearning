<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use ExeLearning\Service\StylesService;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class StylesServeControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new StylesServeController($services->get(StylesService::class));
    }
}
