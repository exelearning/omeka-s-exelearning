---
id: ADR-39-01
title: "Render eXeLearning media through Omeka's media-renderer manager"
status: Proposed
date: 2026-09-18
tracking_issue: 39
deciders:
  - "@erseco"
  - "claude-code"
related:
  issues:
    - "exelearning/exelearning#2443"
    - "exelearning/exelearning#2444"
  prs: [39]
  changes:
    - 39-renderer-registration-files-path-and-claimed-types
  adrs:
    - ADR-39-02
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# ADR-39-01: Render eXeLearning media through Omeka's media-renderer manager

## Context

Omeka S resolves a media's renderer through two different plugin managers, and
this module used one while writing into the other.

`Module::handleMediaHydrate()` wrote `exelearning_renderer` into the entity's
`renderer` column (`Module.php:536-539` @ `184e53f`), but
`config/module.config.php:51-64` registered that name only under
`file_renderers`. `Omeka\Media\Renderer\Manager` — the one that resolves the
column — catches `ServiceNotFoundException` and substitutes
`Omeka\Media\Renderer\Fallback`, whose `render()` returns `''`
(omeka/omeka-s `v4.2.0`, `application/src/Media/Renderer/Manager.php` and
`application/src/Media/Renderer/Fallback.php`). `file_renderers` is consulted
only by `Omeka\Media\Renderer\File`, i.e. only when the column is the literal
`file`. The column had been overwritten, so the aliases were unreachable and the
renderer was dead code, marked `@codeCoverageIgnore` accordingly.

The failure was silent: no exception, no log entry, no failing test. The public
viewer was instead injected from a `Omeka\Controller\Site\Item` /
`view.show.after` listener rendering a second, divergent implementation of the
same viewer, which themes could not position and which ran the ZIP extraction
inline during a GET request.

The obvious repair — register under `media_renderers` and delete the listener —
turns out not to be enough on its own. Whether core calls `$media->render()` on
an item page is a per-site, per-theme configuration question, and at the time
this change began `config/module.ini:9` declared
`omeka_version_constraint = "^3.0.0 || ^4.0.0"`, so it was a per-core-version
question too.

## Problem

Where should the viewer be produced, so that every Omeka surface that renders a
media shows it, it is shown exactly once, and it still appears on sites whose
item page does not embed media?

## Decision drivers

- Correctness on every surface: media show pages, site page blocks, item
  showcases, search results and any theme calling `$media->render()`.
- No double rendering on any supported core version.
- Support only what is worth supporting: Omeka S 3 is old, and carrying it costs
  a second code path in every decision this ADR touches.
- One implementation of the viewer, not two that drift.
- No state mutation during a GET render.
- Themes must be able to position and style the viewer.

## Alternatives considered

### Option 1: Register under `media_renderers`, delete the listener

The shape the upstream issue proposes, and what `LearningObjectAdapter` does.
Correct on Omeka S 4, where `Site\ResourcePageBlockLayout\Manager::RESOURCE_PAGE_BLOCKS_DEFAULT`
includes `mediaEmbeds` for items (`v4.2.0`,
`application/src/Site/ResourcePageBlockLayout/Manager.php:18-28`).

It regresses on both cores in the original support range, for different reasons.

On Omeka S 3, `v3.2.3`'s `application/view/omeka/site/item/show.phtml` gates
media rendering on `$this->siteSetting('item_media_embed', false)`, which
defaults to off, so `$media->render()` is never called on an item page and the
viewer disappears from every S3 site that never ticked that box.

On Omeka S 4 it is incomplete: `mediaEmbeds` is only a *default* and can be
removed per site or per theme — see option 4.

### Option 2: Drop `setRenderer()` and route by MIME through `file_renderers`

Fewer lines. Routes by media type, which forces the module to keep claiming
`application/zip` and `application/octet-stream` — the defect
`exelearning/exelearning#2444` reports — and loses the distinct renderer name
themes can key on. `ThreeDViewer` does this, and collides with this module over
`application/octet-stream` for exactly that reason.

### Option 3: Narrow `module.ini` to `^4.0.0` and delete the listener

Drops the core whose default breaks option 1. Omeka S 3 is old and supporting it
costs a second branch in every decision here, so narrowing is worth doing on its
own merits.

It is not sufficient by itself, though: it fixes the S3 default and leaves the
S4 configuration case (option 4) untouched. A site or theme that removes
`mediaEmbeds` still loses the viewer.

### Option 4: Option 3, plus a listener that re-renders when `mediaEmbeds` is absent

