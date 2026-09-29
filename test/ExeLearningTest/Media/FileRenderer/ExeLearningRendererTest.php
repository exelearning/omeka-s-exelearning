<?php

declare(strict_types=1);

namespace ExeLearningTest\Media\FileRenderer;

use ExeLearning\Media\FileRenderer\ExeLearningRenderer;
use ExeLearning\Service\ElpFileService;
use Omeka\Api\Representation\MediaRepresentation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ExeLearningRenderer.
 *
 * @covers \ExeLearning\Media\FileRenderer\ExeLearningRenderer
 */
class ExeLearningRendererTest extends TestCase
{
    /** A well-formed extraction hash, as generateHash() produces. */
    private const PREVIEW_HASH = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

    private ExeLearningRenderer $renderer;
    private ElpFileService $elpService;

    protected function setUp(): void
    {
        $this->elpService = $this->createMock(ElpFileService::class);
        $this->renderer = new ExeLearningRenderer($this->elpService, $this->createMockRequest());
    }

    private function createMockRequest(): \Laminas\Http\Request
    {
        return new \Laminas\Http\Request();
    }

    /** An ElpFileService that reports an extracted package with a preview. */
    private function previewingService(): ElpFileService
    {
        $service = $this->createMock(ElpFileService::class);
        $service->method('getMediaHash')->willReturn(self::PREVIEW_HASH);
        $service->method('hasPreview')->willReturn(true);

        return $service;
    }

    private function elpxMedia(int $id = 42): MediaRepresentation
    {
        return new MediaRepresentation(
            'http://example.com/course.elpx',
            'Course',
            'course.elpx',
            $id,
            ['exelearning_extracted_hash' => self::PREVIEW_HASH, 'exelearning_has_preview' => '1']
        );
    }

    /**
     * Run $test with a valid editor bundle on disk.
     *
     * The bundle is a release artifact under the gitignored dist/static/, so it
     * is present on a developer checkout that ran `make build-editor` and absent
     * in CI. Branches gated on it would otherwise be covered in one environment
     * and not the other. Only files this helper created are removed again, so a
     * real build is never touched.
     */
    private function withEditorBundle(callable $test): void
    {
        if (\ExeLearning\Service\EditorBundle::isAvailable()) {
            $test();
            return;
        }

        $base = \ExeLearning\Service\EditorBundle::getPath();
        $created = [];
        foreach ([$base, $base . '/app'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
                $created[] = $dir;
            }
        }
        $index = $base . '/index.html';
        $createdIndex = !file_exists($index);
        if ($createdIndex) {
            file_put_contents($index, '<!doctype html><title>editor</title>');
        }

        try {
            $test();
        } finally {
            if ($createdIndex) {
                @unlink($index);
            }
            foreach (array_reverse($created) as $dir) {
                @rmdir($dir);
            }
        }
    }

    /**
     * Run $test with no editor bundle on disk, restoring a real build after.
     */
    private function withoutEditorBundle(callable $test): void
    {
        $index = \ExeLearning\Service\EditorBundle::getPath() . '/index.html';
        $saved = is_readable($index) ? file_get_contents($index) : null;
        if ($saved !== null) {
            unlink($index);
        }

        try {
            $test();
        } finally {
            if ($saved !== null) {
                file_put_contents($index, $saved);
            }
        }
    }

    /**
     * A request on a given path. The default stub URI is an admin path, which
     * is what most of these tests want; a public page needs an explicit one.
     */
    private function requestOn(string $path): \Laminas\Http\Request
    {
        $request = new \Laminas\Http\Request();
        $request->getUri()->setPath($path);

        return $request;
    }

