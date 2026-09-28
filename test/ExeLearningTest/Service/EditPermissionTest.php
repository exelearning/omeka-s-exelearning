<?php
declare(strict_types=1);

namespace ExeLearningTest\Service;

use ExeLearning\Service\EditPermission;
use ExeLearningTest\Doubles\FakeItem;
use ExeLearningTest\Doubles\FakeSite;
use Omeka\Api\Representation\MediaRepresentation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ExeLearning\Service\EditPermission
 */
final class EditPermissionTest extends TestCase
{
    private const USER_ID = 7;

    /**
     * A media the core ACL does not let the user update, on an item assigned
     * to $sites.
     *
     * @param FakeSite[] $sites
     */
    private function mediaOnSites(array $sites): MediaRepresentation
    {
        $media = new MediaRepresentation(
            'http://example.com/course.elpx',
            'Course',
            'course.elpx',
            42,
            [],
            new FakeItem([], $sites)
        );
        $media->userIsAllowed = false;

        return $media;
    }

    private function user(int $id = self::USER_ID): object
    {
        return new class ($id) {
            private int $id;

            public function __construct(int $id)
            {
                $this->id = $id;
            }

            public function getId(): int
            {
                return $this->id;
            }
        };
    }

    public function testAnAnonymousVisitorMayNotEdit(): void
    {
        $media = $this->mediaOnSites([]);
        $media->userIsAllowed = true;

        $this->assertFalse(EditPermission::userCanEdit($media, null));
    }

    public function testAUserTheCoreAclLetsUpdateMayEdit(): void
    {
        // Owners, and global roles that may update any media.
        $media = $this->mediaOnSites([]);
        $media->userIsAllowed = true;

        $this->assertTrue(EditPermission::userCanEdit($media, $this->user()));
    }

    public function testASiteEditorMayEditMediaPublishedOnTheirSite(): void
    {
        $media = $this->mediaOnSites([new FakeSite(1, [self::USER_ID => 'editor'])]);

        $this->assertTrue(EditPermission::userCanEdit($media, $this->user()));
    }

    public function testASiteAdminMayEditMediaPublishedOnTheirSite(): void
    {
        $media = $this->mediaOnSites([
            new FakeSite(1, []),
            new FakeSite(1, [3 => 'editor', self::USER_ID => 'admin']),
        ]);

        $this->assertTrue(EditPermission::userCanEdit($media, $this->user()));
    }

    public function testTheSiteOwnerMayEditMediaPublishedOnTheirSite(): void
    {
        $media = $this->mediaOnSites([new FakeSite(self::USER_ID, [])]);

        $this->assertTrue(EditPermission::userCanEdit($media, $this->user()));
    }

    public function testASiteViewerMayNotEdit(): void
    {
        $media = $this->mediaOnSites([new FakeSite(1, [self::USER_ID => 'viewer'])]);

        $this->assertFalse(EditPermission::userCanEdit($media, $this->user()));
    }

    public function testAnEditorOfAnotherSiteMayNotEdit(): void
    {
        // Another user is editor here; this user's editor role is on a site
        // the item is not assigned to, so it never reaches this check.
        $media = $this->mediaOnSites([new FakeSite(1, [3 => 'editor'])]);

        $this->assertFalse(EditPermission::userCanEdit($media, $this->user()));
    }

    public function testMediaWithoutAnItemFallsBackToTheCoreAcl(): void
    {
        $media = new MediaRepresentation('http://example.com/course.elpx', 'Course', 'course.elpx');
        $media->userIsAllowed = false;

        $this->assertFalse(EditPermission::userCanEdit($media, $this->user()));
    }

    public function testAnIdentityWithoutAnIdFallsBackToTheCoreAcl(): void
    {
        $media = $this->mediaOnSites([new FakeSite(self::USER_ID, [])]);

        $this->assertFalse(EditPermission::userCanEdit($media, (object) ['name' => 'someone']));
    }
}
