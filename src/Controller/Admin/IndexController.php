<?php declare(strict_types=1);

namespace MergeItems\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use MergeItems\ReferenceManager;

class IndexController extends AbstractActionController
{
    private ReferenceManager $referenceManager;

    public function __construct(ReferenceManager $referenceManager)
    {
        $this->referenceManager = $referenceManager;
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

        $items = [];
        foreach ($resourceIds as $resourceId) {
            $items[] = $this->api()->read('items', $resourceId)->getContent();
        }

        $view = new ViewModel([
            'items' => $items,
            'returnQuery' => $returnQuery,
            'masterId' => $masterId,
            'incomingReferences' => $this->referenceManager
                ->findDisplayReferences($resourceIds),
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
