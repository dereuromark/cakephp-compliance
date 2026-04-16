# Gobd — German Tax Retention & Immutability

The `Compliance\Gobd` sub-area implements three compliance primitives that German tax law requires for any digital bookkeeping system:

1. **Retention enforcement** (§147 AO) — financial records must be preserved for **10 years** after the end of the fiscal year in which they were created.
2. **Immutability** of finalized records — once an invoice, statement, or booking is "closed," it must not be mutated. Corrections are allowed only as *new* entries (Stornobuchungen) referencing the original.
3. **Revisionssichere Speicherung** (tamper-evident storage) — the audit trail must be cryptographically verifiable so auditors can detect after-the-fact modifications.

This document covers the plugin's implementation of each.

---

## GobdBehavior — retention enforcement

### Attach to any Table holding financial data

```php
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('Compliance.Gobd', [
        'retentionYears' => 10,
        'dateField' => 'booked_on',
    ]);
}
```

### Configuration

| Option | Default | Purpose |
|---|---|---|
| `retentionYears` | `10` | Years after `dateField` during which deletes are blocked |
| `dateField` | `'created'` | The entity property that defines the reference date |

### Behavior

On every `delete()`, the behavior reads the entity's value of `dateField`, computes a retention cutoff (`now - retentionYears`), and throws `GobdRetentionException` if the entity's date is still inside the window.

```php
$row = $table->find()->where(['amount' => '200.00'])->firstOrFail();
$table->delete($row); // throws if booked_on > now - 10 years
```

### Rows older than the retention window are deletable

Applications that want to actually purge old records after 10+ years can simply call `delete()` normally — the behavior allows the operation once the row's `dateField` is outside the cutoff.

### Why date-field-driven (not created-timestamp-driven)?

Different business flows have different "reference dates": an invoice's retention clock starts on the booking date, not the creation timestamp. A rent receipt's clock starts on the receipt date. Configurable `dateField` lets each Table pick the right one.

---

## ImmutabilityBehavior — finalized records are write-once

### Attach to any Table with a finalize workflow

```php
$this->addBehavior('Compliance.Immutability', [
    'field' => 'finalized_at',
]);
```

### Behavior

- While `finalized_at` is `NULL`, the row is freely editable.
- Setting `finalized_at` from `NULL` to a value is allowed exactly once (the finalize action).
- Once `finalized_at` is set, any subsequent `save()` or `delete()` throws `ImmutableRowException`.

```php
$invoice = $table->get(1);
$invoice->set('amount', '150.00');
$table->save($invoice); // OK while draft

$invoice->set('finalized_at', new \Cake\I18n\DateTime());
$table->save($invoice); // OK — the finalize transition

$invoice->set('amount', '200.00');
$table->save($invoice); // throws ImmutableRowException
```

### Defense-in-depth: DB-level triggers

The behavior is an application-level guard. A raw SQL `UPDATE` bypassing the ORM would still succeed. For a full GoBD posture, add DB-level triggers too.

**PostgreSQL migration template:**

```sql
CREATE OR REPLACE FUNCTION compliance_reject_finalized_update() RETURNS trigger AS $$
BEGIN
    IF OLD.finalized_at IS NOT NULL AND NEW.finalized_at IS NOT NULL THEN
        RAISE EXCEPTION 'Row is finalized and cannot be modified';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER invoices_immutability
    BEFORE UPDATE ON invoices
    FOR EACH ROW
    EXECUTE FUNCTION compliance_reject_finalized_update();

CREATE OR REPLACE FUNCTION compliance_reject_finalized_delete() RETURNS trigger AS $$
BEGIN
    IF OLD.finalized_at IS NOT NULL THEN
        RAISE EXCEPTION 'Row is finalized and cannot be deleted';
    END IF;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER invoices_immutability_delete
    BEFORE DELETE ON invoices
    FOR EACH ROW
    EXECUTE FUNCTION compliance_reject_finalized_delete();
```

