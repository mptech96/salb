# Company Onboarding Flow

Status: Phase 1 target contract.

## Unified workflow

Both platform-created and self-registered companies must call one idempotent provisioning service:

1. Validate company identity, owner identity, plan, billing period, and duplicate keys.
2. Create an onboarding request/correlation ID.
3. Create the company in `PROVISIONING` or `PENDING_ACTIVATION` operational state.
4. Create a `PENDING` or approved `TRIAL` subscription.
5. Snapshot plan features, limits, price, currency, tax, and billing period.
6. Create the platform subscription invoice when required.
7. Create the main branch.
8. Create the primary Company Owner user.
9. Assign a company-scoped owner role; never a platform role.
10. Run accounting, currency, tax, sequence, and settings bootstrap idempotently.
11. Validate the provisioned baseline.
12. Activate according to payment/trial policy.
13. record a complete platform audit event and deliver account activation safely.

## Atomicity and retry

- Database provisioning is transactional where possible.
- External notifications run after commit and are retryable.
- A repeated request with the same idempotency key returns the existing result.
- Partial provisioning is visible to platform operations but not usable as an active tenant.
- Bootstrap steps expose completion markers rather than guessing from row existence.

## Current paths

- Platform `POST /companies` creates company, ACTIVE subscription, main branch, and accounting baseline, but not the primary user.
- Public `POST /register-company` creates company, branch, primary user, role, subscription, invoice, and settings, but follows a different activation/bootstrap policy.

## Current defects

- `DEF-ONB-001`: platform onboarding does not open the company's primary account.
- `DEF-ONB-002`: platform and public onboarding produce different tenant baselines.
- `DEF-ONB-003`: platform onboarding activates subscriptions without a consistent invoice/payment workflow.
- `DEF-ONB-004`: no explicit provisioning state or idempotency key exists.

