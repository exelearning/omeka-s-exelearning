# ExeLearning for Omeka S

Upload, preview and edit ELPX media. PHP 7.4 is the Composer compatibility floor;
check `config/module.ini` and CI for supported Omeka/runtime versions.
`CLAUDE.md` and `.github/copilot-instructions.md` point here.

## Working rules

- Write guidance, new documentation and PRs in English. Use English `feature/` or `hotfix/` branches.
- Read the implementation and callers first. Extend Omeka through its existing hooks, routes and
  service factories; do not patch core or introduce a parallel framework.
- Keep uploaded content behind `ContentController`. Preserve ZIP/path validation, iframe isolation,
  content-type-specific headers, CSRF checks and authorization as separate boundaries.
- Use `Module::extractBasePath()` for request-prefix handling; `getBasePath()` is unreliable in
  scoped installations. Keep the embedded editor and uploaded preview as separate trust contexts.
- PSR2 applies. Factories stay wiring-only. Test stubs are in `test/Stubs/`; collaborator doubles
  are in `test/ExeLearningTest/Doubles/`. Preserve gettext translation handling.

## Verification and commands

```sh
make lint              # PSR2 plus architecture records
make test              # PHPUnit
make test-coverage     # full suite plus MIN_COVERAGE gate
make architecture-check
```

PHP changes must pass lint and the full coverage gate. `MIN_COVERAGE` is a ratchet (90 currently),
covering `src/` and root `Module.php`, excluding factories; never lower it or narrow measurement to
make a change pass. See ADR-32-01. Guidance-only changes require frontmatter/link/symlink checks,
workflow validation and archive exclusions; run `make architecture-check` as well.
Report failures and missing prerequisites explicitly; fix the cause without weakening the gate.

`make up`, `make shell`, `make down` manage the local development environment.
`make build-editor` uses Bun and the editor source checkout. `make package VERSION=X.Y.Z` builds
an artifact and rewrites metadata; use the release skill before invoking it.

## Skills

Load only the skill matching the work:

| Skill | Task |
| --- | --- |
| `omeka-s-module-development` | Lifecycle, configuration, factories, settings and storage |
| `omeka-s-api-and-adapters` | API listeners, entity/representation payloads and custom endpoints |
| `omeka-s-testing` | Test doubles, stubs and coverage |
| `elpx-editor-boundaries` | ZIP extraction, content proxy, preview and editor messages |
| `add-service`, `add-route`, `add-event` | Concrete integration procedures |
| `verify`, `release`, `i18n` | Verification, packaging and translation workflows |
| `security-audit` | Vulnerability investigation (existing vendored skill) |
| `github-actions-hardening` | CI workflow review and changes |

For durable architecture decisions and significant cross-cutting changes, read
[the record procedure](.agents/references/architecture-records.md) and `docs/architecture/README.md`.
Use PR tracking numbers, preserve accepted history, and run `make architecture-check`.
Do not create architecture records for routine guidance or copy edits.

`.distignore` controls the release ZIP; `.gitattributes` controls source archives. They serve distinct
purposes. Keep agent tooling out of both, while retaining `dist/static/` in the release.

## Agent skills and automation

Read the matching skill in `.agents/skills/` when its task applies; load its references only as needed.
Claude Code uses symlinks in `.claude/skills/`, with `CLAUDE.md` pointing here.
Keep local procedures specific to this repository and update them when their paths or contracts change.

`github-actions-hardening` covers workflow changes. Repository policy uses version tags, not commit
SHAs: `actions/checkout@v7`, `peter-evans/create-pull-request@v8`, and
`devantler-tech/actions/update-agent-skills@v13.3.3` (no upstream `v13` tag at review time).
Use least-privilege jobs and pass untrusted values through environment variables, never inline scripts.

Install external skills with `gh skills install OWNER/REPO PATH --dir .agents/skills`;
refresh them with `gh skills update --all`. Keep vendored files verbatim and retain provenance
and licenses. Project rules take precedence over upstream advice. The weekly/manual updater opens
reviewable PRs; review instructions as behavior changes. Default-token PRs do not automatically run CI.
See [the skill assessment](.agents/references/skill-assessment.md) for the selection rationale.
