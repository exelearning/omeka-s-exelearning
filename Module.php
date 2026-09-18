<?php
declare(strict_types=1);

namespace ExeLearning;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Mvc\Controller\Plugin\Messenger;
use Omeka\Stdlib\Message;
use ExeLearning\Form\ConfigForm;
use ExeLearning\Service\DownloadFormats;
use ExeLearning\Service\EditorBundle;

/**
 * Main class for the ExeLearning module.
 *
 * Allows uploading, viewing and editing eXeLearning content (.elpx files) in Omeka S.
 */
class Module extends AbstractModule
{
    /** @var string */
    const NAMESPACE = __NAMESPACE__;

    /** Setting holding the whitelist entries this module added at install. */
    const SETTING_WHITELIST_ADDITIONS = 'exelearning_whitelist_additions';

    /**
     * Omeka S 4's resource-page block manager. Absent in Omeka S 3, which is
     * how this module tells the two apart without reading a version string.
     */
    const SERVICE_RESOURCE_PAGE_BLOCKS = 'Omeka\\ResourcePageBlockLayoutManager';

    /** Core's block layout that calls $media->render() on an item page. */
    const MEDIA_EMBEDS_BLOCK = 'mediaEmbeds';

    /**
     * Upload types earlier releases added that this version withdraws.
     *
     * See dropLegacyWhitelistAdditions() for why these are removed on upgrade
     * even though their provenance was never recorded.
     */
    const LEGACY_WHITELIST_ADDITIONS = ['application/octet-stream'];

    /**
     * What an .elpx upload needs, and nothing else.
     *
     * `application/octet-stream` used to be whitelisted here. It matches any
     * unidentified binary, so adding it let anyone who can add media upload
     * arbitrary files to the whole installation — far beyond what this module
     * needs, and it was never taken back on uninstall.
     */
    const WHITELIST_ADDITIONS = [
        'media_type_whitelist' => ['application/zip', 'application/x-zip-compressed'],
        'extension_whitelist' => ['elpx'],
    ];

    /**
     * Retrieve the configuration array.
     *
     * @return array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Execute logic when the module is installed.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $messenger = new Messenger();
        $message = new Message("ExeLearning module installed.");
        $messenger->addSuccess($message);

        // Register eXeLearning file types
        $this->updateWhitelist($serviceLocator);
    }

    /**
     * Register eXeLearning file types in Omeka settings.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    protected function updateWhitelist(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');

        $added = [];
        foreach (self::WHITELIST_ADDITIONS as $key => $values) {
            $added[$key] = $this->addToWhitelist($settings, $key, $values);
        }

        // Remember exactly what this module contributed, so uninstall can take
        // back its own additions without removing entries the site already had.
        $settings->set(self::SETTING_WHITELIST_ADDITIONS, $added);
    }

    /**
     * Add missing values to one Omeka whitelist, returning the ones added.
     *
     * @param object $settings
     * @param string $key
     * @param string[] $values
     * @return string[]
     */
    protected function addToWhitelist($settings, string $key, array $values): array
    {
        $whitelist = array_values((array) $settings->get($key, []));

        // An empty whitelist means "allow nothing" to Omeka's file validator,
        // never "not configured". Writing one back would break every upload on
        // the site, so leave an unconfigured key alone.
        if (!$whitelist) {
            return [];
        }

        $added = array_values(array_diff($values, $whitelist));
        if (!$added) {
            return [];
        }

        $settings->set($key, array_values(array_merge($whitelist, $added)));

        return $added;
    }

