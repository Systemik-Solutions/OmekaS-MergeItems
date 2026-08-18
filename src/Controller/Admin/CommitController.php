<?php declare(strict_types=1);

namespace MergeItems\Controller\Admin;

use Doctrine\ORM\EntityManager;
use Laminas\Form\FormElementManager;
use Laminas\Http\Response;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use MergeItems\Form\MergeCommitForm;
use MergeItems\ReferenceManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Throwable;

class CommitController extends AbstractActionController
{
    private ApiManager $apiManager;
    private EntityManager $entityManager;
    private FormElementManager $formElementManager;
    private Logger $logger;
    private ReferenceManager $referenceManager;

    public function __construct(
        ApiManager $apiManager,
        EntityManager $entityManager,
        FormElementManager $formElementManager,
        Logger $logger,
        ReferenceManager $referenceManager
    ) {
        $this->apiManager = $apiManager;
        $this->entityManager = $entityManager;
        $this->formElementManager = $formElementManager;
        $this->logger = $logger;
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
        $masterId = (int) $this->params()->fromPost('master_id', 0);
        $returnQuery = $this->decodeReturnQuery(
            $this->params()->fromPost('query', '')
        );

        if (count($resourceIds) < 2 || !in_array($masterId, $resourceIds, true)) {
            $this->messenger()->addError(
                'Select a master record and at least one other item to merge.' // @translate
            );
            return $this->redirectToItems($returnQuery);
        }

        try {
            $context = $this->buildMergeContext($resourceIds, $masterId);
        } catch (Throwable $e) {
            $this->logger->err((string) $e);
            $this->messenger()->addError(
                'The selected items could not be prepared for merging.' // @translate
            );
            return $this->redirectToItems($returnQuery);
        }

        /** @var MergeCommitForm $commitForm */
        $commitForm = $this->formElementManager->get(MergeCommitForm::class);
        $isCommitRequest = (bool) $this->params()->fromPost('commit_changes');
        if ($isCommitRequest) {
            $selectedValues = $this->normaliseSelectedValues(
                $this->params()->fromPost('merge_values', []),
                $context['mergeableValues']
            );
            $selectedMedia = $this->normaliseSelectedMedia(
                $this->params()->fromPost('merge_media', []),
                $context['duplicates']
            );
        } else {
            $selectedValues = $this->defaultSelectedValues(
                $context['mergeableValues'],
                $context['titlePropertyId']
            );
            $selectedMedia = $this->defaultSelectedMedia(
                $context['duplicates']
            );
        }

        if ($isCommitRequest) {
            $commitForm->setData($this->getRequest()->getPost());
            if (!$commitForm->isValid()) {
                $this->messenger()->addError(
                    'The merge request expired or was invalid. No items were changed.' // @translate
                );
                // Render a fresh token so the user can submit again safely.
                $commitForm = $this->formElementManager->get(MergeCommitForm::class);
            } else {
                $mergeSucceeded = false;
                try {
                    $this->performMerge(
                        $context['master'],
                        $context['duplicates'],
                        $context['mergeableValues'],
                        $selectedValues,
                        $selectedMedia
                    );
                    $mergeSucceeded = true;
                } catch (Throwable $e) {
                    $this->logger->err((string) $e);
                    $this->messenger()->addError(
                        'The items could not be merged. Database changes were rolled back.' // @translate
                    );
                    try {
                        $context = $this->buildMergeContext($resourceIds, $masterId);
                        $commitForm = $this->formElementManager->get(MergeCommitForm::class);
                    } catch (Throwable $reloadException) {
                        $this->logger->err((string) $reloadException);
                        return $this->redirectToItems($returnQuery);
                    }
                }

                if ($mergeSucceeded) {
                    $this->messenger()->addSuccess(
                        'The selected items were merged successfully.' // @translate
                    );
                    return $this->redirect()->toRoute('admin/id', [
                        'controller' => 'item',
                        'id' => $masterId,
                    ]);
                }
            }
        }

        $view = new ViewModel($context + [
            'commitForm' => $commitForm,
            'resourceIds' => $resourceIds,
            'returnQuery' => $returnQuery,
            'selectedValues' => $selectedValues,
            'selectedMedia' => $selectedMedia,
        ]);
        $view->setTemplate('merge-items/admin/commit/index');
        return $view;
    }