Narrow to `^4.0.0`, register the renderer, delete the duplicate partial, and keep
the `view.show.after` listener for one case: an S 4 site whose resolved
resource-page block configuration no longer lists `mediaEmbeds`, where core
renders no media at all.

This was implemented and then withdrawn. Two problems, and the second is fatal.

**It overrides the administrator's configuration.** On Omeka S 4, removing
`mediaEmbeds` *is* the supported way to say "this item page does not embed its
media". Re-rendering anyway, for eXeLearning alone, makes this module the one
media type that ignores that setting — and puts its viewer back at the end of the
template, outside where the theme places media, which is consequence 1 of
`exelearning/exelearning#2443` returning by another route.

**The configuration cannot answer the question anyway.** Blocks are stored per
region, but a region only renders if the template asks for it:
`$this->resourcePageBlocks($item, 'sidebar')->getBlocks()`
(`v4.2.0` `application/src/View/Helper/ResourcePageBlocks.php`, `__invoke($resource, $regionName = 'main')`).
`mediaEmbeds` configured in a region the template never invokes is configured and
never rendered. So "the block appears somewhere in the configuration" does not
imply "core renders the media", and the inverse inference fails too. There is no
reliable read; the check would be a guess wearing the costume of a lookup.

## Evidence

- `Omeka\Media\Renderer\Manager` sets `protected $instanceOf = RendererInterface::class`
  and catches only `ServiceNotFoundException` (omeka/omeka-s `v4.2.0`). A
  `media_renderers` entry whose class does not implement that interface raises an
  uncaught `InvalidServiceException`, so the registration and the `implements`
  clause must land in the same commit.
- Both `Omeka\Media\Renderer\RendererInterface` and
  `Omeka\Media\FileRenderer\RendererInterface` declare
  `render(PhpRenderer $view, MediaRepresentation $media, array $options = [])`
  with no return type (`v4.2.0`), so the module's existing `: string` is
  covariant and legal on the PHP 7.4 floor declared in `composer.json:24`.
- `v3.2.3` `application/view/omeka/site/item/show.phtml`:
  `$embedMedia = $this->siteSetting('item_media_embed', false);` and media are
  rendered only `if ($embedMedia && $itemMedia)`.
- `v4.2.0` `application/view/omeka/site/item/show.phtml` renders
  `$this->resourcePageBlocks($item)->getBlocks()`.
- `v4.2.0` `application/src/Site/ResourcePageBlockLayout/Manager.php`:
  `RESOURCE_PAGE_BLOCKS_DEFAULT` lists `mediaEmbeds` first for `items`, but
  `getResourcePageBlocks(Theme $theme)` returns the site administrator's saved
  `resource_page_blocks` when set, then the theme's INI configuration, and only
  falls back to that default when neither exists. The result is normalised to
  `[<resource>][<region>] => [<block>, …]`, and a theme may declare regions
  beyond `main`.
- `v4.2.0` `application/src/Service/ViewHelper/ResourcePageBlocksFactory.php`
  resolves the same configuration with
  `$services->get('Omeka\Site\ThemeManager')->getCurrentTheme()` and
  `$services->get('Omeka\ResourcePageBlockLayoutManager')->getResourcePageBlocks(...)`.
  The shim reads it the same way, so it sees what core renders from, without
  rendering anything or inspecting generated markup.
- `Omeka\ResourcePageBlockLayoutManager` is registered in `v4.2.0`
  `application/config/module.config.php:273` and absent from `v3.2.3`'s.
- Omeka S 4.0 and 4.1 declare `"php": ">=7.4"` and only 4.2 raises it to
  `">=8.1"` (their `composer.json`), so narrowing to `^4.0.0` does **not** let
  this module raise its own PHP floor; `composer.json:24` stays `>=7.4`.
- `v4.2.0` `application/view/omeka/admin/media/show.phtml` calls
  `$media->render()` at line 23 and triggers `view.show.after` at line 122 — so
  the admin partial's own iframe would have produced a second viewer once the
  registration was fixed.
- `.agents/skills/omeka-s-module-development/SKILL.md` records the config-key
  contract this ADR changes.

## Decision

We will adopt options 1 and 3 together: **narrow to Omeka S 4 and delete the
listener outright**, which is what `exelearning/exelearning#2443` asked for.

**`config/module.ini` narrows to `omeka_version_constraint = "^4.0.0"`.** Omeka
S 3 does not embed media on item pages by default, so on S 3 option 1 really was
a regression and really did need a compatibility path. Dropping S 3 removes the
reason that path existed. The module's PHP floor is unaffected: Omeka S 4.0 and
4.1 still allow PHP 7.4.

