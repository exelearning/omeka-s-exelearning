<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Stand-in for Omeka S 4's `Omeka\ResourcePageBlockLayoutManager`.
 *
 * Only `getResourcePageBlocks()` matters here, and it is modelled on the real
 * one: core resolves a site administrator's saved blocks first, then the
 * theme's `resource_page_blocks` INI section, and only then
 * `Manager::RESOURCE_PAGE_BLOCKS_DEFAULT`. Whatever wins, the result is
 * normalised to `[<resource name>][<region name>] => [<block name>, ...]`, and
 * that shape -- not the mere existence of the service or the helper -- is what
 * decides whether core renders media on an item page.
 *
 * The service does not exist at all in Omeka S 3, so a test that omits it is
 * modelling Omeka S 3.
 */
class FakeResourcePageBlockLayoutManager
{
    /** Core's default, from Manager::RESOURCE_PAGE_BLOCKS_DEFAULT. */
    const DEFAULT_BLOCKS = [
        'items' => [
            'main' => ['mediaEmbeds', 'values', 'itemSets', 'sitePages', 'mediaLinks', 'linkedResources'],
        ],
        'item_sets' => ['main' => ['values']],
        'media' => ['main' => ['mediaRender', 'values']],
    ];

    /** @var array<string, array<string, string[]>> */
    private array $blocks;

    /** @var object|null The theme passed to getResourcePageBlocks(). */
    public $themeReceived = null;

    /**
     * @param array<string, array<string, string[]>>|null $blocks Resolved
     *        configuration; core's default when omitted.
     */
    public function __construct(?array $blocks = null)
    {
        $this->blocks = $blocks ?? self::DEFAULT_BLOCKS;
    }

    /** A theme whose administrator removed the media embeds block. */
    public static function withoutMediaEmbeds(): self
    {
        return new self([
            'items' => ['main' => ['values', 'itemSets', 'mediaLinks']],
            'item_sets' => ['main' => ['values']],
            'media' => ['main' => ['mediaRender', 'values']],
        ]);
    }

    /** A theme that declares its own regions and embeds media outside "main". */
    public static function withMediaEmbedsInAnotherRegion(): self
    {
        return new self([
            'items' => [
                'main' => ['values', 'itemSets'],
                'bottom' => ['mediaEmbeds'],
            ],
            'item_sets' => ['main' => ['values']],
            'media' => ['main' => ['mediaRender', 'values']],
        ]);
    }

    /**
     * @param object $theme
     * @return array<string, array<string, string[]>>
     */
    public function getResourcePageBlocks($theme): array
    {
        $this->themeReceived = $theme;

        return $this->blocks;
    }
}
