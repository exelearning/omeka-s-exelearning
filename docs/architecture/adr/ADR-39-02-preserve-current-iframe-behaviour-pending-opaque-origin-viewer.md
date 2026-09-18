---
id: ADR-39-02
title: "Preserve the current package iframe behaviour pending the opaque-origin viewer"
status: Proposed
date: 2026-09-18
tracking_issue: 39
deciders:
  - "@erseco"
  - "claude-code"
related:
  issues:
    - "exelearning/exelearning#2443"
  prs: [21, 39]
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

# ADR-39-02: Preserve the current package iframe behaviour pending the opaque-origin viewer

> **This ADR records a temporary state, not a target architecture.** It exists
> because [ADR-39-01](./ADR-39-01-render-media-through-the-media-renderer-manager.md)
> collapses two viewer implementations into one and something had to be emitted.
> The iframe trust boundary is owned by
> [PR #21](https://github.com/exelearning/omeka-s-exelearning/pull/21), which
> replaces this behaviour with an opaque-origin viewer. When #21 lands, its
> design is authoritative and this decision is superseded.

## Context

Extracted eXeLearning packages are user-uploaded content served from the Omeka
origin by `ContentController` and displayed in an iframe. Until this change the
module shipped two different `sandbox` values for that iframe, in two code paths
that each believed they were the viewer.

`src/Media/FileRenderer/ExeLearningRenderer.php:124` @ `184e53f` emitted
`sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox"`.
`view/exelearning/public/item-show.phtml:93` and
`view/exelearning/admin/media-show.phtml:122` emitted the same list **plus**
`allow-same-origin`.

The stricter of the two had never executed: the renderer was unreachable, for
the reasons recorded in ADR-39-01. So the value users actually got, on every
page, was the permissive one. Collapsing the two implementations into one forces
a single value to be written down, which is the only question this ADR answers.

## Problem

Change 39 fixes renderer registration, files-path resolution and the file types
this module claims. It is not the change that fixes the iframe trust boundary.
Which `sandbox` value should the single surviving code path emit, given that the
security architecture is being replaced by separate work already in review?

## Decision drivers

- Change 39 must not silently alter the security posture users run today, in
  either direction, while refactoring something else.
- PR #21 already implements the real fix and owns that design; duplicating or
  half-porting it here would produce two competing architectures in flight.
- The module must keep displaying real eXeLearning exports, which load their own
  CSS, JavaScript, fonts and images from the package.
- Whatever is emitted, the documentation must describe its security value
  accurately, because a wrong description outlives the code.

## Alternatives considered

### Option 1: Drop `allow-same-origin` here

Superficially a hardening step. It is not one, and it breaks the viewer.

`ContentController::addSecurityHeaders()` serves HTML packages under
`default-src 'self'` (`src/Controller/ContentController.php:147-176`). An opaque
origin never matches `'self'`, so every stylesheet, script, font and image inside
the package is blocked and the viewer renders a broken page. Under the php-wasm
playground the service worker stops intercepting the document and the content
404s outright.

Making the opaque origin work requires the response-level sandbox CSP, the
package-side shim and the external-media relay that PR #21 implements. Doing a
fragment of it here would ship a broken viewer under the banner of security.

### Option 2: Port PR #21's opaque-origin architecture into change 39

The correct destination, in the wrong change. PR #21 is 58 files: `IframeSandbox`,
preview snapshot storage, an authless capability-URL preview route with its own
CSRF handling, the embed shim and relay, and nginx configuration. Merging that
into a renderer-registration and path-resolution fix would make both
unreviewable, and the two would then have to be untangled again.

### Option 3: Emit the value both live paths already emit, and say plainly what it is worth

Keep `allow-same-origin allow-scripts allow-popups
allow-popups-to-escape-sandbox` — behaviour-preserving, since it is what every
executing path already produced — and record without euphemism that this is not
an isolation boundary and that PR #21 is the fix.

## Evidence

- `src/Media/FileRenderer/ExeLearningRenderer.php:124` @ `184e53f` (unreachable,
  stricter) versus `view/exelearning/public/item-show.phtml:93` and
  `view/exelearning/admin/media-show.phtml:122` @ `184e53f` (live, permissive).
  Reachability is established in ADR-39-01.
- `src/Controller/ContentController.php:140-192` @ `184e53f` sets, for HTML:
  `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, a CSP of
  `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src
  'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' data:
  blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self';
  frame-ancestors 'self'; form-action 'none'; base-uri 'self'`,
  `Referrer-Policy: same-origin`, and
  `Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()`.
- The same method gives SVG and XML a script-free, sandboxed CSP.
- `src/Service/ZipSafety.php` validates archives before extraction.
- [PR #21](https://github.com/exelearning/omeka-s-exelearning/pull/21),
  `feature/secure-iframe-sandbox`: opaque-origin viewer under a response-level
  sandbox CSP, plus an opt-in opaque editor preview.

## Decision

We will emit `sandbox="allow-same-origin allow-scripts allow-popups
allow-popups-to-escape-sandbox"` from the single surviving code path, matching
what every executing path already produced before this change, **as a
behaviour-preservation decision only**.

We state explicitly, here and in the code comment at the emission site:

- **`allow-same-origin` together with `allow-scripts`, on content served from
  the Omeka origin, is not a meaningful isolation boundary.** The package's
  JavaScript runs in the Omeka origin. It can read and write that origin's
  cookies and storage, and issue same-origin requests with the viewing user's
  session — including, for an administrator, authenticated requests to Omeka's
  own API. Nothing in change 39 constrains that.
- **`ZipSafety` does not contain it.** `ZipSafety` is a pre-extraction guard: it
  rejects path traversal (zip slip), PHP-capable and otherwise forbidden
  entries, and caps decompression to stop zip bombs. It governs *what may be
  written to disk*. It has no bearing on what JavaScript inside an otherwise
  well-formed package does once that package is loaded in a document on the
  Omeka origin. It remains valuable, for exactly the things it does.
- **The proxy's CSP, `Referrer-Policy` and `Permissions-Policy` are
  defence-in-depth, not origin isolation.** They restrict where the document may
  load resources from, where it may submit forms, what it may frame and which
  device APIs it may touch. They are real and worth keeping. They do not stop
  same-origin script from acting as the viewing user, because `connect-src
  'self'` is precisely the origin that must be protected from.
- **Change 39 does not fix this, deliberately.** PR #21 owns it.

## Consequences

### Positive

- One sandbox value with a recorded reason, instead of two that differed by
  accident and were never reconciled.
- Change 39 is reviewable as what it is: a correctness fix.
- The security posture is described accurately, so nobody reading this
  repository concludes that the same-origin iframe is contained.
- PR #21 rebases onto a codebase with one viewer instead of two, which is
  strictly less work than rebasing onto the previous duplication.

### Negative

- **The module ships a known, unfixed weakness for as long as this ADR stands.**
  Arbitrary JavaScript in an uploaded package runs with the Omeka origin's
  privileges for whoever views it. After ADR-39-01 the renderer reaches more
  surfaces than the old listener did — media show pages, site page blocks, item
  showcases, search results — so the same weakness is reachable from more
  places, even though its nature is unchanged.
- Anyone reading only the code sees a permissive sandbox; the reason it is
  permissive lives here and in the comment at the emission site.

### Neutral

- No behaviour change for existing installations: this is the value the live
  paths already emitted.

## Risks

- **A malicious or compromised package acting as the viewing user.** High
  impact. Likelihood scales with who may upload media: low where that is
  restricted to trusted staff, material where it is not. Unchanged in kind by
  this ADR; reachable from more surfaces after ADR-39-01. Mitigated only by
  restricting who may upload `.elpx`, until PR #21 lands.
- **This ADR being read as an endorsement.** Addressed by the banner above, the
  title, and the comment at the emission site.

## Validation

`ExeLearningRendererTest::testRenderIncludesSecuritySandbox` asserts the exact
attribute value, so changing it means editing a test that explains why. The test
and the code comment both name PR #21 as the fix.

This ADR is validated by being superseded. When PR #21 merges, its opaque-origin
design becomes authoritative, this ADR moves to `Superseded`, and whichever ADR
or change document #21 brings should list this one in `supersedes` with the
matching `superseded_by` here.

## Follow-up work

- **Merge PR #21.** That is the fix.
- Whichever of #21 and #39 is rebased second must preserve #21's opaque-origin
  model and must not reintroduce the temporary value recorded here. Both
  branches touch `src/Media/FileRenderer/ExeLearningRenderer.php`,
  `src/Controller/ContentControllerFactory.php`, `Module.php`,
  `config/module.config.php` and the admin media-show partial, and #39 deletes
  `view/exelearning/public/item-show.phtml` while #21 modifies it.
- Until then, document for site administrators that permission to upload `.elpx`
  should be treated as permission to run JavaScript on the site.

## References

- exelearning/exelearning#2443, consequence 3
- [PR #21 — Secure opaque-origin viewer and opt-in opaque editor preview](https://github.com/exelearning/omeka-s-exelearning/pull/21)
- [ADR-39-01](./ADR-39-01-render-media-through-the-media-renderer-manager.md)
- [Change 39](../changes/39-renderer-registration-files-path-and-claimed-types/design.md)
- `src/Controller/ContentController.php`, `src/Service/ZipSafety.php`
