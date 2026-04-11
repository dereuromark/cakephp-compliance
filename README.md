        # Compliance Plugin for CakePHP

        [![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)
        [![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%208.3-8892BF.svg)](https://php.net/)
        [![Status](https://img.shields.io/badge/status-0.x%20unstable-orange.svg?style=flat-square)](#status)

        > **Status: 0.x unstable.** API may break before 1.0. Pin to `^0.1` in production and read the CHANGELOG before upgrading minor versions. Cut to `1.0` once the API has stabilized across two or more real consumers.

        A focused CakePHP 5.x plugin bundling the every-request compliance plumbing that any DACH vertical-SaaS application needs: German GoBD §147 AO retention and immutability (with a hash-chained audit log on top of `dereuromark/cakephp-audit-stash`), default-deny multi-tenant query isolation, gap-free per-tenant-per-year document number sequencing, and a two-person integrity framework for high-stakes actions.

These four concerns are bundled because they are always installed together in real DACH apps — separating them would just force users to `composer require` four things. Each concern lives under its own sub-namespace (`Compliance\\Gobd`, `Compliance\\TenantScope`, `Compliance\\Numbering`, `Compliance\\DualApproval`) so the internal boundaries stay clean.

        ## Features

        - **GoBD**: retention (10-year enforcement), immutability (DB-level `BEFORE UPDATE` triggers on finalized rows), hash-chained audit persister.
- **TenantScope**: default-deny multi-tenant scoping with configurable scope column, `forTenant()` finder, middleware throwing `MissingScopeException` on unscoped queries, `AbstractTenantScopedPolicy` base class.
- **Numbering**: gap-free, race-safe per-tenant-per-year `Sequencer` with transactional `SELECT ... FOR UPDATE` allocation, append-only audit table for rolled-back allocations, format templates frozen after first use.
- **DualApproval**: pending-approval storage, middleware gating protected actions, default HTMX/Alpine UI partials, pluggable notification hooks, full audit integration.
- All four concerns are co-versioned — one `composer require`, one `bin/cake plugin load`.
- Phinx migration templates shipped for Postgres 14+ and MySQL 8.0.16+.

        ## Structure

This plugin is internally organized into focused sub-areas under the main namespace:

### `Compliance\Gobd`

- `Gobd/Model/Behavior/GobdBehavior`
- `Gobd/Model/Behavior/ImmutabilityBehavior`
- `Gobd/Persister/HashChainAuditPersister`
- `Gobd/Command/VerifyChainCommand`
- `Gobd/Command/RetentionReportCommand`

### `Compliance\TenantScope`

- `TenantScope/Model/Behavior/TenantScopeBehavior`
- `TenantScope/Middleware/TenantScopeMiddleware`
- `TenantScope/Exception/MissingScopeException`
- `TenantScope/Policy/AbstractTenantScopedPolicy`

### `Compliance\Numbering`

- `Numbering/Service/Sequencer`
- `Numbering/Model/Table/SequencesTable`
- `Numbering/Model/Table/SequenceAuditTable`
- `Numbering/Exception/SequenceFormatFrozenException`

### `Compliance\DualApproval`

- `DualApproval/Service/ApprovalService`
- `DualApproval/Model/Table/PendingApprovalsTable`
- `DualApproval/Model/Entity/PendingApproval`
- `DualApproval/Middleware/DualApprovalMiddleware`
- `DualApproval/Traits/RequiresDualApprovalTrait`


        ## Installation

        Install via [composer](https://getcomposer.org):

        ```bash
        composer require dereuromark/cakephp-compliance
        bin/cake plugin load Compliance
        ```

        ## Usage

        > This is a 0.x skeleton. Usage examples will appear here as the API stabilizes. See the `docs/` folder for architecture notes and the `tests/` folder for working examples.

        ## Motivation

        This plugin is part of a three-plugin family extracted from real DACH vertical-SaaS products (landlord billing, freelancer invoicing, Vereinsverwaltung) where German legal and tax requirements shape the architecture:

        - **`dereuromark/cakephp-compliance`** — GoBD retention, multi-tenant scoping, gap-free numbering, dual-approval workflows. Every-request compliance plumbing.
        - **`dereuromark/cakephp-accounting`** — §286 / §288 BGB dunning calculators and DATEV CSV export. German accounting workflow.
        - **`dereuromark/cakephp-sepa`** — IBAN / BIC / Creditor ID validation and CAMT.053 / CAMT.054 parsing with German bank-quirk normalization. SEPA banking primitives.

        Each plugin bundles tightly-cohesive sub-concerns under sub-namespaces so installation is one `composer require` per domain area rather than a scattershot of micro-packages.

        ## Contributing

        PRs welcome. Please include tests, run PHPStan (`composer stan`) and PHPCS (`composer cs-check`) before submitting, and sign off commits per the DCO.

        ## License

        MIT. See [LICENSE](LICENSE).
