<?php

declare(strict_types=1);

namespace ExeLearningTest\Doubles;

use Doctrine\ORM\EntityManager;

/**
 * Entity manager that hands back a caller-supplied entity.
 *
 * The stub under test/Stubs/ always returns null from find(), which makes
 * ElpFileService::updateMediaData() a no-op. Tests that need to observe what
 * the service persisted register this instead.
 */
class RecordingEntityManager extends EntityManager
{
    /** @var object|null */
    private $entity;

    /** @var int How many times flush() was called. */
    public int $flushes = 0;

    /**
     * @param object|null $entity
     */
    public function __construct($entity = null)
    {
        $this->entity = $entity;
    }

    /**
     * @param mixed $id
     * @return object|null
     */
    public function find(string $className, $id)
    {
        return $this->entity;
    }

    public function flush(): void
    {
        $this->flushes++;
    }
}
