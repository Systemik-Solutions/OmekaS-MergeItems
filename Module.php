<?php declare(strict_types=1);

namespace MergeItems;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use MergeItems\Controller\Admin\CommitController;
use MergeItems\Controller\Admin\IndexController;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Acl;

class Module extends AbstractModule
{
    public function getConfig(): array
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow([
            Acl::ROLE_AUTHOR,
            Acl::ROLE_REVIEWER,
            Acl::ROLE_EDITOR,
            Acl::ROLE_SITE_ADMIN,
            Acl::ROLE_GLOBAL_ADMIN,
        ], [IndexController::class, CommitController::class], ['index']);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.browse.after',
            [$this, 'appendMergeSelectedAction']
        );
    }

    public function appendMergeSelectedAction(Event $event): void
    {
        $view = $event->getTarget();
        if (!$view->status()->isAdminRequest()
            || !$view->userIsAllowed(ItemAdapter::class, 'batch_update')
        ) {
            return;
        }

        $scriptUrl = $view->assetUrl('js/merge-items-admin.js', 'MergeItems');
        $actionUrl = $view->url('admin/merge-items');

        echo sprintf(
            '<script src="%s" data-merge-items-action="%s" data-merge-items-label="%s" data-go-label="%s"></script>',
            $view->escapeHtmlAttr($scriptUrl),
            $view->escapeHtmlAttr($actionUrl),
            $view->escapeHtmlAttr($view->translate('Merge selected')), // @translate
            $view->escapeHtmlAttr($view->translate('Go')) // @translate
        );
    }
}