    /**
     * Remove this module's own whitelist additions.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    protected function revertWhitelist(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');

        $added = $settings->get(self::SETTING_WHITELIST_ADDITIONS, []);
        if (!is_array($added)) {
            $added = [];
        }

        foreach ($added as $key => $values) {
            if (!is_string($key) || !is_array($values) || !$values) {
                continue;
            }
            $whitelist = array_values((array) $settings->get($key, []));
            if (!$whitelist) {
                continue;
            }
            $settings->set($key, array_values(array_diff($whitelist, $values)));
        }

        $settings->delete(self::SETTING_WHITELIST_ADDITIONS);
    }

    /**
     * Execute logic when the module is uninstalled.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $messenger = new Messenger();
        $message = new Message("ExeLearning module uninstalled.");
        $messenger->addWarning($message);

        $this->revertWhitelist($serviceLocator);
        $this->removeEditorInstallerSettings($serviceLocator);
    }

    /**
     * Execute logic when the module is upgraded.
     *
     * @param string $oldVersion
     * @param string $newVersion
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        $this->removeEditorInstallerSettings($serviceLocator);
        $this->dropLegacyWhitelistAdditions($serviceLocator);
    }

    /**
     * Withdraw the over-broad upload type earlier releases added.
     *
     * Every released version up to and including 4.0.5 added
     * `application/octet-stream` to the installation-wide
     * `media_type_whitelist` and never took it back, so upgrading alone would
     * leave it there forever and the narrowing in this version would only ever
     * reach fresh installations.
     *
     * Those releases recorded no provenance, so whether the site already
     * allowed the type before installing this module is genuinely unknowable.
     * Removing it anyway is a deliberate hardening call, and it is defensible
     * because the type is not an Omeka default, matches any unidentified binary,
     * and is not needed for `.elpx`. An administrator who wants it can add it
     * back in Omeka's own settings, where it is one checkbox and where the
     * decision is recorded as theirs.
     *
     * Only this one value is touched. `application/zip`,
     * `application/x-zip-compressed`, the `zip` and `elpx` extensions and every
     * unrelated entry are left exactly as they are: withdrawing the renderer
     * aliases is what stops this module claiming ordinary ZIP files, and doing
     * it in the whitelist as well would break ZIP uploads for the rest of the
     * installation.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    protected function dropLegacyWhitelistAdditions(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');

        $whitelist = array_values((array) $settings->get('media_type_whitelist', []));

        // An empty whitelist means "allow nothing" to Omeka's file validator,
        // never "not configured". Leave an unconfigured list alone.
        if (!$whitelist) {
            return;
        }

        $cleaned = array_values(array_diff($whitelist, self::LEGACY_WHITELIST_ADDITIONS));
        if ($cleaned !== $whitelist) {
            $settings->set('media_type_whitelist', $cleaned);
        }

        // Ownership of pre-bookkeeping values cannot be proven, so the value is
        // withdrawn without ever being claimed in
        // SETTING_WHITELIST_ADDITIONS -- that record stays a log of what this
        // version's install() actually added, and uninstall() must not start
        // subtracting entries the module never demonstrably contributed.
    }

    /**
     * Drop the settings left behind by the removed runtime editor installer.
     *
     * The embedded editor became a release artifact bundled inside the module
     * package (ADR-28-01), so the runtime installer and its bookkeeping are
     * gone. Deleting the keys is idempotent and safe to run on every upgrade.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    protected function removeEditorInstallerSettings(ServiceLocatorInterface $serviceLocator): void
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $legacyKeys = [
            'exelearning_editor_installed_version',
            'exelearning_editor_installed_at',
            'exelearning_editor_install_phase',
            'exelearning_editor_install_message',
            'exelearning_editor_install_target_version',
            'exelearning_editor_install_started_at',
            'exelearning_editor_install_success',
            'exelearning_editor_install_error',
        ];
        foreach ($legacyKeys as $key) {
            $settings->delete($key);
        }
    }

    /**
     * Attach event listeners.
     *
     * @param SharedEventManagerInterface $sharedEventManager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Listen for media hydration to set the correct renderer
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.post',
            [$this, 'handleMediaHydrate']
        );

        // Listen for media creation to process eXeLearning files
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.create.post',
            [$this, 'handleMediaCreate']
        );

        // Listen for media deletion to clean up extracted content
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.delete.pre',
            [$this, 'handleMediaDelete']
        );

        // Inject iframe viewer in admin media show page
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.show.after',
            [$this, 'handleAdminMediaShow']
        );

        // Add thumbnail script to admin pages
        $sharedEventManager->attach(
            '*',
            'view.layout',
            [$this, 'handleViewLayout']
        );

        // Compatibility shim for item pages that never call $media->render().
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'handlePublicItemShow']
        );

        // Expose screenshot URL through the standard Omeka media JSON-LD API.
        $sharedEventManager->attach(
            'Omeka\Api\Representation\MediaRepresentation',
            'rep.resource.json',
            [$this, 'handleMediaJsonLd']
        );
    }

    /**
     * Add eXeLearning-specific fields (notably the bundled screenshot URL)
     * to the JSON-LD output of media representations served by the API.
     *
     * @param Event $event
     */
    public function handleMediaJsonLd(Event $event)
    {
        $media = $event->getTarget();
        if (!$media || !$this->isExeLearningFile($media)) {
            return;
        }

        $services = $this->getServiceLocator();
        $elpService = $services->get(Service\ElpFileService::class);

        $hash = $elpService->getMediaHash($media);
        if (!$hash) {
            return;
        }

        $jsonLd = $event->getParam('jsonLd', []);

        if ($elpService->hasScreenshot($media)) {
            $jsonLd['o-module-exelearning:screenshot'] =
                '/exelearning/content/' . $hash . '/' . Service\ElpFileService::SCREENSHOT_FILENAME;
        }
        if ($elpService->hasPreview($media)) {
            $jsonLd['o-module-exelearning:content'] =
                '/exelearning/content/' . $hash . '/index.html';
        }

        $event->setParam('jsonLd', $jsonLd);
    }