**The `Omeka\Controller\Site\Item` / `view.show.after` listener is deleted**,
along with `handlePublicItemShow()`, `itemPageEmbedsMedia()` and the
configuration lookup behind it. Whether an item page embeds its media is Omeka's
decision and the site administrator's, expressed through resource page blocks.
This module registers a renderer and stops there; every surface that chooses to
render a media gets the viewer, and a page configured not to embed media does not
— exactly as for every other media type in the installation.

`ExeLearningRenderer` implements both renderer interfaces and is registered
under `media_renderers`, so `$media->render()` produces the viewer on every
Omeka surface. `file_renderers` keeps the factory and a single `elpx` alias for
media stored before this module claimed them, whose `renderer` column is still
`file`.

`view/exelearning/public/item-show.phtml` is deleted and the admin partial is
reduced to the editor modal, leaving one implementation of the viewer.

The resulting flow has no module-side inference in it at all:

```
media.renderer = exelearning_renderer
      ↓  Omeka\Media\Renderer\Manager
ExeLearningRenderer
      ↓
every Omeka surface that decides to render that media
```

## Consequences

### Positive

- `$media->render()` works on media show pages, page blocks, item showcases,
  search results and any theme that calls it — none of which the listener ever
  covered.
- **No `view.show.after` listener on the public side at all**, which is what the
  issue asked for. Themes place and style the viewer, because it arrives through
  the normal media-rendering path.
- The module no longer tries to infer what a theme "should" have rendered, so a
  whole class of unreliable configuration reads disappears: two constants, two
  methods, two test doubles and their tests.
- Themes can position and style the viewer, because it arrives through the
  normal media-rendering path rather than appended after the template.
- One viewer implementation. The divergent sandbox attribute, the 43-line inline
  `<style>` and the duplicated base-path script are gone.
- No extraction during a public GET.
- `$media->renderer()` still reads `exelearning_renderer`, so themes grouping
  media by renderer keep a meaningful label.

### Negative

- **Omeka S 3 installations can no longer install this version.** Deliberate.
  `v4.0.5` is the last published release that supports them.
- **A site that removed `mediaEmbeds` shows no eXeLearning viewer on its item
  pages.** That is now the intended behaviour — it is what removing the block
  means — but it is a behaviour change for anyone who removed the block and still
  expected this module's viewer. It is configuration, and re-adding the block
  restores it.
- The shim reads Omeka's configuration, so it is correct for themes that render
  through the normal resource-page block mechanism. A theme that overrides
  `site/item/show.phtml` and bypasses `resourcePageBlocks()` entirely is outside
  what any configuration read can predict; see *Risks*.

### Neutral

- Removing `@codeCoverageIgnore` from `render()` brings roughly 100 lines into
  the coverage gate; they are covered.

## Risks

- **A theme that never renders media.** A theme whose `site/item/show.phtml`
  neither invokes the region carrying `mediaEmbeds` nor calls `$media->render()`
  shows no viewer. This is no longer the module's problem to detect or work
  around: such a theme renders no media of any type, and that is its own
  contract with the site.
- **Wider exposure of package JavaScript.** The renderer now runs on more
  surfaces than the listener did. The exposure itself is unchanged in kind and
  is the subject of [ADR-39-02](./ADR-39-02-preserve-current-iframe-behaviour-pending-opaque-origin-viewer.md).

## Validation

`ExeLearningRendererTest` asserts `$media->render()` for an `.elpx` returns
non-empty HTML containing `exelearning-viewer`, which is the regression test the
upstream issue asks for. `ModuleTest` asserts the `media_renderers`
registration, that the renderer satisfies the interface `Manager` enforces, and
that the `file_renderers` aliases are exactly `['elpx' => …]`.

`ModuleTest::testAttachListenersRegistersEveryOmekaHook` pins the full listener
roster, so re-adding a public `view.show.after` hook is a visible change to a
test that states why there is none.

Review if Omeka changes how resource pages render media.

## Follow-up work

- Announce in the release notes that Omeka S 3 is no longer supported and that
  `v4.0.5` is the last release for those sites, and that a site which removed the
  `mediaEmbeds` block will no longer see the eXeLearning viewer on item pages.
- Consider a bulk reprocess job for media that were never extracted, now that
  public rendering no longer repairs them.

## References

- exelearning/exelearning#2443, exelearning/exelearning#2444
- [Change 39](../changes/39-renderer-registration-files-path-and-claimed-types/design.md)
- [ADR-39-02](./ADR-39-02-preserve-current-iframe-behaviour-pending-opaque-origin-viewer.md)
- omeka/omeka-s `v3.2.3` and `v4.2.0`, paths cited inline above
