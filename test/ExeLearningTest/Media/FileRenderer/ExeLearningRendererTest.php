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
        $this->assertStringContainsString('Open fullscreen', $result);
        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function testRenderOmitsTheEditButtonOnAPublicRequest(): void
    {
        // Editing is admin-only, and this renderer now also runs on public
        // pages, site page blocks and search results.
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';

        $elpService = $this->createMock(ElpFileService::class);
        $elpService->method('getMediaHash')->willReturn($hash);
        $elpService->method('hasPreview')->willReturn(true);

        $renderer = new ExeLearningRenderer($elpService, $this->requestOn('/s/default/item/42'));

        $view = new \Laminas\View\Renderer\PhpRenderer();
        $view->identity = (object) ['name' => 'admin'];
        $view->userIsAllowed = true;

        $media = new MediaRepresentation(
            'http://example.com/course.elpx',
            'Course',
            'course.elpx',
            42,
            ['exelearning_extracted_hash' => $hash, 'exelearning_has_preview' => '1']
        );

        $result = $renderer->render($view, $media);

        $this->assertStringNotContainsString('exelearning-edit-btn', $result);
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
            $view->userIsAllowed = false;

            $result = $renderer->render($view, $this->elpxMedia());

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
        // twice; the edit button lives here now and the partial carries only
        // the modal.
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

        // allow-same-origin is deliberate and load-bearing: ContentController
        // serves the package under `default-src 'self'`, which an opaque origin
        // can never match, so without it every asset inside the package is
        // blocked. The sandbox is not the security boundary here -- the ZIP
        // validation and the proxy's response headers are.
        $this->assertStringContainsString(
            'sandbox="allow-same-origin allow-scripts allow-popups allow-popups-to-escape-sandbox"',
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
    // buildContentUrl() tests
    // =========================================================================

    public function testBuildContentUrlIncludesNonStandardPort(): void
    {
        $uri = new class extends \Laminas\Uri\Http {
            public function getPort(): ?int { return 8080; }
        };
        $request = new class($uri) extends \Laminas\Http\Request {
            private $customUri;
            public function __construct($uri) { $this->customUri = $uri; }
            public function getUri(): \Laminas\Uri\Http { return $this->customUri; }
        };

        $renderer = new ExeLearningRenderer($this->elpService, $request);

        $url = $this->callProtectedMethod($renderer, 'buildContentUrl', ['abc123def456789012345678901234567890abcd']);

        $this->assertStringContainsString(':8080', $url);
        $this->assertStringContainsString('/exelearning/content/abc123def456789012345678901234567890abcd/index.html', $url);
    }

    public function testBuildContentUrlStripsPlaygroundPrefixFromUriPath(): void
    {
        $uri = new class extends \Laminas\Uri\Http {
            public function getPath(): string { return '/omeka-s-playground/playground/abc123/php83/admin/media/3'; }
        };
        $request = new class($uri) extends \Laminas\Http\Request {
            private $customUri;
            public function __construct($uri) { $this->customUri = $uri; }
            public function getUri(): \Laminas\Uri\Http { return $this->customUri; }
        };

        $renderer = new ExeLearningRenderer($this->elpService, $request);
        $url = $this->callProtectedMethod($renderer, 'buildContentUrl', ['abc123def456789012345678901234567890abcd']);

        $this->assertStringContainsString('/omeka-s-playground/playground/abc123/php83/exelearning/content/', $url);
        $this->assertStringNotContainsString('/admin/', $url);
    }

    public function testExtractBasePathWithAdminRoute(): void
    {
        $basePath = $this->callProtectedMethod($this->renderer, 'extractBasePath', ['/playground/uuid/php83/admin/media/3']);
        $this->assertSame('/playground/uuid/php83', $basePath);
    }

    public function testExtractBasePathWithPublicRoute(): void
    {
        $basePath = $this->callProtectedMethod($this->renderer, 'extractBasePath', ['/playground/uuid/php83/s/mysite/item/1']);
        $this->assertSame('/playground/uuid/php83', $basePath);
    }

    public function testExtractBasePathWithNoKnownMarker(): void
    {
        $basePath = $this->callProtectedMethod($this->renderer, 'extractBasePath', ['/some/unknown/path']);
        $this->assertSame('', $basePath);
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
