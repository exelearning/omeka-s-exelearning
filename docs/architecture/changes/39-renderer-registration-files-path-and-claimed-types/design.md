---
tracking_issue: 39
title: "Renderer registration, files-path resolution and the file types this module claims"
date: 2026-09-18
authors:
  - "@erseco"
  - "claude-code"
related_issues:
  - "exelearning/exelearning#2443"
  - "exelearning/exelearning#2444"
  - "exelearning/exelearning#2445"
related_prs: [21]
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# Renderer registration, files-path resolution and the file types this module claims — design

## Current behavior

All paths below are at `184e53f`, the commit this branch started from.

**Four independent copies of the files-directory resolution.**
`src/Service/ElpFileServiceFactory.php:18-49` and
`src/Service/StylesServiceFactory.php:22-46` were identical and both wrong;
`src/Controller/ContentControllerFactory.php:16-19` was a third copy that was
correct, and `Module::getDataPath()` (`Module.php:99-102`) was a fourth root,
`__DIR__ . '/data/exelearning'`, that nothing ever wrote to. The extractor
therefore wrote under `<omeka>/exelearning/`, the content proxy read
`<omeka>/files/exelearning/`, and media deletion cleaned
`<module>/data/exelearning/` — three different directories.

**Failure was never recorded.** `ElpFileService::processUploadedFile()` threw at
`src/Service/ElpFileService.php:94-98` before any `updateMediaData()` call, so
`exelearning_processed` was never written. `Module::handleAdminMediaShow()` and
`Module::handlePublicItemShow()` gated on `isProcessed()` alone, so a
permanently unreadable file was re-extracted and re-logged on every render.

**Renderer registered in the wrong manager.** `Module::handleMediaHydrate()`
wrote `exelearning_renderer` into the entity's `renderer` column
(`Module.php:536-539`), but `config/module.config.php:51-64` registered that name
only under `file_renderers`. Omeka resolves the column through
`Omeka\Media\Renderer\Manager`, which catches `ServiceNotFoundException` and
substitutes `Omeka\Media\Renderer\Fallback`, whose `render()` returns `''`.

**Two viewers.** Because `$media->render()` produced nothing, the public viewer
was injected by a `Omeka\Controller\Site\Item` / `view.show.after` listener
(`Module.php:198-202`) rendering `view/exelearning/public/item-show.phtml`, a
second implementation with its own toolbar, its own 43-line inline `<style>`,
its own base-path script and a different iframe `sandbox` value from the
renderer's. It also ran `processUploadedFile()` inline during a GET.

**Generic claims.** The `file_renderers` aliases covered `application/zip`,
`application/x-zip-compressed`, `application/octet-stream` and `zip`;
`Module::updateWhitelist()` (`Module.php:61-81`) added `application/octet-stream`
to the installation-wide `media_type_whitelist` and never reverted it;
`isExeLearningFile()` accepted `['elpx', 'zip']` in four separate places.

## Technical design

### One resolver

New `src/Service/FilesPath.php`, a static `resolve($services): string` used by
`ElpFileServiceFactory`, `StylesServiceFactory` and `ContentControllerFactory`.
Order:

1. `Omeka\File\Store`, when it has `getLocalPath()`, `rtrim`'d of the trailing
   slash. Never `dirname()`. Rejected when the result is empty, which is what
   `Local` produces when its constructor's `realpath($basePath)` returned
   `false` for a directory that does not exist yet.
2. `$config['file_store']['local']['base_path']`, when it is a non-empty string.
3. `OMEKA_PATH . '/files'`.

The store comes first because it is the only source that is always right; the
config value is absent on a stock install. The `/var/www/html/volume/files`
override is deleted — it masked exactly this class of defect.

It lives in `src/Service/` rather than in a factory because `test/phpunit.xml`
excludes `*Factory.php` from the coverage gate, and this logic must be covered.
It is hinted loosely (`@param \Psr\Container\ContainerInterface|object`) because
`Interop\Container\ContainerInterface`, which the factories name, is not
vendored in this repository and a test calling the method would fatal on it.

### Failure marker

`processUploadedFile()` becomes a thin wrapper around the existing body
(`doProcessUploadedFile()`, which keeps its `@codeCoverageIgnore`): it records
`exelearning_process_error` before rethrowing and clears it on a later success.
Bookkeeping inside the catch is itself guarded, so it can never mask the real
failure. `getProcessingError()` / `hasProcessingError()` read it, and
`Module::handleAdminMediaShow()` gates on it and passes it to the partial, which
renders a notice.