    private function buildMergeContext(array $resourceIds, int $masterId): array
    {
        /** @var ItemRepresentation $master */
        $master = $this->apiManager->read('items', $masterId)->getContent();
        if (!$master->userIsAllowed('update')) {
            throw new \RuntimeException('The current user cannot update the master item.');
        }

        $duplicates = [];
        foreach ($resourceIds as $resourceId) {
            if ($resourceId === $masterId) {
                continue;
            }
            /** @var ItemRepresentation $duplicate */
            $duplicate = $this->apiManager->read('items', $resourceId)->getContent();
            if (!$duplicate->userIsAllowed('delete')) {
                throw new \RuntimeException(sprintf(
                    'The current user cannot delete item %d.',
                    $resourceId
                ));
            }
            $duplicates[$resourceId] = $duplicate;
        }

        $masterPropertyIds = [];
        $masterValueKeys = [];
        foreach ($master->values() as $propertyData) {
            $propertyId = $propertyData['property']->id();
            $masterPropertyIds[$propertyId] = true;
            foreach ($propertyData['values'] as $value) {
                $masterValueKeys[$propertyId][$this->valueEqualityKey($value)] = true;
            }
        }

        $titlePropertyId = $this->getTitlePropertyId($master);

        $mergeableValues = [];
        foreach ($duplicates as $duplicateId => $duplicate) {
            $mergeableValues[$duplicateId] = [];
            foreach ($duplicate->values() as $term => $propertyData) {
                $propertyId = $propertyData['property']->id();
                if (isset($masterPropertyIds[$propertyId])) {
                    $propertyData['term'] = $term;
                    $propertyData['value_statuses'] = [];
                    $propertyData['new_value_count'] = 0;
                    foreach ($propertyData['values'] as $value) {
                        $isNew = !isset(
                            $masterValueKeys[$propertyId][$this->valueEqualityKey($value)]
                        );
                        $propertyData['value_statuses'][] = $isNew
                            ? 'new'
                            : 'existing';
                        if ($isNew) {
                            ++$propertyData['new_value_count'];
                        }
                    }
                    $mergeableValues[$duplicateId][$propertyId] = $propertyData;
                }
            }
        }

        return [
            'master' => $master,
            'duplicates' => $duplicates,
            'mergeableValues' => $mergeableValues,
            'titlePropertyId' => $titlePropertyId,
            'incomingReferences' => $this->referenceManager
                ->findDisplayReferences($resourceIds, $masterId),
        ];
    }

