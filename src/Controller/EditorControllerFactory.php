<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ExeLearning\Service\ElpFileService;
use ExeLearning\Service\StylesService;

class EditorControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $elpService = $services->get(ElpFileService::class);
        $stylesService = $services->get(StylesService::class);
        return new EditorController($elpService, $stylesService);
    }
}
