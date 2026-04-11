# DualApproval — Two-Person Integrity for High-Stakes Actions

German Vereinsrecht (§26 BGB) makes the Vorstand personally liable for financial decisions. A single volunteer Kassenwart who can close a Kassenbuch alone is a liability hazard both for the Verein (accidental errors) and for the Kassenwart personally (fraud accusations). A two-person sign-off on high-stakes actions is the standard mitigation.

The `Compliance\DualApproval` sub-area provides a general-purpose two-person workflow framework. It's not specific to Vereinsrecht — any application that needs "action X requires two different users to approve" can use it.

---

## Core invariant

**The approving user must be different from the initiating user.** Period. This is enforced at the service layer and throws `ApprovalConflictException` on violation.

---

## API

```php
use Compliance\DualApproval\ApprovalService;

$service = new ApprovalService();

// Step 1: first user requests the action
$approval = $service->request(
    action: 'close_cashbook',
    payload: ['year' => 2026, 'closing_balance' => '12500.00'],
    initiatorId: $currentUser->id,
);
// Returns PendingApproval entity with status = 'pending'

// Step 2: second user approves
$approved = $service->approve(
    (int)$approval->get('id'),
    $secondUser->id,
);
// Returns PendingApproval with status = 'approved', approver_id = secondUser->id,
// resolved_at = now

// Alternative: second user rejects with a reason
$rejected = $service->reject(
    (int)$approval->get('id'),
    $secondUser->id,
    'Missing bank statement for December',
);
```

### Errors

- Same user trying to approve their own request → `ApprovalConflictException`
- Approve or reject on an already-resolved approval → `ApprovalConflictException`
- Unknown approval ID → `ApprovalNotFoundException`

---

## Data model

```sql
CREATE TABLE pending_approvals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action VARCHAR(100) NOT NULL,
    payload TEXT NOT NULL, -- JSON
    initiator_id VARCHAR(50) NOT NULL,
    approver_id VARCHAR(50) NULL,
    status VARCHAR(20) NOT NULL, -- pending | approved | rejected
    reason TEXT NULL,
    created DATETIME NOT NULL,
    modified DATETIME NOT NULL,
    resolved_at DATETIME NULL
);

CREATE INDEX idx_pending_approvals_status ON pending_approvals(status);
CREATE INDEX idx_pending_approvals_initiator ON pending_approvals(initiator_id);
```

The `payload` column is declared as the `json` column type inside `PendingApprovalsTable::initialize()` — CakePHP transparently encodes/decodes it, so consumers pass arrays and receive arrays.

---

## Middleware integration

`DualApprovalMiddleware` gates protected actions on the HTTP boundary. It reads configured request attributes, creates a pending approval, and short-circuits the handler with `202 Accepted`.

### Setup

```php
// src/Application.php
use Compliance\DualApproval\Middleware\DualApprovalMiddleware;

public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
{
    return $middlewareQueue
        // ... auth middleware first, sets 'currentUserId' ...
        ->add(new DualApprovalMiddleware([
            'protected' => ['close_cashbook', 'issue_dues_run', 'change_freistellungsbescheid'],
            'actionAttribute' => 'dualApprovalAction',
            'userAttribute' => 'currentUserId',
            'payloadAttribute' => 'dualApprovalPayload',
            'resumeAttribute' => 'dualApprovalResume',
        ]));
}
```

### Request flow

**First request** (user A):

```php
// Controller sets the attributes that identify the action:
$request = $request
    ->withAttribute('dualApprovalAction', 'close_cashbook')
    ->withAttribute('dualApprovalPayload', ['year' => 2026]);

return $handler->handle($request);
```

The middleware intercepts, creates a pending approval, returns:

```
HTTP/1.1 202 Accepted
X-Dual-Approval-Id: 42
```

**Second request** (user B dispatches with the resume attribute):

```php
$request = $request
    ->withAttribute('dualApprovalResume', 42)
    ->withAttribute('currentUserId', 'user-b');

return $handler->handle($request);
```

The middleware calls `ApprovalService::approve(42, 'user-b')` and then lets the handler run — the original action executes under user-B's authority.

### Why attribute-based (not controller-based)?