    private function callProtectedMethod(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    // =========================================================================
    // isExeLearningFile() tests
    // =========================================================================

    public function testIsExeLearningFileReturnsTrueForElpx(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'content.elpx'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertTrue($result);
    }

    public function testIsExeLearningFileReturnsFalseForAPlainZip(): void
    {
        // .elpx is the only extension eXeLearning owns; claiming .zip took over
        // a file type belonging to the rest of the installation.
        $media = new MediaRepresentation(
            'http://example.com/file.zip',
            'Test File',
            'content.zip'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertFalse($result);
    }

    public function testIsExeLearningFileStillRecognisesAZipThisModuleExtracted(): void
    {
        // Packages uploaded as .zip under the old rule carry the module's own
        // marker, so they keep working instead of going dark on upgrade.
        $media = new MediaRepresentation(
            'http://example.com/file.zip',
            'Legacy Package',
            'content.zip',
            1,
            ['exelearning_extracted_hash' => 'abc123']
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertTrue($result);
    }

    public function testIsExeLearningFileReturnsTrueForUppercaseExtension(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.ELPX',
            'Test File',
            'content.ELPX'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertTrue($result);
    }

    public function testIsExeLearningFileReturnsFalseForPdf(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.pdf',
            'Test File',
            'document.pdf'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertFalse($result);
    }

    public function testIsExeLearningFileReturnsFalseForEmptyFilename(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            ''
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertFalse($result);
    }

    public function testIsExeLearningFileReturnsFalseForJpg(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/image.jpg',
            'Test Image',
            'image.jpg'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertFalse($result);
    }

    public function testIsExeLearningFileReturnsFalseForHtml(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/page.html',
            'Test Page',
            'page.html'
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // getConfig() tests
    // =========================================================================

    public function testGetConfigReturnsDefaults(): void
    {
        // Use stub PhpRenderer which throws exception in getHelperPluginManager
        $view = new \Laminas\View\Renderer\PhpRenderer();

        $config = $this->callProtectedMethod($this->renderer, 'getConfig', [$view]);

        $this->assertIsArray($config);
        $this->assertEquals(600, $config['height']);
        $this->assertArrayNotHasKey('showEditButton', $config);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorSetsElpService(): void
    {
        $reflection = new ReflectionClass($this->renderer);
        $property = $reflection->getProperty('elpService');
        $property->setAccessible(true);

        $this->assertSame($this->elpService, $property->getValue($this->renderer));
    }

    // =========================================================================
    // File extension edge cases
    // =========================================================================

    /**
     * @dataProvider fileExtensionProvider
     */
    public function testIsExeLearningFileWithVariousExtensions(string $filename, bool $expected): void
    {
        $media = new MediaRepresentation(
            'http://example.com/' . $filename,
            'Test File',
            $filename
        );

        $result = $this->callProtectedMethod($this->renderer, 'isExeLearningFile', [$media]);
        $this->assertEquals($expected, $result);
    }

    public function fileExtensionProvider(): array
    {
        return [
            'elpx lowercase' => ['file.elpx', true],
            'elpx uppercase' => ['FILE.ELPX', true],
            'elpx mixed case' => ['File.ElPx', true],
            'zip lowercase' => ['file.zip', false],
            'zip uppercase' => ['FILE.ZIP', false],
            'pdf' => ['file.pdf', false],
            'doc' => ['file.doc', false],
            'docx' => ['file.docx', false],
            'txt' => ['file.txt', false],
            'no extension' => ['file', false],
            'multiple dots' => ['file.name.elpx', true],
            'hidden file elpx' => ['.hidden.elpx', true],
        ];
    }

    // =========================================================================
    // renderFallback() tests
    // =========================================================================

    public function testRenderFallbackReturnsHtml(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test eXeLearning File',
            'test.elpx',
            1
        );

        $result = $this->callProtectedMethod($this->renderer, 'renderFallback', [$view, $media]);

        $this->assertIsString($result);
        $this->assertStringContainsString('exelearning-fallback', $result);
        $this->assertStringContainsString('Download', $result);
        $this->assertStringContainsString('test.elpx', $result);
    }

    public function testRenderFallbackContainsDownloadLink(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/files/original/content.elpx',
            'My Content',
            'content.elpx',
            42
        );

        $result = $this->callProtectedMethod($this->renderer, 'renderFallback', [$view, $media]);

        $this->assertStringContainsString('href="http://example.com/files/original/content.elpx"', $result);
        $this->assertStringContainsString('download', $result);
    }

    // =========================================================================
    // render() tests - using mocked service
    // =========================================================================

    public function testRenderReturnsHtmlForNonExeLearningFile(): void
    {
        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.pdf',
            'Test PDF',
            'document.pdf',
            1
        );

        $result = $this->renderer->render($view, $media);

        // Should render fallback since it's not an eXeLearning file
        $this->assertStringContainsString('exelearning-fallback', $result);
    }

    public function testRenderReturnsHtmlForElpxWithoutHash(): void
    {
        // Create a mock ElpFileService that returns no hash
        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn(null);
        $elpService->method('hasPreview')->willReturn(false);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test File',
            'content.elpx',
            1,
            []
        );

        $result = $renderer->render($view, $media);

        // Should render fallback since there's no hash
        $this->assertStringContainsString('exelearning-fallback', $result);
    }

    public function testRenderReturnsIframeForValidElpx(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        // Create a mock ElpFileService that returns valid data
        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test eXeLearning Content',
            'content.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $result = $renderer->render($view, $media);

        // Should render with iframe
        $this->assertStringContainsString('exelearning-viewer', $result);
        $this->assertStringContainsString('<iframe', $result);
        $this->assertStringContainsString('sandbox=', $result);
        $this->assertStringContainsString('Test eXeLearning Content', $result);
    }

    public function testRenderProducesTheViewerForAnElpxMedia(): void
    {
        // The regression this pins: exelearning_renderer was registered only
        // under file_renderers while its name was written to the media's
        // `renderer` column, so Omeka never resolved it, substituted a Fallback
        // renderer and $media->render() returned an empty string. The failure
        // was silent -- no exception, no log line, no test.
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $media = new MediaRepresentation(
            'http://example.com/course.elpx',
            'Course',
            'course.elpx',
            42,
            ['exelearning_extracted_hash' => $hash, 'exelearning_has_preview' => '1']
        );

        $result = $renderer->render(new \Laminas\View\Renderer\PhpRenderer(), $media);

        $this->assertNotSame('', $result);
        $this->assertStringContainsString('exelearning-viewer', $result);
        $this->assertStringContainsString('/exelearning/content/' . $hash . '/index.html', $result);
        $this->assertStringNotContainsString('exelearning-fallback', $result);
    }

    /**
     * Runs the viewer's inline script in Node with a fake window and checks the
     * base it derives. Skipped where Node is not installed.
     *
     * @dataProvider contentBaseProvider
     */
    public function testInlineScriptDerivesContentBaseFromTheEarliestMarker(string $href, string $expected): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node is not installed.');
        }
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);
        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());
        $media = new MediaRepresentation(
            'http://example.com/course.elpx',
            'Course',
            'course.elpx',
            42,
            ['exelearning_extracted_hash' => $hash, 'exelearning_has_preview' => '1']
        );
        $html = $renderer->render(new \Laminas\View\Renderer\PhpRenderer(), $media);
        $this->assertSame(1, preg_match('#<script>(\(function\(\)\{var h=.*?)</script>#s', $html, $m));

        $js = 'var window={location:{href:' . json_encode($href) . '}};'
            . 'var document={getElementById:function(){return null;}};'
            . $m[1] . ';process.stdout.write(window.exelearningContentBase);';
        $file = tempnam(sys_get_temp_dir(), 'exe-base-') . '.js';
        file_put_contents($file, $js);
        try {
            $out = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file));
        } finally {
            @unlink($file);
        }
        $this->assertSame($expected, $out);
    }

    public function contentBaseProvider(): array
    {
        return [
            'admin page' => ['https://x.test/omeka/admin/media/42', 'https://x.test/omeka'],
            'public site' => ['https://x.test/omeka/s/demo/item/5', 'https://x.test/omeka'],
            'site slug admin' => ['https://x.test/omeka/s/admin/item/5', 'https://x.test/omeka'],
            'playground prefix' => [
                'https://x.test/playground/abc/php83/s/admin/media/1',
                'https://x.test/playground/abc/php83',
            ],
        ];
    }

    public function testRenderScopesItsUrlRewritingToItsOwnViewer(): void
    {
        // Several eXeLearning media can appear on one page; each viewer's inline
        // script must only touch its own elements.
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $media = new MediaRepresentation(
            'http://example.com/course.elpx',
            'Course',
            'course.elpx',
            42,
            ['exelearning_extracted_hash' => $hash, 'exelearning_has_preview' => '1']
        );

        $result = $renderer->render(new \Laminas\View\Renderer\PhpRenderer(), $media);

        $this->assertStringContainsString('id="exelearning-viewer-42"', $result);
        $this->assertStringContainsString('getElementById("exelearning-viewer-42")', $result);
        // Both the iframe and the open-in-new-tab link are resolved by that one
        // scoped pass, not by a document-wide query.
        $this->assertStringNotContainsString('document.querySelectorAll', $result);
        $this->assertStringContainsString('r.querySelectorAll("[data-exe-content-path]")', $result);
    }

    public function testRenderOffersOpenFullscreenAlongsideTheViewer(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $media = new MediaRepresentation(
            'http://example.com/course.elpx',
            'Course',
            'course.elpx',
            42,
            ['exelearning_extracted_hash' => $hash, 'exelearning_has_preview' => '1']
        );

        $result = $renderer->render(new \Laminas\View\Renderer\PhpRenderer(), $media);

        $this->assertStringContainsString('exelearning-open-tab-btn', $result);
        $this->assertStringContainsString('Open in new window', $result);
        $this->assertStringContainsString('o-icon-external', $result);
        $this->assertStringContainsString('target="_blank"', $result);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function testRenderOffersTheEditButtonOnAPublicRequest(): void
    {
        // A logged-in user who may edit the media sees the button on the public
        // site too, with the editor script and its labels, since no admin
        // template injects the modal there.
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/s/default/item/42')
            );

            $view = new \Laminas\View\Renderer\PhpRenderer();
            $view->identity = (object) ['name' => 'admin'];

            $result = $renderer->render($view, $this->elpxMedia());

            $this->assertStringContainsString('exelearning-edit-btn', $result);
            $this->assertStringContainsString('ExeLearningEditor.open(42', $result);
            $this->assertStringContainsString('window.exelearningEditorI18n=', $result);
            $this->assertStringContainsString('Edit eXeLearning File', $result);
            $this->assertContains('/modules/ExeLearning/js/exelearning-editor.js', $view->headScript()->files);
            $this->assertContains('/modules/ExeLearning/css/exelearning-editor.css', $view->headLink()->stylesheets);
        });
    }

    public function testRenderOffersTheEditButtonToAnEditorOfTheItemsSite(): void
    {
        // Core ACL refuses the update (not the owner), but the user edits a
        // site the item is published on.
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/s/default/item/42')
            );

            $view = new \Laminas\View\Renderer\PhpRenderer();
            $view->identity = new class {
                public function getId(): int
                {
                    return 7;
                }
            };
            $media = new MediaRepresentation(
                'http://example.com/course.elpx',
                'Course',
                'course.elpx',
                42,
                ['exelearning_extracted_hash' => self::PREVIEW_HASH, 'exelearning_has_preview' => '1'],
                new \ExeLearningTest\Doubles\FakeItem([], [new \ExeLearningTest\Doubles\FakeSite(1, [7 => 'editor'])])
            );
            $media->userIsAllowed = false;

            $result = $renderer->render($view, $media);

            $this->assertStringContainsString('exelearning-edit-btn', $result);
        });
    }

    public function testRenderLoadsNoEditorAssetsWithoutTheEditButton(): void
    {
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/s/default/item/42')
            );

            $view = new \Laminas\View\Renderer\PhpRenderer();
            $view->identity = null;

            $result = $renderer->render($view, $this->elpxMedia());

            $this->assertStringNotContainsString('exelearningEditorI18n', $result);
            $this->assertNotContains('/modules/ExeLearning/js/exelearning-editor.js', $view->headScript()->files);
        });
    }

    /**
     * A view whose `setting` helper answers from $settings, with a logged-in
     * user the core ACL lets update the media.
     *
     * @param array<string, mixed> $settings
     */
    private function viewWithSettings(array $settings): \Laminas\View\Renderer\PhpRenderer
    {
        $view = new class ($settings) extends \Laminas\View\Renderer\PhpRenderer {
            /** @var array<string, mixed> */
            private array $settings;

            public function __construct(array $settings)
            {
                parent::__construct();
                $this->settings = $settings;
            }

            public function getHelperPluginManager()
            {
                $settings = $this->settings;
                return new class ($settings) {
                    /** @var array<string, mixed> */
                    private array $settings;

                    public function __construct(array $settings)
                    {
                        $this->settings = $settings;
                    }

                    public function get(string $name)
                    {
                        $settings = $this->settings;
                        return function (string $key, $default = null) use ($settings) {
                            return array_key_exists($key, $settings) ? $settings[$key] : $default;
                        };
                    }
                };
            }
        };
        $view->identity = (object) ['name' => 'admin'];

        return $view;
    }

    public function testRenderOmitsTheEditButtonOnAPublicRequestWhenTheAdministratorTurnedItOff(): void
    {
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/s/default/item/42')
            );
            $view = $this->viewWithSettings(['exelearning_public_edit' => '0']);

            $result = $renderer->render($view, $this->elpxMedia());

            $this->assertStringNotContainsString('exelearning-edit-btn', $result);
            $this->assertNotContains('/modules/ExeLearning/js/exelearning-editor.js', $view->headScript()->files);
        });
    }

    public function testRenderKeepsTheEditButtonInAdminWhenPublicEditingIsOff(): void
    {
        // The setting governs public pages only.
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/admin/media/42')
            );
            $view = $this->viewWithSettings(['exelearning_public_edit' => '0']);

            $result = $renderer->render($view, $this->elpxMedia());

            $this->assertStringContainsString('exelearning-edit-btn', $result);
        });
    }

    public function testRenderOffersTheEditButtonOnAPublicRequestWhenTheAdministratorAllowsIt(): void
    {
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/s/default/item/42')
            );
            $view = $this->viewWithSettings(['exelearning_public_edit' => '1']);

            $result = $renderer->render($view, $this->elpxMedia());

            $this->assertStringContainsString('exelearning-edit-btn', $result);
            // Last action in the toolbar: the top-right corner of the viewer.
            $this->assertGreaterThan(
                strpos($result, 'exelearning-fullscreen-btn'),
                strpos($result, 'exelearning-edit-btn')
            );
        });
    }

    public function testRenderLoadsOmekasIconFontForTheToolbarIcons(): void
    {
        $renderer = new ExeLearningRenderer($this->previewingService(), $this->requestOn('/s/default/item/42'));
        $view = new \Laminas\View\Renderer\PhpRenderer();

        $result = $renderer->render($view, $this->elpxMedia());

        $this->assertContains('/modules/Omeka/css/iconfonts.css', $view->headLink()->stylesheets);
        $this->assertStringContainsString('o-icon-exelearning-fullscreen', $result);
    }

    public function testRenderOmitsTheEditButtonForAUserWhoMayNotUpdate(): void
    {
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/admin/media/42')
            );

            $view = new \Laminas\View\Renderer\PhpRenderer();
            $view->identity = (object) ['name' => 'viewer'];
            // Class-level update is allowed, but not for this media.
            $view->userIsAllowed = true;
            $media = $this->elpxMedia();
            $media->userIsAllowed = false;

            $result = $renderer->render($view, $media);

            $this->assertStringNotContainsString('exelearning-edit-btn', $result);
        });
    }

    public function testRenderOmitsTheEditButtonForAnAnonymousVisitor(): void
    {
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/admin/media/42')
            );

            $view = new \Laminas\View\Renderer\PhpRenderer();
            $view->identity = null;
            $view->userIsAllowed = true;

            $result = $renderer->render($view, $this->elpxMedia());

            $this->assertStringNotContainsString('exelearning-edit-btn', $result);
        });
    }

    public function testRenderOffersTheEditButtonToAnAdminWhoMayUpdate(): void
    {
        // The admin viewer used to be a second, divergent implementation in
        // view/exelearning/admin/media-show.phtml. Core's admin media template
        // calls $media->render() itself, so keeping both showed the viewer
        // twice; the edit button lives here now, and the modal is built by
        // the editor script the renderer enqueues.
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/admin/media/42')
            );

            $view = new \Laminas\View\Renderer\PhpRenderer();
            $view->identity = (object) ['name' => 'admin'];
            $view->userIsAllowed = true;

            $result = $renderer->render($view, $this->elpxMedia());

            $this->assertStringContainsString('exelearning-edit-btn', $result);
            $this->assertStringContainsString('ExeLearningEditor.open(42', $result);
            $this->assertStringContainsString('Edit in eXeLearning', $result);
        });
    }

    public function testRenderOmitsTheEditButtonWhenTheEditorUrlCannotBeBuilt(): void
    {
        // The admin editor route is only registered under the admin router, so
        // url() can throw on a request that reached the renderer some other way.
        // A missing edit button is the right outcome, not a 500.
        $this->withEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/admin/media/42')
            );

            $view = new class extends \Laminas\View\Renderer\PhpRenderer {
                public function url(string $route, array $params = [], array $options = []): string
                {
                    throw new \RuntimeException('route not found: ' . $route);
                }
            };
            $view->identity = (object) ['name' => 'admin'];
            $view->userIsAllowed = true;

            $result = $renderer->render($view, $this->elpxMedia());

            $this->assertStringNotContainsString('exelearning-edit-btn', $result);
            $this->assertStringContainsString('exelearning-viewer', $result);
        });
    }

    public function testRenderOmitsTheEditButtonWithoutTheBundledEditor(): void
    {
        $this->withoutEditorBundle(function (): void {
            $renderer = new ExeLearningRenderer(
                $this->previewingService(),
                $this->requestOn('/admin/media/42')
            );

            $view = new \Laminas\View\Renderer\PhpRenderer();
            $view->identity = (object) ['name' => 'admin'];
            $view->userIsAllowed = true;

            $result = $renderer->render($view, $this->elpxMedia());

            $this->assertStringNotContainsString('exelearning-edit-btn', $result);
        });
    }

    public function testRenderIncludesSecuritySandbox(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $result = $renderer->render($view, $media);

        // Pinned so that changing it means editing a test that explains why.
        // This value is NOT an isolation boundary: allow-same-origin with
        // allow-scripts lets package JavaScript act as the Omeka origin. It is
        // preserved only so this change alters renderer registration without
        // also altering the security posture, and because dropping the flag
        // alone breaks the viewer without hardening anything. PR #21 replaces
        // this with an opaque-origin viewer; see ADR-39-02. allow-downloads
        // lets the package's own .elpx download button save its file
        // (ADR-63-01).
        $this->assertStringContainsString(
            'sandbox="allow-same-origin allow-scripts allow-popups allow-popups-to-escape-sandbox allow-downloads"',
            $result
        );
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $result);
    }

    public function testRenderIncludesDownloadButton(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/original/file.elpx',
            'Test',
            'test.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $result = $renderer->render($view, $media);

        $this->assertStringContainsString('exelearning-download', $result);
        $this->assertStringContainsString('data-format="elpx"', $result);
        $this->assertStringContainsString('data-suffix=".elpx"', $result);
        $this->assertStringContainsString('Download', $result);
    }

    public function testRenderIncludesMultiFormatSplitButton(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/original/file.elpx',
            'Test',
            'test.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $result = $renderer->render($view, $media);

        // Default formats are the conservative subset (elpx, html5, scorm12).
        foreach (['elpx', 'html5', 'scorm12'] as $id) {
            $this->assertStringContainsString('data-format="' . $id . '"', $result);
        }
        // IMS and EPUB3 are opt-in — not enabled by default.
        $this->assertStringNotContainsString('data-format="ims"', $result);
        $this->assertStringNotContainsString('data-format="epub3"', $result);
        $this->assertStringContainsString('_web.zip', $result);
        $this->assertStringContainsString('_scorm.zip', $result);
        $this->assertStringContainsString('exelearning-download__toggle', $result);
        $this->assertStringContainsString('exelearning-download__menu', $result);
    }

    public function testRenderIncludesFullscreenButton(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            [
                'exelearning_extracted_hash' => $hash,
                'exelearning_has_preview' => '1',
            ]
        );

        $result = $renderer->render($view, $media);

        $this->assertStringContainsString('exelearning-fullscreen-btn', $result);
        $this->assertStringContainsString('Fullscreen', $result);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testRenderHandlesExceptionGracefully(): void
    {
        // Create a mock that throws an exception
        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willThrowException(new \Exception('Test error'));

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1
        );

        $result = $renderer->render($view, $media);

        // Should gracefully fall back to download link
        $this->assertStringContainsString('exelearning-fallback', $result);
    }

    // =========================================================================
    // getConfig() additional tests
    // =========================================================================

    public function testGetConfigUsesSettingsWhenAvailable(): void
    {
        // Create mock setting helper
        $mockSetting = new class {
            public function __invoke($key, $default = null) {
                $settings = [
                    'exelearning_viewer_height' => 800,
                ];
                return $settings[$key] ?? $default;
            }
        };

        $mockPluginManager = new class($mockSetting) {
            private $setting;
            public function __construct($setting) { $this->setting = $setting; }
            public function get($name) {
                if ($name === 'setting') return $this->setting;
                throw new \Exception("Unknown helper: $name");
            }
        };

        $view = new class($mockPluginManager) extends \Laminas\View\Renderer\PhpRenderer {
            private $pm;
            public function __construct($pm) { $this->pm = $pm; }
            public function getHelperPluginManager() { return $this->pm; }
        };

        $config = $this->callProtectedMethod($this->renderer, 'getConfig', [$view]);

        $this->assertEquals(800, $config['height']);
        $this->assertArrayNotHasKey('showEditButton', $config);
    }

    // =========================================================================
    // render() with hasPreview=false tests
    // =========================================================================

    public function testRenderFallbackWhenHasHashButNoPreview(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(false);

        $renderer = new ExeLearningRenderer($elpService, $this->createMockRequest());

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'content.elpx',
            1
        );

        $result = $renderer->render($view, $media);

        // Should render fallback
        $this->assertStringContainsString('exelearning-fallback', $result);
    }

    // =========================================================================
    // isTeacherModeVisible() tests
    // =========================================================================

    public function testIsTeacherModeVisibleReturnsFalseWhenSetToZero(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            ['exelearning_teacher_mode_visible' => '0']
        );

        $result = $this->callProtectedMethod($this->renderer, 'isTeacherModeVisible', [$media]);
        $this->assertFalse($result);
    }

    public function testIsTeacherModeVisibleReturnsTrueWhenSetToOne(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            ['exelearning_teacher_mode_visible' => '1']
        );

        $result = $this->callProtectedMethod($this->renderer, 'isTeacherModeVisible', [$media]);
        $this->assertTrue($result);
    }

    // =========================================================================
    // buildContentPath() tests
    //
    // eXeLearning exports hide teacher content by default and expose a selector to
    // show it via ?exe-teacher=1. The module adds that parameter (for every viewer)
    // whenever the per-media "Show teacher layer selector" setting allows it.
    // =========================================================================

    private const HASH = 'abc123def456789012345678901234567890abcd';

    public function testBuildContentPathAppendsTeacherParamWhenSettingAllows(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            ['exelearning_teacher_mode_visible' => '1']
        );

        $path = $this->callProtectedMethod($this->renderer, 'buildContentPath', [self::HASH, $media]);

        $this->assertSame('/exelearning/content/' . self::HASH . '/index.html?exe-teacher=1', $path);
    }

    public function testBuildContentPathOmitsTeacherParamWhenSettingUnset(): void
    {
        // Setting unset defaults to "selector not available" (opt-in, aligned with #1772).
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            []
        );

        $path = $this->callProtectedMethod($this->renderer, 'buildContentPath', [self::HASH, $media]);

        $this->assertSame('/exelearning/content/' . self::HASH . '/index.html', $path);
        $this->assertStringNotContainsString('exe-teacher', $path);
    }

    public function testBuildContentPathOmitsTeacherParamWhenSettingDenies(): void
    {
        $media = new MediaRepresentation(
            'http://example.com/file.elpx',
            'Test',
            'test.elpx',
            1,
            ['exelearning_teacher_mode_visible' => '0']
        );

        $path = $this->callProtectedMethod($this->renderer, 'buildContentPath', [self::HASH, $media]);

        $this->assertSame('/exelearning/content/' . self::HASH . '/index.html', $path);
        $this->assertStringNotContainsString('exe-teacher', $path);
    }
}