    /**
     * Render eXeLearning media on item pages that core would otherwise skip.
     *
     * The viewer itself lives in ExeLearningRenderer, reached through
     * $media->render(); this hook only decides whether the page is one where
     * core never calls it. See itemPageEmbedsMedia() for how that is
     * established -- on Omeka S 3 the `item_media_embed` site setting, which
     * defaults to off, and on Omeka S 4 whether `mediaEmbeds` survives in the
     * site's resolved resource-page block configuration. On such a page the
     * item would otherwise show no viewer at all; everywhere else this stays
     * silent so the viewer is never rendered twice.
     *
     * Read-only by contract: no extraction, no media data written. Repairing an
     * unprocessed package belongs to the admin view, not to a public GET.
     *
     * @param Event $event
     */
    public function handlePublicItemShow(Event $event)
    {
        $view = $event->getTarget();
        $item = $view->item;

        if (!$item || $this->itemPageEmbedsMedia($view)) {
            return;
        }

        foreach ($item->media() as $media) {
            if ($this->isExeLearningFile($media)) {
                echo $media->render();
            }
        }
    }

    /**
     * Whether this item page already renders its media itself.
     *
     * Omeka S 4 and Omeka S 3 answer this in completely different ways, and the
     * two services below are the capability probe: the resource-page block
     * manager exists only in S4, so its presence identifies the core version
     * without comparing version strings.
     *
     * @param mixed $view
     * @return bool
     */
    protected function itemPageEmbedsMedia($view): bool
    {
        try {
            $services = $this->getServiceLocator();

            if (!$services->has(self::SERVICE_RESOURCE_PAGE_BLOCKS)) {
                // Omeka S 3 has no resource page blocks; one site setting
                // decides whether item pages embed media at all.
                return (bool) $view->siteSetting('item_media_embed', false);
            }

            return $this->itemPageHasMediaEmbedsBlock($services);
        } catch (\Throwable $e) {
            // Without a usable view or container we cannot tell; rendering
            // nothing is safer than rendering the viewer twice.
            return true;
        }
    }

