<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use ExeLearning\Service\ElpFileService;

/**
 * REST API controller for eXeLearning operations.
 */
class ApiController extends AbstractActionController
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
     * Create a JSON error response with status code.
     */
    protected function errorResponse(int $statusCode, string $message): JsonModel
    {
        $this->getResponse()->setStatusCode($statusCode);
        return new JsonModel(['success' => false, 'message' => $message]);
    }

    /**
     * Get media by ID or return null if not found.
     */
    protected function getMediaOrFail(int $mediaId)
    {
        try {
            return $this->api()->read('media', $mediaId)->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Save an edited eXeLearning file.
     *
     * POST /api/exelearning/save/:id
     *
     * @return JsonModel
     *
     * @codeCoverageIgnore
     */
    public function saveAction()
    {
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->errorResponse(405, 'Method not allowed');
        }

        if (!$this->identity()) {
            return $this->errorResponse(401, 'Unauthorized');
        }

        // Validate CSRF token
        $csrfToken = $request->getPost('csrf') ?? $request->getHeaders()->get('X-CSRF-Token')?->getFieldValue();
        if ($csrfToken) {
            $csrf = new \Laminas\Validator\Csrf(['name' => 'csrf']);
            if (!$csrf->isValid($csrfToken)) {
                return $this->errorResponse(403, 'CSRF: Invalid or missing CSRF token');
            }
        }

        $mediaId = $this->params('id');
        if (!$mediaId) {
            return $this->errorResponse(400, 'Media ID required');
        }

        $media = $this->getMediaOrFail((int) $mediaId);
        if (!$media) {
            return $this->errorResponse(404, 'Media not found');
        }

        // Check permissions
        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
            return $this->errorResponse(403, 'Forbidden');
        }

        $files = $request->getFiles();
        if (empty($files['file'])) {
            return $this->errorResponse(400, 'No file uploaded');
        }

        $uploadedFile = $files['file'];
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return $this->errorResponse(400, 'Upload failed: error code ' . $uploadedFile['error']);
        }

        try {
            // Replace the file
            $result = $this->elpService->replaceFile($media, $uploadedFile['tmp_name']);

            // Build secure preview URL using proxy controller
            $previewUrl = null;
            if ($result['hasPreview']) {
                $previewUrl = $this->url()->fromRoute('exelearning-content', [
                    'hash' => $result['hash'],
                    'file' => 'index.html',
                ]);
            }

            return new JsonModel([
                'success' => true,
                'message' => 'File saved successfully',
                'media_id' => (int) $mediaId,
                'preview_url' => $previewUrl,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(500, 'Save failed: ' . $e->getMessage());
        }
    }

    /**
     * Get eXeLearning file data.
     *
     * GET /api/exelearning/elp-data/:id
     */
    public function getDataAction(): JsonModel
    {
        $mediaId = $this->params('id');
        if (!$mediaId) {
            return $this->errorResponse(400, 'Media ID required');
        }

        $media = $this->getMediaOrFail((int) $mediaId);
        if (!$media) {
            return $this->errorResponse(404, 'Media not found');
        }

        $hash = $this->elpService->getMediaHash($media);
        $hasPreview = $this->elpService->hasPreview($media);

        // Build secure preview URL using proxy controller
        $previewUrl = null;
        if ($hash && $hasPreview) {
            $previewUrl = $this->url()->fromRoute('exelearning-content', [
                'hash' => $hash,
                'file' => 'index.html',
            ]);
        }

        return new JsonModel([
            'success' => true,
            'id' => (int) $mediaId,
            'url' => $media->originalUrl(),
            'title' => $media->displayTitle(),
            'filename' => $media->filename(),
            'hasPreview' => $hasPreview,
            'previewUrl' => $previewUrl,
            'teacherModeVisible' => $this->elpService->isTeacherModeVisible($media),
        ]);
    }

    /**
     * Persist teacher mode visibility setting for a media item.
     *
     * POST /api/exelearning/teacher-mode/:id
     */
    public function setTeacherModeAction(): JsonModel
    {
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->errorResponse(405, 'Method not allowed');
        }

        if (!$this->identity()) {
            return $this->errorResponse(401, 'Unauthorized');
        }

        $mediaId = $this->params('id');
        if (!$mediaId) {
            return $this->errorResponse(400, 'Media ID required');
        }

        $media = $this->getMediaOrFail((int) $mediaId);
        if (!$media) {
            return $this->errorResponse(404, 'Media not found');
        }

        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
            return $this->errorResponse(403, 'Forbidden');
        }

        $rawValue = $request->getPost('teacher_mode_visible', '1');
        $visible = !in_array(strtolower((string) $rawValue), ['0', 'false', 'no'], true);

        try {
            $this->elpService->setTeacherModeVisible($media, $visible);
            return new JsonModel([
                'success' => true,
                'media_id' => (int) $mediaId,
                'teacherModeVisible' => $visible,
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse(500, 'Update failed: ' . $e->getMessage());
        }
    }
}
