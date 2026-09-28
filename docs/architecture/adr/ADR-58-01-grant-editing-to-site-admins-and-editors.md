---
id: ADR-58-01
title: "Grant eXeLearning editing to the admins and editors of the item's sites"
status: Proposed
date: 2026-09-28
tracking_issue: 58
deciders:
  - "@erseco"
  - "claude-code"
related:
  issues: []
  prs: [58]
  changes: []
  adrs:
    - ADR-39-02
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5-5"
---

# ADR-58-01: Grant eXeLearning editing to the admins and editors of the item's sites

## Context

Before this change the editor could be opened only from the admin media page, and
only by a user who passed core Omeka's `update` check on the media
(`$media->userIsAllowed('update')`). That check was used in three places: the edit
button in `src/Media/FileRenderer/ExeLearningRenderer.php`, `editAction()` in
`src/Controller/EditorController.php`, and `saveAction()` in
`src/Controller/ApiController.php` (all at `0921098`).

Core Omeka grants media `update` to the media's owner (roles `author` and `reviewer`,
through `OwnsEntityAssertion`) and to global roles that may update any resource
(`editor`, `global_admin`). Site permissions (`admin`, `editor`, `viewer` on a
site) are consulted only for `Omeka\Entity\Site` and `Omeka\Entity\SitePage`
resources. They never apply to items assigned to the site
(`application/src/Service/AclFactory.php` in omeka/omeka-s).

Sites are how content is organised for the people who publish it. Those people
asked to edit the eXeLearning content published on their site, including from the
public site while logged in.

## Problem

Who may open an eXeLearning media in the embedded editor and save it back?

## Decision drivers

- Site admins and editors must be able to maintain the packages published on
  their site without being made global editors.
- The button, the editor page and the save endpoint must follow the same rule.
  Otherwise users are offered an editor whose save fails, or the save endpoint
  accepts a user the UI would never show the button to.
- Saving writes the file and extraction through `ElpFileService` and the entity
  manager, not through the Omeka API. Core ACL is therefore not re-checked on
  write, and this module's check is the only gate.
- Keep core's meaning of site roles: `viewer` is read-only.

## Alternatives considered

### Option 1: Keep core's `update` ACL only

This option needs no new rule. However, site editors who do not own an item can
never edit its package, which is what was asked for.

### Option 2: Add an ACL rule to `Omeka\Entity\Media` for site roles

A custom assertion allowing `update` on media would make site editors pass
`userIsAllowed('update')` everywhere, including core's media and item edit forms
and the REST API. That goes well beyond eXeLearning editing and changes core
behaviour for every media type.

### Option 3: A module-level policy for eXeLearning editing only

A single `EditPermission::userCanEdit()` allows the edit when core's `update`
check passes, or when the user is the owner, an admin or an editor of a site the
item is assigned to. The rule applies only to this module's button, editor page and
save endpoint.

## Evidence

- Core ACL rules for sites and site pages, and the absence of site rules for
  items: `application/src/Service/AclFactory.php` and
  `application/src/Permissions/Assertion/HasSitePermissionAssertion.php` in
  [omeka/omeka-s](https://github.com/omeka/omeka-s).
- The save path writes through the entity manager, not the API:
  `ElpFileService::replaceFile()` in `src/Service/ElpFileService.php`.
- `test/ExeLearningTest/Service/EditPermissionTest.php` covers each branch of the
  rule.

## Decision

We will use Option 3. `src/Service/EditPermission.php` is the only authority for
eXeLearning editing. The edit button, `EditorController::editAction()` and
`ApiController::saveAction()` all call it. The site roles that grant editing are
`admin` and `editor`, plus the site owner. The edit button is shown wherever the
media is rendered, on admin and public pages, and the editor script builds its
modal on first use.

## Consequences

### Positive

- Site admins and editors can maintain the packages on their sites, from admin or
  from the public site.
- One rule governs the whole editing flow, so the UI and the endpoint cannot drift
  apart.

### Negative

- For site roles, editing rights for eXeLearning packages are wider than core's
  rights for the same media's metadata. A site editor can replace the package but
  cannot edit the media's properties in core's form.

### Neutral

- Core ACL, core forms and the REST API are unchanged.
- The teacher-mode endpoint keeps core's `update` check, because it backs core's
  media edit form.

## Risks

- An item assigned to several sites can be edited by the editors of any of those
  sites. This matches how the item is shared, but site owners should know about it.
- Until the opaque-origin viewer from
  [PR #21](https://github.com/exelearning/omeka-s-exelearning/pull/21) lands, package
  scripts run as the Omeka origin ([ADR-39-02](./ADR-39-02-preserve-current-iframe-behaviour-pending-opaque-origin-viewer.md)).
  Showing the viewer to more users with save rights increases the number of
  sessions a hostile package could act as. It does not create a new class of
  exposure.

## Validation

- `EditPermissionTest` covers the core-ACL, site-owner, site-admin, site-editor,
  site-viewer and other-site cases.
- Renderer tests cover the button on a public request and for a site editor who
  does not own the media.
- Manual check on a running Omeka as an owner, a non-owning site editor, a site
  viewer and an anonymous visitor.

## Follow-up work

- Revisit the risk above when PR #21 replaces the same-origin viewer.

## References

- [PR #58](https://github.com/exelearning/omeka-s-exelearning/pull/58)
- [PR #21](https://github.com/exelearning/omeka-s-exelearning/pull/21)
- [ADR-39-02](./ADR-39-02-preserve-current-iframe-behaviour-pending-opaque-origin-viewer.md)
- [omeka/omeka-s `AclFactory`](https://github.com/omeka/omeka-s/blob/develop/application/src/Service/AclFactory.php)