    /**
     * Whether the current site's resolved item page includes `mediaEmbeds`.
     *
     * The block being registered is not the question -- it always is. The
     * question is whether this site's *resolved* configuration still lists it,
     * because `Manager::getResourcePageBlocks()` prefers the site
     * administrator's saved blocks, then the theme's `resource_page_blocks`
     * from its INI file, and only falls back to
     * `RESOURCE_PAGE_BLOCKS_DEFAULT` when neither is set. An administrator or a
     * theme may drop `mediaEmbeds`, and then core never calls
     * `$media->render()` on the item page.
     *
     * This mirrors what `Omeka\Service\ViewHelper\ResourcePageBlocksFactory`
     * does to build the helper, so it reads the same configuration core renders
     * from -- without rendering anything or inspecting generated markup.
     *
     * @param mixed $services
     * @return bool
     */
    protected function itemPageHasMediaEmbedsBlock($services): bool
    {
        $theme = $services->get('Omeka\Site\ThemeManager')->getCurrentTheme();
        $blocks = $services->get(self::SERVICE_RESOURCE_PAGE_BLOCKS)->getResourcePageBlocks($theme);

        // Blocks are grouped by region, and a theme may declare regions beyond
        // "main", so any region carrying the block counts.
        foreach ($blocks['items'] ?? [] as $regionBlocks) {
            if (is_array($regionBlocks) && in_array(self::MEDIA_EMBEDS_BLOCK, $regionBlocks, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle admin media show view.
     *
     * The viewer is rendered by ExeLearningRenderer, which core's admin media
     * template reaches through $media->render() before this event fires. What is
     * left for the hook is the editor modal the toolbar's edit button opens, and
     * the extraction repair path: an .elpx uploaded while the files directory
     * resolved to the wrong place never got processed, and opening it in admin
     * is where that is noticed and fixed.
     *
     * @param Event $event
     */
    public function handleAdminMediaShow(Event $event)
    {
        $view = $event->getTarget();
        $media = $view->resource;

        if (!$this->isExeLearningFile($media)) {
            return;
        }

        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $elpService = $services->get(Service\ElpFileService::class);

        // $media is a snapshot taken before this request, so track the outcome
        // here rather than re-reading it after a write.
        $processingError = $elpService->getProcessingError($media);

        // Auto-process once. Gate on the processed marker (not hasPreview), and
        // skip a media whose last attempt failed: without that, an unreadable
        // file is re-extracted and re-logged on every single render, forever.
        if (!$elpService->isProcessed($media) && $processingError === null) {
            $logger->info(sprintf('[ExeLearning] Auto-processing media %d on view', $media->id()));
            try {
                $result = $elpService->processUploadedFile($media);
                $logger->info(sprintf(
                    '[ExeLearning] Auto-process complete: hash=%s, hasPreview=%s',
                    $result['hash'],
                    $result['hasPreview'] ? 'yes' : 'no'
                ));
            } catch (\Throwable $e) {
                $processingError = $e->getMessage();
                $logger->err(sprintf('[ExeLearning] Auto-process failed: %s', $processingError));
            }
        }

        echo $view->partial('exelearning/admin/media-show', [
            'media' => $media,
            'processingError' => $processingError,
        ]);
    }

    /**
     * Handle view layout - add thumbnail replacement script to admin pages.
     *
     * @param Event $event
     */
    public function handleViewLayout(Event $event)
    {
        $view = $event->getTarget();

        // Only add to admin pages
        $routeMatch = $this->getServiceLocator()->get('Application')->getMvcEvent()->getRouteMatch();
        if (!$routeMatch) {
            return;
        }

        $routeName = $routeMatch->getMatchedRouteName();
        if (strpos($routeName, 'admin') !== 0) {
            return;
        }

        // Add the thumbnail URL as a data attribute and load the script
        $basePath = $view->basePath();
        $thumbnailUrl = $basePath . '/modules/ExeLearning/asset/thumbnails/elpx.png';
        $scriptUrl = $basePath . '/modules/ExeLearning/asset/js/exelearning-thumbnail.js';

        // Get item IDs that contain eXeLearning media
        $exeItemIds = $this->getExeLearningItemIds();

        $view->headScript()->appendFile($scriptUrl);
        $view->headScript()->appendScript(
            'document.documentElement.setAttribute("data-exelearning-thumbnail", "' . $thumbnailUrl . '");' .
            'window.exelearningItemIds = ' . json_encode($exeItemIds) . ';'
        );

        // Robust injection of Teacher Mode setting into admin media edit form.
        $label = $view->escapeJs($view->translate('Teacher Mode'));
        $visibleLabel = $view->escapeJs($view->translate('Show teacher layer selector'));
        $help = $view->escapeJs($view->translate('If disabled, the teacher layer selector is hidden in the embedded eXeLearning content.'));
        $apiBase = $basePath . '/api/exelearning';
        $view->headScript()->appendScript(<<<JS
(function() {
    function isExeFilename(filename) {
        if (!filename) {
            return false;
        }
        var lower = String(filename).toLowerCase();
        return lower.endsWith('.elpx');
    }

    function getMediaIdFromPath() {
        var match = window.location.pathname.match(/\\/admin\\/media\\/(\\d+)/);
        return match ? match[1] : null;
    }

    function injectField(checked) {
        if (document.getElementById('exelearning-teacher-mode-field')) {
            return;
        }
        var form = document.querySelector('form#edit-media');
        if (!form) {
            return;
        }

        var target = document.querySelector('#advanced-settings') ||
            document.querySelector('#resource-values') ||
            form;

        var wrapper = document.createElement('div');
        wrapper.className = 'field';
        wrapper.id = 'exelearning-teacher-mode-field';
        wrapper.innerHTML =
            '<div class="field-meta">' +
                '<label for="exelearning-teacher-mode-visible">{$label}</label>' +
            '</div>' +
            '<div class="inputs">' +
                '<input type="hidden" name="exelearning_teacher_mode_visible" value="0">' +
                '<label>' +
                    '<input type="checkbox" id="exelearning-teacher-mode-visible" name="exelearning_teacher_mode_visible" value="1" ' + (checked ? 'checked' : '') + '> {$visibleLabel}' +
                '</label>' +
                '<p class="field-description">{$help}</p>' +
            '</div>';

        target.appendChild(wrapper);
    }

    function init() {
        var form = document.querySelector('form#edit-media');
        if (!form) {
            return;
        }

        var mediaId = getMediaIdFromPath();
        if (!mediaId) {
            return;
        }

        fetch('{$apiBase}/elp-data/' + mediaId, {credentials: 'same-origin'})
            .then(function(resp) {
                if (!resp.ok) {
                    throw new Error('data endpoint unavailable');
                }
                return resp.json();
            })
            .then(function(data) {
                if (!data || !data.success || !isExeFilename(data.filename)) {
                    return;
                }
                injectField(data.teacherModeVisible === true);
            })
            .catch(function() {
                // Fallback: use current page title as heuristic.
                var title = document.querySelector('h1 .title');
                if (title && isExeFilename(title.textContent || '')) {
                    injectField(false);
                }
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS
        );
    }

    /**
     * Get IDs of items that contain eXeLearning media.
     *
     * @return array
     */
    protected function getExeLearningItemIds(): array
    {
        $services = $this->getServiceLocator();

        try {
            $connection = $services->get('Omeka\Connection');

            // Query for item IDs that have media with .elpx extension
            $sql = "SELECT DISTINCT m.item_id
                    FROM media m
                    WHERE m.source LIKE '%.elpx'
                       OR m.source LIKE '%.elp'";

            $stmt = $connection->query($sql);
            $results = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            return array_map('intval', $results);
        } catch (\Throwable $e) {
            $logger = $services->get('Omeka\Logger');
            $logger->err(sprintf('[ExeLearning] Failed to get item IDs: %s', $e->getMessage()));
            return [];
        }
    }

    /**
     * Handle media hydration - set the correct renderer for eXeLearning files.
     *
     * @param Event $event
     */
    public function handleMediaHydrate(Event $event)
    {
        $entity = $event->getParam('entity');

        // Get the filename from the entity
        $filename = null;
        if (method_exists($entity, 'getFilename')) {
            $filename = $entity->getFilename();
        } elseif (method_exists($entity, 'getSource')) {
            $filename = $entity->getSource();
        }

        if (!$filename) {
            return;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Set our renderer for eXeLearning files. Only `.elpx`: stamping every
        // uploaded `.zip` with this module's renderer name took over a file type
        // belonging to the rest of the installation.
        if ($extension === 'elpx') {
            if (method_exists($entity, 'setRenderer')) {
                $entity->setRenderer('exelearning_renderer');
            }

            // Saving the media is the retry. A failed extraction is remembered
            // so the view hooks stop reattempting it on every render, which
            // would otherwise make the marker permanent; clearing it here gives
            // an administrator who has fixed the underlying problem -- file
            // permissions, a re-upload, a corrected files directory -- an
            // explicit way to arm one more attempt, on a write request rather
            // than a GET. The next admin view of the media performs it.
            $this->clearProcessingError($entity);

            // Persist custom eXeLearning media settings from admin edit form.
            $request = $event->getParam('request');
            if ($request && method_exists($request, 'getContent')) {
                $content = $request->getContent();
                if (is_array($content) && array_key_exists('exelearning_teacher_mode_visible', $content)) {
                    $rawValue = $content['exelearning_teacher_mode_visible'];
                    if (is_array($rawValue)) {
                        $rawValue = end($rawValue);
                    }
                    $visible = !in_array((string) $rawValue, ['0', 'false', 'no', 'off', ''], true);

                    if (method_exists($entity, 'getData') && method_exists($entity, 'setData')) {
                        $data = $entity->getData() ?? [];
                        $data['exelearning_teacher_mode_visible'] = $visible ? '1' : '0';
                        $entity->setData($data);
                    }
                }
            }
        }
    }

    /**
     * Forget a previous extraction failure, so the next admin view retries.
     *
     * @param mixed $entity
     */
    protected function clearProcessingError($entity): void
    {
        if (!method_exists($entity, 'getData') || !method_exists($entity, 'setData')) {
            return;
        }

        $data = $entity->getData() ?? [];
        if (!is_array($data) || !array_key_exists('exelearning_process_error', $data)) {
            return;
        }

        unset($data['exelearning_process_error']);
        $entity->setData($data);
    }

    /**
     * Handle media creation event.
     * Process uploaded eXeLearning files.
     *
     * @param Event $event
     */
    public function handleMediaCreate(Event $event)
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        $response = $event->getParam('response');
        $entity = $response->getContent();

        // The api.create.post event provides an Entity, not a Representation.
        // Convert to Representation via API read for consistent method calls.
        $mediaId = $entity->getId();
        $logger->info(sprintf('ExeLearning: handleMediaCreate called for media %d', $mediaId));

        try {
            $media = $services->get('Omeka\ApiManager')
                ->read('media', $mediaId)->getContent();
        } catch (\Throwable $e) {
            $logger->err(sprintf(
                'ExeLearning: Could not load media representation for %d: %s',
                $mediaId,
                $e->getMessage()
            ));
            return;
        }

        $logger->info(sprintf('ExeLearning: Media filename: %s', $media->filename() ?? 'null'));

        // Check if this is an eXeLearning file
        if (!$this->isExeLearningFile($media)) {
            $logger->info('ExeLearning: Not an eXeLearning file, skipping');
            return;
        }

        $logger->info('ExeLearning: Processing eXeLearning file');

        try {
            $elpService = $services->get(Service\ElpFileService::class);
            $result = $elpService->processUploadedFile($media);
            $logger->info(sprintf(
                'ExeLearning: File processed successfully. Hash: %s, HasPreview: %s',
                $result['hash'],
                $result['hasPreview'] ? 'yes' : 'no'
            ));
        } catch (\Throwable $e) {
            $logger->err(sprintf(
                'ExeLearning: Failed to process uploaded file for media %d: %s',
                $mediaId,
                $e->getMessage()
            ));
            $logger->err('ExeLearning: Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Handle media deletion event.
     * Clean up extracted content.
     *
     * @param Event $event
     */
    public function handleMediaDelete(Event $event)
    {
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');

        try {
            // Get the entity directly from the event, not via API
            $entity = $event->getParam('entity');
            if (!$entity) {
                return;
            }

            $data = $entity->getData();
            $hash = is_array($data) ? ($data['exelearning_extracted_hash'] ?? null) : null;
            if (!$hash) {
                return;
            }

            // Delegate to the service: it owns the extraction root, which is
            // derived from Omeka's files directory. This used to delete
            // <module>/data/exelearning/<hash>, a path nothing ever wrote to, so
            // every deleted media left its extracted package on disk.
            $services->get(Service\ElpFileService::class)->cleanupMediaByHash($hash);
            $logger->info(sprintf('ExeLearning: Cleaned up extracted content for media %d', $entity->getId()));
        } catch (\Throwable $e) {
            $logger->err(sprintf(
                'ExeLearning: Failed to cleanup media: %s',
                $e->getMessage()
            ));
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir
     */
    protected function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Check if a media item is an eXeLearning file.
     *
     * @param mixed $media
     * @return bool
     */
    /**
     * Build an absolute content proxy URL for the given hash.
     *
     * Derives the base path from the actual request URI path so that the
     * playground prefix (/playground/{uuid}/php83/) is correctly included
     * even in PHP-WASM environments where $_SERVER['SCRIPT_NAME'] does not
     * contain it (making getBasePath() unreliable).
     */
    protected function buildContentUrl(string $hash): string
    {
        $request = $this->getServiceLocator()->get('Request');
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $port = $uri->getPort();
        $serverUrl = $scheme . '://' . $uri->getHost();
        if ($port && !(($scheme === 'http' && $port == 80) || ($scheme === 'https' && $port == 443))) {
            $serverUrl .= ':' . $port;
        }
        $basePath = $this->extractBasePath($uri->getPath());
        return $serverUrl . $basePath . '/exelearning/content/' . $hash . '/index.html';
    }

    /**
     * Derive the Omeka base path from the actual request URI path.
     *
     * Strips everything from the first known Omeka route segment onward
     * (/admin/, /s/, /api/). This is reliable in PHP-WASM playgrounds where
     * the full URL path (e.g. /playground/{uuid}/php83/admin/...) is preserved
     * in the request URI even when $_SERVER['SCRIPT_NAME'] is not.
     */
    protected function extractBasePath(string $uriPath): string
    {
        foreach (['/admin/', '/s/', '/api/'] as $marker) {
            $pos = strpos($uriPath, $marker);
            if ($pos !== false) {
                return substr($uriPath, 0, $pos);
            }
        }
        return '';
    }

    /**
     * Check whether teachers may reveal teacher-only content for this media.
     *
     * eXeLearning exports hide teacher content by default; it is revealed via
     * the ?exe-teacher=1 URL parameter. This per-media setting controls whether
     * the module is allowed to add that parameter for teacher viewers.
     */
    protected function isTeacherModeVisible($media): bool
    {
        $data = $media->mediaData();
        if (!isset($data['exelearning_teacher_mode_visible'])) {
            return false;
        }

        $value = $data['exelearning_teacher_mode_visible'];
        return !in_array((string) $value, ['0', 'false', 'no'], true);
    }

    /**
     * Build the relative content path for a media. The per-media "Show teacher layer
     * selector" setting alone controls it: when on, the package's ?exe-teacher=1
     * parameter is appended so the teacher-layer selector is available to every viewer;
     * otherwise the default (student) view is served with no parameter.
     */
    protected function buildContentPath(string $hash, $media): string
    {
        $contentPath = '/exelearning/content/' . $hash . '/index.html';
        if ($this->isTeacherModeVisible($media)) {
            $contentPath .= '?exe-teacher=1';
        }

        return $contentPath;
    }

    protected function isExeLearningFile($media): bool
    {
        return Service\ElpFileService::isExeLearningMedia($media);
    }

    /**
     * Get the configuration form for this module.
     *
     * @param PhpRenderer $renderer
     * @return string
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $form = new ConfigForm;
        $form->init();

        $storedFormats = $settings->get('exelearning_download_formats', null);
        if (is_string($storedFormats)) {
            $decoded = json_decode($storedFormats, true);
            $storedFormats = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($storedFormats)) {
            $storedFormats = DownloadFormats::enabledByDefault();
        }

        $form->setData([
            'exelearning_viewer_height' => $settings->get('exelearning_viewer_height', 600),
            'exelearning_download_formats' => DownloadFormats::sanitize($storedFormats),
        ]);

        $formHtml = $renderer->formCollection($form, false);

        return $this->renderEditorStatusSection($renderer)
            . $this->renderStylesSection($renderer)
            . $formHtml;
    }

    /**
     * Render a short section pointing to the dedicated Styles admin page.
     */
    protected function renderStylesSection(PhpRenderer $renderer): string
    {
        $translate = function ($text) use ($renderer) {
            return $renderer->translate($text);
        };
        $stylesUrl = $renderer->url('admin/exelearning-styles');
        $html = '<fieldset id="exelearning-styles-link">';
        $html .= '<legend>' . $renderer->escapeHtml($translate('Styles')) . '</legend>'; // @translate
        $html .= '<div class="field"><div class="field-meta">';
        $html .= '<label>' . $renderer->escapeHtml($translate('Style management')) . '</label>'; // @translate
        $html .= '</div><div class="inputs">';
        $html .= '<a class="button" href="' . $renderer->escapeHtmlAttr($stylesUrl) . '">';
        $html .= $renderer->escapeHtml($translate('Open styles page'));  // @translate
        $html .= '</a>';
        $html .= '<p class="explanation">';
        $html .= $renderer->escapeHtml($translate( // @translate
            'Upload eXeLearning style packages, enable/disable built-in styles, '
            . 'and control the "Block user-imported styles" policy from a dedicated page.'
        ));
        $html .= '</p>';
        $html .= '</div></div></fieldset>';
        return $html;
    }

    /**
     * Warn when the bundled editor is missing.
     *
     * The editor ships inside the module package (ADR-28-01), so in a normal
     * installation there is nothing to show or do here; the section only
     * appears when the bundle is absent (e.g. a development checkout that has
     * not run `make build-editor`).
     *
     * @param PhpRenderer $renderer
     * @return string
     */
    protected function renderEditorStatusSection(PhpRenderer $renderer): string
    {
        if (EditorBundle::isAvailable()) {
            return '';
        }

        $translate = function ($text) use ($renderer) {
            return $renderer->translate($text);
        };

        $html = '<fieldset id="exelearning-editor-status">';
        $html .= '<legend>' . $renderer->escapeHtml($translate('Embedded Editor')) . '</legend>'; // @translate
        $html .= '<div class="field"><div class="field-meta"></div><div class="inputs">';
        $html .= '<p><span style="color: #dc3232;">&#10007;</span> ';
        $html .= $renderer->escapeHtml($translate( // @translate
            'This installation does not include the embedded editor, so editing eXeLearning content is disabled.'
            . ' Official release packages include it; development checkouts must build it with "make build-editor".'
        ));
        $html .= '</p>';
        $html .= '</div></div>';
        $html .= '</fieldset>';

        return $html;
    }

    /**
     * Handle the configuration form submission.
     *
     * @param AbstractController $controller
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $config = $controller->params()->fromPost();

        $settings->set(
            'exelearning_viewer_height',
            (int) ($config['exelearning_viewer_height'] ?? 600)
        );
        $settings->set(
            'exelearning_download_formats',
            DownloadFormats::sanitize($config['exelearning_download_formats'] ?? [])
        );
    }
}