Middleware is decoupled from controllers — the same protection policy applies to HTTP requests, CLI commands, queue jobs, and scheduled tasks (by setting attributes in those contexts too). A controller-annotation approach would be tightly coupled to the HTTP layer.

---

## Trait for declarative action marking

`RequiresDualApprovalTrait` lets a controller (or any class) declaratively list which of its actions require approval:

```php
use Compliance\DualApproval\Traits\RequiresDualApprovalTrait;

class CashbookController extends AppController
{
    use RequiresDualApprovalTrait;

    public function initialize(): void
    {
        parent::initialize();
        $this->requiresDualApproval('close_cashbook', 'delete_cashbook');
    }

    public function closeCashbook(): ?Response
    {
        if ($this->requiresApproval('close_cashbook')) {
            $this->request = $this->request->withAttribute('dualApprovalAction', 'close_cashbook');
            // ... dispatch the rest of the flow ...
        }
    }
}
```

The trait is deliberately minimal: just tracks which actions are protected. Integration with the middleware is the consumer's responsibility (typically via request attributes).

---

## UI patterns

The plugin does NOT ship a UI. A minimal HTMX/Alpine implementation looks like:

### Pending approvals list

```php
$approvals = $this->PendingApprovals->find()
    ->where(['status' => 'pending'])
    ->where(['initiator_id !=' => $currentUserId]) // exclude approvals I can't approve
    ->all();
```

### Approve button (HTMX)

```html
<form hx-post="/admin/approvals/{{ approval.id }}/approve"
      hx-target="#approval-{{ approval.id }}"
      hx-swap="outerHTML">
    <button type="submit">Approve</button>
</form>
```

### Controller handler

```php
public function approve(int $id): Response
{
    $this->ApprovalService->approve($id, $this->Auth->user()->id);
    return $this->redirect(['action' => 'index']);
}
```

The service throws `ApprovalConflictException` if the current user is the initiator — the controller catches this and shows a flash message.

---

## Notification hooks

The plugin does NOT send notifications itself. Consumers listen for a successful `request()` and send emails (or webhooks, Slack messages, etc.) from their own code. A typical wiring:

```php
$approval = $this->ApprovalService->request('close_cashbook', ['year' => 2026], $currentUser->id);

$this->getEventManager()->dispatch(new Event('Approval.requested', $this, [
    'approval' => $approval,
    'candidates' => $this->findApproverCandidates($approval),
]));
```

Listeners subscribe to `Approval.requested` and send notifications.

A dedicated notification interface is on the 0.2 roadmap but deliberately absent from 0.1 — this keeps the plugin focused and avoids coupling to any specific mail library.

---

## Audit integration

Pair with `Compliance.Gobd` audit behaviors for a full audit trail:

```php
class PendingApprovalsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->addBehavior('AuditStash.AuditLog', [
            'persister' => \Compliance\Gobd\Persister\HashChainAuditPersister::class,
        ]);
    }
}
```

Every approval request, approval, or rejection lands in the hash-chained audit log, giving the Vorstand cryptographic evidence that a decision was made by two specific users at a specific time — exactly what §26 BGB liability defense needs.

---

## Test suite

18 passing tests in `tests/TestCase/DualApproval/`:

### `ApprovalServiceTest` (9 tests)
- `request` creates pending row with correct fields
- `approve` marks resolved with timestamp and approver
- `approve` by same user throws `ApprovalConflictException`
- `reject` marks rejected, captures reason
- `reject` by same user throws
- `approve` on unknown ID throws `ApprovalNotFoundException`
- Already-resolved approval cannot be approved again
- Already-resolved approval cannot be rejected
- Payload round-trips through JSON encoding including umlauts

### `DualApprovalMiddlewareTest` (5 tests)
- Unprotected actions pass through
- Protected actions create a pending approval and short-circuit with 202
- Handler does not run when a protected action is in flight
- Pass-through when action attribute is absent
- Resume attribute approves and runs the handler under the second user

### `RequiresDualApprovalTraitTest` (4 tests)
- `declare` adds actions to the protected list
- `requiresApproval` reports correctly
- Multiple declare calls accumulate
- Declare is idempotent
