<?php

declare(strict_types=1);

namespace Omeka\Api\Representation;

/**
 * Minimal stub of Omeka's MediaRepresentation for tests.
 */
class MediaRepresentation
{
    private string $originalUrl;
    private string $displayTitle;
    private string $filename;
    private int $id;
    private array $mediaData;
    private ?object $item;

    /** @var string What render() returns; also records that it was called. */
    public string $rendered = '';

    /** @var int How many times render() was called. */
    public int $renderCalls = 0;

    public function __construct(
        string $originalUrl,
        string $displayTitle,
        string $filename,
        int $id = 1,
        array $mediaData = [],
        ?object $item = null
    ) {
        $this->originalUrl = $originalUrl;
        $this->displayTitle = $displayTitle;
        $this->filename = $filename;
        $this->id = $id;
        $this->mediaData = $mediaData;
        $this->item = $item;
    }

    public function originalUrl(): string
    {
        return $this->originalUrl;
    }

    public function displayTitle(): string
    {
        return $this->displayTitle;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function mediaData(): array
    {
        return $this->mediaData;
    }

    public function item(): ?object
    {
        return $this->item;
    }

    /**
     * Omeka resolves the media's `renderer` column through
     * Omeka\Media\Renderer\Manager and delegates here.
     */
    public function render(array $options = []): string
    {
        $this->renderCalls++;
        return $this->rendered;
    }
}
