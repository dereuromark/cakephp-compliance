# Compliance Plugin for CakePHP

[![CI](https://github.com/dereuromark/cakephp-compliance/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dereuromark/cakephp-compliance/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.3-8892BF.svg)](https://php.net/)
[![CakePHP](https://img.shields.io/badge/cakephp-%3E%3D%205.2-red.svg?style=flat-square)](https://cakephp.org/)
[![Status](https://img.shields.io/badge/status-0.x%20unstable-orange.svg?style=flat-square)](#status)

DACH compliance primitives for CakePHP 5.x — bundled into one focused plugin so you don't have to wire four micro-packages to get the baseline every German SaaS needs.

> **Status: 0.x unstable.** API may break before 1.0. Pin to `^0.1` in production and read [CHANGELOG.md](CHANGELOG.md) before upgrading. Cut to 1.0 once the API has stabilized across two or more real consumers.

## What's in the box

Four sub-concerns that are always installed together in real DACH apps:

| Sub-area | Purpose | Key classes |
|---|---|---|
| **Gobd** | German GoBD §147 AO retention + immutability + hash-chained audit log | `GobdBehavior`, `ImmutabilityBehavior`, `HashChain`, `HashChainAuditPersister`, `VerifyChainCommand`, `RetentionReportCommand` |
| **TenantScope** | Default-deny multi-tenant query isolation | `TenantScopeRegistry`, `TenantScopeBehavior`, `TenantScopeMiddleware`, `AbstractTenantScopedPolicy`, `MissingScopeException` |
| **Numbering** | Gap-free, race-safe per-tenant-per-year document number sequencer | `Sequencer`, `SequenceFormatFrozenException` |
| **DualApproval** | Two-person integrity workflow for high-stakes actions | `ApprovalService`, `DualApprovalMiddleware`, `RequiresDualApprovalTrait`, `PendingApproval` entity + table |

Each concern lives under its own sub-namespace (`Compliance\Gobd\…`, `Compliance\TenantScope\…`, etc.) so internal boundaries stay clean even though everything ships under one Composer package.

## Why bundled?

A DACH SaaS app that needs GoBD retention **also** needs tenant isolation, **also** needs gap-free invoice numbering, **and** benefits from dual-approval on high-stakes actions. These four concerns travel together. Shipping them as four micro-plugins would just force you to `composer require` four things. Internal sub-namespacing keeps the separation without the packaging overhead.

## Installation

```bash
composer require dereuromark/cakephp-compliance
bin/cake plugin load Compliance
```

Requires **PHP 8.3+** and **CakePHP 5.2+**.

## Quick start

### GoBD retention on a ledger table

```php
// src/Model/Table/LedgerEntriesTable.php
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('Compliance.Gobd', [
        'retentionYears' => 10,
        'dateField' => 'booked_on',
    ]);
}
```

A `$table->delete($row)` on an entry whose `booked_on` is inside the 10-year retention window throws `GobdRetentionException`.

### Immutability on finalized invoices

```php
$this->addBehavior('Compliance.Immutability', ['field' => 'finalized_at']);
```

Once `finalized_at` is set on a row, subsequent `save()` or `delete()` calls throw `ImmutableRowException`. The transition from NULL to a set value (the finalize action) is allowed exactly once.

### Tenant-scoped table

```php
// Table
$this->addBehavior('Compliance.TenantScope', ['field' => 'account_id']);

// Middleware (Application::middleware)
$middlewareQueue->add(new \Compliance\TenantScope\Middleware\TenantScopeMiddleware([
    'attribute' => 'accountId',
]));

// Authenticated middleware upstream sets:
$request = $request->withAttribute('accountId', $user->account_id);
```

All `find()` queries on the scoped table are automatically filtered by the active tenant. `save()` auto-stamps the tenant field. Any query run without an active tenant throws `MissingScopeException`.

Need to query across tenants for admin tooling? Use the `acrossTenants` finder:

```php
$table->find('acrossTenants')->all();
```

### Gap-free invoice numbers

```php
use Compliance\Numbering\Service\Sequencer;
use Cake\Datasource\ConnectionManager;

$sequencer = new Sequencer(ConnectionManager::get('default'));
$next = $sequencer->next('account-42', 'invoice', 2026, '{YYYY}-{####}');
// → "2026-0001"
```

First call for `(account-42, invoice, 2026)` freezes the format template. Attempts to use a different format for the same sequence throw `SequenceFormatFrozenException`. Every allocation is logged append-only to `compliance_sequence_audit` including rolled-back ones, so an auditor can explain any gap in the sequence.

**Schema setup** (once per installation, see [docs/Numbering.md](docs/Numbering.md)):

```sql
CREATE TABLE compliance_sequences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
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
```

### Dual approval for high-stakes actions

```php
use Compliance\DualApproval\ApprovalService;

$service = new ApprovalService();

// Step 1 — first user requests the action
$approval = $service->request(
    action: 'close_cashbook',
    payload: ['year' => 2026],
    initiatorId: $currentUser->id,
);

// Step 2 — second user approves (must be different from initiator)
$service->approve((int)$approval->get('id'), $secondUser->id);
// Or rejects:
$service->reject((int)$approval->get('id'), $secondUser->id, 'Missing documentation');
```

`ApprovalConflictException` is thrown if the same user tries to both request and approve.

### Hash-chained audit log (GoBD tamper-evident)

```php
// On any Table with financial data:
$this->addBehavior('AuditStash.AuditLog', [
    'persister' => \Compliance\Gobd\Persister\HashChainAuditPersister::class,
]);
```

Every create/update/delete is logged into `compliance_audit_chain` with a SHA-256 hash chaining each row to its predecessor. Tampering with any historical row breaks the chain and is detected by:

```bash
bin/cake gobd verify_chain
```

The verifier streams the table in bounded memory and reports the first
broken row id plus the reason for the break.

## Documentation

- [docs/Gobd.md](docs/Gobd.md) — GoBD retention, immutability, hash chain, migration templates
- [docs/TenantScope.md](docs/TenantScope.md) — multi-tenant scoping patterns, authorization policies
- [docs/Numbering.md](docs/Numbering.md) — gap-free sequencer, concurrency semantics, schema
- [docs/DualApproval.md](docs/DualApproval.md) — two-person workflow, middleware integration, UI patterns

## Testing

```bash
composer install
composer test      # PHPUnit — in-memory SQLite
composer stan      # PHPStan level 8
composer cs-check  # PhpCollective code style
```

All tests run in under a second against an in-memory SQLite database. No external service dependencies.

## Related plugins

Part of a family of focused DACH-compliance plugins for CakePHP 5.x:

- **`dereuromark/cakephp-compliance`** — this plugin. Every-request compliance plumbing.
- **`dereuromark/cakephp-accounting`** — §286 / §288 BGB dunning + DATEV CSV export.
- **`dereuromark/cakephp-sepa`** — IBAN / BIC / Creditor ID validation + CAMT.053 / CAMT.054 parsing.

## Contributing

PRs welcome. Please include tests, run PHPStan (`composer stan`) and PHPCS (`composer cs-check`) before submitting, and sign off commits per the DCO.

## License

MIT. See [LICENSE](LICENSE).
