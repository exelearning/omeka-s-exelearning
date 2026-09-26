<?php
declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * Resolves Omeka's files directory.
 *
 * Three factories need this path (ELPX extraction, uploaded styles and the
 * content proxy). They each carried their own copy of the resolution and drifted
 * apart, so two of them looked one directory too high and the third did not.
 *
 * The store is asked first because it is the only source that is always right:
 * Omeka ships `file_store.local.base_path` as `null` and lets
 * `Omeka\Service\File\Store\LocalFactory` substitute `OMEKA_PATH . '/files'`
 * itself, so the raw config value is absent on a stock install.
 */
class FilesPath
{
    /**
     * Absolute path to Omeka's files directory, without a trailing slash.
     *
     * @param \Psr\Container\ContainerInterface|object $services
     * @return string
     */
    public static function resolve($services): string
    {
        $fromStore = self::fromStore($services);
        if ($fromStore !== null) {
            return $fromStore;
        }

        $fromConfig = self::fromConfig($services);
        if ($fromConfig !== null) {
            return $fromConfig;
        }

        return defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : '/var/www/html/files';
    }

    /**
     * Ask the configured file store where it keeps local files.
     *
     * `Omeka\File\Store\Local::getLocalPath($p)` is `sprintf('%s/%s', $basePath, $p)`,
     * so `getLocalPath('')` returns "<basePath>/". Trim the trailing slash;
     * never `dirname()`, which drops the last real segment as well and is the
     * defect this class exists to prevent.
     *
     * A non-local store (S3 and similar) has no `getLocalPath()` at all, and a
     * `Local` store whose base path does not exist yet reports `false` from
     * `realpath()` and yields "/". Both cases return null so the caller falls
     * through to the remaining sources.
     *
     * @param \Psr\Container\ContainerInterface|object $services
     * @return string|null
     */
    private static function fromStore($services): ?string
    {
        try {
            $fileStore = $services->get('Omeka\File\Store');
        } catch (\Throwable $e) {
            return null;
        }

        if (!method_exists($fileStore, 'getLocalPath')) {
            return null;
        }

        try {
            $path = rtrim((string) $fileStore->getLocalPath(''), '/');
        } catch (\Throwable $e) {
            return null;
        }

        return $path !== '' ? $path : null;
    }

    /**
     * Fall back to an explicitly configured base path.
     *
     * @param \Psr\Container\ContainerInterface|object $services
     * @return string|null
     */
    private static function fromConfig($services): ?string
    {
        try {
            $config = $services->get('Config');
        } catch (\Throwable $e) {
            return null;
        }

        $configured = $config['file_store']['local']['base_path'] ?? null;
        if (!is_string($configured)) {
            return null;
        }

        $configured = rtrim($configured, '/');

        return $configured !== '' ? $configured : null;
    }
}
