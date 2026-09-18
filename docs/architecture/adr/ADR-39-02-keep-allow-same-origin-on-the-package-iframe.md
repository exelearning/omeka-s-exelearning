---
id: ADR-39-02
title: "Keep allow-same-origin on the package iframe"
status: Proposed
date: 2026-09-18
tracking_issue: 39
deciders:
  - "@erseco"
  - "claude-code"
related:
  issues:
    - "exelearning/exelearning#2443"
  prs: [39]
  changes:
    - 39-renderer-registration-files-path-and-claimed-types
  adrs:
    - ADR-39-01
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5"
---

# ADR-39-02: Keep allow-same-origin on the package iframe

## Context

Extracted eXeLearning packages are user-uploaded content served same-origin
through `ContentController` and displayed in an iframe. Until this change the
module shipped two different `sandbox` values for that iframe, in two code paths
that each believed they were the viewer.

`src/Media/FileRenderer/ExeLearningRenderer.php:124` @ `184e53f` emitted
`sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"`.
`view/exelearning/public/item-show.phtml:93` and
`view/exelearning/admin/media-show.phtml:122` emitted the same list plus
`allow-same-origin`, with a comment explaining that the php-wasm playground's
service worker only intercepts same-origin documents.

The stricter of the two had never executed: the renderer was unreachable, for
the reasons recorded in [ADR-39-01](./ADR-39-01-render-media-through-the-media-renderer-manager.md).
Collapsing the two implementations into one forces the question to be answered
deliberately, because `allow-same-origin` together with `allow-scripts` on
same-origin content effectively neutralises the sandbox: the framed document can
reach the embedder's origin, its cookies and its storage.

## Problem

Should the iframe that displays an extracted package carry `allow-same-origin`,
and if so, what is actually containing the package's JavaScript?

## Decision drivers

- Packages are untrusted input: any user who can add media can upload one.
- The viewer must display real eXeLearning exports, which bundle their own CSS,
  JavaScript, fonts and images and fetch them at runtime.
- The module is demonstrated through the php-wasm playground linked from
  `README.md`, which serves Omeka from a service worker.
- A security posture that is stated once and enforced beats one that differs by
  code path.
- `ADR-39-01` widens the surfaces on which the renderer runs, so whatever is
  decided applies more broadly than before.

## Alternatives considered

### Option 1: Drop `allow-same-origin`

Maximally restrictive on paper: the package gets an opaque origin and cannot
touch Omeka's cookies or storage.

It also does not work. `ContentController::addSecurityHeaders()` serves HTML
packages under `default-src 'self'` with `script-src 'self' 'unsafe-inline'
'unsafe-eval'`, `style-src 'self' 'unsafe-inline'`, `img-src 'self' data: blob:`
and `connect-src 'self'` (`src/Controller/ContentController.php:147-176`). An
opaque origin never matches `'self'`, so every stylesheet, script, font and
image inside the package is blocked and the viewer renders a broken page. Under
the playground the service worker stops intercepting the document entirely and
the content 404s against the static host.

### Option 2: Keep `allow-same-origin`, and be explicit that the sandbox is not the boundary

What both live paths already did. The package's JavaScript runs in the Omeka
origin, so the containment has to come from elsewhere: from what is allowed into
the extraction directory, and from the headers the proxy sets on the way out.

### Option 3: Serve packages from a separate origin

The correct long-term answer: a distinct host or a per-package subdomain makes
`allow-same-origin` harmless, because "same origin" is then the package's own.
It requires a second hostname, deployment documentation for both nginx and
Apache, and rework of the content proxy and of every URL the viewer builds. It
also has to keep working inside the playground, where the origin is fixed. Out
of scope for a bug-fix change.

## Evidence

- `src/Controller/ContentController.php:140-192` @ `184e53f` sets, for HTML:
  `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, the CSP
  quoted above with `frame-ancestors 'self'`, `form-action 'none'` and
  `base-uri 'self'`, `Referrer-Policy: same-origin`, and
  `Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()`.
- The same method gives SVG and XML — which can carry inline script and are
  served from an attacker-controlled archive — a script-free, sandboxed CSP
  (`default-src 'none'`, `script-src 'none'`, `sandbox`).
- `src/Service/ZipSafety.php` validates archives before extraction; its tests are
  in `test/ExeLearningTest/Service/ZipSafetyTest.php`.
- `view/exelearning/public/item-show.phtml:90-93` @ `184e53f` documents the
  service-worker constraint that motivated `allow-same-origin`.
- The `elpx-editor-boundaries` skill records extraction, proxy and preview as
  separate trust boundaries.

## Decision

We will keep `allow-same-origin allow-scripts allow-popups
allow-popups-to-escape-sandbox` on the package iframe, in one place — the
renderer — and state in the code what is actually containing the package:
`ZipSafety`'s validation of what may be extracted, and the response headers
`ContentController` sets on what is served. The `sandbox` attribute is not the
boundary and the code no longer implies that it is.

## Consequences

### Positive

- One sandbox value, with a recorded reason, instead of two that differ by
  accident.
- The viewer displays real packages, including under the playground.
- The actual containment is named, so a future change that weakens `ZipSafety`
  or the proxy headers is visibly a security change.

### Negative

- Package JavaScript runs in the Omeka origin and can issue same-origin
  requests as the viewing user. For an administrator viewing an untrusted
  upload, that is a real privilege the sandbox would otherwise have removed —
  and, after ADR-39-01, on more pages than before.
- The honest fix is option 3, which this defers.

### Neutral

- No behaviour changes for existing installations: this is the value both live
  paths already emitted.

## Risks

- **Malicious package acting as the viewer.** High impact, low likelihood on a
  site where media upload is restricted to trusted staff; higher on a site that
  accepts uploads from untrusted accounts. Unchanged in kind by this ADR, wider
  in reach because the renderer now runs on more surfaces.
- **Mistaking the sandbox for protection.** Addressed by the comment at the
  emission site and by this record.

## Validation

`ExeLearningRendererTest::testRenderIncludesSecuritySandbox` asserts the exact
attribute value, so a change to it is a deliberate edit to a test that explains
why. `ContentControllerTest` covers the response headers this decision leans on.

Revisit if the module ever gains a separate content origin, or if upload
permissions widen.

## Follow-up work

- Evaluate serving `/exelearning/content/` from a distinct origin, which would
  make `allow-same-origin` harmless and is the durable fix.
- Document, for site administrators, that `.elpx` upload should be treated as a
  trusted-content permission.

## References

- exelearning/exelearning#2443, consequence 3
- [ADR-39-01](./ADR-39-01-render-media-through-the-media-renderer-manager.md)
- [Change 39](../changes/39-renderer-registration-files-path-and-claimed-types/design.md)
- `src/Controller/ContentController.php`, `src/Service/ZipSafety.php`
