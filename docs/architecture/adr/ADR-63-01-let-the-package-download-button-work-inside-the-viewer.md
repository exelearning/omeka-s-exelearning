---
id: ADR-63-01
title: "Let the package's own .elpx download button work inside the viewer"
status: Proposed
date: 2026-09-29
tracking_issue: 63
deciders:
  - "@erseco"
  - "claude-code"
related:
  issues:
    - "exelearning/exelearning#2488"
    - "exelearning/exelearning#2196"
  prs: [63, 21]
  changes: []
  adrs:
    - ADR-39-02
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5-5"
---

# ADR-63-01: Let the package's own .elpx download button work inside the viewer

## Context

eXeLearning packages can contain a *download-source-file* iDevice: a "Download
.elpx" button inside the content. Its inline `onclick` calls the package's global
`downloadElpx()` (`libs/exe_elpx_download/exe_elpx_download.js`). That function
refetches every file listed in `libs/elpx-manifest.js`, zips the files in the
browser with fflate, and clicks an `<a download>` inside the content document.

In this module that button never produced a file, and it failed in two
independent places:

1. **CSP.** `ContentController::addSecurityHeaders()` served HTML with
   `script-src 'self' 'unsafe-inline' 'unsafe-eval'` and no `worker-src`. The
   async `fflate.zip()` starts one `blob:` worker per large compressible file
   and listens only for its messages. The CSP blocks those workers, the callback
   never fires, and the button stays at "Processing... 100%"
   (exelearning/exelearning#2488).
2. **Sandbox.** The viewer iframe (`ExeLearningRenderer`) omits
   `allow-downloads`, so Chrome drops any download the frame starts
   ("Download is disallowed. The frame initiating or instantiating the download
   is sandboxed, but the flag 'allow-downloads' is not set").

Rebuilding the package is also a poor substitute for the original upload. It
refetches the whole package (137 MB and 833 files in the reported case) and
holds it in memory two to three times over. Packages exported before
exelearning/exelearning#2196 rebuild without `content.xml`, so the result cannot
be re-imported.

## Problem

How should the package's own "Download .elpx" button behave in the Omeka viewer?
The answer must cover packages that are already published, whose script cannot
be changed.

## Decision drivers

- Fix content that is already published, without re-exporting it.
- Give users the same file the toolbar already offers, byte for byte.
- Respect an administrator who hides the `.elpx` download format.
- Do not widen what package JavaScript can do. ADR-39-02 records that the
  current frame is not an isolation boundary, and PR #21 owns the real one.
- Keep working when PR #21 moves the viewer to an opaque origin.

## Alternatives considered

### Option 1: Wait for the upstream script fix

exelearning/exelearning#2489 adds a `zipSync` fallback when workers are blocked.
It helps only packages exported after it ships, and inside this frame the
download is still dropped by the sandbox. On its own it fixes nothing that users
see here.

### Option 2: Relax the CSP and the sandbox only

Add `worker-src 'self' blob:` and `allow-downloads`. The existing rebuild then
completes, including in already-published packages. It still refetches the whole
package, can still drop `content.xml`, and serves a rebuild where the original
exists.

### Option 3: Serve the original from the parent, and relax the CSP and the sandbox as a fallback

While the viewer offers the `.elpx` format and the frame is same-origin, the
viewer script (`asset/js/omeka-exe-download.js`) replaces the package's
`downloadElpx()` with a function that downloads the original upload, using the
toolbar's own code path. In every other case, such as a hidden `.elpx` format, a
top-level "Open in new window" view or a future opaque frame, the package's own
rebuild runs, and Option 2's relaxations let it finish.

## Evidence

- Reproduced on the live Mediateca, item 269964, with Playwright. Clicking the
  in-content button gave 34 `worker-src blob:` violations and no download after
  25 s.
- With exelearning/exelearning#2489's script served inside this module's
  iframe, compression finished but the download was dropped with the
  `allow-downloads` console message quoted above.
- With this change's `omeka-exe-download.js` served in place, the in-content
  button downloaded the original in about 11 s, with the same SHA-256 as the
  uploaded file.
- fflate 0.8.3 `zip()` compresses files under 160 kB on the calling thread and
  starts a worker for each larger compressible file
  (`node_modules/fflate/lib/browser.cjs:2294`). Its browser worker wrapper sets
  only `onmessage`.
- `src/Controller/ContentController.php` and
  `src/Media/FileRenderer/ExeLearningRenderer.php` on the base of this change
  (`bc360a9`).

## Decision

We will take Option 3:

- `asset/js/omeka-exe-download.js` routes the package's `downloadElpx()` to the
  original upload, per viewer, only while that viewer offers the `.elpx` format.
  A capturing `load` listener reapplies it on every page the frame navigates to.
  A cross-origin frame raises an access error, which is caught, and the package
  keeps its own download.
- HTML content gets `worker-src 'self' blob:`.
- The viewer iframe gets `allow-downloads`.

Neither relaxation grants new capability. Package scripts already run with
`'unsafe-inline'` and `'unsafe-eval'` in the Omeka origin, so they can already
run arbitrary code and start downloads through the same-origin parent.

## Consequences

### Positive

- The button works for every existing package: it gives the original when the
  toolbar offers it, and a completed rebuild otherwise.
- There is no full refetch and no in-memory rebuild in the common case.

### Negative

- The routing depends on the same-origin frame that ADR-39-02 calls temporary.
  Under PR #21 it silently stops applying and the rebuild path takes over.
- The parent reaches into package globals. If a future eXeLearning renames
  `downloadElpx`, the routing stops, and the package's own download still works.

### Neutral

- No change to the SVG/XML script-free policy or to `ZipSafety`.

## Risks

- A package could redefine `downloadElpx` after `load`. The only effect is that
  its own download runs instead of the original. Low severity.
- PR #21 must carry `allow-downloads` and a `worker-src` allowance into its
  response-level sandbox policy, or the fallback breaks there.

## Validation

- `ContentControllerTest::testCspContainsRequiredDirectives` pins
  `worker-src 'self' blob:`.
- The renderer sandbox test pins `allow-downloads`.
- Manual check in a real browser with a package that contains the
  download-source-file iDevice: the toolbar offered and the in-content button
  gives the original; the format hidden and the rebuild completes.

## Follow-up work

- PR #21: keep `allow-downloads` and a `worker-src blob:` allowance in the
  opaque-origin viewer policy.

## References

- exelearning/exelearning#2488 and #2489 (worker fallback), #2196 (manifest
  includes `content.xml`)
- [ADR-39-02](./ADR-39-02-preserve-current-iframe-behaviour-pending-opaque-origin-viewer.md)
- PR #21, PR #63
