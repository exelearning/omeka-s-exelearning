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
turns out to depend on the core version, and `config/module.ini:9` declares
`omeka_version_constraint = "^3.0.0 || ^4.0.0"`.

## Problem

Where should the viewer be produced, so that every Omeka surface that renders a
media shows it, it is shown exactly once, and it still appears on the Omeka S
versions this module declares support for?

## Decision drivers

- Correctness on every surface: media show pages, site page blocks, item
  showcases, search results and any theme calling `$media->render()`.
- No double rendering on any supported core version.
- Backward compatibility: the module claims Omeka S 3 *and* 4.
- One implementation of the viewer, not two that drift.
- No state mutation during a GET render.
- Themes must be able to position and style the viewer.

## Alternatives considered

### Option 1: Register under `media_renderers`, delete the listener

The shape the upstream issue proposes, and what `LearningObjectAdapter` does.
Correct on Omeka S 4, where `Site\ResourcePageBlockLayout\Manager::RESOURCE_PAGE_BLOCKS_DEFAULT`
includes `mediaEmbeds` for items (`v4.2.0`,
`application/src/Site/ResourcePageBlockLayout/Manager.php:18-28`).

On Omeka S 3 it is a silent public-facing regression. `v3.2.3`'s
`application/view/omeka/site/item/show.phtml` gates media rendering on
`$this->siteSetting('item_media_embed', false)`, which defaults to off, so
`$media->render()` is never called on an item page and the viewer simply
disappears from every S3 site that never ticked that box.

It is also incomplete on Omeka S 4, where `mediaEmbeds` is only a *default*
and can be removed per site or per theme — see option 4.

### Option 2: Drop `setRenderer()` and route by MIME through `file_renderers`

Fewer lines. Routes by media type, which forces the module to keep claiming
`application/zip` and `application/octet-stream` — the defect
`exelearning/exelearning#2444` reports — and loses the distinct renderer name
themes can key on. `ThreeDViewer` does this, and collides with this module over
`application/octet-stream` for exactly that reason.

### Option 3: Register under `media_renderers`, narrow `module.ini` to `^4.0.0`

Makes option 1 honest by dropping the version it breaks. It is a support
decision rather than a technical one, and it is not forced: option 4 keeps both
versions working at the cost of about fifteen lines.

### Option 4: Register under `media_renderers`, keep the listener as a version shim

Register the renderer where the column is resolved, delete the duplicate
partial, and keep the `view.show.after` listener reduced to one job: call
`$media->render()` when — and only when — the item page did not embed the media
itself.

The question the shim has to answer is not "which Omeka is this", it is "does
this page already render its media". The two cores answer it from different
places:

| Core | What decides | How the shim reads it |
| --- | --- | --- |
| S 3 | the `item_media_embed` site setting, default off | `$view->siteSetting('item_media_embed', false)` |
| S 4 | whether `mediaEmbeds` survives in the site's **resolved** resource-page block configuration | `Omeka\ResourcePageBlockLayoutManager::getResourcePageBlocks($currentTheme)` |

**Helper or service existence is not sufficient**, and an earlier draft of this
change got that wrong. In S 4 the `resourcePageBlocks` helper always exists and
`mediaEmbeds` is always registered; what varies is whether this *site's*
configuration still lists the block.
`Manager::getResourcePageBlocks()` prefers the administrator's saved blocks,
then the theme's `resource_page_blocks` INI section, and only then
`RESOURCE_PAGE_BLOCKS_DEFAULT`. An administrator or a theme may drop
`mediaEmbeds`, and then core renders no media and the viewer vanishes — the very
regression option 1 causes on S 3, reappearing on S 4 through configuration.

Reading the resolved configuration answers it exactly. The presence of
`Omeka\ResourcePageBlockLayoutManager` in the container distinguishes the two
cores without comparing version strings, since S 3 has no such service.

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
  `application/config/module.config.php:273` and absent from `v3.2.3`'s, which
  makes its presence a capability probe rather than a version check.
- `v4.2.0` `application/view/omeka/admin/media/show.phtml` calls
  `$media->render()` at line 23 and triggers `view.show.after` at line 122 — so
  the admin partial's own iframe would have produced a second viewer once the
  registration was fixed.
- `.agents/skills/omeka-s-module-development/SKILL.md` records the config-key
  contract this ADR changes.

## Decision

We will adopt option 4.

