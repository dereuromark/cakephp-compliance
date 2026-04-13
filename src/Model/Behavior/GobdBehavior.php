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
 *     'graceHours' => 24,
 * ]);
 * ```
 *
 * Rows older than `retentionYears` (measured from the configured date field)
 * are deletable. Rows younger than `graceHours` are ALSO deletable — this
 * grace window lets users correct bookkeeping mistakes immediately after
 * data entry without running into retention errors for legitimate cleanup.
 * Rows between the grace window and the retention cutoff throw
 * `GobdRetentionException` on delete.
 */
class GobdBehavior extends Behavior
{
    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'retentionYears' => 10,
        'dateField' => 'created',
        'graceHours' => 24,
    ];

    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $field = (string)$this->getConfig('dateField');
        $retentionYears = (int)$this->getConfig('retentionYears');
        $graceHours = (int)$this->getConfig('graceHours');

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

        $now = new DateTime();

        // Grace window: freshly created rows can still be deleted to correct
        // immediate mistakes. Defaults to 24 hours — configurable per table.
        if ($graceHours > 0) {
            $graceCutoff = $now->subHours($graceHours);
            if ($bookedAt->greaterThan($graceCutoff)) {
                return;
            }
        }

        $retentionCutoff = $now->subYears($retentionYears);

        if ($bookedAt->greaterThan($retentionCutoff)) {
            throw new GobdRetentionException(sprintf(
                'Row cannot be deleted: still inside the %d-year GoBD retention window (field "%s" = %s).',
                $retentionYears,
                $field,
                $bookedAt->format('Y-m-d'),
            ));
        }
    }

    /**
     * Returns true if the given entity is currently deletable per the
     * grace-window rule. Useful for hiding delete buttons in templates.
     */
    public function isDeletable(EntityInterface $entity): bool
    {
        $field = (string)$this->getConfig('dateField');
        $graceHours = (int)$this->getConfig('graceHours');
        $retentionYears = (int)$this->getConfig('retentionYears');

        $value = $entity->get($field);
        if ($value === null) {
            return false;
        }

        $bookedAt = $value instanceof DateTime
            ? $value
            : new DateTime((string)$value);

        $now = new DateTime();

        if ($graceHours > 0 && $bookedAt->greaterThan($now->subHours($graceHours))) {
            return true;
        }

        return !$bookedAt->greaterThan($now->subYears($retentionYears));
    }
}
