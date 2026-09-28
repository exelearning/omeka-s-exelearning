<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

/**
 * Site representation exposing owner() and sitePermissions(), the two things
 * EditPermission reads. Users are represented only by their id.
 */
class FakeSite
{
    private ?int $ownerId;

    /** @var array<int, string> Site role keyed by user id. */
    private array $roles;

    /**
     * @param array<int, string> $roles Site role ('admin', 'editor', 'viewer') keyed by user id.
     */
    public function __construct(?int $ownerId = null, array $roles = [])
    {
        $this->ownerId = $ownerId;
        $this->roles = $roles;
    }

    public function owner(): ?object
    {
        return $this->ownerId === null ? null : self::user($this->ownerId);
    }

    /**
     * @return array<int, object>
     */
    public function sitePermissions(): array
    {
        $permissions = [];
        foreach ($this->roles as $userId => $role) {
            $permissions[] = new class (self::user($userId), $role) {
                private object $user;
                private string $role;

                public function __construct(object $user, string $role)
                {
                    $this->user = $user;
                    $this->role = $role;
                }

                public function user(): object
                {
                    return $this->user;
                }

                public function role(): string
                {
                    return $this->role;
                }
            };
        }
        return $permissions;
    }

    private static function user(int $id): object
    {
        return new class ($id) {
            private int $id;

            public function __construct(int $id)
            {
                $this->id = $id;
            }

            public function id(): int
            {
                return $this->id;
            }
        };
    }
}