### Deletion

The listener moves from `api.delete.pre` to `api.delete.post`.
`Omeka\Api\Manager::initialize()` builds the `.pre` event with
`['request' => $request]` and nothing else, so the `entity` parameter this code
read was never there and the cleanup silently did nothing on real Omeka — the
module's own `omeka-s-api-and-adapters` skill already said so.
`finalize()` builds the `.post` event with
`['request' => $request, 'response' => $response]`, and
`AbstractEntityAdapter::delete()` returns `new Response($entity)` after the
flush, so the removed entity is the response content; `finalize()` transforms
that content only after triggering the event, and `batchDelete()` finalizes each
subresponse, so batch deletes are covered too. Running after the delete has
succeeded is also better ordering: files go only once authorization and the
flush have passed.

`Module::handleMediaDelete()` reads the hash from that entity and calls
`ElpFileService::cleanupMediaByHash()`, a hash-taking sibling of the existing
`cleanupMedia()`. `getDataPath()`, `createDataDirectory()` and `deleteDirectory()`
are removed with the directory they served, along with `buildContentUrl()`,
`extractBasePath()`, `isTeacherModeVisible()` and `buildContentPath()` — copies
that became unreachable once the public partial and listener went, and which live
on in the controllers and the renderer that actually use them.

### Renderer

`ExeLearningRenderer` implements `Omeka\Media\FileRenderer\RendererInterface` and
`Omeka\Media\Renderer\RendererInterface`; both declare `render()` with no return
type, so the existing `: string` is covariant and legal on the PHP 7.4 floor.
`config/module.config.php` gains a `media_renderers` entry pointing at the same
factory. `file_renderers` keeps the factory and one alias, `elpx`, for media
stored before this module claimed them, whose `renderer` column is still `file`.

`view/exelearning/public/item-show.phtml` is deleted. The admin partial keeps
only the editor modal, because core's `admin/media/show.phtml` calls
`$media->render()` itself before triggering `view.show.after`; the edit button
moves into the renderer, gated on `isAdminRequest()`, `identity()`,
`userIsAllowed()` and `EditorBundle::isAvailable()`. The renderer's inline script
is scoped to its own viewer element so several packages on one page do not
rewrite each other's URLs.

The `view.show.after` listener survives as a compatibility shim — see
[ADR-39-01](../../adr/ADR-39-01-render-media-through-the-media-renderer-manager.md)
and the next section.

### Supported Omeka narrowed to `^4.0.0`, and the public listener deleted

`config/module.ini` drops `^3.0.0`. Omeka S 3 does not embed media on item pages
unless an administrator enables `item_media_embed`, so every S 3 site needed a
public compatibility listener. That was the only reason one existed, so dropping
S 3 lets it go entirely — which is what
`exelearning/exelearning#2443` asked for.

The PHP floor is unaffected and `composer.json:24` stays `>=7.4`: Omeka S 4.0 and
4.1 declare `"php": ">=7.4"`, and only 4.2 raises it to `">=8.1"`.

An intermediate design kept the listener for S 4 sites that removed
`mediaEmbeds`, deciding by reading the resolved block configuration. It was
implemented and withdrawn, for two reasons recorded in
[ADR-39-01](../../adr/ADR-39-01-render-media-through-the-media-renderer-manager.md):
removing `mediaEmbeds` is the supported way to say "do not embed media here", so
overriding it for one media type is wrong; and the configuration cannot answer
the question anyway, because a region only renders when the template invokes it
(`ResourcePageBlocks::__invoke($resource, $regionName = 'main')`), so a block
configured in an uninvoked region is configured and never rendered.

What remains is no inference at all: `handlePublicItemShow()`,
`itemPageEmbedsMedia()`, both service constants and the two test doubles behind
them are gone.

### Iframe sandbox — out of scope, see PR #21

Collapsing two viewers into one forces a single `sandbox` value to be written
down. The value kept is the one every executing path already emitted, purely so
this change does not alter the security posture while refactoring registration.

