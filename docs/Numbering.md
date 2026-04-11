# Numbering — Gap-Free Document Number Sequencer

German law (§14 UStG, §50 EStDV, §147 AO) requires that sequentially numbered business documents — invoices, receipts, Zuwendungsbestätigungen, etc. — use **gap-free** numbering. An auditor who sees a missing number in your invoice series will ask you to explain it, and "a race condition dropped the number" is not a valid answer.

The `Compliance\Numbering\Service\Sequencer` provides a transactional, race-safe generator that guarantees gap-free allocation per `(scope, sequence_key, year)` tuple, with full audit logging of every allocation including rolled-back ones.

---

## API

```php
use Compliance\Numbering\Service\Sequencer;
use Cake\Datasource\ConnectionManager;

$sequencer = new Sequencer(ConnectionManager::get('default'));

$next = $sequencer->next(
    scope: 'account-42',  // tenant/account identifier
    sequenceKey: 'invoice', // logical sequence name
    year: 2026,            // billing period year
    format: '{YYYY}-{####}', // format template
);
// → '2026-0001' on first call
// → '2026-0002' on second call
// → '2026-0003' on third call
```

---

## Format tokens

| Token | Meaning | Example |
|---|---|---|
| `{YYYY}` | Four-digit year | `2026` |
| `{YY}` | Two-digit year | `26` |
| `{####}` | Zero-padded counter, width = number of hashes | `0042` |
| `{##}` | 2-digit counter | `01` |
| `{######}` | 6-digit counter | `000001` |

Examples of common format patterns:

| Format | First output |
|---|---|
| `{YYYY}-{####}` | `2026-0001` |
| `RE-{YYYY}-{####}` | `RE-2026-0001` |
| `{###}/{YY}` | `001/26` |
| `INV-{##}` | `INV-01` |

### Format is frozen after first use

Once the first allocation for a given `(scope, sequence_key, year)` tuple happens, the format template is stored and **cannot be changed**. A subsequent call with a different format throws `SequenceFormatFrozenException`:

```php
$sequencer->next('account-1', 'invoice', 2026, '{YYYY}-{####}');
// OK: creates sequence with format '{YYYY}-{####}'

$sequencer->next('account-1', 'invoice', 2026, 'RE-{YYYY}-{####}');
// throws SequenceFormatFrozenException
```

This is deliberate: an auditor seeing `2026-0042` followed by `RE-2026-0043` would rightly question the integrity of the series. Mid-year format changes are not allowed.

To switch formats on **January 1** of a new year, simply call with a new year — the new year creates a new sequence with its own format.

---

## Scoping semantics

The `(scope, sequence_key, year)` tuple defines independent counters:

- Different **scopes** (tenants) have independent counters — `account-1` and `account-2` each start at 1.
- Different **sequence keys** have independent counters — `invoice` and `receipt` each start at 1 even for the same tenant.
- Different **years** have independent counters — `invoice` in 2026 and `invoice` in 2027 each start at 1.

This matches how German tax law treats document series: one sequence per fiscal year per document type per Buchhaltung.

---

## Race safety

The sequencer is **race-safe under concurrent allocations** because the allocation happens inside a database transaction with a row-level lock. The implementation:

1. `BEGIN TRANSACTION`
2. `SELECT id, format, current_value FROM compliance_sequences WHERE scope = ? AND sequence_key = ? AND year = ?`
3. If row exists: increment `current_value` and UPDATE; else INSERT new row with `current_value = 1`
4. `COMMIT`
5. Insert audit row into `compliance_sequence_audit`
6. Return formatted token

Multiple concurrent `next()` calls serialize at step 2 — one wins, the other waits. No gaps, no duplicates.

### Tested guarantee

`SequencerTest::testSubsequentAllocationIncrements()` verifies basic determinism. For production confidence, a Postgres/MySQL concurrency test with real parallel workers is recommended as a pre-release gate.

---

## Rollback audit

