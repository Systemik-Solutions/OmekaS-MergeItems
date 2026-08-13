<?php declare(strict_types=1);

namespace MergeItems\Controller\Admin;

use Laminas\Log\Logger;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use MergeItems\ReferenceManager;
use Throwable;

class IndexController extends AbstractActionController
{
    private ReferenceManager $referenceManager;
    private Logger $logger;

    public function __construct(ReferenceManager $referenceManager, Logger $logger)
    {
        $this->referenceManager = $referenceManager;
        $this->logger = $logger;
    }

    public function indexAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirectToItems();
        }

        $resourceIds = $this->normaliseResourceIds(
            $this->params()->fromPost('resource_ids', [])
        );
        $returnQuery = $this->decodeReturnQuery(
            $this->params()->fromPost('query', '')
        );

        if (count($resourceIds) < 2) {
            $this->messenger()->addError(
                'You must select at least two items to merge.' // @translate
            );
            return $this->redirectToItems($returnQuery);
        }

        $masterId = (int) $this->params()->fromPost('master_id', 0);
        if (!in_array($masterId, $resourceIds, true)) {
            $masterId = null;
        }

        try {
            $items = [];
            foreach ($resourceIds as $resourceId) {
                $items[] = $this->api()->read('items', $resourceId)->getContent();
            }
            $incomingReferences = $this->referenceManager
                ->findDisplayReferences($resourceIds);
        } catch (Throwable $e) {
            $this->logger->err((string) $e);
            $this->messenger()->addError(
                'The selected items could not be loaded. One or more items may have been deleted or may not be available to you.' // @translate
            );
            return $this->redirectToItems($returnQuery);
        }

        $view = new ViewModel([
            'items' => $items,
            'returnQuery' => $returnQuery,
            'masterId' => $masterId,
            'incomingReferences' => $incomingReferences,
        ]);
        $view->setTemplate('merge-items/admin/index/index');
        return $view;
    }

    private function normaliseResourceIds($resourceIds): array
    {
        if (!is_array($resourceIds)) {
            return [];
        }

        $resourceIds = array_filter(
            array_map('intval', $resourceIds),
            static fn (int $resourceId): bool => $resourceId > 0
        );

        return array_values(array_unique($resourceIds));
    }

    private function decodeReturnQuery($query): array
    {
        if (!is_string($query) || $query === '') {
            return [];
        }

        $query = json_decode($query, true);
        return is_array($query) ? $query : [];
    }

    private function redirectToItems(array $query = [])
    {
        return $this->redirect()->toRoute(
            'admin/default',
            ['controller' => 'item', 'action' => 'browse'],
            ['query' => $query]
        );
    }
}
