<?php

declare(strict_types=1);

namespace Compliance\Model\Behavior;

use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Compliance\Gobd\AuditChainWriter;

/**
 * Automatically appends a tamper-evident audit entry to
 * `compliance_audit_chain` every time an entity on this table is saved
 * or deleted. For imperative audit events (DSGVO exports, manual resets,
 * ad-hoc administrative actions), use {@see AuditChainWriter::log()}
 * directly instead of this behavior.
 *
 * ```php
 * class InvoicesTable extends Table
 * {
 *     public function initialize(array $config): void
 *     {
 *         parent::initialize($config);
 *         $this->addBehavior('Compliance.AuditChain');
 *     }
 * }
 * ```
 *
 * The payload captured for each row is the entity's
 * {@see EntityInterface::toArray()} projection, which is the same
 * surface CakePHP exposes to serializers. Use `$entity->setHidden([...])`
 * on sensitive fields (password hashes, 2FA secrets) to keep them out of
 * the audit trail — they will then be absent from the payload and the
 * hash alike.
 */
class AuditChainBehavior extends Behavior
{
    /**
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        // Logical source name recorded in the `source` column. Defaults to
        // the owning table's alias (e.g. `Invoices`).
        'source' => null,
        // Optional transaction id closure
        // `\Closure(\Cake\Datasource\EntityInterface):?string|null`.
        // Receives the entity and returns a string that groups audit rows
        // from a single logical unit of work. Useful when a controller
        // action touches several tables and you want all their audit rows
        // to share an id.
        'transactionId' => null,
        // Entity field name holding the tenant account FK. Set to null to
        // skip (single-tenant apps). Defaults to 'account_id'.
        'accountIdField' => 'account_id',
        // Entity field name holding the acting user FK. Set to null to
        // skip. Defaults to null (system-level auditing by default).
        'userIdField' => null,
    ];

    protected AuditChainWriter $writer;

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->writer = new AuditChainWriter();

        if ($this->getConfig('source') === null) {
            $this->setConfig('source', $this->table()->getAlias());
        }
    }

    /**
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event
     * @param \Cake\Datasource\EntityInterface $entity
     *
     * @return void
     */
    public function afterSave(EventInterface $event, EntityInterface $entity): void
    {
        $this->writer->log(
            eventType: $entity->isNew() ? 'create' : 'update',
            source: (string)$this->getConfig('source'),
            targetId: $this->extractId($entity),
            payload: $entity->toArray(),
            transactionId: $this->resolveTransactionId($entity),
            accountId: $this->extractInt($entity, $this->getConfig('accountIdField')),
            userId: $this->extractInt($entity, $this->getConfig('userIdField')),
        );
    }

    /**
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event
     * @param \Cake\Datasource\EntityInterface $entity
     *
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity): void
    {
        $this->writer->log(
            eventType: 'delete',
            source: (string)$this->getConfig('source'),
            targetId: $this->extractId($entity),
            payload: $entity->toArray(),
            transactionId: $this->resolveTransactionId($entity),
            accountId: $this->extractInt($entity, $this->getConfig('accountIdField')),
            userId: $this->extractInt($entity, $this->getConfig('userIdField')),
        );
    }

    protected function extractId(EntityInterface $entity): ?string
    {
        $primaryKey = (array)$this->table()->getPrimaryKey();
        if (count($primaryKey) === 1) {
            $value = $entity->get($primaryKey[0]);

            return $value === null ? null : (string)$value;
        }

        $values = [];
        foreach ($primaryKey as $column) {
            $values[$column] = $entity->get($column);
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }

    protected function resolveTransactionId(EntityInterface $entity): ?string
    {
        $callback = $this->getConfig('transactionId');
        if ($callback === null) {
            return null;
        }

        $result = $callback($entity);

        return $result === null ? null : (string)$result;
    }

    protected function extractInt(EntityInterface $entity, ?string $field): ?int
    {
        if ($field === null) {
            return null;
        }

        $value = $entity->get($field);

        return $value === null ? null : (int)$value;
    }
}
