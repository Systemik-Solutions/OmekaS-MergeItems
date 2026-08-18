<?php declare(strict_types=1);

namespace MergeItems;

use Doctrine\ORM\EntityManager;
use Laminas\Log\Logger;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\ValueAnnotation;
use Throwable;

class ReferenceManager
{
    private EntityManager $entityManager;
    private ApiManager $apiManager;
    private Logger $logger;

    public function __construct(
        EntityManager $entityManager,
        ApiManager $apiManager,
        Logger $logger
    ) {
        $this->entityManager = $entityManager;
        $this->apiManager = $apiManager;
        $this->logger = $logger;
    }

    public function findDisplayReferences(
        array $targetIds,
        ?int $masterId = null
    ): array
    {
        $targetIds = array_values(array_unique(array_filter(
            array_map('intval', $targetIds),
            static fn (int $id): bool => $id > 0
        )));
        $targetLookup = array_fill_keys($targetIds, true);
        if ($masterId !== null && !isset($targetLookup[$masterId])) {
            $masterId = null;
        }
        $reports = [];
        foreach ($targetIds as $targetId) {
            $reports[$targetId] = $this->newDisplayReport($masterId === null);
        }

        $rows = $this->getIncomingReferenceRows($targetIds);
        $sourceIds = [];
        $rowsByTargetAndSource = [];
        foreach ($rows as $row) {
            $targetId = (int) $row['target_id'];
            $sourceId = (int) $row['source_id'];
            if ($sourceId === $targetId) {
                continue;
            }
            $sourceIds[$sourceId] = true;
            $rowsByTargetAndSource[$targetId][$sourceId][] = $row;
        }

        $sourceResources = [];
        if ($sourceIds) {
            try {
                $resources = $this->apiManager->search('resources', [
                    'id' => array_keys($sourceIds),
                    'limit' => count($sourceIds),
                ])->getContent();
                foreach ($resources as $resource) {
                    $sourceResources[$resource->id()] = $resource;
                }
            } catch (Throwable $e) {
                $this->logger->err(sprintf(
                    'MergeItems could not load incoming reference sources: %s',
                    $e->getMessage()
                ), ['exception' => $e]);
                foreach ($reports as &$report) {
                    $report['load_error'] = true;
                }
                unset($report);
                return $reports;
            }
        }

        foreach ($rowsByTargetAndSource as $targetId => $sourceRows) {
            foreach ($sourceRows as $sourceId => $referenceRows) {
                $requiresUpdate = !isset($targetLookup[$sourceId])
                    && ($masterId === null || $targetId !== $masterId);
                if (!isset($sourceResources[$sourceId])) {
                    if ($requiresUpdate) {
                        ++$reports[$targetId]['unreadable_count'];
                    } else {
                        ++$reports[$targetId]['non_blocking_unreadable_count'];
                    }
                    continue;
                }

                $resource = $sourceResources[$sourceId];
                $canUpdate = !$requiresUpdate || $resource->userIsAllowed('update');
                if (!$canUpdate) {
                    ++$reports[$targetId]['non_updatable_count'];
                }
                $reports[$targetId]['resources'][$sourceId] = [
                    'resource' => $resource,
                    'properties' => [],
                    'requires_update' => $requiresUpdate,
                    'can_update' => $canUpdate,
                ];
                foreach ($referenceRows as $row) {
                    $reports[$targetId]['resources'][$sourceId]['properties'][(int) $row['property_id']]
                        = $row['property_label'];
                }
            }
        }

        return $reports;
    }

    private function newDisplayReport(bool $warningsConditional): array
    {
        return [
            'resources' => [],
            'unreadable_count' => 0,
            'non_blocking_unreadable_count' => 0,
            'non_updatable_count' => 0,
            'load_error' => false,
            'warnings_conditional' => $warningsConditional,
        ];
    }

    public function buildRewirePlan(int $masterId, array $duplicateIds): array
    {
        $duplicateLookup = array_fill_keys($duplicateIds, true);
        $plan = [
            'updates' => [],
            'deletes' => [],
            'annotation_ids' => [],
            'affected_source_ids' => [],
        ];

        // Include the master so pre-existing master-to-master references are
        // removed as part of the same self-reference protection.
        $targetIds = array_merge([$masterId], $duplicateIds);
        foreach ($this->getIncomingReferenceRows($targetIds) as $row) {
            $sourceId = (int) $row['source_id'];
            $targetId = (int) $row['target_id'];
            if (isset($duplicateLookup[$sourceId])) {
                // The source item will be deleted. Selected outbound values are
                // normalized separately before they are appended to the master.
                continue;
            }

            $valueId = (int) $row['value_id'];
            if ($sourceId === $masterId) {
                // A master reference to itself, or to an item that is about to
                // become the master, would be a self-reference after merging.
                $plan['deletes'][] = $valueId;
                if ($row['value_annotation_id']) {
                    $plan['annotation_ids'][] = (int) $row['value_annotation_id'];
                }
                continue;
            }

            if ($targetId === $masterId) {
                // This external reference already points to the surviving item.
                continue;
            }

            $plan['updates'][] = $valueId;
            $plan['affected_source_ids'][$sourceId] = true;
        }

        $plan['affected_source_ids'] = array_keys($plan['affected_source_ids']);
        $plan['annotation_ids'] = array_values(array_unique($plan['annotation_ids']));
        return $plan;
    }

    public function applyRewirePlan(array $plan, int $masterId): void
    {
        $connection = $this->entityManager->getConnection();
        foreach ($plan['updates'] as $valueId) {
            $connection->update('value', [
                'value_resource_id' => $masterId,
            ], ['id' => $valueId]);
        }
        foreach ($plan['deletes'] as $valueId) {
            $connection->delete('value', ['id' => $valueId]);
        }
    }

    public function removeValueAnnotations(array $annotationIds): void
    {
        foreach (array_unique(array_map('intval', $annotationIds)) as $annotationId) {
            if ($annotationId < 1) {
                continue;
            }
            $annotation = $this->entityManager->find(
                ValueAnnotation::class,
                $annotationId
            );
            if ($annotation) {
                // Value annotations deliberately have no standalone delete
                // API operation. Removing the entity also removes its values
                // (and any nested annotations) through Doctrine's cascades.
                $this->entityManager->remove($annotation);
            }
        }
    }

    private function getIncomingReferenceRows(array $targetIds): array
    {
        $targetIds = array_values(array_unique(array_filter(
            array_map('intval', $targetIds),
            static fn (int $id): bool => $id > 0
        )));
        if (!$targetIds) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($targetIds), '?'));
        $sql = sprintf(
            'SELECT v.id AS value_id,
                    v.resource_id AS source_id,
                    v.value_resource_id AS target_id,
                    v.value_annotation_id,
                    v.property_id,
                    p.label AS property_label
             FROM `value` v
             INNER JOIN property p ON p.id = v.property_id
             WHERE v.value_resource_id IN (%s)
             ORDER BY v.value_resource_id, v.resource_id, v.property_id, v.id',
            $placeholders
        );

        return $this->entityManager->getConnection()
            ->executeQuery($sql, $targetIds)
            ->fetchAllAssociative();
    }
}