`ExeLearningRenderer` implements both renderer interfaces and is registered
under `media_renderers`, so `$media->render()` produces the viewer on every
Omeka surface. `file_renderers` keeps the factory and a single `elpx` alias for
media stored before this module claimed them, whose `renderer` column is still
`file`.

`view/exelearning/public/item-show.phtml` is deleted and the admin partial is
reduced to the editor modal, leaving one implementation of the viewer.

The `Omeka\Controller\Site\Item` / `view.show.after` listener is kept, reduced to
a compatibility shim that calls `$media->render()` when the item page did not
embed the media itself — on S 3 when `item_media_embed` is off, and on S 4 when
`mediaEmbeds` is absent from the site's resolved resource-page block
configuration. It renders the same renderer, not a second implementation, and it
performs no write.

## Consequences

### Positive

- `$media->render()` works on media show pages, page blocks, item showcases,
  search results and any theme that calls it — none of which the listener ever
  covered.
- Themes can position and style the viewer, because it arrives through the
  normal media-rendering path rather than appended after the template.
- One viewer implementation. The divergent sandbox attribute, the 43-line inline
  `<style>` and the duplicated base-path script are gone.
- No extraction during a public GET.
- `$media->renderer()` still reads `exelearning_renderer`, so themes grouping
  media by renderer keep a meaningful label.

### Negative

- The `view.show.after` listener remains, which the upstream issue asked to
  remove outright. It is now two small methods with a single documented purpose
  rather than a parallel viewer.
- The shim reads Omeka's configuration, so it is correct for themes that render
  through the normal resource-page block mechanism. A theme that overrides
  `site/item/show.phtml` and bypasses `resourcePageBlocks()` entirely is outside
  what any configuration read can predict; see *Risks*.

### Neutral

- Removing `@codeCoverageIgnore` from `render()` brings roughly 100 lines into
  the coverage gate; they are covered.

## Risks

- **A theme that bypasses resource-page blocks.** An S 4 theme may ship its own
  `site/item/show.phtml` that neither calls `resourcePageBlocks()` nor
  `$media->render()`, or that calls `$media->render()` directly without the
  block. The resolved configuration cannot describe either case: in the first
  the viewer does not appear, in the second it appears twice. This is not
  inferable from configuration and is not guessed at. Such a theme is
  responsible for its own media rendering, exactly as it is for every other
  media type in Omeka.
- **Double render on a core version not tested here.** Medium impact, low
  likelihood: the shim covers the two documented embedding mechanisms in the
  supported range. A third would need a third branch.
- **Wider exposure of package JavaScript.** The renderer now runs on more
  surfaces than the listener did. The exposure itself is unchanged in kind and
  is the subject of [ADR-39-02](./ADR-39-02-keep-allow-same-origin-on-the-package-iframe.md).

## Validation

`ExeLearningRendererTest` asserts `$media->render()` for an `.elpx` returns
non-empty HTML containing `exelearning-viewer`, which is the regression test the
upstream issue asks for. `ModuleTest` asserts the `media_renderers`
registration, that the renderer satisfies the interface `Manager` enforces, and
that the `file_renderers` aliases are exactly `['elpx' => …]`.

The shim is covered against a modelled resource-page configuration rather than
against a version flag: S 3 with `item_media_embed` off (renders) and on
(silent); S 4 with the resolved configuration containing `mediaEmbeds` (silent),
with it removed (renders), and with it declared in a non-`main` region
(silent); that the resolved blocks are read for the site's current theme; that
only eXeLearning media render; and that no extraction or write happens during
the render.

Review after the first release that reaches an Omeka S 3 site: if no one is
still on S3, the shim and the `^3.0.0` constraint can both go, which is option 3
arrived at by evidence rather than assumption.

## Follow-up work

- Decide whether Omeka S 3 is still supported. If not, narrow
  `config/module.ini:9` to `^4.0.0` and delete the shim.
- Consider a bulk reprocess job for media that were never extracted, now that
  public rendering no longer repairs them.

## References

- exelearning/exelearning#2443, exelearning/exelearning#2444
- [Change 39](../changes/39-renderer-registration-files-path-and-claimed-types/design.md)
- [ADR-39-02](./ADR-39-02-keep-allow-same-origin-on-the-package-iframe.md)
- omeka/omeka-s `v3.2.3` and `v4.2.0`, paths cited inline above
