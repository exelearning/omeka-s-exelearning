<?php
declare(strict_types=1);

namespace ExeLearning\Service;

/**
 * Decides who may open an eXeLearning media in the embedded editor and save it.
 *
 * A user may edit when Omeka's own ACL lets them update the media (they own it,
 * or their global role may update any resource), or when they administer or
 * edit a site the media's item is published on. The site branch is this
 * module's own rule: core Omeka grants site permissions over site pages only,
 * never over the items assigned to the site.
 *
 * The edit button, the editor page and the save endpoint all ask this class,
 * so a user is never offered an editor whose save would then be refused.
 */
class EditPermission
{
    /** Site roles, and the site owner, that may edit the site's eXeLearning media. */
    public const SITE_ROLES = ['admin', 'editor'];

    /**
     * @param object $media A MediaRepresentation.
     * @param object|null $user The authenticated user entity, or null.
     */
    public static function userCanEdit($media, $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($media->userIsAllowed('update')) {
            return true;
        }

        $item = $media->item();
        if (!$item || !method_exists($user, 'getId')) {
            return false;
        }

        $userId = (int) $user->getId();
        foreach ($item->sites() as $site) {
            if (self::hasSiteRole($site, $userId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param object $site A SiteRepresentation.
     */
    private static function hasSiteRole($site, int $userId): bool
    {
        $owner = $site->owner();
        if ($owner && (int) $owner->id() === $userId) {
            return true;
        }

        foreach ($site->sitePermissions() as $permission) {
            $permissionUser = $permission->user();
            if ($permissionUser
                && (int) $permissionUser->id() === $userId
                && in_array($permission->role(), self::SITE_ROLES, true)
            ) {
                return true;
            }
        }

        return false;
    }
}
