<?php declare(strict_types=1);

namespace MergeItems;

use MergeItems\Controller\Admin\CommitController;
use MergeItems\Controller\Admin\IndexController;
use MergeItems\Form\MergeCommitForm;
use MergeItems\ReferenceManager;
use MergeItems\Service\Controller\CommitControllerFactory;
use MergeItems\Service\Controller\IndexControllerFactory;
use MergeItems\Service\ReferenceManagerFactory;

return [
    'controllers' => [
        'factories' => [
            CommitController::class => CommitControllerFactory::class,
            IndexController::class => IndexControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            ReferenceManager::class => ReferenceManagerFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            MergeCommitForm::class => MergeCommitForm::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'merge-items' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/item/merge-items',
                            'defaults' => [
                                '__NAMESPACE__' => 'MergeItems\Controller\Admin',
                                'controller' => IndexController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                    'merge-items-commit' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/item/merge-items-commit',
                            'defaults' => [
                                '__NAMESPACE__' => 'MergeItems\Controller\Admin',
                                'controller' => CommitController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
];
