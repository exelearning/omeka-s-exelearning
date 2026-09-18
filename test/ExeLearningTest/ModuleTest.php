<?php

declare(strict_types=1);

namespace ExeLearningTest;

use ExeLearning\Service\ElpFileService;
use ExeLearningTest\Doubles\FakeApiManager;
use ExeLearningTest\Doubles\FakeApiRequest;
use ExeLearningTest\Doubles\FakeApiResponse;
use ExeLearningTest\Doubles\FakeApplication;
use ExeLearningTest\Doubles\FakeConfigController;
use ExeLearningTest\Doubles\FakeElpFileService;
use ExeLearningTest\Doubles\FakeHttpRequest;
use ExeLearningTest\Doubles\FakeItem;
use ExeLearningTest\Doubles\FakeMediaEntity;
use ExeLearningTest\Doubles\FakeSourceOnlyEntity;
use ExeLearningTest\Doubles\RecordingSettings;
use ExeLearningTest\Doubles\RecordingSharedEventManager;
use Laminas\EventManager\Event;
use Laminas\Log\Logger;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Covers Module.php: lifecycle hooks, listener registration, the Omeka event
 * handlers and the path/URL helpers.
 *
 * Module was outside the coverage gate until the stubs under test/Stubs/Omeka/
 * made it loadable without an Omeka runtime -- see ADR-32-01. Collaborators are
 * registered in a TestServiceLocator under the same service names production
 * uses, so a handler asking for something the test did not provide fails loudly
 * instead of taking a different branch.
 */
class ModuleTest extends TestCase
{
    /** @var string|null */
    private $tmpDir = null;

