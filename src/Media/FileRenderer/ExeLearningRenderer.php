<?php
declare(strict_types=1);

namespace ExeLearning\Media\FileRenderer;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\FileRenderer\RendererInterface as FileRendererInterface;
use Omeka\Media\Renderer\RendererInterface as MediaRendererInterface;
use Laminas\View\Renderer\PhpRenderer;
use ExeLearning\Service\ElpFileService;
use ExeLearning\Service\DownloadFormats;
use ExeLearning\Service\EditorBundle;
use ExeLearning\Service\EditPermission;
use ExeLearning\Service\IframeSandbox;

/**
 * Renderer for eXeLearning files.
 *
 * Displays the extracted HTML content in an iframe with an optional edit button.
 *
 * Registered under both `media_renderers` and `file_renderers`, and so
 * implements both interfaces. Omeka resolves a media's `renderer` column
 * through `Omeka\Media\Renderer\Manager`, whose `$instanceOf` is the
 * media-renderer interface; `file_renderers` is consulted only when that column
 * is the literal `file`, which covers media stored before this module claimed
 * them. The two interfaces declare the same method with no return type, so the
 * narrower `: string` below satisfies both.
 */
class ExeLearningRenderer implements FileRendererInterface, MediaRendererInterface
{
    /** @var ElpFileService */
    protected $elpService;

    /** @var \Laminas\Http\Request */
    protected $request;

    /**
     * @param ElpFileService $elpService
     * @param \Laminas\Http\Request $request
     */
    public function __construct(ElpFileService $elpService, \Laminas\Http\Request $request)
    {
        $this->elpService = $elpService;
        $this->request = $request;
    }

