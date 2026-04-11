<?php

declare(strict_types=1);

namespace Compliance\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Behavior;
use Compliance\Gobd\Exception\GobdRetentionException;

/**
 * Enforce GoBD / §147 AO retention by blocking hard deletes on rows whose
 * reference date is still inside the retention window.
 *
 * Attach to any Table holding financially relevant data:
 *
 * ```php
 * $this->addBehavior('Compliance.Gobd', [
 *     'retentionYears' => 10,
 *     'dateField' => 'booked_on',
 * ]);
 * ```
 *
 * Rows older than `retentionYears` (measured from the configured date field)
 * are deletable; younger rows throw `GobdRetentionException` on delete.
 */
class GobdBehavior extends Behavior
{
    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'retentionYears' => 10,
        'dateField' => 'created',
    ];

    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $field = (string)$this->getConfig('dateField');
        $retentionYears = (int)$this->getConfig('retentionYears');

        $value = $entity->get($field);
        if ($value === null) {
            throw new GobdRetentionException(sprintf(
                'Cannot evaluate retention: entity has no value for field "%s".',
                $field,
            ));
        }

        $bookedAt = $value instanceof DateTime
            ? $value
            : new DateTime((string)$value);

        $retentionCutoff = (new DateTime())->subYears($retentionYears);

        if ($bookedAt->greaterThan($retentionCutoff)) {
            throw new GobdRetentionException(sprintf(
                'Row cannot be deleted: still inside the %d-year GoBD retention window (field "%s" = %s).',
                $retentionYears,
                $field,
                $bookedAt->format('Y-m-d'),
            ));
        }
    }
}
