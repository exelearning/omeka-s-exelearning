<?php

declare(strict_types=1);

namespace ExeLearningTest;

use PHPUnit\Framework\TestCase;

/**
 * Runs the real .distignore through rsync, exactly as `make package` does, so
 * a rule that strips part of the bundled editor fails here instead of in a
 * published release.
 */
class DistignoreTest extends TestCase
{
    private string $source;
    private string $target;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v rsync')) === '') {
            $this->markTestSkipped('rsync is required to apply .distignore like `make package`.');
        }
        $base = sys_get_temp_dir() . '/exelearning-distignore-' . bin2hex(random_bytes(4));
        $this->source = $base . '/src';
        $this->target = $base . '/out';
        mkdir($this->source, 0777, true);
        mkdir($this->target, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->source)) {
            exec('rm -rf ' . escapeshellarg(dirname($this->source)));
        }
    }

    public function testEditorDirectoriesNamedLikeExcludedRootFoldersShip(): void
    {
        $editorFiles = [
            'dist/static/files/perm/themes/base/flux/style.css',
            'dist/static/libs/tinymce_5/js/tinymce/themes/silver/theme.min.js',
            'dist/static/app/common/scorm/scorm12/vendor/pipwerks.js',
            'dist/static/app/common/edicuatex/menus/vendor/menu.js',
        ];
        $this->package(array_merge($editorFiles, ['Module.php']));

        foreach ($editorFiles as $file) {
            $this->assertFileExists($this->target . '/' . $file, "$file must ship in the release ZIP");
        }
    }

    public function testRootDevelopmentFoldersStayOut(): void
    {
        $devFiles = [
            'themes/sample/theme.ini',
            'vendor/autoload.php',
            'test/bootstrap.php',
            'scripts/check.sh',
            'docs/index.md',
            'artifacts/report.txt',
            // Only one spelling: the two collide on case-insensitive file systems.
            'claude.md',
            'AGENTS.md',
            '.env.dist',
            '.editor-version',
            'architecture-records.json',
            'data/fixtures/really-simple-test-project.elpx',
            'data/sample_data.csv',
            'data/sample_3d_data.csv',
            'data/seed-exelearning.sh',
            'language/es.po',
            'language/template.pot',
            'analysis/report.md',
            'legacy/old.php',
        ];
        $this->package(array_merge($devFiles, ['Module.php']));

        $this->assertFileExists($this->target . '/Module.php');
        foreach ($devFiles as $file) {
            $this->assertFileDoesNotExist($this->target . '/' . $file, "$file must not ship in the release ZIP");
        }
    }

    public function testRuntimeFilesShip(): void
    {
        $runtimeFiles = [
            'Module.php',
            'config/module.ini',
            'config/module.config.php',
            'src/Service/FilesPath.php',
            'view/exelearning/editor-bootstrap.phtml',
            'asset/js/exelearning-viewer.js',
            'language/es.mo',
            'data/nginx-exelearning.conf',
            'README.md',
            'LICENSE',
        ];
        $this->package($runtimeFiles);

        foreach ($runtimeFiles as $file) {
            $this->assertFileExists($this->target . '/' . $file, "$file must ship in the release ZIP");
        }
    }

    /**
     * @param string[] $files Paths relative to the module root.
     */
    private function package(array $files): void
    {
        foreach ($files as $file) {
            $path = $this->source . '/' . $file;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, 'x');
        }
        $distignore = dirname(__DIR__, 2) . '/.distignore';
        exec(
            sprintf(
                'rsync -a --exclude-from=%s %s/ %s/ 2>&1',
                escapeshellarg($distignore),
                escapeshellarg($this->source),
                escapeshellarg($this->target)
            ),
            $output,
            $status
        );
        $this->assertSame(0, $status, implode("\n", $output));
    }
}
