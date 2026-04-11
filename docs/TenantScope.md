# TenantScope — Default-Deny Multi-Tenant Isolation

Multi-tenant isolation is the single most important security primitive in a SaaS. Get it wrong and you leak one customer's data to another — a breach that wipes out trust and potentially violates DSGVO.

The `Compliance\TenantScope` sub-area provides a **default-deny** isolation layer: queries against a tenant-scoped table **fail loudly** if no active tenant is in context, rather than silently returning an arbitrary tenant's data.

---

## Core principle

Three components work together:

1. **`TenantScopeRegistry`** — an ambient holder for the current tenant ID (static, per-request).
2. **`TenantScopeMiddleware`** — resolves the tenant from the incoming request and installs it into the registry for the duration of the handler.
3. **`TenantScopeBehavior`** — attached to each scoped Table, automatically filters every query by the active tenant and throws if none is set.

The combination means: once you've attached the behavior, you *cannot* query the table without having set a tenant. Failing to authenticate becomes a `MissingScopeException` instead of a data leak.

---

## Setup

### 1. Attach the behavior to tenant-scoped tables

```php
// src/Model/Table/InvoicesTable.php
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('Compliance.TenantScope', [
        'field' => 'account_id', // or 'verein_id', or 'tenant_id', etc.
    ]);
}
```

### 2. Add the middleware to your application

```php
// src/Application.php
use Compliance\TenantScope\Middleware\TenantScopeMiddleware;

public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
{
    return $middlewareQueue
        // ... your auth middleware BEFORE this ...
        ->add(new TenantScopeMiddleware([
            'attribute' => 'accountId', // request attribute to read
        ]));
}
```

### 3. Set the tenant from upstream auth middleware

The `TenantScopeMiddleware` reads from a request attribute — it does **not** authenticate. Your authentication middleware is responsible for setting the attribute:

```php
// In your auth middleware, after resolving the user:
$request = $request->withAttribute('accountId', $user->account_id);
return $handler->handle($request);
```

---

## Behavior

### Every find() is auto-filtered

```php
TenantScopeRegistry::setTenant('tenant-a');
$invoices = $this->Invoices->find()->all();
// Internally: SELECT * FROM invoices WHERE invoices.account_id = 'tenant-a'
```

### Every save() auto-stamps the tenant

```php
TenantScopeRegistry::setTenant('tenant-a');
$invoice = $this->Invoices->newEntity(['amount' => '119.00']);
$this->Invoices->save($invoice);
// $invoice->account_id is now 'tenant-a' (stamped automatically)
```

### Queries without a tenant throw

```php
// No TenantScopeRegistry::setTenant() called
$this->Invoices->find()->all();
// throws MissingScopeException
```

### Explicit bypass for admin tooling

For internal dashboards, ETL jobs, or cross-tenant reporting, use the `acrossTenants` finder:

```php
$this->Invoices->find('acrossTenants')->all();
// SELECT * FROM invoices -- no WHERE clause from the behavior
```

This is the **only** sanctioned way to escape the scope. A PR that adds a different escape hatch should be rejected on principle.

---

## TenantScopeRegistry

### API

```php
use Compliance\TenantScope\TenantScopeRegistry;

TenantScopeRegistry::setTenant('tenant-a');
TenantScopeRegistry::setTenant(42); // int also accepted
TenantScopeRegistry::getTenant();   // 'tenant-a' — throws if not set
TenantScopeRegistry::hasTenant();   // bool
TenantScopeRegistry::clear();

// Temporarily switch — restores previous even on exception
$result = TenantScopeRegistry::withTenant('other', function () {
    return $this->Invoices->find()->count();
});
```

### `withTenant()` — the escape hatch for multi-tenant background jobs

A queue worker that processes jobs across many tenants in a single run should never call `setTenant()` outside of a `withTenant()` block:

```php
foreach ($jobsByTenant as $tenantId => $jobs) {
    TenantScopeRegistry::withTenant($tenantId, function () use ($jobs) {
        foreach ($jobs as $job) {
            $this->processJob($job);
        }
    });
}
```

If `processJob()` throws, the previous tenant (typically none) is restored automatically — no cross-tenant contamination.

---

## TenantScopeMiddleware

The middleware restores the previous tenant context on exit, which matters for nested middleware chains and sub-requests. It honors a configurable attribute name so different apps can use different conventions:

```php
new TenantScopeMiddleware(['attribute' => 'accountId']);
new TenantScopeMiddleware(['attribute' => 'currentTenant']);
new TenantScopeMiddleware(['attribute' => 'vereinId']);
```

If the attribute is absent from the request (e.g., unauthenticated endpoints, healthchecks), the middleware is a no-op — it does not install a tenant. The protected tables will then throw when queried, which is correct: a request with no tenant context should not be allowed to touch tenant data.

---

## AbstractTenantScopedPolicy

For CakePHP Authorization policies, `AbstractTenantScopedPolicy` provides a base class that enforces strict-comparison tenant match:

```php
use Compliance\TenantScope\Policy\AbstractTenantScopedPolicy;

class InvoicePolicy extends AbstractTenantScopedPolicy
{
    protected function scopeField(): string
    {
        return 'account_id';
    }

    public function canView($user, Invoice $invoice): bool
    {
        return $this->belongsToCurrentTenant($invoice);
    }

    public function canEdit($user, Invoice $invoice): bool
    {
        return $this->belongsToCurrentTenant($invoice) && $user->hasPermission('edit_invoices');
    }
}
```

### Strict comparison

`belongsToCurrentTenant()` uses `===`, not `==`. This matters because PHP would otherwise consider `'42' == 42` equal, creating a subtle cross-tenant leak if your tenant IDs mix string and integer types. The behavior and middleware consistently store whatever type was passed in, and the policy refuses to compare across types.

---

## Testing

```php
use Compliance\TenantScope\TenantScopeRegistry;

protected function tearDown(): void
{
    TenantScopeRegistry::clear(); // always clear in tearDown
    parent::tearDown();
}

public function testSomething(): void
{
    TenantScopeRegistry::setTenant('tenant-a');
    // ... test ...
}
```

Forgetting to clear in `tearDown` causes test pollution — a test that sets a tenant leaves state for the next test. The included integration tests (`TenantScopeBehaviorTest`, `TenantScopeMiddlewareTest`, `AbstractTenantScopedPolicyTest`) all demonstrate the right pattern.

---

## What's deliberately NOT here

- **Row-level DB security**: some teams use Postgres RLS instead of application-level scoping. RLS is stronger but adds operational complexity. This plugin is the application-level path.
- **Per-column encryption**: even strong tenant scoping doesn't protect against database-level snooping. If you need that, look at `cakephp-data-encryption` or equivalent.
- **Automatic user→tenant resolution**: the plugin does NOT decide how to get from an authenticated user to a tenant ID. That's your auth layer's job. The plugin only propagates what you give it.

---

## Test suite

17 passing tests in `tests/TestCase/TenantScope/`:

- 7 for `TenantScopeRegistry` (set/get/clear/withTenant + restore on exception)
- 6 for `TenantScopeBehavior` (filter by tenant, isolated tenants, missing scope, across-tenants finder, auto-stamping, save without tenant)
- 4 for `TenantScopeMiddleware` (attribute resolution, clear after request, custom attribute, no-op without attribute)
- 5 for `AbstractTenantScopedPolicy` (match, mismatch, no tenant, custom field, strict type comparison)
