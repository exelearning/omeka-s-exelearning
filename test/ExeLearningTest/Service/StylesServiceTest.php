<?php
declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\StylesService;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Unit tests for StylesService.
 *
 * @covers \ExeLearning\Service\StylesService
 */
class StylesServiceTest extends TestCase
{
    private string $tmpRoot;
    private StylesService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/exelearning-styles-' . uniqid();
        mkdir($this->tmpRoot . '/files', 0755, true);
        mkdir($this->tmpRoot . '/module', 0755, true);
        $settings = new Settings();
        $this->svc = new StylesService(
            $settings,
            $this->tmpRoot . '/files',
            $this->tmpRoot . '/module'
        );
    }

    protected function tearDown(): void
    {
        StylesService::recursiveDelete($this->tmpRoot);
        parent::tearDown();
    }

    public function testRegistryDefaultsToEmptyShape(): void
    {
        $registry = $this->svc->getRegistry();
        $this->assertSame([], $registry['uploaded']);
        $this->assertSame([], $registry['disabled_builtins']);
    }

    public function testImportIsAllowedByDefault(): void
    {
        $this->assertFalse($this->svc->isImportBlocked());
        $this->svc->setImportBlocked(true);
        $this->assertTrue($this->svc->isImportBlocked());
    }

    public function testSetBuiltinEnabledTogglesDisabledList(): void
    {
        $this->svc->setBuiltinEnabled('zen', false);
        $this->assertSame(['zen'], $this->svc->getRegistry()['disabled_builtins']);
        $this->svc->setBuiltinEnabled('zen', false);
        $this->assertSame(['zen'], $this->svc->getRegistry()['disabled_builtins']);
        $this->svc->setBuiltinEnabled('zen', true);
        $this->assertSame([], $this->svc->getRegistry()['disabled_builtins']);
    }

    public function testValidateZipRejectsMissingConfig(): void
    {
        $zip = $this->makeZip(['style.css' => '.x{}']);
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testValidateZipRejectsTraversalEntry(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('acme'),
            '../evil.css' => 'pwn',
        ]);
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testValidateZipRejectsDisallowedExtension(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('acme'),
            'evil.php'   => '<?php',
        ]);
        $this->expectException(\RuntimeException::class);
        try {
            $this->svc->validateZip($zip);
        } finally {
            @unlink($zip);
        }
    }

    public function testValidateAcceptsValidRootPackage(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('acme-2026', 'Acme 2026', '1.0.0'),
            'style.css'  => 'body{}',
        ]);
        $result = $this->svc->validateZip($zip);
        $this->assertSame('acme-2026', $result['config']['name']);
        $this->assertSame('', $result['prefix']);
        @unlink($zip);
    }

    public function testInstallExtractsAndRegisters(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('acme', 'Acme', '1.0.0'),
            'style.css'  => 'body{color:red}',
        ]);
        $entry = $this->svc->installFromZip($zip, 'acme.zip');
        $this->assertSame('acme', $entry['name']);
        $this->assertTrue($entry['enabled']);
        $this->assertContains('style.css', $entry['css_files']);

        $this->assertFileExists($this->svc->getStorageDir() . '/acme/style.css');
        $this->assertArrayHasKey('acme', $this->svc->getRegistry()['uploaded']);
        @unlink($zip);
    }

    public function testInstallAllocatesUniqueSlugOnCollision(): void
    {
        $z1 = $this->makeZip([
            'config.xml' => $this->configXml('duo'),
            'style.css'  => 'a{}',
        ]);
        $z2 = $this->makeZip([
            'config.xml' => $this->configXml('duo'),
            'style.css'  => 'b{}',
        ]);
        $a = $this->svc->installFromZip($z1);
        $b = $this->svc->installFromZip($z2);
        $this->assertSame('duo', $a['name']);
        $this->assertSame('duo-2', $b['name']);
        @unlink($z1);
        @unlink($z2);
    }

    public function testDeleteClearsFilesAndRegistry(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('bye'),
            'style.css'  => 'x{}',
        ]);
        $this->svc->installFromZip($zip);
        $dir = $this->svc->getStorageDir() . '/bye';
        $this->assertDirectoryExists($dir);

        $this->assertTrue($this->svc->deleteUploaded('bye'));
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertArrayNotHasKey('bye', $this->svc->getRegistry()['uploaded']);
        @unlink($zip);
    }

    public function testBuildOverrideRespectsEnabledFlagAndImportPolicy(): void
    {
        $zip = $this->makeZip([
            'config.xml' => $this->configXml('seen'),
            'style.css'  => 'a{}',
        ]);
        $this->svc->installFromZip($zip);
        $this->svc->setBuiltinEnabled('zen', false);
        $this->svc->setImportBlocked(true);

        $override = $this->svc->buildThemeRegistryOverride('https://example.test');
        $this->assertSame(['zen'], $override['disabledBuiltins']);
        $this->assertTrue($override['blockImportInstall']);
        $this->assertSame('base', $override['fallbackTheme']);
        $this->assertCount(1, $override['uploaded']);
        $this->assertSame('seen', $override['uploaded'][0]['id']);
        $this->assertSame(
            'https://example.test/exelearning/styles/seen',
            $override['uploaded'][0]['url']
        );

        $this->svc->setUploadedEnabled('seen', false);
        $override = $this->svc->buildThemeRegistryOverride();
        $this->assertCount(0, $override['uploaded']);
        @unlink($zip);
    }

    public function testExtractThemesFromBundleAcceptsBothShapes(): void
    {
        $flat = ['themes' => [['name' => 'a']]];
        $nested = ['themes' => ['themes' => [['name' => 'b']]]];
        $this->assertCount(1, $this->svc->extractThemesFromBundle($flat));
        $this->assertCount(1, $this->svc->extractThemesFromBundle($nested));
        $this->assertSame([], $this->svc->extractThemesFromBundle([]));
        $this->assertSame([], $this->svc->extractThemesFromBundle(['themes' => 'nope']));
    }

    // ---------- helpers ----------

    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'omkstyle') . '.zip';
        @unlink($path);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            $this->fail('Could not create test zip');
        }
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    private function configXml(string $name, string $title = '', string $version = '1.0.0'): string
    {
        $title = $title === '' ? ucfirst($name) : $title;
        return '<?xml version="1.0"?>'
            . '<theme>'
            . '<name>' . htmlspecialchars($name) . '</name>'
            . '<title>' . htmlspecialchars($title) . '</title>'
            . '<version>' . htmlspecialchars($version) . '</version>'
            . '<author>Test</author>'
            . '<license>CC-BY-SA</license>'
            . '<description>Test theme.</description>'
            . '</theme>';
    }
}
