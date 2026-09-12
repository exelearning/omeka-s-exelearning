---
name: elpx-editor-boundaries
description: "Change ELPX extraction, the content proxy, iframe previews, editor messaging, or save/load security."
---

# ELPX and editor boundaries

Read `src/Service/ZipSafety.php`, `src/Service/ElpFileService.php`,
`src/Controller/ContentController.php`, `src/Controller/ApiController.php`,
`src/Controller/CsrfValidationTrait.php`, `src/Media/FileRenderer/ExeLearningRenderer.php`,
and `asset/js/exelearning-editor.js` for the boundary you change.

- Validate ZIP entries and extracted paths before writing or serving them. Preserve hash validation
  and canonical-path containment; checking `..` alone is not a complete filesystem boundary.
- Derive the actual storage path from service factories/configuration; do not assume `/files/` and
  module `data/` are interchangeable. Serve extracted content only through the proxy, with the
  deployment's direct-access blocking rules in place.
- Keep HTML CSP and the script-free sandboxed SVG/XML policy distinct. Check actual response headers
  rather than copying a historical CSP string. The preview's sandbox excludes `allow-same-origin`.
  Interactive HTML still runs scripts: headers are mitigation, not proof uploaded content is trusted.
- The editor message handler checks both `event.origin` and `event.source` against the active editor
  iframe. Preserve both checks; the uploaded preview must not spoof save-complete or close messages.
- Every mutating custom endpoint needs CSRF and authorization. The shared trait rejects absent tokens;
  the current media-update ACL check must not be weakened. Verify resource-specific access for new endpoints.
- Save failures must keep recoverable original content and leave the editor's unsaved state truthful.
  Test replacement/cleanup paths, not only successful first upload.

Run the affected service/controller tests, then `make lint` and `make test-coverage`. For bridge or
header changes, inspect real preview/editor frames, denied requests and malformed packages. Consult
existing architecture records before changing these contracts; do not claim a full security audit from unit tests.
