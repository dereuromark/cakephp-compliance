<?php

declare(strict_types=1);

namespace Compliance\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $id
 * @property string $action
 * @property array<string, mixed> $payload
 * @property string $initiator_id
 * @property string|null $approver_id
 * @property string $status
 * @property string|null $reason
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Cake\I18n\DateTime|null $resolved_at
 */
class PendingApproval extends Entity
{
    /**
     * @var string
     */
    public const STATUS_PENDING = 'pending';

    /**
     * @var string
     */
    public const STATUS_APPROVED = 'approved';

    /**
     * @var string
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];
}