It is **not** an isolation boundary: `allow-same-origin` with `allow-scripts` on
content served from the Omeka origin lets package JavaScript act as that origin.
`ZipSafety` guards extraction and the proxy's CSP is defence-in-depth; neither
contains it.
[PR #21](https://github.com/exelearning/omeka-s-exelearning/pull/21) implements
the opaque-origin viewer that does, and owns that work. None of its design is
duplicated here. Full reasoning and cost:
[ADR-39-02](../../adr/ADR-39-02-preserve-current-iframe-behaviour-pending-opaque-origin-viewer.md).

### Claimed types

`ElpFileService::isExeLearningMedia()` is the single definition, used by
`Module::isExeLearningFile()`, `ExeLearningRenderer::isExeLearningFile()` and
both `EditorController` checks: `.elpx`, or any media carrying this module's own
`exelearning_extracted_hash`. `handleMediaHydrate()` stamps the renderer column
for `.elpx` only, and the admin inline JS heuristic follows.

`updateWhitelist()` adds `application/zip` and `application/x-zip-compressed` to
`media_type_whitelist` and `elpx` to `extension_whitelist`, records the entries
it actually added in `exelearning_whitelist_additions`, and skips a list it read
empty — Omeka's `File\Validator` reads an empty whitelist as "allow nothing", so
writing one back would break every upload on the site. `uninstall()` removes
exactly the recorded entries and the record.

### Retrying a failed extraction

`processUploadedFile()` records `exelearning_process_error` before rethrowing,
and the admin view gates on it, which is what stops the retry-on-every-render
loop. Left there, the marker would be permanent.

`handleMediaHydrate()` clears it. That runs on `api.hydrate.post` — a write —
so saving the media in the admin form is the retry: the administrator fixes the
cause, saves, and the next admin view of that media makes one more attempt. The
notice in the admin partial says so. No new route, no new button, no job
system, and extraction never returns to a GET request.

## ADRs required or referenced

| ADR | Decision |
| --- | --- |
| [ADR-39-01](../../adr/ADR-39-01-render-media-through-the-media-renderer-manager.md) | Narrow to Omeka S 4, route the viewer through `media_renderers`, keep a configuration-gated `view.show.after` listener for pages that removed `mediaEmbeds` |
| [ADR-39-02](../../adr/ADR-39-02-preserve-current-iframe-behaviour-pending-opaque-origin-viewer.md) | Preserve the current iframe behaviour unchanged; the trust boundary is out of scope and owned by PR #21 |

## Migration / rollout

### Omeka S 3 installations

`config/module.ini` now declares `^4.0.0`, so Omeka S 3 will not install this
version. Those sites stay on the last release that supported them; the release
notes must say which one that is. No data is affected.

### ELPX packages

Nothing to migrate. On an affected installation `processUploadedFile()` threw
before writing anything, so there are no extraction directories and no media
data to move. Every `.elpx` is still unprocessed and is extracted on the next
admin view, now into the correct directory.

### Uploaded styles

The exception. `StylesService::installFromZip()` does not read `files/original/`,
so style uploads succeeded — into `<OMEKA_PATH>/exelearning-styles/`. After this
change they resolve under `<files>/exelearning-styles/` and a previously
uploaded style 404s while the settings registry still advertises it.
Re-uploading the package restores it. An automatic `rename()` in `upgrade()` was
considered and rejected: it moves data outside the web root on every upgrade to
repair a state only reachable through a bug, and the failure mode without it is
visible and recoverable.

### The legacy upload whitelist

Every released version up to and including 4.0.5 added
`application/octet-stream` to the installation-wide `media_type_whitelist` and
never took it back. Narrowing `install()` alone would therefore only ever reach
fresh installations, and every upgraded site would keep the value forever —
defeating half of `exelearning/exelearning#2444`.

`upgrade()` now calls `dropLegacyWhitelistAdditions()` — but only when
`version_compare($oldVersion, Module::LAST_VERSION_WITH_LEGACY_WHITELIST, '<=')`,
i.e. once, on the upgrade that crosses the boundary. Running it unconditionally
would re-remove the value on every future upgrade, quietly undoing an
administrator who had deliberately re-added it, which contradicts the very
argument that makes removing it defensible. It removes exactly that one value
from `media_type_whitelist` and nothing else.

The provenance problem is real and unsolvable: those releases recorded nothing,
so whether the site already allowed `application/octet-stream` before installing
this module cannot be known. Removing it regardless is a deliberate hardening
call. It is defensible because the value is not an Omeka default, matches any
unidentified binary, and is not needed for `.elpx`; and it is recoverable
because an administrator who wants it can re-add it in Omeka's own settings,
where the decision is then recorded as theirs. The alternative — leaving it
forever because ownership is unknowable — keeps a site-wide upload relaxation
nobody asked for.

What the upgrade deliberately does **not** touch:

| Value | Why it stays |
| --- | --- |
| `application/zip` | An Omeka default, and `.elpx` needs it |
| `application/x-zip-compressed` | Needed where detection reports it |
| `zip` in `extension_whitelist` | An Omeka default and a site-wide capability. Withdrawing the renderer aliases is what stops this module claiming ordinary ZIP files; removing the extension would break ZIP uploads for the rest of the installation |
| every unrelated entry | Not this module's to remove |
| an empty whitelist | Omeka reads it as "allow nothing"; writing one back would break every upload |

Ownership bookkeeping stays conservative. The upgrade withdraws the value
without ever claiming it in `exelearning_whitelist_additions`, so that record
remains a log of what *this* version's `install()` demonstrably added, and a
later `uninstall()` never subtracts entries the module cannot prove it
contributed.

### Legacy `.zip` media

New `.zip` uploads are not claimed. A legacy `.zip` this module already
extracted carries `exelearning_extracted_hash`, and
`ElpFileService::isExeLearningMedia()` accepts that marker whatever the
extension, so those keep rendering, keep being served and are still cleaned up
on delete. One `||`, no migration.

A `.zip` uploaded under an older version that failed *before* extraction has no
marker and is not recovered; re-uploading as `.elpx` is the path. There are no
known deployments in that state, but the module has public releases, so the
limit is documented rather than assumed away.

Media already broken by the path defect do not backfill on public pages, because
extraction no longer runs during a GET. For `.elpx` the admin view repairs them.

## Testing strategy

- `test/ExeLearningTest/Service/FilesPathTest.php` pins `getLocalPath('')`'s
  trailing-slash shape and asserts that `dirname()` on it is wrong, so the
  defect cannot return silently. It covers store-first ordering, the `null`
  base path Omeka ships, a remote (S3) store with no `getLocalPath()`, an
  unresolved `realpath()`, a store that throws, and the no-trailing-slash
  postcondition.
- `ModuleTest` asserts the `media_renderers` registration, that the renderer
  satisfies the interface `Manager` enforces, and that the `file_renderers`
  aliases are exactly `['elpx' => …]`.
- `ExeLearningRendererTest` asserts `render()` returns non-empty HTML containing
  `exelearning-viewer` — the regression test the upstream issue asks for — plus
  the scoped URL rewriting and the admin-only edit button.
- `ModuleTest::testAttachListenersRegistersEveryOmekaHook` pins the listener
  roster, so the absence of a public `view.show.after` hook is asserted rather
  than assumed.
- `ModuleTest` covers media deletion against the event Omeka actually triggers
  (`entity.remove.post`, entity as target), including cascade removal from an
  item delete, and an explicit test that both previously-used shapes — an
  `entity` parameter and a `response` parameter — clean nothing.
- `ModuleTest` covers the upgrade: `application/octet-stream` withdrawn,
  unrelated entries and site-wide `zip` preserved, an empty whitelist left
  empty, no ownership claimed over pre-existing values, a later uninstall
  reverting only what was recorded, and idempotence across two upgrades.
- `ModuleTest` covers the retry path: saving a media clears a recorded failure
  and leaves its other data alone.
- `ElpFileServiceTest` covers the failure marker and the `.elpx`-only rule,
  including the legacy-`.zip`-with-hash carve-out.

`ExeLearningRenderer::render()` loses its `@codeCoverageIgnore`, which brings
~100 previously unmeasured lines into the gate.
`ElpFileService::doProcessUploadedFile()` keeps its annotation: it needs a real
filesystem and a real entity manager, and measuring it would drop coverage for
no added confidence.

## Verification

```sh
make lint
make test
make test-coverage    # 93.24% >= 90
make check-untranslated
make architecture-check
```

Coverage held at 93.24% against a 93.27% baseline despite the renderer entering
the gate. Note that `pcov.directory` may be set to `src` locally, which silently
excludes `Module.php`; run with `php -d pcov.directory=.` to reproduce what CI
measures with xdebug.