**MySQL 8.0+ migration template:**

```sql
DELIMITER $$

CREATE TRIGGER invoices_immutability
    BEFORE UPDATE ON invoices
    FOR EACH ROW
BEGIN
    IF OLD.finalized_at IS NOT NULL AND NEW.finalized_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Row is finalized and cannot be modified';
    END IF;
END$$

CREATE TRIGGER invoices_immutability_delete
    BEFORE DELETE ON invoices
    FOR EACH ROW
BEGIN
    IF OLD.finalized_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Row is finalized and cannot be deleted';
    END IF;
END$$

DELIMITER ;
```

---

## HashChain — tamper-evident audit

### The principle

Every audit entry is SHA-256 hashed together with the hash of the *previous* entry. Mutating any entry, reordering entries, or removing entries breaks the chain and is detectable by recomputing hashes end-to-end.

```
entry_1.hash = sha256(GENESIS         || canonicalize(entry_1.payload))
entry_2.hash = sha256(entry_1.hash    || canonicalize(entry_2.payload))
entry_3.hash = sha256(entry_2.hash    || canonicalize(entry_3.payload))
...
```

### Usage (standalone)

```php
use Compliance\Gobd\HashChain;

$entry1 = HashChain::hash(null, ['event' => 'create', 'id' => 1]);
$entry2 = HashChain::hash($entry1, ['event' => 'update', 'id' => 1, 'amount' => 100]);

// Later — verify a chain
$entries = [
    ['payload' => [...], 'prev_hash' => null,        'hash' => '...'],
    ['payload' => [...], 'prev_hash' => '...',       'hash' => '...'],
];
HashChain::verify($entries); // true | false
```

### Canonical encoding

Payloads are canonicalized by recursive key sorting before hashing, so two payloads with the same keys in different order produce the same hash:

```php
HashChain::hash(null, ['a' => 1, 'b' => 2]) === HashChain::hash(null, ['b' => 2, 'a' => 1]);
```

This is important because JSON field order is not stable across PHP versions, database drivers, and serializers.

---

## AuditChainWriter + AuditChainBehavior — wiring the chain

The plugin ships two self-contained pieces of writing machinery — no
dependency on any other audit plugin.

### `Compliance.AuditChain` behavior for automatic ORM auditing

Attach to any Table whose saves and deletes must land in the chain:

```php
// src/Model/Table/InvoicesTable.php
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('Compliance.AuditChain');
}
```

The behavior listens to `Model.afterSave` / `Model.afterDelete`, captures
`$entity->toArray()` as the payload, and delegates to
`AuditChainWriter::log()`. Sensitive fields (password hashes, 2FA
secrets) should be marked `setHidden()` on the entity so they are
absent from both the payload and the resulting hash.

Optional behavior config:

```php
$this->addBehavior('Compliance.AuditChain', [
    'source' => 'Invoices',           // defaults to the table alias
    'transactionId' => static fn ($entity) => $entity->get('transaction_uuid'),
]);
```

### `AuditChainWriter` for imperative, non-ORM events

For DSGVO exports, data resets, administrative actions, or any other
event that doesn't map cleanly to a single entity save, call the writer
directly:

```php
use Compliance\Gobd\AuditChainWriter;

$writer = new AuditChainWriter();
$writer->log(
    eventType: 'dsgvo_export',
    source: 'Accounts',
    targetId: (string)$account->id,
    payload: [
        'exported_by_user_id' => $user->id,
        'exported_at' => (string)DateTime::now(),
        'file_hash' => $sha256,
    ],
);
```

Batch operations that should live in one transaction use `logMany()`.

### Table

The plugin ships a migration for the `compliance_audit_chain` backing
table — run it with the standard plugin migrate command:

```bash
bin/cake migrations migrate -p Compliance
```

Or wire it into your `composer.json` `migrate` script alongside the
other plugin migrations.

