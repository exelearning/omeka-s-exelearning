---
id: ADR-40-01
title: "Decouple the module version from the bundled editor version"
status: Proposed
date: 2026-09-18
tracking_issue: 40
deciders:
  - "@erseco"
  - "claude-code"
related:
  issues: []
  prs: [40]
  changes: []
  adrs:
    - ADR-28-01
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-fable-5-1"
---

# ADR-40-01: Decouple the module version from the bundled editor version

## Context

Every module release bundles a static build of the eXeLearning editor under
`dist/static/`, and that bundle is the only editor the module ever serves
(ADR-28-01). Releases are produced by two workflows:

- `.github/workflows/check-editor-releases.yml` @ `f2f9db5` runs daily, and
  when `exelearning/exelearning` publishes a new release it builds that
  editor, writes its tag to `.editor-version`, packages the module with the
  **same** version number and publishes the GitHub release. Every release
  from `v4.0.0` to `v4.0.5` was produced this way.
- `.github/workflows/release.yml` @ `f2f9db5` runs on a pushed tag or on
  manual dispatch. On a pushed tag it used the tag both as the module
  version **and** as the editor ref to build
  (`EXELEARNING_EDITOR_REF=${RAW_TAG}`).

So far the module version and the bundled editor version have always been
identical. That coupling breaks as soon as the module needs to ship a
module-only fix before the next editor release: the fix must install over
`4.0.5`, yet stay below the `4.0.6` that the automated workflow will publish
together with editor `v4.0.6`. Pushing `v4.0.6-rc.1` failed at
`make build-editor`, because no such tag exists in the editor repository.

Omeka S decides whether an installed module needs an upgrade by comparing the
`version` in `config/module.ini` with the stored one using
`Composer\Semver\Comparator::greaterThan()`
(`application/src/Service/ModuleManagerFactory.php` @ Omeka S `v4.2.1`,
line 113; `composer.json` requires `composer/semver: ^3.2`). That comparison
runs while the module manager is built, on every request. Composer's
`VersionParser` accepts only the pre-release labels `alpha`/`a`, `beta`/`b`,
`RC`, `patch`/`pl`/`p`, `dev` and `stable`
(`composer/semver` `3.4.4`, `src/VersionParser.php`, `$modifierRegex`); any
other label makes `normalize()` throw `UnexpectedValueException`.

## Problem

How should a module-only release be versioned and built so that it installs
over the current release, is superseded by the next automated release, and
does not require an editor release that does not exist?

## Decision drivers

- Upgrade ordering must be correct for Omeka S (`composer/semver`) and for
  `Module::upgrade()`, which uses PHP `version_compare()`
  (`Module.php` @ `f2f9db5`, line 191).
- A version string must never break an installation: Omeka S parses it on
  every request.
- The automated editor-driven release flow must keep working unchanged.
- System administrators must be able to recognise the release as an
  installable, reviewable package.
- Minimal change to the existing workflows and the `make package` recipe.

## Alternatives considered

### Option 1: Wait for the next editor release

- Pro: no change.
- Con: a module bug stays unfixed for as long as the editor takes to
  release, which can be weeks. Rejected.

### Option 2: A custom label such as `4.0.6-prerelease.1` or `4.0.6-hotfix.1`

- Pro: reads as "not a real 4.0.6" to humans.
- Con: `composer/semver` rejects both strings
  (`VersionParser::normalize()` throws `UnexpectedValueException`, verified
  with `composer/semver` 3.4.4). A module shipped with such a version breaks
  the Omeka S module manager on every request of the installation. Rejected.

### Option 3: A fourth component, `4.0.5.1`

- Pro: `composer/semver` accepts it and orders it correctly
  (`4.0.5 < 4.0.5.1 < 4.0.6`, verified with 3.4.4); it reads as a stable
  patch of `4.0.5`, which is what the release actually is.
- Con: it is not SemVer 2.0 (three components), GitHub tooling and
  Dependabot-style consumers sort four-component versions inconsistently,
  and it diverges from the `vX.Y.Z` tag convention every existing release
  and the Playground blueprint follow. Kept as the fallback if a
  production-grade intermediate release is ever refused on the "rc" label
  alone; not chosen now.

### Option 4: Fully independent module versioning

- Pro: the module is free to move at its own pace.
- Con: the number no longer tells which editor is bundled, every consumer
  (Playground blueprint, release notes, support) needs a second field, and
  `check-editor-releases.yml` would need its own numbering policy. Far more
  change than the problem warrants. Rejected.

### Option 5: SemVer pre-release of the next version, editor from `.editor-version` (chosen)

- Pro: `4.0.5 < 4.0.6-rc.1 < 4.0.6` under `composer/semver` and
  `version_compare()`; `rc` is a standard label; the module already records
  the bundled editor tag in `.editor-version`, so the workflow needs no new
  state; the automated flow is untouched.
- Con: the label says "release candidate" although the package is meant to
  be installed in production; documented in the README and in the release
  notes.

## Evidence

