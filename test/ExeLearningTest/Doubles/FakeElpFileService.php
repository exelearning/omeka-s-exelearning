<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Stand-in for ElpFileService in the Module event-handler tests.
 *
 * Module resolves the service by class-string name, so the container may hand
 * back any object with the right methods. Keeping this a plain double (rather
 * than a partial mock of the real service) means the handler tests exercise
 * Module's branching only, and stay unaffected by extraction changes.
 */
class FakeElpFileService
{
    /** @var string|null */
    private $hash;
    private bool $hasScreenshot;
    private bool $hasPreview;
    private bool $processed;

    /** @var array{hash: string, hasPreview: bool} */
    public array $processResult = ['hash' => 'processed-hash', 'hasPreview' => true];

    /** @var \Throwable|null Thrown by processUploadedFile() when set. */
    public $processException = null;

    /** @var int How many times processUploadedFile() was called. */
    public int $processCalls = 0;

    /** @var string|null Recorded reason the last processing attempt failed. */
    public $processingError = null;

    /** @var array<int, string> Hashes passed to cleanupMediaByHash(). */
    public array $cleanedHashes = [];

    public function __construct(
        ?string $hash,
        bool $hasScreenshot,
        bool $hasPreview,
        bool $processed
    ) {
        $this->hash = $hash;
        $this->hasScreenshot = $hasScreenshot;
        $this->hasPreview = $hasPreview;
        $this->processed = $processed;
    }

    /**
     * @param mixed $media
     */
    public function getMediaHash($media): ?string
    {
        return $this->hash;
    }

    /**
     * @param mixed $media
     */
    public function hasScreenshot($media): bool
    {
        return $this->hasScreenshot;
    }

    /**
     * @param mixed $media
     */
    public function hasPreview($media): bool
    {
        return $this->hasPreview;
    }

    /**
     * @param mixed $media
     */
    public function isProcessed($media): bool
    {
        return $this->processed;
    }

    /**
     * @param mixed $media
     * @return array{hash: string, hasPreview: bool}
     */
    public function processUploadedFile($media): array
    {
        $this->processCalls++;
        if ($this->processException !== null) {
            $this->processingError = $this->processException->getMessage();
            throw $this->processException;
        }
        return $this->processResult;
    }

    /**
     * @param mixed $media
     */
    public function getProcessingError($media): ?string
    {
        return $this->processingError;
    }

    /**
     * @param mixed $media
     */
    public function hasProcessingError($media): bool
    {
        return $this->processingError !== null;
    }

    public function cleanupMediaByHash(string $hash): void
    {
        $this->cleanedHashes[] = $hash;
    }
}