    private function performMerge(
        ItemRepresentation $master,
        array $duplicates,
        array $mergeableValues,
        array $selectedValues,
        array $selectedMedia
    ): void {
        $masterId = $master->id();
        $masterIsPublic = $master->isPublic();
        $duplicateIds = array_keys($duplicates);
        $valuePayload = $this->buildValuePayload(
            $master,
            $duplicates,
            $mergeableValues,
            $selectedValues
        );
        $mediaIds = [];

        foreach ($duplicates as $duplicateId => $duplicate) {
            if (!isset($selectedMedia[$duplicateId])) {
                continue;
            }
            foreach ($duplicate->media() as $media) {
                if (!$media->userIsAllowed('update')) {
                    throw new \RuntimeException(sprintf(
                        'The current user cannot move media %d.',
                        $media->id()
                    ));
                }
                $mediaIds[] = $media->id();
            }
        }

        $rewirePlan = $this->referenceManager->buildRewirePlan(
            $masterId,
            $duplicateIds
        );
        $affectedSources = [];
        foreach ($rewirePlan['affected_source_ids'] as $sourceId) {
            $source = $this->apiManager->read('resources', $sourceId)->getContent();
            if (!$source->userIsAllowed('update')) {
                throw new \RuntimeException(sprintf(
                    'The current user cannot update referencing resource %d.',
                    $sourceId
                ));
            }
            if ($source->resourceName() === 'value_annotations') {
                // Value annotations can be authorized through the generic
                // resources adapter, but they have no standalone update API.
                // The rewired value needs no independent index refresh.
                continue;
            }
            $affectedSources[$sourceId] = [
                'resource_name' => $source->resourceName(),
                'is_public' => $source->isPublic(),
            ];
        }

        foreach ($rewirePlan['annotation_ids'] as $annotationId) {
            $annotation = $this->apiManager
                ->read('resources', $annotationId)->getContent();
            if (!$annotation->userIsAllowed('delete')) {
                throw new \RuntimeException(sprintf(
                    'The current user cannot delete value annotation %d.',
                    $annotationId
                ));
            }
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $position = (int) $connection->executeQuery(
                'SELECT COALESCE(MAX(position), 0) FROM media WHERE item_id = ?',
                [$masterId]
            )->fetchOne() + 1;
            $mediaPositions = [];
            foreach ($mediaIds as $mediaId) {
                $mediaPositions[$mediaId] = $position++;
                $connection->update('media', [
                    'item_id' => $masterId,
                    'position' => $mediaPositions[$mediaId],
                ], ['id' => $mediaId]);
            }

            if ($mediaIds) {
                // MediaAdapter does not hydrate o:item on UPDATE, so ownership
                // must be changed directly. Reload the moved media, then pass
                // each one through the API to dispatch the standard update
                // events and refresh media-level derived state.
                $this->entityManager->clear();
                foreach ($mediaIds as $mediaId) {
                    $this->apiManager->update('media', $mediaId, [
                        'o:item' => ['o:id' => $masterId],
                        'position' => $mediaPositions[$mediaId],
                    ], [], [
                        'isPartial' => true,
                    ]);
                }
            }

            $this->referenceManager->applyRewirePlan($rewirePlan, $masterId);

            // Reload all entities after changing media ownership and references
            // outside the ORM.
            $this->entityManager->clear();

            $this->referenceManager->removeValueAnnotations(
                $rewirePlan['annotation_ids']
            );

            // Always update the master so its modified date and full-text index
            // reflect moved media and removed self-references even when no
            // property values were selected.
            $valuePayload['o:is_public'] = $masterIsPublic;
            $this->apiManager->update('items', $masterId, $valuePayload, [], [
                'isPartial' => true,
                'collectionAction' => 'append',
            ]);

            // A lightweight partial update refreshes the full-text index and
            // modified date of every resource whose reference was rewired.
            foreach ($affectedSources as $sourceId => $sourceData) {
                $this->apiManager->update($sourceData['resource_name'], $sourceId, [
                    'o:is_public' => $sourceData['is_public'],
                ], [], [
                    'isPartial' => true,
                    'collectionAction' => 'append',
                ]);
            }

            foreach ($duplicateIds as $duplicateId) {
                $this->apiManager->delete('items', $duplicateId);
            }

            $connection->commit();
        } catch (Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->clear();
            throw $e;
        }
    }

    private function normaliseSelectedValues($selection, array $mergeableValues): array
    {
        if (!is_array($selection)) {
            return [];
        }

        $normalised = [];
        foreach ($selection as $duplicateId => $propertySelection) {
            $duplicateId = (int) $duplicateId;
            if (!isset($mergeableValues[$duplicateId]) || !is_array($propertySelection)) {
                continue;
            }
            foreach (array_keys($propertySelection) as $propertyId) {
                $propertyId = (int) $propertyId;
                if (isset($mergeableValues[$duplicateId][$propertyId])) {
                    $normalised[$duplicateId][] = $propertyId;
                }
            }
        }
        return $normalised;
    }

