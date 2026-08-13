<?php declare(strict_types=1);

namespace MergeItems\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use MergeItems\Controller\Admin\IndexController;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null): IndexController
    {
        return new IndexController(
            $services->get('MergeItems\ReferenceManager'),
            $services->get('Omeka\Logger')
        );
    }
}
