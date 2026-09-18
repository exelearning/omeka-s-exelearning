<?php
declare(strict_types=1);

namespace ExeLearning;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Regex;
use Laminas\Router\Http\Segment;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\ApiController::class => Controller\ApiControllerFactory::class,
            Controller\EditorController::class => Controller\EditorControllerFactory::class,
            Controller\ContentController::class => Controller\ContentControllerFactory::class,
            Controller\StylesServeController::class => Controller\StylesServeControllerFactory::class,
            Controller\Admin\StylesController::class => Controller\Admin\StylesControllerFactory::class,
        ],
        'aliases' => [
            'ExeLearning\Controller\Editor' => Controller\EditorController::class,
            'ExeLearning\Controller\Api' => Controller\ApiController::class,
            'ExeLearning\Controller\Content' => Controller\ContentController::class,
            'ExeLearning\Controller\StylesServe' => Controller\StylesServeController::class,
            'ExeLearning\Controller\Admin\Styles' => Controller\Admin\StylesController::class,
        ],
    ],

    'service_manager' => [
        'factories' => [
            Service\ElpFileService::class => Service\ElpFileServiceFactory::class,
            Service\StylesService::class => Service\StylesServiceFactory::class,
        ],
    ],

    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\StylesUploadForm::class => Form\StylesUploadForm::class,
        ],
    ],

    // Omeka resolves a media's `renderer` column through this manager, which is
    // what handleMediaHydrate() writes 'exelearning_renderer' into. Registering
    // it only under `file_renderers` (as this module used to) left the name
    // unresolvable, so Omeka fell back to a renderer that returns an empty
    // string and $media->render() produced nothing.
    'media_renderers' => [
        'factories' => [
            'exelearning_renderer' => Media\FileRenderer\ExeLearningRendererFactory::class,
        ],
    ],

    // Consulted only when a media's `renderer` column is the literal 'file',
    // i.e. eXeLearning media stored before this module claimed them. Only the
    // `elpx` extension is claimed: `zip`, `application/zip` and especially
    // `application/octet-stream` are installation-wide types that belong to the
    // rest of the site, and this manager's aliases are a single namespace shared
    // by every installed module.
    'file_renderers' => [
        'factories' => [
            'exelearning_renderer' => Media\FileRenderer\ExeLearningRendererFactory::class,
        ],
        'aliases' => [
            'elpx' => 'exelearning_renderer',
        ],
    ],

    'router' => [
        'routes' => [
            // Secure content delivery route - serves extracted eXeLearning content with security headers
            // Uses Regex route to properly capture file paths with multiple slashes
            'exelearning-content' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/exelearning/content/(?<hash>[a-f0-9]{40})(?:/(?<file>.*))?',
                    'spec' => '/exelearning/content/%hash%/%file%',
                    'defaults' => [
                        '__NAMESPACE__' => 'ExeLearning\Controller',
                        'controller' => 'Content',
                        'action' => 'serve',
                        'file' => 'index.html',
                    ],
                ],
            ],
            // Public anonymous export bootstrap. Hidden iframe loaded by the
            // download split-button JS — no admin auth, export-only.
            'exelearning-export' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/exelearning/export',
                    'defaults' => [
                        '__NAMESPACE__' => 'ExeLearning\Controller',
                        'controller' => 'Editor',
                        'action' => 'export',
                    ],
                ],
            ],
            'exelearning-styles-serve' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/exelearning/styles/(?<slug>[a-z0-9-]+)(?:/(?<file>.*))?',
                    'spec' => '/exelearning/styles/%slug%/%file%',
                    'defaults' => [
                        '__NAMESPACE__' => 'ExeLearning\Controller',
                        'controller' => 'StylesServe',
                        'action' => 'serve',
                        'file' => 'style.css',
                    ],
                ],
            ],
            'exelearning-api' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/api/exelearning',
                    'defaults' => [
                        '__NAMESPACE__' => 'ExeLearning\Controller',
                        'controller' => 'Api',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'save' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/save/:id',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                'action' => 'save',
                            ],
                        ],
                    ],
                    'elp-data' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/elp-data/:id',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                'action' => 'getData',
                            ],
                        ],
                    ],
                    'teacher-mode' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/teacher-mode/:id',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                'action' => 'setTeacherMode',
                            ],
                        ],
                    ],
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'exelearning-editor' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/exelearning/editor/:action[/:id]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'ExeLearning\Controller',
                                'controller' => 'Editor',
                                'action' => 'index',
                            ],
                        ],
                    ],
                    'exelearning-styles' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/exelearning/styles',
                            'defaults' => [
                                '__NAMESPACE__' => 'ExeLearning\Controller\Admin',
                                'controller' => 'Styles',
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'toggle-builtin' => [
                                'type' => Literal::class,
                                'options' => [
                                    'route' => '/toggle-builtin',
                                    'defaults' => ['action' => 'toggleBuiltin'],
                                ],
                            ],
                            'toggle-uploaded' => [
                                'type' => Literal::class,
                                'options' => [
                                    'route' => '/toggle-uploaded',
                                    'defaults' => ['action' => 'toggleUploaded'],
                                ],
                            ],
                            'delete' => [
                                'type' => Literal::class,
                                'options' => [
                                    'route' => '/delete',
                                    'defaults' => ['action' => 'delete'],
                                ],
                            ],
                            'toggle-block-import' => [
                                'type' => Literal::class,
                                'options' => [
                                    'route' => '/toggle-block-import',
                                    'defaults' => ['action' => 'toggleBlockImport'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'eXeLearning styles', // @translate
                'route' => 'admin/exelearning-styles',
                'resource' => 'ExeLearning\Controller\Admin\Styles',
                'privilege' => 'index',
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

    'exelearning' => [
        'settings' => [
            'exelearning_viewer_height' => 600,
        ],
    ],
];