    /**
     * Render the eXeLearning media.
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @param array $options
     * @return string
     */
    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []): string
    {
        try {
            // Check if this is an eXeLearning file
            if (!$this->isExeLearningFile($media)) {
                return $this->renderFallback($view, $media);
            }

            $hash = $this->elpService->getMediaHash($media);
            $hasPreview = $this->elpService->hasPreview($media);

            if (!$hash || !$hasPreview) {
                return $this->renderFallback($view, $media);
            }
        } catch (\Throwable $e) {
            return $this->renderFallback($view, $media);
        }

        // Get configuration
        $config = $this->getConfig($view);

        // Relative path; JS constructs the full URL from window.location so the
        // playground SW scope prefix is always included (PHP cannot see it).
        $contentPath = $this->buildContentPath($hash, $media);

        // Load assets. The toolbar icons come from Omeka's own icon font, which
        // the admin and core themes already load; appending it again is a no-op
        // there (headLink drops duplicates) and covers themes that do not.
        $view->headLink()->appendStylesheet($view->assetUrl('css/iconfonts.css', 'Omeka'));
        $view->headLink()->appendStylesheet(
            $view->assetUrl('css/exelearning.css', 'ExeLearning')
        );
        $view->headScript()->appendFile(
            $view->assetUrl('js/exelearning-viewer.js', 'ExeLearning')
        );

        // In secure mode the content is opaque, so external embeds are promoted to this
        // page (no-op in legacy, where they already work inline). The embed policy
        // (open default | strict) mirrors mod_exelearning's embedmode setting (DEC-0061).
        IframeSandbox::enqueueEmbedRelay($view, $config['iframe_mode'], $config['embed_mode']);

        // Parent-side media host for the interactive-video iDevice in secure mode (DEC-0067):
        // completes the window.exeMediaBridge handshake and plays the provider video in a
        // modal via raw postMessage (no third-party SDK on this page). No-op in legacy.

        // Enqueue the download orchestrator only when the multi-format
        // button will actually be rendered.
        $downloadFormatIds = $this->getEnabledDownloadFormats($view);
        $showDownload = !empty($downloadFormatIds);
        if ($showDownload) {
            DownloadFormats::enqueueDownloadAssets($view);
        }

        $iframeId = 'exelearning-iframe-' . $media->id();
        $viewerId = 'exelearning-viewer-' . $media->id();

        // Build HTML
        $html = '<div class="exelearning-viewer" id="' . $viewerId . '" ';
        $html .= 'data-media-id="' . $media->id() . '">';

        // Toolbar
        $html .= '<div class="exelearning-toolbar">';
        $html .= '<span class="exelearning-title">' . $view->escapeHtml($media->displayTitle()) . '</span>';
        $html .= '<div class="exelearning-toolbar-actions">';

        // Download button — multi-format split-button when enabled, otherwise
        // a plain link to the original .elpx.
        if ($showDownload) {
            $variant = $this->isAdminRequest() ? 'admin' : 'default';
            $html .= DownloadFormats::renderSplitButton($view, $media, $downloadFormatIds, $variant);
        }

        // Open in a new window. href is filled in by the inline script below,
        // for the same base-path reason as the iframe src.
        $html .= '<a class="button exelearning-open-tab-btn" ';
        $html .= 'data-exe-content-path="' . $view->escapeHtmlAttr($contentPath) . '" ';
        $html .= 'target="_blank" rel="noopener noreferrer">';
        $html .= '<span class="o-icon-external" aria-hidden="true"></span>';
        $html .= '<span>' . $view->escapeHtml($view->translate('Open in new window')) . '</span>';
        $html .= '</a>';

        // Fullscreen button
        $html .= '<button type="button" class="button exelearning-fullscreen-btn" ';
        $html .= 'data-target="' . $iframeId . '">';
        $html .= '<span class="o-icon-exelearning-fullscreen" aria-hidden="true"></span>';
        $html .= '<span>' . $view->escapeHtml($view->translate('Fullscreen')) . '</span>';
        $html .= '</button>';

        // Offered to a user EditPermission allows, and only when the editor
        // bundle shipped with this package. Always on the admin media page; on
        // public pages unless the administrator turned it off. Last in the
        // toolbar, so it sits at the top right of the viewer.
        if ($this->isAdminRequest() || $this->isPublicEditEnabled($view)) {
            $html .= $this->renderEditButton($view, $media);
        }

        $html .= '</div>'; // toolbar-actions
        $html .= '</div>'; // toolbar

        // Iframe — src is set by inline JS so the playground SW scope prefix
        // from window.location is correctly prepended to the content path.
        //
        // Sandbox tokens come from the iframe-mode setting (default secure).
        // Secure = opaque origin (no allow-same-origin): package HTML/JS cannot
        // reach the Omeka page, its cookies or its DOM (ADR-39-02). Legacy
        // restores allow-same-origin only where an opaque iframe cannot be
        // served (the php-wasm Playground, whose service worker only intercepts
        // same-origin documents).
        //
        // Both modes carry `allow-downloads` so the package's own "Download
        // .elpx" button (download-source-file iDevice) can save the file it
        // builds. See ADR-63-01.
        $html .= '<iframe ';
        $html .= 'id="' . $iframeId . '" ';
        $html .= 'data-exe-content-path="' . $view->escapeHtmlAttr($contentPath) . '" ';
        $html .= 'class="exelearning-iframe" ';
        $html .= 'style="width: 100%; height: ' . (int) $config['height'] . 'px; border: none;" ';
        $html .= 'sandbox="' . IframeSandbox::tokens($config['iframe_mode']) . '" ';
        $html .= 'referrerpolicy="no-referrer" ';
        $html .= 'allowfullscreen>';
        $html .= '</iframe>';

        // Build content URLs from window.location so the playground service
        // worker scope prefix (/playground/{uuid}/php83/) is included — PHP
        // cannot see it, JS can. Scoped to this viewer so several eXeLearning
        // media on one page do not each rewrite the others' elements.
        $html .= '<script>(function(){';
        // Cut at the EARLIEST marker (same rule as the controllers'
        // extractBasePath): a site slug such as "admin" puts "/admin/" after "/s/".
        $html .= 'var h=window.location.href,b=h,e=-1;';
        $html .= '["/admin/","/s/","/api/"].forEach(function(m){var i=h.indexOf(m);if(i!==-1&&(e===-1||i<e))e=i;});';
        $html .= 'if(e!==-1)b=h.substring(0,e);';
        $html .= 'window.exelearningContentBase=b;';
        $html .= 'var r=document.getElementById("' . $viewerId . '");';
        $html .= 'if(!r)return;';
        $html .= 'r.querySelectorAll("[data-exe-content-path]").forEach(function(el){';
        $html .= 'var u=b+el.getAttribute("data-exe-content-path");';
        $html .= 'if(el.tagName==="IFRAME"){el.src=u;}else{el.href=u;}';
        $html .= '});';
        $html .= '})();</script>';

        $html .= '</div>'; // exelearning-viewer

        return $html;
    }

    /**
     * The "Edit in eXeLearning" button, or an empty string when editing is not
     * offered on this request.
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @return string
     */
    protected function renderEditButton(PhpRenderer $view, MediaRepresentation $media): string
    {
        if (!EditorBundle::isAvailable()) {
            return '';
        }

        try {
            if (!EditPermission::userCanEdit($media, $view->identity())) {
                return '';
            }
            $editUrl = $view->url('admin/exelearning-editor', ['action' => 'edit', 'id' => $media->id()]);
        } catch (\Throwable $e) {
            return '';
        }

        // The modal is built by exelearning-editor.js on first use, so the
        // button works wherever the media is rendered: the admin media page, a
        // public item or media page, or a site page block.
        $view->headLink()->appendStylesheet($view->assetUrl('css/exelearning-editor.css', 'ExeLearning'));
        $view->headScript()->appendFile($view->assetUrl('js/exelearning-editor.js', 'ExeLearning'));

        $html = '<button type="button" class="button exelearning-edit-btn" ';
        $html .= 'onclick="ExeLearningEditor.open(' . (int) $media->id();
        $html .= ", '" . $view->escapeJs($editUrl) . "')\">";
        $html .= '<span class="o-icon-edit" aria-hidden="true"></span>';
        $html .= '<span>' . $view->escapeHtml($view->translate('Edit in eXeLearning')) . '</span>';
        $html .= '</button>';
        $html .= '<script>window.exelearningEditorI18n=' . $this->editorI18n($view) . ';</script>';

        return $html;
    }

    /**
     * Labels for the editor modal, as a JSON object safe to inline in a script.
     */
    protected function editorI18n(PhpRenderer $view): string
    {
        return (string) json_encode([
            'title' => $view->translate('Edit eXeLearning File'),
            'saving' => $view->translate('Saving...'),
            'saveButton' => $view->translate('Save to Omeka'),
            'savingWait' => $view->translate('Please wait while the file is being saved.'),
            'unsavedChanges' => $view->translate('You have unsaved changes. Are you sure you want to close?'),
            'close' => $view->translate('Close'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * Render fallback for files without preview.
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @return string
     */
    protected function renderFallback(PhpRenderer $view, MediaRepresentation $media): string
    {
        $view->headLink()->appendStylesheet($view->assetUrl('css/iconfonts.css', 'Omeka'));
        $view->headLink()->appendStylesheet(
            $view->assetUrl('css/exelearning.css', 'ExeLearning')
        );

        $fileUrl = $media->originalUrl();
        $fileName = pathinfo($fileUrl, PATHINFO_BASENAME);

        $html = '<div class="exelearning-fallback">';
        $html .= '<div class="exelearning-icon"></div>';
        $html .= '<p class="exelearning-filename">' . $view->escapeHtml($fileName) . '</p>';
        $html .= '<a href="' . $view->escapeHtmlAttr($fileUrl) . '" ';
        $html .= 'class="button exelearning-download-btn" download>';
        $html .= '<span class="o-icon-exelearning-download" aria-hidden="true"></span> ';
        $html .= $view->translate('Download eXeLearning file');
        $html .= '</a>';
        $html .= '</div>';

        return $html;
    }



    /**
     * Whether teachers may reveal teacher-only content for this media.
     *
     * eXeLearning exports hide teacher content by default; it is revealed via
     * the ?exe-teacher=1 URL parameter. This per-media setting controls whether
     * the renderer is allowed to add that parameter for teacher viewers.
     */
    protected function isTeacherModeVisible(MediaRepresentation $media): bool
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
    protected function buildContentPath(string $hash, MediaRepresentation $media): string
    {
        $contentPath = '/exelearning/content/' . $hash . '/index.html';
        if ($this->isTeacherModeVisible($media)) {
            $contentPath .= '?exe-teacher=1';
        }

        return $contentPath;
    }

    protected function isExeLearningFile(MediaRepresentation $media): bool
    {
        return ElpFileService::isExeLearningMedia($media);
    }

    /**
     * Get viewer configuration.
     *
     * @param PhpRenderer $view
     * @return array
     */
    protected function getConfig(PhpRenderer $view): array
    {
        $defaults = [
            'height' => 600,
            'iframe_mode' => IframeSandbox::MODE_SECURE,
            'embed_mode' => IframeSandbox::EMBED_STRICT,
        ];

        try {
            $setting = $view->getHelperPluginManager()->get('setting');
            return [
                'height' => $setting('exelearning_viewer_height', $defaults['height']),
                'iframe_mode' => IframeSandbox::normalizeMode(
                    $setting('exelearning_iframe_mode', $defaults['iframe_mode'])
                ),
                // Raw setting value; IframeSandbox::embedMode() resolves it (strict default).
                'embed_mode' => $setting(IframeSandbox::EMBED_OPTION, IframeSandbox::EMBED_STRICT),
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Whether the administrator allows the edit button on public pages.
     * Defaults to on; the setting is stored as '1' or '0'.
     */
    protected function isPublicEditEnabled(PhpRenderer $view): bool
    {
        try {
            $setting = $view->getHelperPluginManager()->get('setting');
            return (bool) $setting('exelearning_public_edit', '1');
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Whether the current request targets the Omeka admin UI.
     */
    protected function isAdminRequest(): bool
    {
        try {
            $path = (string) $this->request->getUri()->getPath();
        } catch (\Throwable $e) {
            return false;
        }
        return strpos($path, '/admin/') !== false;
    }

    /**
     * Read the configured set of download formats from module settings.
     *
     * @return string[] Sanitized list, possibly empty when the admin opted to
     *                  hide the download button entirely.
     */
    protected function getEnabledDownloadFormats(PhpRenderer $view): array
    {
        try {
            $setting = $view->getHelperPluginManager()->get('setting');
            $stored = $setting('exelearning_download_formats', null);
            if ($stored === null) {
                return DownloadFormats::enabledByDefault();
            }
            if (is_string($stored)) {
                $decoded = json_decode($stored, true);
                if (is_array($decoded)) {
                    $stored = $decoded;
                }
            }
            return DownloadFormats::sanitize($stored);
        } catch (\Exception $e) {
            return DownloadFormats::enabledByDefault();
        }
    }
}