- `.github/workflows/release.yml` @ `f2f9db5` — the tag was used as the
  editor ref; `.github/workflows/check-editor-releases.yml` @ `f2f9db5` —
  writes `.editor-version` and publishes module `X.Y.Z` for editor `vX.Y.Z`.
- `application/src/Service/ModuleManagerFactory.php` @ Omeka S `v4.2.1`,
  line 113 — `Comparator::greaterThan($moduleIni['version'], $moduleRow['version'])`.
- `composer/semver` `3.4.4`, `src/VersionParser.php`, `$modifierRegex` —
  accepted labels; reproducible check:
  `VersionParser::normalize('4.0.6-prerelease.1')` throws,
  `normalize('4.0.6-rc.1')` returns `4.0.6.0-RC1`.
- Ordering checks with `composer/semver` 3.4.4 `Comparator::greaterThan()`:
  `4.0.6-rc.1 > 4.0.5`, `4.0.6 > 4.0.6-rc.1`, `4.0.6-rc.2 > 4.0.6-rc.1`,
  all `true`.
- PHP `version_compare()` (used by `Module::upgrade()`, `Module.php` @
  `f2f9db5`): `4.0.6-rc.1 <= 4.0.5` is `false`, `4.0.6-rc.1 < 4.0.6` is
  `true`, so the one-off legacy-whitelist withdrawal runs exactly once.
- `make package VERSION=4.0.6-rc.1` @ `0333753` in an isolated worktree:
  the ZIP carries `version = "4.0.6-rc.1"` in `config/module.ini`,
  `.editor-version` = `v4.0.5` and the bundled `dist/static/`.

## Decision

We will version the module independently of the bundled editor:

- The module version is whatever the pushed tag (or the `release_tag`
  dispatch input) says. The bundled editor is built from the tag recorded in
  `.editor-version`, unless the dispatch inputs name another ref.
  `.editor-version` is the single source of truth for the bundled editor.
- A module-only release between two editor releases is a SemVer pre-release
  of the **next** version: `vX.Y.Z-rc.N`, where `X.Y.Z` is the version the
  automated flow will publish with the next editor. `N` starts at `1`.
- Accepted module version strings are `X.Y.Z` and
  `X.Y.Z-(alpha|beta|rc).N` only. `make package` refuses anything else,
  because Omeka S cannot parse it.
- A SemVer pre-release is published as a GitHub pre-release, so
  `releases/latest` keeps pointing at the last stable version.
- `check-editor-releases.yml` is unchanged: the next editor release still
  produces module `X.Y.Z`, which supersedes every `X.Y.Z-rc.N`.

## Consequences

### Positive

- Module-only fixes can ship the same day, with a version that upgrades
  cleanly from the previous release and is cleanly replaced by the next one.
- The release workflow no longer depends on an editor tag that matches the
  module tag.
- Version strings that would break an installation are rejected at
  packaging time.

### Negative

- An intermediate production release carries an `rc` label. Administrators
  who filter on "stable only" will not see it, and `releases/latest` will not
  offer it.
- Two tags now describe one release (module tag and editor tag); the release
  notes state the bundled editor version to keep this visible.

### Neutral

- Releases whose module and editor versions coincide are produced exactly as
  before.
- The manual-dispatch path no longer defaults to a `manual-<date>-<sha>`
  version; `release_tag` is required.

## Risks

- A pre-release is tagged from the wrong commit, bundling an editor that is
  not the one in `.editor-version`. Low: the workflow reads the file from
  the tagged commit itself.
- Someone tags `vX.Y.Z-rc.N` for a version the editor never publishes (for
  example the editor jumps to `4.1.0`). Harmless: `4.0.6-rc.1 < 4.1.0`, the
  next automated release still supersedes it.
- The `rc` label is refused by an institution's change policy. Fallback:
  Option 3 (`X.Y.Z.N`), which would require widening the `make package` guard
  and a superseding ADR.

## Validation

- `actionlint` on `release.yml`; `make lint` and `make architecture-check`.
- `make package` rejects `4.0.6-prerelease.1`, `4.0.6-hotfix.1`,
  `manual-20260918-abc1234`, `4.0.5.1` and `v4.0.6`; accepts `4.0.6`,
  `4.0.6-rc.1`, `4.0.0-rc3`, `4.0.0-beta1`, `4.0.6-alpha.2`.
- The first real intermediate release (`v4.0.6-rc.1` or whichever comes
  first) must install over `4.0.5` on an Omeka S 4 site and be offered an
  upgrade to `4.0.6` once the editor releases it.

## Follow-up work

- Cut the first intermediate release once a module-only fix needs it, and
  confirm the upgrade sequence on a real installation.

## References

- ADR-28-01 — the embedded editor is a release artifact.
- [Omeka S `ModuleManagerFactory.php` @ v4.2.1](https://github.com/omeka/omeka-s/blob/v4.2.1/application/src/Service/ModuleManagerFactory.php)
- [`composer/semver` `VersionParser.php` @ 3.4.4](https://github.com/composer/semver/blob/3.4.4/src/VersionParser.php)
- [Semantic Versioning 2.0.0, item 9 (pre-release versions)](https://semver.org/#spec-item-9)
- PR #40 — implementing pull request.
