<?php
namespace BatchValues;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        // For the JSON endpoints (progress, resource picker).
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            View\Helper\BatchValuesRender::class => Service\ViewHelperFactory::class,
        ],
        'aliases' => [
            'batchValuesRender' => View\Helper\BatchValuesRender::class,
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            Media\LocalFileIngester::NAME => Service\LocalFileIngesterFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\Admin\IndexController::class => Service\IndexControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            Stdlib\ChangeLog::class => Service\StdlibFactory::class,
            Stdlib\Planner::class => Service\StdlibFactory::class,
            Stdlib\Processor::class => Service\StdlibFactory::class,
            Stdlib\MediaTools::class => Service\StdlibFactory::class,
            Stdlib\Operations::class => Service\StdlibFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'batch-values' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/batch-values[/:action[/:id]]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'BatchValues\Controller\Admin',
                                'controller' => Controller\Admin\IndexController::class,
                                'action' => 'browse',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    // In the RESOURCES section, next to the entry with the navigation key "tcf", where a module provides one
    // when that module is there: Module::placeMenu() moves it right under it.
    'navigation' => [
        'AdminResource' => [
            'batchvalues' => [
                'label' => 'Batch Values', // @translate
                'route' => 'admin/batch-values',
                'resource' => Controller\Admin\IndexController::class,
                'privilege' => 'browse',
                'class' => 'o-icon-edit',
                'pages' => [
                    ['route' => 'admin/batch-values', 'visible' => false],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'batchvalues' => [
        // Runs with at most this many items are applied immediately; larger
        // runs go to a background job.
        'sync_limit' => 50,
    ],
];