    private function defaultSelectedValues(
        array $mergeableValues,
        ?int $titlePropertyId
    ): array
    {
        $selected = [];
        foreach ($mergeableValues as $duplicateId => $properties) {
            $selected[$duplicateId] = [];
            foreach ($properties as $propertyId => $propertyData) {
                if ($propertyId !== $titlePropertyId
                    && !empty($propertyData['new_value_count'])
                ) {
                    $selected[$duplicateId][] = $propertyId;
                }
            }
        }
        return $selected;
    }

    private function buildValuePayload(
        ItemRepresentation $master,
        array $duplicates,
        array $mergeableValues,
        array $selectedValues
    ): array {
        $selectedItemLookup = array_fill_keys(
            array_merge([$master->id()], array_keys($duplicates)),
            true
        );
        $seenValueKeys = [];
        foreach ($master->values() as $propertyData) {
            $propertyId = $propertyData['property']->id();
            foreach ($propertyData['values'] as $value) {
                $seenValueKeys[$propertyId][$this->valueEqualityKey($value)] = true;
            }
        }

        $valuePayload = [];
        foreach ($duplicates as $duplicateId => $duplicate) {
            foreach ($selectedValues[$duplicateId] ?? [] as $propertyId) {
                if (!isset($mergeableValues[$duplicateId][$propertyId])) {
                    continue;
                }
                $propertyData = $mergeableValues[$duplicateId][$propertyId];
                $term = $propertyData['term'];
                foreach ($propertyData['values'] as $value) {
                    $valueData = $value->jsonSerialize();
                    $valueResourceId = isset($valueData['value_resource_id'])
                        ? (int) $valueData['value_resource_id']
                        : 0;
                    if ($valueResourceId && isset($selectedItemLookup[$valueResourceId])) {
                        // Every selected item resolves to the master. Do not
                        // append a value that would point the master to itself.
                        continue;
                    }

                    $valueKey = $this->valueEqualityKey($value);
                    if (isset($seenValueKeys[$propertyId][$valueKey])) {
                        continue;
                    }
                    $seenValueKeys[$propertyId][$valueKey] = true;
                    $valuePayload[$term][] = $valueData;
                }
            }
        }

        return $valuePayload;
    }

    private function valueEqualityKey($value): string
    {
        $valueResource = $value->valueResource();
        if ($valueResource) {
            $payload = ['resource', $valueResource->id()];
        } elseif ($value->uri() !== null) {
            $payload = ['uri', $value->uri()];
        } else {
            $payload = ['value', $value->value()];
        }

        return json_encode([
            $value->type(),
            $payload,
            $value->lang() ?? '',
        ], JSON_THROW_ON_ERROR);
    }

    private function getTitlePropertyId(ItemRepresentation $master): ?int
    {
        $resourceTemplate = $master->resourceTemplate();
        $titleProperty = $resourceTemplate
            ? $resourceTemplate->titleProperty()
            : null;
        if ($titleProperty) {
            return $titleProperty->id();
        }

        $properties = $this->apiManager->search('properties', [
            'term' => 'dcterms:title',
            'limit' => 1,
        ])->getContent();
        return $properties ? $properties[0]->id() : null;
    }

    private function normaliseSelectedMedia($selection, array $duplicates): array
    {
        if (!is_array($selection)) {
            return [];
        }

        $normalised = [];
        foreach (array_keys($selection) as $duplicateId) {
            $duplicateId = (int) $duplicateId;
            if (isset($duplicates[$duplicateId])) {
                $normalised[$duplicateId] = true;
            }
        }
        return $normalised;
    }

    private function defaultSelectedMedia(array $duplicates): array
    {
        $selected = [];
        foreach ($duplicates as $duplicateId => $duplicate) {
            if ($duplicate->media()) {
                $selected[$duplicateId] = true;
            }
        }
        return $selected;
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

    private function redirectToItems(array $query = []): Response
    {
        return $this->redirect()->toRoute(
            'admin/default',
            ['controller' => 'item', 'action' => 'browse'],
            ['query' => $query]
        );
    }
}
