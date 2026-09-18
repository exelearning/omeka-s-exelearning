<?php

declare(strict_types=1);

namespace Omeka\Media\Renderer;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\MediaRepresentation;

/**
 * Stub of Omeka's media-renderer interface.
 *
 * Core declares no return type on render(); mirroring that here is what lets
 * ExeLearningRenderer narrow it to `: string` and still satisfy both this and
 * the file-renderer interface, exactly as it does against real Omeka.
 */
interface RendererInterface
{
    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []);
}
