---
tracking_issue: 39
title: "Renderer registration, files-path resolution and the file types this module claims"
status: draft
date: 2026-09-18
authors:
  - "@erseco"
  - "claude-code"
implementation_prs: [39]
related_issues:
  - "exelearning/exelearning#2443"
  - "exelearning/exelearning#2444"
  - "exelearning/exelearning#2445"
related_adrs:
  - ADR-39-01
  - ADR-39-02
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# Renderer registration, files-path resolution and the file types this module claims — proposal

## Motivation

Three upstream reports describe what is really one failure chain, and they are
filed against this repository because GitHub Issues are disabled here.

The module could not find its own uploads. `ElpFileServiceFactory` and
`StylesServiceFactory` derived Omeka's files directory with
`dirname($fileStore->getLocalPath(''))`. `Omeka\File\Store\Local::getLocalPath()`
is `sprintf('%s/%s', $basePath, $storagePath)`, so `getLocalPath('')` returns
`"<basePath>/"`, and `dirname()` strips the trailing slash *and then the last
real segment*. Every stock installation looked for originals in
`<omeka>/original/` instead of `<omeka>/files/original/`, and every `.elpx`
failed with `Media file not found` on every render, forever.

Nothing caught it. The branch above it read
`$config['file_store']['local']['base_path']`, which Omeka ships as `null` and
substitutes in `Omeka\Service\File\Store\LocalFactory` itself, so it never fired.
A hardcoded `/var/www/html/volume/files` override at the end of both factories
repaired the value in the development image, which is why CI stayed green.

Because no package was ever extracted, two further defects stayed invisible.
`exelearning_renderer` was written into the media's `renderer` column but
registered only under `file_renderers`, so Omeka's `Media\Renderer\Manager`
could not resolve it, substituted its `Fallback`, and `$media->render()`
returned an empty string on every surface. And the `file_renderers` aliases
claimed `application/zip`, `application/x-zip-compressed`,
`application/octet-stream` and `zip` — types belonging to the rest of the
installation, in a plugin-manager namespace shared with every other module.

## Problem statement

Site administrators running a stock Omeka S installation cannot use this module
at all: uploads succeed, extraction always fails, the viewer never appears, and
the only signal is two log lines per media per page view with no path to
recovery and nothing visible in the admin UI.

Developers integrating with the module cannot rely on `$media->render()`, which
is the API every theme, page block and search result uses, and site
administrators running other viewer modules can have their file types taken over
by this one.

## Scope

In scope:

- Resolving Omeka's files directory once, correctly, for every consumer.
- Recording why an extraction failed, so it is not retried on every render.
- Removing the extracted package when its media is deleted.
- Registering the renderer where Omeka resolves the `renderer` column, and
  collapsing the second, divergent viewer implementation into it.
- Narrowing the renderer aliases and the install-time upload whitelist to what
  `.elpx` actually needs, and reverting the module's own whitelist additions on
  uninstall.

Out of scope:

- A bulk reprocess job or CLI task for media broken by the path defect. Opening
  the media in admin still repairs it; a bulk path is a separate change.
- Dropping Omeka S 3 support. See [ADR-39-01](../../adr/ADR-39-01-render-media-through-the-media-renderer-manager.md).
- The stale `language/template.pot`, which is missing msgids for code unrelated
  to this change. Regenerating it here would bury this diff.

## Goals

- `$filesPath` resolves to Omeka's real files directory with `base_path` unset,
  with `base_path` set explicitly, and at an `OMEKA_PATH` other than
  `/var/www/html`.
- Regression coverage pins `getLocalPath('')`'s trailing-slash return shape, so
  `dirname()` cannot be reintroduced.
- `$media->render()` for an `.elpx` media returns non-empty HTML containing
  `exelearning-viewer`.
- The viewer is rendered exactly once per media on both the admin media page and
  a public item page, on Omeka S 3 and Omeka S 4.
- An unprocessable media is attempted once, not once per render, and the reason
  is visible to an administrator rather than only in the log.
- A plain `.zip` uploaded to the site is not claimed by this module, while a
  package this module already extracted keeps working.
- `make lint`, `make test-coverage` and `make check-untranslated` pass, with
  coverage at or above the 90 ratchet.

## Non-goals

- Changing the storage layout. `<files>/exelearning/` is where extraction was
  always meant to land; this change makes it land there.
- Changing the content proxy's response headers or its ZIP validation.
- Adding a configuration option for any of the above.
