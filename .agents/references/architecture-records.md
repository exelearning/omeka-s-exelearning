# Architecture records

Paths below are relative to the repository root.


Significant technical work is documented alongside the code under
[`docs/architecture/`](../../docs/architecture/README.md). Full policy:
[ADR guide](../../docs/architecture/adr/README.md),
[change-document guide](../../docs/architecture/changes/README.md).

- **Identifiers are tracking numbers, not a counter.** ADRs are
  `ADR-<number>-<NN>-<decision-slug>.md`; change directories are
  `changes/<number>-<change-slug>/`. `<NN>` is two digits scoped to that number
  alone, starting at `01`, present even for a single ADR. The slug names the
  **decision**, not the topic.
- **In this repository the tracking number is always a pull-request number.**
  GitHub Issues are disabled here; issue tracking lives upstream in
  [`exelearning/exelearning`](https://github.com/exelearning/exelearning/issues),
  whose numbers come from a different sequence and must never be used as an
  identifier — link them under `related.issues` instead. **Never open an issue
  anywhere just to obtain a number.** A PR number only exists once the PR is
  open: open the PR first, then add the record in a follow-up commit on the same
  branch.
- Before implementing a significant architectural change, run
  `make architecture-records` to list the existing records. There is **no
  committed index** — it is derived from frontmatter on demand.
- **Create or update a change document** for large changes, cross-cutting
  features, security-sensitive changes, data/storage changes, module lifecycle
  changes, content-proxy changes, embedded-editor changes, upload/extraction
  changes, or changes affecting public rendering. They live under
  `docs/architecture/changes/<number>-<slug>/` as `proposal.md`, `spec.md`,
  `design.md`, `research.md` and `tasks.md` — create only the files that carry
  real content.
- **Create an ADR** for durable technical decisions with long-term consequences
  (storage layout, ELPX validation/extraction, content-proxy/CSP/iframe security
  model, CSRF/ACL boundaries, Omeka S event-hook contracts, Omeka S/PHP
  compatibility, the verification contract). ADRs live under
  `docs/architecture/adr/`.
- Templates: `docs/architecture/adr/template.md`,
  `docs/architecture/changes/template.md`.
- **Status lives in the frontmatter only.** Do not add a `## Status` section.
  The H1 must be exactly `# <id>: <title>`.
- Keep **accepted ADRs append-only** — supersede them with a new ADR
  (`supersedes` / `superseded_by`) instead of rewriting history. Preserve
  **implemented change documents** as historical records; fix only typos/links.
- When both exist, **link them** (the design's *ADRs required or referenced*
  table plus `related_adrs`, and the ADR's `related.changes`).
- Record AI assistance in the frontmatter (`ai_assistance.tool` /
  `ai_assistance.model`; `none` if not used). Use issue/PR links for
  attribution — no people's names in frontmatter or templates.
- **Retired identifiers** (the old four-digit `ADR-NNNN` / `SDD-NNNN` form) must
  not appear in new content; `make architecture-check` fails on them.
  [`docs/architecture/migration-map.md`](../../docs/architecture/migration-map.md)
  resolves each one to its current path.
- **Do not** create records for trivial fixes, copy edits, translation-only or
  test-only changes, or straightforward bug fixes that do not change
  architecture. Do not create one ADR per section of a design.
- Run `make architecture-check` before submitting. It also runs as part of
  `make lint`, and in CI through the *Architecture records* workflow, which —
  unlike the main CI job — is not filtered by `paths-ignore`, so a
  documentation-only PR is still validated.
- Keep all architecture docs in English. For PHP code, continue following the
  repository's coding standards and the testing/linting rules above.
