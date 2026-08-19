<?php declare(strict_types=1);

namespace MergeItems\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use MergeItems\Controller\Admin\CommitController;

class CommitControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null): CommitController
    {
        return new CommitController(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\Acl'),
            $services->get('Omeka\EntityManager'),
            $services->get('FormElementManager'),
            $services->get('Omeka\Logger'),
            $services->get('MergeItems\ReferenceManager')
        );
    }
}
