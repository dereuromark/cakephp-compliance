<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Tamper-evident audit trail backing `Compliance\Gobd\AuditChainWriter`
 * and `Compliance\Model\Behavior\AuditChainBehavior`.
 *
 * Every row links to its predecessor via `prev_hash` → `hash` (SHA-256).
 * Any later edit to a historic row invalidates that row's hash and
 * transitively every hash that follows, which `bin/cake gobd
 * verify_chain` walks in insertion order.
 *
 * For production-grade "revisionssichere Speicherung" combine this table
 * with a BEFORE UPDATE / BEFORE DELETE trigger that rejects all writes
 * (the writer only INSERTs). See docs/Gobd.md for a trigger template.
 */
class CreateComplianceAuditChain extends BaseMigration
{
    public function change(): void
    {
        $this->table('compliance_audit_chain')
            ->addColumn('account_id', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => 'Tenant account FK for multi-tenant scoping. Nullable for single-tenant apps.',
            ])
            ->addColumn('user_id', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => 'Acting user FK. Nullable for system-initiated events.',
            ])
            ->addColumn('transaction_id', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'comment' => 'Opaque grouping id linking audit rows from a single logical unit of work.',
            ])
            ->addColumn('event_type', 'string', [
                'limit' => 32,
                'null' => false,
                'comment' => 'create | update | delete | <custom verb>',
            ])
            ->addColumn('source', 'string', [
                'limit' => 128,
                'null' => false,
                'comment' => 'Logical name of the audited record (typically the table alias).',
            ])
            ->addColumn('target_id', 'string', [
                'limit' => 128,
                'null' => true,
                'default' => null,
                'comment' => 'Primary key of the audited record as a string.',
            ])
            ->addColumn('payload', 'text', [
                'null' => false,
                'comment' => 'Canonical JSON of the audited payload.',
            ])
            ->addColumn('prev_hash', 'string', [
                'limit' => 64,
                'null' => true,
                'default' => null,
                'comment' => 'SHA-256 of the previous row; null for the first row.',
            ])
            ->addColumn('hash', 'string', [
                'limit' => 64,
                'null' => false,
                'comment' => 'SHA-256 of (prev_hash || canonical_json(payload)).',
            ])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['account_id'], ['name' => 'idx_compliance_audit_chain_account'])
            ->addIndex(['transaction_id'], ['name' => 'idx_compliance_audit_chain_transaction'])
            ->addIndex(['source', 'target_id'], ['name' => 'idx_compliance_audit_chain_source_target'])
            ->addIndex(['hash'], ['name' => 'idx_compliance_audit_chain_hash'])
            ->addIndex(['created'], ['name' => 'idx_compliance_audit_chain_created'])
            ->create();
    }
}
