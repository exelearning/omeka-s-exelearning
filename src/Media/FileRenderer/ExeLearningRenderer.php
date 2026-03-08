<?php
declare(strict_types=1);

namespace ExeLearning\Media\FileRenderer;

use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\FileRenderer\RendererInterface;
use Laminas\View\Renderer\PhpRenderer;
use ExeLearning\Service\ElpFileService;

/**
 * Renderer for eXeLearning files.
 *
 * Displays the extracted HTML content in an iframe with an optional edit button.
 */
class ExeLearningRenderer implements RendererInterface
{
    /** @var ElpFileService */
    protected $elpService;

    /**
     * @param ElpFileService $elpService
     */
    public function __construct(ElpFileService $elpService)
    {
        $this->elpService = $elpService;
    }

    /**
     * Render the eXeLearning media.
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @param array $options
     * @return string
     *
     * @codeCoverageIgnore
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
        } catch (\Exception $e) {
            return $this->renderFallback($view, $media);
        }

        // Get configuration
        $config = $this->getConfig($view);

        // Build secure preview URL via proxy controller
        $previewUrl = $view->url('exelearning-content', ['hash' => $hash, 'file' => 'index.html']);
        if (!$this->isTeacherModeVisible($media)) {
            $previewUrl .= '?teacher_mode_visible=0';
        }

        // Load assets
        $view->headLink()->appendStylesheet(
            $view->assetUrl('css/exelearning.css', 'ExeLearning')
        );
        $view->headScript()->appendFile(
            $view->assetUrl('js/exelearning-viewer.js', 'ExeLearning')
        );

        // Build HTML
        $html = '<div class="exelearning-viewer" data-media-id="' . $media->id() . '">';

        // Toolbar
        $html .= '<div class="exelearning-toolbar">';
        $html .= '<span class="exelearning-title">' . $view->escapeHtml($media->displayTitle()) . '</span>';
        $html .= '<div class="exelearning-toolbar-actions">';

        // Download button
        $html .= '<a href="' . $view->escapeHtmlAttr($media->originalUrl()) . '" ';
        $html .= 'class="button exelearning-download-btn" download>';
        $html .= '<span class="icon-download"></span> ';
        $html .= $view->translate('Download');
        $html .= '</a>';

        // Fullscreen button
        $html .= '<button type="button" class="button exelearning-fullscreen-btn" ';
        $html .= 'data-target="exelearning-iframe-' . $media->id() . '">';
        $html .= '<span class="icon-fullscreen"></span> ';
        $html .= $view->translate('Fullscreen');
        $html .= '</button>';

        // Edit button (if allowed)
        if ($config['showEditButton'] && $this->canEdit($view, $media)) {
            $editUrl = $view->url('admin/exelearning-editor', [
                'action' => 'edit',
                'id' => $media->id()
            ]);
            $html .= '<a href="' . $view->escapeHtmlAttr($editUrl) . '" ';
            $html .= 'class="button exelearning-edit-btn" target="_blank">';
            $html .= '<span class="icon-edit"></span> ';
            $html .= $view->translate('Edit in eXeLearning');
            $html .= '</a>';
        }

        $html .= '</div>'; // toolbar-actions
        $html .= '</div>'; // toolbar

        // Iframe with security sandbox
        // sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" prevents:
        // - Access to parent page DOM
        // - Access to cookies/localStorage from parent origin
        // - Form submissions to external sites
        // - Running plugins
        // While allowing:
        // - JavaScript execution (needed for eXeLearning interactivity)
        // - Opening popups for external links
        $html .= '<iframe ';
        $html .= 'id="exelearning-iframe-' . $media->id() . '" ';
        $html .= 'src="' . $view->escapeHtmlAttr($previewUrl) . '" ';
        $html .= 'class="exelearning-iframe" ';
        $html .= 'style="width: 100%; height: ' . (int) $config['height'] . 'px; border: none;" ';
        $html .= 'sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" ';
        $html .= 'referrerpolicy="no-referrer" ';
        $html .= 'allowfullscreen>';
        $html .= '</iframe>';

        $html .= '</div>'; // exelearning-viewer

        return $html;
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
        $html .= '<span class="icon-download"></span> ';
        $html .= $view->translate('Download eXeLearning file');
        $html .= '</a>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if the current user can edit the media.
     *
     * @param PhpRenderer $view
     * @param MediaRepresentation $media
     * @return bool
     */
    protected function canEdit(PhpRenderer $view, MediaRepresentation $media): bool
    {
        try {
            $acl = $view->getHelperPluginManager()->get('acl');
            return $acl->userIsAllowed($media->item(), 'update');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if media is an eXeLearning file.
     *
     * @param MediaRepresentation $media
     * @return bool
     */
    /**
     * Determine whether teacher mode toggler should be visible.
     */
    protected function isTeacherModeVisible(MediaRepresentation $media): bool
    {
        $data = $media->mediaData();
        if (!isset($data['exelearning_teacher_mode_visible'])) {
            return true;
        }

        $value = $data['exelearning_teacher_mode_visible'];
        return !in_array((string) $value, ['0', 'false', 'no'], true);
    }

    protected function isExeLearningFile(MediaRepresentation $media): bool
    {
        $filename = $media->filename();
        if (!$filename) {
            return false;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, ['elpx', 'zip']);
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
            'showEditButton' => true,
        ];

        try {
            $setting = $view->getHelperPluginManager()->get('setting');
            return [
                'height' => $setting('exelearning_viewer_height', $defaults['height']),
                'showEditButton' => $setting('exelearning_show_edit_button', $defaults['showEditButton']),
            ];
        } catch (\Exception $e) {
            return $defaults;
        }
    }
}
