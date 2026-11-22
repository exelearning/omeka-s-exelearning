<?php

declare(strict_types=1);

namespace ExeLearningTest;

use PHPUnit\Framework\TestCase;

/**
 * The editor serves its new-tab (fullscreen) preview only through its preview
 * Service Worker. The bootstrap used to replace navigator.serviceWorker.register
 * with a stub, which left that button dead (exelearning/exelearning issue 2773).
 * The editor handles a rejected registration by itself, so the bootstrap must
 * leave the registration alone.
 */
class EditorBootstrapTemplateTest extends TestCase
{
    public function testBootstrapDoesNotOverrideServiceWorkerRegistration(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/view/exelearning/editor-bootstrap.phtml');

        $this->assertIsString($template);
        $this->assertDoesNotMatchRegularExpression('/serviceWorker\s*\.\s*register\s*=/', $template);
    }
}
