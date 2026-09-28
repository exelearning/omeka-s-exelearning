<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Item representation exposing media(), which Module::handlePublicItemShow()
 * iterates over, and sites(), which EditPermission checks for site roles.
 */
class FakeItem
{
    /** @var array<int, object> */
    private array $media;

    /** @var array<int, FakeSite> */
    private array $sites;

    /**
     * @param array<int, object> $media
     * @param array<int, FakeSite> $sites
     */
    public function __construct(array $media = [], array $sites = [])
    {
        $this->media = $media;
        $this->sites = $sites;
    }

    /**
     * @return array<int, object>
     */
    public function media(): array
    {
        return $this->media;
    }

    /**
     * @return array<int, FakeSite>
     */
    public function sites(): array
    {
        return $this->sites;
    }
}
