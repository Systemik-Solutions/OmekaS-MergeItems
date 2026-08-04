<?php declare(strict_types=1);

namespace MergeItems\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use MergeItems\ReferenceManager;

class ReferenceManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null): ReferenceManager
    {
        return new ReferenceManager(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\ApiManager')
        );
    }
}
