<?php

declare(strict_types=1);

namespace Compliance\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Compliance\Gobd\Exception\ImmutableRowException;

/**
 * Make rows immutable once a "finalized" flag is set.
 *
 * Attach to any Table whose rows must become write-once after a transition:
 *
 * ```php
 * $this->addBehavior('Compliance.Immutability', ['field' => 'finalized_at']);
 * ```
 *
 * Rules:
 *
 * - If the row's stored value of the configured field is NULL → edits are allowed.
 * - If the row's stored value is already set → save and delete both throw.
 * - The transition from NULL to a set value is allowed exactly once (the
 *   "finalize" action); after that the row becomes immutable.
 *
 * This is a defense-in-depth layer in PHP. Production deployments should
 * additionally install a DB-level `BEFORE UPDATE` / `BEFORE DELETE` trigger
 * so that raw SQL can't bypass the ORM — see `docs/migration-templates.md`.
 */
class ImmutabilityBehavior extends Behavior
{
    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'field' => 'finalized_at',
    ];

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if ($entity->isNew()) {
            return;
        }

        $field = (string)$this->getConfig('field');
        $original = $entity->getOriginal($field);

        if ($original !== null) {
            throw new ImmutableRowException(sprintf(
                'Row is finalized and cannot be modified (field "%s" was already set).',
                $field,
            ));
        }
    }

    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $field = (string)$this->getConfig('field');
        if ($entity->get($field) !== null) {
            throw new ImmutableRowException(sprintf(
                'Row is finalized and cannot be deleted (field "%s" is set).',
                $field,
            ));
        }
    }
}
