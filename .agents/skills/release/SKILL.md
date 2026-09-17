---
name: release
description: "Prepare and inspect an ExeLearning module release ZIP for a requested version."
---

# Release package

Read the `Makefile` package target, `.distignore`, `config/module.ini` and editor build metadata.
Confirm the requested version is a valid release value; pass it as one quoted Make argument, never
execute raw skill arguments as shell text. Packaging is not permission to publish a release.

1. Run `make lint` and `make test-coverage`; report any unavailable coverage driver.
2. Use a clean isolated checkout for the version-mutating recipe: `make package VERSION=X.Y.Z`.
3. Inspect the ZIP: `ExeLearning/` root, module config and required runtime files, bundled editor in
   `dist/static/`, and no `.agents`, `.claude`, tests, credentials or development checkout.
4. Verify the packaged version and editor payload; check that the original working tree is unchanged.
5. Report artifact path, version and validation results. Publishing or tagging requires a release request.
