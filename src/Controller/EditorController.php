<?php
declare(strict_types=1);

namespace ExeLearning\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use ExeLearning\Service\ElpFileService;

/**
 * Controller for the eXeLearning editor page.
 */
class EditorController extends AbstractActionController
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
     * Display the eXeLearning editor.
     *
     * @return ViewModel|\Laminas\Http\Response
     *
     * @codeCoverageIgnore
     */
    public function editAction()
    {
        // Check authentication
        $user = $this->identity();
        if (!$user) {
            return $this->redirect()->toRoute('login');
        }

        $mediaId = $this->params('id');
        if (!$mediaId) {
            return $this->redirect()->toRoute('admin');
        }

        // Get the media
        $api = $this->api();
        try {
            $media = $api->read('media', $mediaId)->getContent();
        } catch (\Exception $e) {
            $this->messenger()->addError('Media not found.');
            return $this->redirect()->toRoute('admin');
        }

        // Check permissions
        $acl = $this->getEvent()->getApplication()->getServiceManager()->get('Omeka\Acl');
        if (!$acl->userIsAllowed('Omeka\Entity\Media', 'update')) {
            $this->messenger()->addError('You do not have permission to edit media.');
            return $this->redirect()->toRoute('admin');
        }

        // Check if it's an eXeLearning file
        $filename = $media->filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['elpx', 'zip'])) {
            $this->messenger()->addError('This is not an eXeLearning file.');
            return $this->redirect()->toRoute('admin');
        }

        // Check if editor exists
        $editorPath = dirname(__DIR__, 2) . '/dist/static/index.html';
        if (!file_exists($editorPath)) {
            $this->messenger()->addError(
                'eXeLearning editor not found. Please run "make build-editor" in the module directory.'
            );
            return $this->redirect()->toRoute('admin');
        }

        // Build configuration for the editor
        $uri = $this->getRequest()->getUri();
        $port = $uri->getPort();
        $serverUrl = $uri->getScheme() . '://' . $uri->getHost();
        // Include port if it's not the default for the scheme
        if ($port && !(($uri->getScheme() === 'http' && $port == 80) || ($uri->getScheme() === 'https' && $port == 443))) {
            $serverUrl .= ':' . $port;
        }
        $basePath = $this->getRequest()->getBasePath();

        // Generate CSRF token
        $csrf = new \Laminas\Form\Element\Csrf('csrf');
        $csrfToken = $csrf->getValue();

        $config = [
            'mode' => 'OmekaS',
            'mediaId' => (int) $mediaId,
            'elpUrl' => $media->originalUrl(),
            'projectId' => 'omeka-media-' . $mediaId,
            'saveEndpoint' => $serverUrl . $basePath . '/api/exelearning/save/' . $mediaId,
            'editorBaseUrl' => $serverUrl . $basePath . '/modules/ExeLearning/asset/static',
            'csrfToken' => $csrfToken,
            'locale' => substr($this->settings()->get('locale', 'en_US'), 0, 2),
            'userName' => $user->getName(),
            'userId' => $user->getId(),
            'i18n' => [
                'saving' => $this->translate('Saving...'),
                'saved' => $this->translate('Saved successfully'),
                'saveButton' => $this->translate('Save to Omeka'),
                'loading' => $this->translate('Loading project...'),
                'error' => $this->translate('Error'),
                'savingWait' => $this->translate('Please wait while the file is being saved.'),
                'unsavedChanges' => $this->translate('You have unsaved changes. Are you sure you want to close?'),
                'close' => $this->translate('Close'),
            ],
        ];

        // Use terminal view model to output full HTML
        $view = new ViewModel([
            'media' => $media,
            'config' => $config,
            'editorBaseUrl' => $config['editorBaseUrl'],
        ]);

        $view->setTemplate('exelearning/editor-bootstrap');
        $view->setTerminal(true);

        return $view;
    }

    /**
     * Index action - redirect to admin.
     *
     * @return \Laminas\Http\Response
     */
    public function indexAction()
    {
        return $this->redirect()->toRoute('admin');
    }
}