    protected function setUp(): void
    {
        Messenger::reset();
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->removeTree($this->tmpDir);
        }
        $this->tmpDir = null;
    }

    // ---------------------------------------------------------------- config

    public function testGetConfigReturnsModuleConfiguration(): void
    {
        $module = new TestableModule();
        $config = $module->getConfig();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('controllers', $config);
        $this->assertArrayHasKey('router', $config);
    }

    public function testRendererIsRegisteredWhereOmekaResolvesTheRendererColumn(): void
    {
        // handleMediaHydrate() writes 'exelearning_renderer' into the media's
        // `renderer` column, and Omeka resolves that column through
        // Omeka\Media\Renderer\Manager -- i.e. `media_renderers`. Registering
        // it only under `file_renderers` left the name unresolvable, so Omeka
        // substituted a Fallback renderer that returns an empty string and
        // $media->render() produced nothing at all.
        $config = (new TestableModule())->getConfig();

        $this->assertArrayHasKey('media_renderers', $config);
        $this->assertSame(
            \ExeLearning\Media\FileRenderer\ExeLearningRendererFactory::class,
            $config['media_renderers']['factories']['exelearning_renderer']
        );
    }

    public function testRendererSatisfiesTheInterfaceThatManagerEnforces(): void
    {
        // Omeka\Media\Renderer\Manager sets $instanceOf to this interface and
        // only catches ServiceNotFoundException, so registering a renderer that
        // does not implement it is an uncaught InvalidServiceException, not a
        // silent fallback. The two registrations must stay in step.
        $this->assertTrue(is_subclass_of(
            \ExeLearning\Media\FileRenderer\ExeLearningRenderer::class,
            \Omeka\Media\Renderer\RendererInterface::class
        ));
        $this->assertTrue(is_subclass_of(
            \ExeLearning\Media\FileRenderer\ExeLearningRenderer::class,
            \Omeka\Media\FileRenderer\RendererInterface::class
        ));
    }

    public function testFileRendererAliasesClaimOnlyTheElpxExtension(): void
    {
        // These aliases are a single namespace shared by every installed module.
        // Claiming application/octet-stream or zip took over file types
        // belonging to the rest of the installation and collided with other
        // viewer modules that claim the same generic types.
        $aliases = (new TestableModule())->getConfig()['file_renderers']['aliases'];

        $this->assertSame(['elpx' => 'exelearning_renderer'], $aliases);
    }

    // ------------------------------------------------------------- lifecycle

    public function testInstallWhitelistsOnlyWhatElpxNeeds(): void
    {
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['image/png']);
        $settings->set('extension_whitelist', ['png']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->install($services);

        $mediaTypes = $settings->get('media_type_whitelist');
        $this->assertContains('application/zip', $mediaTypes);
        $this->assertContains('application/x-zip-compressed', $mediaTypes);
        $this->assertNotContains(
            'application/octet-stream',
            $mediaTypes,
            'octet-stream matches any unidentified binary and must not be whitelisted installation-wide'
        );

        $extensions = $settings->get('extension_whitelist');
        $this->assertContains('elpx', $extensions);
        $this->assertNotContains('zip', $extensions, 'zip belongs to the rest of the installation');

        $this->assertNotEmpty(Messenger::SUCCESS);
    }

    public function testInstallRecordsItsOwnAdditionsSoUninstallCanRevertThem(): void
    {
        $settings = new Settings();
        // The site already allows application/zip; only the other entry is ours.
        $settings->set('media_type_whitelist', ['image/png', 'application/zip']);
        $settings->set('extension_whitelist', ['png', 'elpx']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->install($services);

        $this->assertSame([
            'media_type_whitelist' => ['application/x-zip-compressed'],
            'extension_whitelist' => [],
        ], $settings->get('exelearning_whitelist_additions'));
    }

    public function testUninstallRevertsOnlyTheModulesOwnWhitelistAdditions(): void
    {
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['image/png', 'application/zip']);
        $settings->set('extension_whitelist', ['png', 'elpx']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->install($services);
        $module->uninstall($services);

        $this->assertSame(
            ['image/png', 'application/zip'],
            $settings->get('media_type_whitelist'),
            'entries the site had before install must survive uninstall'
        );
        $this->assertSame(['png', 'elpx'], $settings->get('extension_whitelist'));
        $this->assertNull($settings->get('exelearning_whitelist_additions'));
    }

    public function testUpdateWhitelistLeavesAnUnconfiguredListAlone(): void
    {
        // Omeka's file validator reads an empty whitelist as "allow nothing",
        // so writing one back would break every upload on the site.
        $settings = new Settings();
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->callUpdateWhitelist($services);

        $this->assertNull($settings->get('media_type_whitelist'));
        $this->assertNull($settings->get('extension_whitelist'));
    }

    // -------------------------------------------- upgrade from an old release

    public function testUpgradeWithdrawsTheLegacyOctetStreamUploadType(): void
    {
        // Releases up to 4.0.5 added application/octet-stream to the
        // installation-wide whitelist and never took it back, so narrowing
        // install() alone would only ever reach fresh installations.
        $settings = new Settings();
        $settings->set('media_type_whitelist', [
            'image/png',
            'application/pdf',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ]);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->upgrade('4.0.5', '4.1.0', $services);

        $mediaTypes = $settings->get('media_type_whitelist');
        $this->assertNotContains('application/octet-stream', $mediaTypes);
        $this->assertContains('image/png', $mediaTypes, 'unrelated entries must survive');
        $this->assertContains('application/pdf', $mediaTypes, 'unrelated entries must survive');
        $this->assertContains('application/zip', $mediaTypes, '.elpx still needs ZIP');
        $this->assertContains('application/x-zip-compressed', $mediaTypes);
        // Omeka serialises the list as a JSON array; a gapped key list would
        // become a JSON object instead.
        $this->assertSame(range(0, count($mediaTypes) - 1), array_keys($mediaTypes));
    }

    public function testUpgradeLeavesSiteWideZipUploadsAlone(): void
    {
        // Withdrawing the renderer aliases is what stops this module claiming
        // ordinary ZIP files. Removing zip from the upload whitelist as well
        // would break ZIP uploads for the rest of the installation.
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['application/zip', 'application/octet-stream']);
        $settings->set('extension_whitelist', ['png', 'zip', 'elpx', 'odt']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->upgrade('4.0.5', '4.1.0', $services);

        $this->assertSame(
            ['png', 'zip', 'elpx', 'odt'],
            $settings->get('extension_whitelist'),
            'the extension whitelist is not this upgrade\'s business'
        );
        $this->assertContains('application/zip', $settings->get('media_type_whitelist'));
    }

    public function testUpgradeLeavesAnIntentionallyEmptyWhitelistEmpty(): void
    {
        // Omeka's file validator reads an empty whitelist as "allow nothing".
        $settings = new Settings();
        $settings->set('media_type_whitelist', []);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->upgrade('4.0.5', '4.1.0', $services);

        $this->assertSame([], $settings->get('media_type_whitelist'));
    }

    public function testUpgradeClaimsNoOwnershipOfPreExistingValues(): void
    {
        // Old releases recorded no provenance, so the upgrade withdraws the one
        // value it knows they added without ever claiming the rest. Inventing
        // ownership here would make a later uninstall subtract entries this
        // module never demonstrably contributed.
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['image/png', 'application/zip', 'application/octet-stream']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->upgrade('4.0.5', '4.1.0', $services);

        $this->assertNull($settings->get('exelearning_whitelist_additions'));
    }

    public function testUninstallAfterAnUpgradeRevertsNothingItDidNotRecord(): void
    {
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['image/png', 'application/zip', 'application/octet-stream']);
        $settings->set('extension_whitelist', ['png', 'zip', 'elpx']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->upgrade('4.0.5', '4.1.0', $services);
        $module->uninstall($services);

        $this->assertSame(
            ['image/png', 'application/zip'],
            $settings->get('media_type_whitelist'),
            'uninstall may only take back what this version recorded adding'
        );
        $this->assertSame(['png', 'zip', 'elpx'], $settings->get('extension_whitelist'));
    }

    public function testALaterUpgradeDoesNotUndoAnAdministratorsChoice(): void
    {
        // The whole reason withdrawing the value is defensible is that the
        // decision becomes the administrator's. Re-running the withdrawal on
        // every future upgrade would take it back off them again.
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['image/png', 'application/octet-stream']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->upgrade('4.0.5', '4.1.0', $services);
        $this->assertNotContains('application/octet-stream', $settings->get('media_type_whitelist'));

        // The administrator deliberately puts it back.
        $settings->set('media_type_whitelist', ['image/png', 'application/octet-stream']);

        $module->upgrade('4.1.0', '4.1.1', $services);

        $this->assertContains(
            'application/octet-stream',
            $settings->get('media_type_whitelist'),
            'an upgrade past the boundary must leave the administrator\'s choice alone'
        );
    }

    public function testUpgradeIsIdempotentAcrossTheBoundary(): void
    {
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['image/png', 'application/zip']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->upgrade('4.0.3', '4.1.0', $services);
        $module->upgrade('4.0.5', '4.1.0', $services);

        $this->assertSame(['image/png', 'application/zip'], $settings->get('media_type_whitelist'));
    }

    public function testUninstallSkipsAWhitelistThatIsNowEmpty(): void
    {
        // Omeka's file validator reads an empty whitelist as "allow nothing",
        // so subtracting from one -- or writing one back -- is never right.
        $settings = new Settings();
        $settings->set('exelearning_whitelist_additions', [
            'media_type_whitelist' => ['application/zip'],
            'extension_whitelist' => [],
        ]);
        $settings->set('media_type_whitelist', []);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->uninstall($services);

        $this->assertSame([], $settings->get('media_type_whitelist'));
        $this->assertNull($settings->get('exelearning_whitelist_additions'));
    }

    public function testUninstallToleratesMissingOrMalformedAdditionRecord(): void
    {
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['image/png']);
        $settings->set('exelearning_whitelist_additions', 'not-an-array');
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->uninstall($services);

        $this->assertSame(['image/png'], $settings->get('media_type_whitelist'));
    }

    public function testInstallPreservesExistingWhitelistEntriesWithoutDuplicating(): void
    {
        $settings = new Settings();
        $settings->set('media_type_whitelist', ['image/png', 'application/zip']);
        $settings->set('extension_whitelist', ['png', 'elpx']);
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->callUpdateWhitelist($services);

        $mediaTypes = $settings->get('media_type_whitelist');
        $this->assertContains('image/png', $mediaTypes, 'pre-existing entry was dropped');
        $this->assertSame(
            1,
            count(array_keys($mediaTypes, 'application/zip', true)),
            'application/zip was added a second time'
        );
        // The list must stay a packed list; Omeka serialises it as a JSON array.
        $this->assertSame(range(0, count($mediaTypes) - 1), array_keys($mediaTypes));
        $this->assertSame(range(0, count($settings->get('extension_whitelist')) - 1), array_keys(
            $settings->get('extension_whitelist')
        ));
    }

    public function testUninstallDropsLegacyEditorInstallerSettings(): void
    {
        $settings = new RecordingSettings();
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->uninstall($services);

        $this->assertContains('exelearning_editor_installed_version', $settings->deleted);
        $this->assertContains('exelearning_editor_install_error', $settings->deleted);
        // Eight legacy editor-installer keys, plus the whitelist-additions
        // record uninstall takes back with it.
        $this->assertCount(9, $settings->deleted);
    }

    public function testUpgradeDropsTheSameLegacySettings(): void
    {
        $settings = new RecordingSettings();
        $services = new TestServiceLocator(['Omeka\Settings' => $settings]);
        $module = new TestableModule($services);

        $module->upgrade('1.0.0', '1.1.0', $services);

        // Only the legacy editor-installer keys: an upgrade must not revert the
        // whitelist entries the module still needs.
        $this->assertCount(8, $settings->deleted);
    }

    // -------------------------------------------------------------- listeners

    public function testAttachListenersRegistersEveryOmekaHook(): void
    {
        $module = new TestableModule();
        $shared = new RecordingSharedEventManager();

        $module->attachListeners($shared);

        $registered = array_map(
            function (array $row) {
                return $row['identifier'] . '::' . $row['event'];
            },
            $shared->attached
        );

        $this->assertSame([
            'Omeka\Api\Adapter\MediaAdapter::api.hydrate.post',
            'Omeka\Api\Adapter\MediaAdapter::api.create.post',
            'Omeka\Entity\Media::entity.remove.post',
            'Omeka\Controller\Admin\Media::view.show.after',
            '*::view.layout',
            'Omeka\Api\Representation\MediaRepresentation::rep.resource.json',
        ], $registered);

        foreach ($shared->attached as $row) {
            $this->assertIsCallable($row['listener']);
            $this->assertSame($module, $row['listener'][0]);
        }
    }

    // ------------------------------------------------------------- helpers

    /**
     * @dataProvider extensionProvider
     */
    public function testIsExeLearningFileRecognisesSupportedExtensions(
        string $filename,
        bool $expected
    ): void {
        $module = new TestableModule();
        $this->assertSame($expected, $module->callIsExeLearningFile($this->makeMedia($filename)));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function extensionProvider(): array
    {
        return [
            'elpx' => ['course.elpx', true],
            'zip is no longer claimed' => ['course.zip', false],
            'uppercase elpx' => ['COURSE.ELPX', true],
            'mixed case zip' => ['Course.Zip', false],
            'pdf' => ['course.pdf', false],
            'no extension' => ['course', false],
            'empty filename' => ['', false],
            'elpx in the middle' => ['course.elpx.pdf', false],
        ];
    }


    public function testGetExeLearningItemIdsReturnsIntegers(): void
    {
        $connection = new class {
            /** @var string */
            public $sql = '';
            public function query(string $sql)
            {
                $this->sql = $sql;
                return new class {
                    public function fetchAll($mode = null): array
                    {
                        return ['3', '7', 11];
                    }
                };
            }
        };
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Connection' => $connection,
            'Omeka\Logger' => new Logger(),
        ]));

        $this->assertSame([3, 7, 11], $module->callGetExeLearningItemIds());
        $this->assertStringContainsString('.elpx', $connection->sql);
    }

    public function testGetExeLearningItemIdsLogsAndReturnsEmptyOnFailure(): void
    {
        $connection = new class {
            public function query(string $sql)
            {
                throw new \RuntimeException('database is gone');
            }
        };
        $logger = new Logger();
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Connection' => $connection,
            'Omeka\Logger' => $logger,
        ]));

        $this->assertSame([], $module->callGetExeLearningItemIds());
        $this->assertStringContainsString('database is gone', $this->lastMessage($logger, 'err'));
    }

    // -------------------------------------------------------- event handlers

    public function testHandleMediaJsonLdAddsScreenshotAndContentUrls(): void
    {
        $media = $this->makeMedia('course.elpx');
        $elp = new FakeElpFileService('hash123', true, true, true);
        $module = new TestableModule(new TestServiceLocator([ElpFileService::class => $elp]));

        $event = new Event('rep.resource.json', $media, ['jsonLd' => ['@id' => 'x']]);
        $module->handleMediaJsonLd($event);

        $jsonLd = $event->getParam('jsonLd');
        $this->assertSame('x', $jsonLd['@id'], 'existing keys must be preserved');
        $this->assertSame(
            '/exelearning/content/hash123/' . ElpFileService::SCREENSHOT_FILENAME,
            $jsonLd['o-module-exelearning:screenshot']
        );
        $this->assertSame(
            '/exelearning/content/hash123/index.html',
            $jsonLd['o-module-exelearning:content']
        );
    }

    public function testHandleMediaJsonLdOmitsKeysWhenAssetsAreAbsent(): void
    {
        $elp = new FakeElpFileService('hash123', false, false, true);
        $module = new TestableModule(new TestServiceLocator([ElpFileService::class => $elp]));

        $event = new Event('rep.resource.json', $this->makeMedia('course.elpx'), ['jsonLd' => []]);
        $module->handleMediaJsonLd($event);

        $this->assertSame([], $event->getParam('jsonLd'));
    }

    public function testHandleMediaJsonLdIgnoresNonExeLearningMedia(): void
    {
        // No ElpFileService registered: reaching for one would throw, which is
        // exactly the assertion -- the guard must return before that.
        $module = new TestableModule(new TestServiceLocator([]));

        $event = new Event('rep.resource.json', $this->makeMedia('photo.png'), ['jsonLd' => ['a' => 1]]);
        $module->handleMediaJsonLd($event);

        $this->assertSame(['a' => 1], $event->getParam('jsonLd'));
    }

    public function testHandleMediaJsonLdIgnoresMissingTargetAndHashlessMedia(): void
    {
        $module = new TestableModule(new TestServiceLocator([]));
        $event = new Event('rep.resource.json', null, ['jsonLd' => []]);
        $module->handleMediaJsonLd($event);
        $this->assertSame([], $event->getParam('jsonLd'));

        $elp = new FakeElpFileService(null, true, true, true);
        $module = new TestableModule(new TestServiceLocator([ElpFileService::class => $elp]));
        $event = new Event('rep.resource.json', $this->makeMedia('c.elpx'), ['jsonLd' => []]);
        $module->handleMediaJsonLd($event);
        $this->assertSame([], $event->getParam('jsonLd'));
    }

    public function testHandleAdminMediaShowRendersThePartialForProcessedMedia(): void
    {
        $media = $this->makeMedia('course.elpx', 5, ['exelearning_teacher_mode_visible' => '1']);
        $elp = new FakeElpFileService('abc', true, true, true);
        $view = new PhpRenderer();
        $view->resource = $media;

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('partial:exelearning/admin/media-show', $output);
        $this->assertSame('exelearning/admin/media-show', $view->partials[0]['name']);
        $this->assertSame($media, $view->partials[0]['vars']['media']);
        $this->assertNull($view->partials[0]['vars']['processingError']);
        $this->assertSame(0, $elp->processCalls, 'a processed package must not be re-extracted');
    }

    public function testHandleAdminMediaShowAutoProcessesUnprocessedMedia(): void
    {
        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processResult = ['hash' => 'fresh', 'hasPreview' => true];
        $view = new PhpRenderer();
        $view->resource = $this->makeMedia('course.elpx', 9);

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        ob_end_clean();

        $this->assertSame(1, $elp->processCalls);
        $this->assertNull($view->partials[0]['vars']['processingError']);
    }

    public function testHandleAdminMediaShowReportsProcessingFailuresInsteadOfRenderingNothing(): void
    {
        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processException = new \RuntimeException('Media file not found: /srv/original/a.elpx');
        $logger = new Logger();
        $view = new PhpRenderer();
        $view->resource = $this->makeMedia('course.elpx', 9);

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => $logger,
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        ob_end_clean();

        $this->assertStringContainsString('Media file not found', $this->lastMessage($logger, 'err'));
        $this->assertSame(
            'Media file not found: /srv/original/a.elpx',
            $view->partials[0]['vars']['processingError'],
            'the admin must see why the package has no preview, not only the log'
        );
    }

    public function testHandleAdminMediaShowDoesNotRetryAFailedMediaOnEveryView(): void
    {
        // The whole point of the failure marker: an unreadable file used to be
        // re-extracted, and re-logged, on every single render.
        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processingError = 'Media file not found';
        $view = new PhpRenderer();
        $view->resource = $this->makeMedia('course.elpx', 9);

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        ob_end_clean();

        $this->assertSame(0, $elp->processCalls);
        $this->assertSame('Media file not found', $view->partials[0]['vars']['processingError']);
    }

    public function testHandleAdminMediaShowSkipsNonExeLearningMedia(): void
    {
        $view = new PhpRenderer();
        $view->resource = $this->makeMedia('photo.png');
        $module = new TestableModule(new TestServiceLocator([]));

        ob_start();
        $module->handleAdminMediaShow(new Event('view.show.after', $view));
        $this->assertSame('', (string) ob_get_clean());
    }

    public function testHandleViewLayoutInjectsScriptsOnAdminRoutes(): void
    {
        $view = new PhpRenderer();
        $view->basePath = '/omeka';
        $connection = new class {
            public function query(string $sql)
            {
                return new class {
                    public function fetchAll($mode = null): array
                    {
                        return ['4'];
                    }
                };
            }
        };

        $module = new TestableModule(new TestServiceLocator([
            'Application' => new FakeApplication('admin/default'),
            'Omeka\Connection' => $connection,
            'Omeka\Logger' => new Logger(),
        ]));

        $module->handleViewLayout(new Event('view.layout', $view));

        $this->assertSame(
            ['/omeka/modules/ExeLearning/asset/js/exelearning-thumbnail.js'],
            $view->headScript()->files
        );
        $scripts = $view->headScript()->scripts;
        $this->assertStringContainsString('data-exelearning-thumbnail', $scripts[0]);
        $this->assertStringContainsString('/omeka/modules/ExeLearning/asset/thumbnails/elpx.png', $scripts[0]);
        $this->assertStringContainsString('window.exelearningItemIds = [4]', $scripts[0]);
        $this->assertStringContainsString('/omeka/api/exelearning/elp-data/', $scripts[1]);
        $this->assertStringContainsString('exelearning_teacher_mode_visible', $scripts[1]);
    }

    public function testHandleViewLayoutSkipsPublicRoutesAndUnroutedRequests(): void
    {
        $view = new PhpRenderer();
        $module = new TestableModule(new TestServiceLocator([
            'Application' => new FakeApplication('site/resource-id'),
        ]));
        $module->handleViewLayout(new Event('view.layout', $view));
        $this->assertSame([], $view->headScript()->files);

        $view = new PhpRenderer();
        $module = new TestableModule(new TestServiceLocator([
            'Application' => new FakeApplication(null),
        ]));
        $module->handleViewLayout(new Event('view.layout', $view));
        $this->assertSame([], $view->headScript()->files);
    }

    public function testHandleMediaHydrateSetsRendererAndPersistsTeacherMode(): void
    {
        $entity = new FakeMediaEntity('course.elpx');
        $request = new FakeApiRequest(['exelearning_teacher_mode_visible' => '1']);
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, [
            'entity' => $entity,
            'request' => $request,
        ]));

        $this->assertSame('exelearning_renderer', $entity->renderer);
        $this->assertSame('1', $entity->data['exelearning_teacher_mode_visible']);
    }

    public function testHandleMediaHydrateUsesTheLastValueOfACheckboxPair(): void
    {
        // Omeka posts a hidden 0 followed by the checkbox's 1; the last wins.
        $entity = new FakeMediaEntity('course.elpx');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, [
            'entity' => $entity,
            'request' => new FakeApiRequest(['exelearning_teacher_mode_visible' => ['0', '1']]),
        ]));

        $this->assertSame('1', $entity->data['exelearning_teacher_mode_visible']);
    }

    /**
     * @dataProvider falsyTeacherModeProvider
     * @param mixed $posted
     */
    public function testHandleMediaHydrateStoresZeroForFalsyValues($posted): void
    {
        $entity = new FakeMediaEntity('course.elpx');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, [
            'entity' => $entity,
            'request' => new FakeApiRequest(['exelearning_teacher_mode_visible' => $posted]),
        ]));

        $this->assertSame('0', $entity->data['exelearning_teacher_mode_visible']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function falsyTeacherModeProvider(): array
    {
        return [
            'zero' => ['0'],
            'false' => ['false'],
            'no' => ['no'],
            'off' => ['off'],
            'empty' => [''],
        ];
    }

    public function testHandleMediaHydrateFallsBackToSourceWhenFilenameIsAbsent(): void
    {
        $entity = new FakeSourceOnlyEntity('course.elpx');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, ['entity' => $entity]));

        $this->assertSame('exelearning_renderer', $entity->renderer);
    }

    public function testSavingAMediaArmsOneMoreExtractionAttempt(): void
    {
        // A recorded failure stops the view hooks retrying on every render,
        // which would otherwise make it permanent. Saving the media is the
        // administrator's explicit retry, on a write request rather than a GET.
        $entity = new FakeMediaEntity('course.elpx', 40, [
            'exelearning_process_error' => 'Media file not found',
            'exelearning_extracted_hash' => 'keep-me',
        ]);
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, ['entity' => $entity]));

        $this->assertArrayNotHasKey('exelearning_process_error', $entity->data);
        $this->assertSame('keep-me', $entity->data['exelearning_extracted_hash'], 'other data must survive');
    }

    public function testSavingAMediaWithNoRecordedFailureChangesNothing(): void
    {
        $entity = new FakeMediaEntity('course.elpx', 41, ['exelearning_extracted_hash' => 'abc']);
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, ['entity' => $entity]));

        $this->assertSame(['exelearning_extracted_hash' => 'abc'], $entity->data);
    }

    public function testHandleMediaHydrateNoLongerClaimsPlainZipUploads(): void
    {
        // file_renderers/media_renderers aliases are one namespace shared by
        // every installed module; stamping this module's renderer onto every
        // .zip took over a file type belonging to the rest of the site.
        $entity = new FakeMediaEntity('archive.zip', 30, []);
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, ['entity' => $entity]));

        $this->assertNull($entity->renderer);
    }

    public function testHandleMediaHydrateLeavesOtherFileTypesAlone(): void
    {
        $entity = new FakeMediaEntity('photo.png');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, [
            'entity' => $entity,
            'request' => new FakeApiRequest(['exelearning_teacher_mode_visible' => '1']),
        ]));

        $this->assertNull($entity->renderer);
        $this->assertSame([], $entity->data);
    }

    public function testHandleMediaHydrateIgnoresAnEntityWithoutAFilename(): void
    {
        $entity = new FakeMediaEntity('');
        $module = new TestableModule();

        $module->handleMediaHydrate(new Event('api.hydrate.post', null, ['entity' => $entity]));

        $this->assertNull($entity->renderer);
    }

    public function testHandleMediaCreateProcessesTheUploadedFile(): void
    {
        $media = $this->makeMedia('course.elpx', 12);
        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processResult = ['hash' => 'abc', 'hasPreview' => true];

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            'Omeka\ApiManager' => new FakeApiManager($media),
            ElpFileService::class => $elp,
        ]));

        $module->handleMediaCreate($this->createEvent($media, 12));

        $this->assertSame(1, $elp->processCalls);
    }

    public function testHandleMediaCreateSkipsNonExeLearningMedia(): void
    {
        $media = $this->makeMedia('photo.png', 13);
        $elp = new FakeElpFileService(null, false, false, false);

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            'Omeka\ApiManager' => new FakeApiManager($media),
            ElpFileService::class => $elp,
        ]));

        $module->handleMediaCreate($this->createEvent($media, 13));

        $this->assertSame(0, $elp->processCalls);
    }

    public function testHandleMediaCreateLogsWhenTheRepresentationCannotBeRead(): void
    {
        $logger = new Logger();
        $api = new FakeApiManager(null);
        $api->exception = new \RuntimeException('no such media');

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => $logger,
            'Omeka\ApiManager' => $api,
        ]));

        $module->handleMediaCreate($this->createEvent(null, 99));

        $this->assertStringContainsString('no such media', $this->lastMessage($logger, 'err'));
    }

    public function testHandleMediaCreateLogsProcessingFailures(): void
    {
        $media = $this->makeMedia('course.elpx', 14);
        $elp = new FakeElpFileService(null, false, false, false);
        $elp->processException = new \RuntimeException('extraction failed');
        $logger = new Logger();

        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => $logger,
            'Omeka\ApiManager' => new FakeApiManager($media),
            ElpFileService::class => $elp,
        ]));

        $module->handleMediaCreate($this->createEvent($media, 14));

        $errors = array_column(array_filter($logger->getMessages(), function (array $m) {
            return $m['level'] === 'err';
        }), 'message');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('extraction failed', implode("\n", $errors));
    }

    /**
     * Build the event Omeka actually triggers when a media is removed.
     *
     * `Omeka\Db\Event\Subscriber\Entity::postRemove()` relays Doctrine's
     * lifecycle event with the entity as the *target*, which is the same shape
     * core's own `deleteMediaFiles()` consumes. Earlier tests here passed an
     * `entity` parameter on `api.delete.pre` -- a shape
     * `Api\Manager::initialize()` never produces -- which is why they stayed
     * green while the cleanup did nothing against real Omeka.
     *
     * @param mixed $entity
     */
    private function deleteEvent($entity): Event
    {
        return new Event('entity.remove.post', $entity);
    }

    public function testHandleMediaDeleteRemovesTheExtractionDirectory(): void
    {
        // The extraction root belongs to ElpFileService, which derives it from
        // Omeka's files directory. Module used to delete
        // <module>/data/exelearning/<hash> instead -- a path nothing ever wrote
        // to -- so every deleted media left its package on disk.
        $elp = new FakeElpFileService(null, false, false, true);
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        $entity = new FakeMediaEntity('course.elpx', 21, ['exelearning_extracted_hash' => 'hash-1']);
        $module->handleMediaDelete($this->deleteEvent($entity));

        $this->assertSame(['hash-1'], $elp->cleanedHashes);
    }

    public function testHandleMediaDeleteReadsTheEntityFromTheEventTarget(): void
    {
        // Pin the contract against both earlier mistakes: an `entity` parameter
        // (which api.delete.pre never carried) and a `response` parameter (which
        // api.delete.post carries but never fires for a cascade-removed media).
        // If the listener drifts back to either, these stay empty and fail.
        $elp = new FakeElpFileService(null, false, false, true);
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));
        $entity = new FakeMediaEntity('course.elpx', 21, ['exelearning_extracted_hash' => 'hash-1']);

        $module->handleMediaDelete(new Event('entity.remove.post', $entity));
        $this->assertSame(['hash-1'], $elp->cleanedHashes);

        $elp->cleanedHashes = [];
        $module->handleMediaDelete(new Event('api.delete.pre', null, ['entity' => $entity]));
        $module->handleMediaDelete(new Event('api.delete.post', null, [
            'response' => new FakeApiResponse($entity),
        ]));
        $this->assertSame([], $elp->cleanedHashes);
    }

    public function testHandleMediaDeleteCleansUpMediaRemovedByDeletingTheirItem(): void
    {
        // Item::$media is mapped cascade={"persist","remove","detach"}, so
        // deleting an item removes its media through Doctrine with no API
        // request. That is the commonest way an eXeLearning package is deleted,
        // and an api.delete.* listener would never see it. The lifecycle event
        // fires once per cascade-removed media, which is why core's own
        // deleteMediaFiles() uses it.
        $elp = new FakeElpFileService(null, false, false, true);
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        foreach ([['a.elpx', 31, 'hash-a'], ['b.elpx', 32, 'hash-b']] as [$name, $id, $hash]) {
            $module->handleMediaDelete(new Event(
                'entity.remove.post',
                new FakeMediaEntity($name, $id, ['exelearning_extracted_hash' => $hash])
            ));
        }

        $this->assertSame(['hash-a', 'hash-b'], $elp->cleanedHashes);
    }

    public function testHandleMediaDeleteCleansUpLegacyZipPackagesToo(): void
    {
        // A package uploaded as .zip under the old rule still carries the
        // module's hash, and its extracted content must still be removed.
        $elp = new FakeElpFileService(null, false, false, true);
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => new Logger(),
            ElpFileService::class => $elp,
        ]));

        $entity = new FakeMediaEntity('legacy.zip', 25, ['exelearning_extracted_hash' => 'hash-z']);
        $module->handleMediaDelete($this->deleteEvent($entity));

        $this->assertSame(['hash-z'], $elp->cleanedHashes);
    }

    public function testHandleMediaDeleteIgnoresIrrelevantEntities(): void
    {
        $logger = new Logger();
        $elp = new FakeElpFileService(null, false, false, true);
        $module = new TestableModule(new TestServiceLocator([
            'Omeka\Logger' => $logger,
            ElpFileService::class => $elp,
        ]));

        // No entity at all.
        $module->handleMediaDelete($this->deleteEvent(null));
        // Media this module never extracted.
        $module->handleMediaDelete($this->deleteEvent(new FakeMediaEntity('photo.png', 22, [])));
        $module->handleMediaDelete($this->deleteEvent(new FakeMediaEntity('course.elpx', 23, [])));

        $this->assertSame([], $elp->cleanedHashes);
        $this->assertSame([], array_filter($logger->getMessages(), function (array $m) {
            return $m['level'] === 'err';
        }));
    }

    public function testHandleMediaDeleteLogsUnexpectedFailures(): void
    {
        $logger = new Logger();
        $module = new TestableModule(new TestServiceLocator(['Omeka\Logger' => $logger]));

        $entity = new class {
            public function getData(): array
            {
                throw new \RuntimeException('entity detached');
            }
        };
        $module->handleMediaDelete($this->deleteEvent($entity));

        $this->assertStringContainsString('entity detached', $this->lastMessage($logger, 'err'));
    }

    // ------------------------------------------------------------ config form

    public function testGetConfigFormRendersSettingsAndStylesSection(): void
    {
        $settings = new Settings();
        $settings->set('exelearning_viewer_height', 800);
        $settings->set('exelearning_download_formats', json_encode(['elpx', 'html5']));

        $module = new TestableModule(new TestServiceLocator(['Omeka\Settings' => $settings]));
        $html = $module->getConfigForm(new PhpRenderer());

        $this->assertStringContainsString('exelearning-styles-link', $html);
        $this->assertStringContainsString('<!--formCollection-->', $html);
    }

    public function testGetConfigFormToleratesAnUnparseableStoredFormatList(): void
    {
        $settings = new Settings();
        $settings->set('exelearning_download_formats', 'not json at all');

        $module = new TestableModule(new TestServiceLocator(['Omeka\Settings' => $settings]));
        $html = $module->getConfigForm(new PhpRenderer());

        $this->assertStringContainsString('exelearning-styles-link', $html);
    }

    public function testRenderStylesSectionLinksToTheStylesPage(): void
    {
        $module = new TestableModule();
        $html = $module->callRenderStylesSection(new PhpRenderer());

        $this->assertStringContainsString('admin/exelearning-styles', $html);
        $this->assertStringContainsString('Open styles page', $html);
    }

    /**
     * The complementary branch (bundle present -> empty string) would need a
     * real dist/static/ in the checkout. Fabricating one risks deleting a
     * developer's actual build in tearDown, so it is left to the packaging
     * checks in `make package` instead. See ADR-28-01 and ADR-32-01.
     */
    public function testRenderEditorStatusSectionWarnsWhenTheBundleIsMissing(): void
    {
        if (\ExeLearning\Service\EditorBundle::isAvailable()) {
            $this->markTestSkipped('This checkout has a built editor bundle; nothing to warn about.');
        }

        $module = new TestableModule();
        $html = $module->callRenderEditorStatusSection(new PhpRenderer());

        $this->assertStringContainsString('exelearning-editor-status', $html);
        $this->assertStringContainsString('does not include the embedded editor', $html);
    }

    public function testHandleConfigFormPersistsSanitizedValues(): void
    {
        $settings = new Settings();
        $module = new TestableModule(new TestServiceLocator(['Omeka\Settings' => $settings]));

        $module->handleConfigForm(new FakeConfigController([
            'exelearning_viewer_height' => '750',
            'exelearning_download_formats' => ['elpx', 'bogus-format', 'epub3'],
        ]));

        $this->assertSame(750, $settings->get('exelearning_viewer_height'));
        $stored = $settings->get('exelearning_download_formats');
        $this->assertContains('elpx', $stored);
        $this->assertNotContains('bogus-format', $stored, 'unknown format ids must be dropped');
    }

    public function testHandleConfigFormFallsBackToDefaultHeight(): void
    {
        $settings = new Settings();
        $module = new TestableModule(new TestServiceLocator(['Omeka\Settings' => $settings]));

        $module->handleConfigForm(new FakeConfigController([]));

        $this->assertSame(600, $settings->get('exelearning_viewer_height'));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $mediaData
     */
    private function makeMedia(string $filename, int $id = 1, array $mediaData = []): object
    {
        return new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.test/files/' . $filename,
            $filename,
            $filename,
            $id,
            $mediaData
        );
    }

    /**
     * @param object|null $media
     */
    private function createEvent($media, int $mediaId): Event
    {
        return new Event('api.create.post', null, [
            'response' => new FakeApiResponse(new FakeMediaEntity('x', $mediaId)),
        ]);
    }

    private function servicesWithRequest(
        string $scheme,
        string $host,
        ?int $port,
        string $path
    ): TestServiceLocator {
        return new TestServiceLocator(['Request' => new FakeHttpRequest($scheme, $host, $port, $path)]);
    }

    private function lastMessage(Logger $logger, string $level): string
    {
        $matching = array_values(array_filter($logger->getMessages(), function (array $m) use ($level) {
            return $m['level'] === $level;
        }));
        $this->assertNotEmpty($matching, 'no ' . $level . ' message was logged');
        return (string) end($matching)['message'];
    }

    private function makeTmpDir(): string
    {
        if ($this->tmpDir === null) {
            $this->tmpDir = sys_get_temp_dir() . '/exe-module-test-' . uniqid('', true);
            mkdir($this->tmpDir, 0777, true);
        }
        return $this->tmpDir;
    }

    private function removeTree(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