Columns:

| Column           | Type         | Notes |
|------------------|--------------|-------|
| `id`             | auto-increment | |
| `transaction_id` | VARCHAR(64)  | Optional grouping id for a logical unit of work |
| `event_type`     | VARCHAR(32)  | `create` \| `update` \| `delete` \| custom verb |
| `source`         | VARCHAR(128) | Typically the table alias |
| `target_id`      | VARCHAR(128) | Primary key of the audited record as a string |
| `payload`        | TEXT         | Canonical JSON of the audited payload |
| `prev_hash`      | VARCHAR(64)  | SHA-256 of the previous row; null for the first row |
| `hash`           | VARCHAR(64)  | SHA-256 of `(prev_hash \|\| canonical_json(payload))` |
| `created`        | DATETIME     | |

Indexes on `transaction_id`, `(source, target_id)`, `hash`, `created`.

### Concurrency

`AuditChainWriter` wraps every `log()` / `logMany()` call in a
transaction. On MySQL and Postgres it first acquires a per-table
advisory lock (`GET_LOCK` / `pg_try_advisory_lock`) with a bounded
10-second wait, then reads the current chain tail with
`SELECT … FOR UPDATE`, so even the empty-table bootstrap case
serializes cleanly and a stuck writer never hangs indefinitely.
SQLite's database-level locking gives the same guarantee and the
writer omits the extra hints there. SQL Server
consumers should wrap the call in a SERIALIZABLE transaction — the
plugin does not issue `WITH (UPDLOCK)` hints today.

### Append-only discipline

The writer only INSERTs. For a full GoBD posture, protect the table at
the DB level against UPDATE and DELETE:

```sql
-- PostgreSQL
CREATE RULE audit_chain_no_update AS ON UPDATE TO compliance_audit_chain DO INSTEAD NOTHING;
CREATE RULE audit_chain_no_delete AS ON DELETE TO compliance_audit_chain DO INSTEAD NOTHING;

-- MySQL
CREATE TRIGGER audit_chain_no_update BEFORE UPDATE ON compliance_audit_chain
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only';
CREATE TRIGGER audit_chain_no_delete BEFORE DELETE ON compliance_audit_chain
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only';
```

### Verifying the chain

```bash
bin/cake gobd verify_chain
```

Exit code `0` for intact, `1` for tampered. Reports the entry count,
first offending row id, and a short reason when a break is found.

---

## Commands

### `bin/cake gobd verify_chain`

Walks the `compliance_audit_chain` table in order and asserts every hash matches `HashChain::hash(prev_hash, payload)`. Verification is streamed in chunks, so the command stays bounded in memory on larger audit tables. Run as a scheduled task (daily/weekly) and before any Kassenprüfung.

### `bin/cake gobd retention_report`

Lists every registered table with its retention window and date field. Registration is explicit:

```php
// Application::bootstrap()
$command = new \Compliance\Gobd\Command\RetentionReportCommand();
$command->registerTable('invoices', 10, 'booked_on');
$command->registerTable('ledger_entries', 10, 'created');
```

Auto-discovery is deliberately not attempted — applications have to explicitly declare what they want reported.

---

## Test suite

`tests/TestCase/Gobd/` covers:

- HashChain correctness (genesis, determinism, order-independence, tamper detection, chain verification)
- GobdBehavior (block delete inside retention, allow delete beyond retention)
- ImmutabilityBehavior (edit draft, block edit on finalized, allow NULL→set transition, block delete on finalized)
- AuditChainWriter (single event, multi-event batch, hash continuity across calls, concurrency via `FOR UPDATE`)
- AuditChainBehavior (create/update/delete lifecycle, hidden fields excluded, transactionId callback)
- Both Commands (intact chain success, tampered chain failure, empty chain, registered tables)

All tests run in under 100ms against in-memory SQLite. Zero external dependencies.
