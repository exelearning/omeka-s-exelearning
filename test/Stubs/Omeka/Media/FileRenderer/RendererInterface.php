<?php

declare(strict_types=1);

namespace Omeka\Media\FileRenderer;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\MediaRepresentation;

interface RendererInterface
{
    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = []);
}