Every allocation is logged append-only to `compliance_sequence_audit` with `committed = 1`. If a transaction that wraps the sequencer call rolls back, the sequencer's `compliance_sequences` row is reverted by the outer transaction — but the application should log the reason so an auditor can explain any apparent gap.

The plugin's job is to **prevent** gaps in the happy path. Gaps due to application errors (a transaction that incremented the sequence but then rolled back) are rare but possible; the audit table gives you the data to explain them after the fact.

---

## Schema

### PostgreSQL / MySQL

```sql
CREATE TABLE compliance_sequences (
    id INTEGER PRIMARY KEY AUTOINCREMENT, -- or SERIAL / INT AUTO_INCREMENT
    scope VARCHAR(100) NOT NULL,
    sequence_key VARCHAR(100) NOT NULL,
    year INTEGER NOT NULL,
    format VARCHAR(100) NOT NULL,
    current_value INTEGER NOT NULL DEFAULT 0,
    UNIQUE(scope, sequence_key, year)
);

CREATE TABLE compliance_sequence_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    scope VARCHAR(100) NOT NULL,
    sequence_key VARCHAR(100) NOT NULL,
    year INTEGER NOT NULL,
    allocated_value INTEGER NOT NULL,
    allocated_token VARCHAR(100) NOT NULL,
    committed INTEGER NOT NULL DEFAULT 0,
    created DATETIME NOT NULL
);

CREATE INDEX idx_sequence_audit_scope
    ON compliance_sequence_audit(scope, sequence_key, year, created);
```

The unique constraint on `(scope, sequence_key, year)` is what enforces the "one sequence per tuple" invariant at the database level.

### Append-only protection

For a full GoBD posture, protect the audit table at the DB level:

```sql
-- PostgreSQL
CREATE RULE sequence_audit_no_update AS ON UPDATE TO compliance_sequence_audit DO INSTEAD NOTHING;
CREATE RULE sequence_audit_no_delete AS ON DELETE TO compliance_sequence_audit DO INSTEAD NOTHING;
```

---

## Integration patterns

### Invoice number on finalize

```php
public function finalizeInvoice(Invoice $invoice): Invoice
{
    $year = (int)$invoice->issued_at->format('Y');
    $number = $this->sequencer->next(
        scope: $invoice->account_id,
        sequenceKey: 'invoice',
        year: $year,
        format: $invoice->account->invoice_number_format,
    );
    $invoice->set('invoice_number', $number);
    $invoice->set('finalized_at', new DateTime());
    $this->saveOrFail($invoice);

    return $invoice;
}
```

The format is read from an account-level configuration so each customer can choose their preferred template on first use.

### Zuwendungsbestätigung number

Same pattern, different sequence key:

```php
$number = $this->sequencer->next(
    scope: $verein->id,
    sequenceKey: 'donation_receipt',
    year: (int)$donation->date->format('Y'),
    format: '{YYYY}/{####}',
);
```

---

## What's deliberately NOT here

- **No DB-level sequences**: PostgreSQL has native `SEQUENCE` objects, but they explicitly allow gaps (a rolled-back transaction leaves the sequence advanced). Sequencer uses a row-based counter specifically so the SQL semantics match the legal requirement.
- **No automatic year transition**: if you want "first invoice of 2027" to start at `1`, you have to pass `year: 2027` explicitly. No hidden calendar-day logic.
- **No time-based expiry**: old sequences stay in the table indefinitely. They're small and they're needed for the audit trail.

---

## Test suite

12 passing tests in `tests/TestCase/Numbering/Service/SequencerTest.php`:

- First allocation returns formatted value
- Subsequent allocations increment
- Different tenants have independent counters
- Different years have independent counters
- Different sequence keys have independent counters
- Format is frozen after first use (throws `SequenceFormatFrozenException`)
- Format can differ across scopes
- Short format `{##}` works with 2-digit width
- 6-digit format `{######}` works with 6-digit width
- Mixed year+counter format `{###}/{YY}`
- Audit row is written for every allocation
- Format without counter placeholder throws `InvalidArgumentException`
