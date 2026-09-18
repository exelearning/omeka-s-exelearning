<?php

declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\FilesPath;
use ExeLearningTest\TestServiceLocator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the files-directory resolution shared by the ELPX, styles and content
 * factories.
 *
 * The bug this class replaced derived the path with
 * `dirname($store->getLocalPath(''))`. `Local::getLocalPath('')` is
 * `sprintf('%s/%s', $basePath, '')`, i.e. "<basePath>/", and `dirname()` strips
 * the trailing slash *and then* the last segment, so every install landed one
 * directory too high and every .elpx failed with "Media file not found".
 *
 * @covers \ExeLearning\Service\FilesPath
 */
class FilesPathTest extends TestCase
{
    /**
     * A stand-in for Omeka\File\Store\Local.
     *
     * The trailing-slash return shape is the whole point of these tests: pin it
     * here so `dirname()` cannot be reintroduced without a red test.
     *
     * @param string|false $basePath What realpath() produced in the real store.
     */
    private function localStore($basePath): object
    {
        return new class ($basePath) {
            /** @var string|false */
            private $basePath;

            /**
             * @param string|false $basePath
             */
            public function __construct($basePath)
            {
                $this->basePath = $basePath;
            }

            public function getLocalPath(string $storagePath): string
            {
                return sprintf('%s/%s', $this->basePath, $storagePath);
            }
        };
    }

    /** A remote store (S3 and similar) has no local path at all. */
    private function remoteStore(): object
    {
        return new class {
            public function getUri(string $storagePath): string
            {
                return 'https://bucket.example/' . $storagePath;
            }
        };
    }

    public function testAsksTheStoreFirstAndTrimsOnlyTheTrailingSlash(): void
    {
        $services = new TestServiceLocator([
            'Omeka\File\Store' => $this->localStore('/var/www/html/medusa/mediateca/files'),
            'Config' => [],
        ]);

        $this->assertSame(
            '/var/www/html/medusa/mediateca/files',
            FilesPath::resolve($services)
        );
    }

    public function testStoreResultKeepsTheFilesSegmentThatDirnameUsedToEat(): void
    {
        $store = $this->localStore('/var/www/html/files');

        // The exact shape the old code mis-read.
        $this->assertSame('/var/www/html/files/', $store->getLocalPath(''));
        $this->assertSame('/var/www/html', dirname($store->getLocalPath('')), 'regression guard');

        $services = new TestServiceLocator(['Omeka\File\Store' => $store, 'Config' => []]);
        $this->assertSame('/var/www/html/files', FilesPath::resolve($services));
    }

    public function testStoreWinsOverAnExplicitlyConfiguredBasePath(): void
    {
        $services = new TestServiceLocator([
            'Omeka\File\Store' => $this->localStore('/srv/omeka/files'),
            'Config' => ['file_store' => ['local' => ['base_path' => '/wrong/path']]],
        ]);

        $this->assertSame('/srv/omeka/files', FilesPath::resolve($services));
    }

    public function testFallsBackToTheConfiguredBasePathForARemoteStore(): void
    {
        $services = new TestServiceLocator([
            'Omeka\File\Store' => $this->remoteStore(),
            'Config' => ['file_store' => ['local' => ['base_path' => '/srv/omeka/files']]],
        ]);

        $this->assertSame('/srv/omeka/files', FilesPath::resolve($services));
    }

    public function testTrimsATrailingSlashFromTheConfiguredBasePath(): void
    {
        $services = new TestServiceLocator([
            'Config' => ['file_store' => ['local' => ['base_path' => '/srv/omeka/files/']]],
        ]);

        $this->assertSame('/srv/omeka/files', FilesPath::resolve($services));
    }

    public function testIgnoresTheNullBasePathOmekaShipsByDefault(): void
    {
        // Omeka's own module.config.php ships this key as null and lets
        // LocalFactory substitute the default, so the raw config value is absent
        // on a stock install. Reading it first is what made the store branch --
        // the broken one -- the only branch that ever ran.
        $services = new TestServiceLocator([
            'Omeka\File\Store' => $this->localStore('/srv/omeka/files'),
            'Config' => ['file_store' => ['local' => ['base_path' => null]]],
        ]);

        $this->assertSame('/srv/omeka/files', FilesPath::resolve($services));
    }

    public function testIgnoresAStoreWhoseBasePathDoesNotResolve(): void
    {
        // Local's constructor does `realpath($basePath)`, which is false when
        // the directory does not exist yet; getLocalPath('') is then "/".
        // Accepting that would make every module path root-relative.
        $services = new TestServiceLocator([
            'Omeka\File\Store' => $this->localStore(false),
            'Config' => ['file_store' => ['local' => ['base_path' => '/srv/omeka/files']]],
        ]);

        $this->assertSame('/srv/omeka/files', FilesPath::resolve($services));
    }

    public function testFallsThroughTheNullBasePathToOmekaPath(): void
    {
        // The stock-install shape with no usable store: Omeka ships base_path
        // as null, so the config branch must decline rather than resolve to it.
        $services = new TestServiceLocator([
            'Omeka\File\Store' => $this->remoteStore(),
            'Config' => ['file_store' => ['local' => ['base_path' => null]]],
        ]);

        $expected = defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : '/var/www/html/files';
        $this->assertSame($expected, FilesPath::resolve($services));
    }

    public function testFallsBackToOmekaPathWhenNothingElseResolves(): void
    {
        $services = new TestServiceLocator([]);

        $expected = defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : '/var/www/html/files';
        $this->assertSame($expected, FilesPath::resolve($services));
    }

    public function testSurvivesAStoreThatThrows(): void
    {
        $store = new class {
            public function getLocalPath(string $storagePath): string
            {
                throw new \RuntimeException('store misconfigured');
            }
        };
        $services = new TestServiceLocator([
            'Omeka\File\Store' => $store,
            'Config' => ['file_store' => ['local' => ['base_path' => '/srv/omeka/files']]],
        ]);

        $this->assertSame('/srv/omeka/files', FilesPath::resolve($services));
    }

    public function testNeverReturnsATrailingSlash(): void
    {
        foreach (['/srv/files', '/srv/files/', '/'] as $basePath) {
            $services = new TestServiceLocator([
                'Omeka\File\Store' => $this->localStore($basePath),
                'Config' => ['file_store' => ['local' => ['base_path' => '/fallback']]],
            ]);

            $resolved = FilesPath::resolve($services);
            $this->assertStringEndsNotWith('/', $resolved, $basePath);
        }
    }
}
