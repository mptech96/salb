# SULB ERP Platform Admin Architecture Baseline

Status: Phase 1 contract. No production implementation is authorized by this document.

## Architectural boundary

SULB ERP has two security planes that must remain separate:

1. **Platform / System Administration** is the SaaS control plane owned by SULB. Its actors manage companies, plans, subscriptions, platform billing, support access, global configuration, and platform audit.
2. **Company ERP** is a tenant data plane. Its actors work only inside one company and, where applicable, one branch.

A platform administrator is not a company employee. Entering a tenant for support creates an explicit, temporary support context; it does not change the administrator into a tenant user.

## Current baseline

- `SUPER_ADMIN` is the current platform role.
- Platform routes are grouped behind `platform.admin`.
- Company routes are grouped behind company context, tenant scope, and route permission middleware.
- The authenticated context distinguishes the actual role from the effective support role.
- Support tokens carry the target company and optional branch as token abilities.
- Platform and company sessions are presented separately by the frontend shell.

## Required request decision

Every company request must satisfy all applicable gates:

1. Authenticated actor and valid token.
2. Explicit platform or company context.
3. Company operational status.
4. Subscription access mode.
5. Effective module/feature entitlement.
6. Usage limit, for create-like actions.
7. Tenant and branch isolation.
8. User action permission.
9. Business invariant.

Frontend visibility is a usability projection of these decisions and is never an authorization boundary.

## Platform responsibilities

- Provision and activate companies and their primary owners.
- Manage plans, prices, features, limits, subscription periods, renewals, suspension, expiry, and cancellation.
- Record subscription invoices and payments.
- Calculate effective entitlements and usage.
- Perform intentional, time-bounded, audited support access.
- Monitor platform health and tenant usage without weakening tenant isolation.
- Maintain platform-scoped audit records and global settings.

## Company responsibilities

A company administrator may manage only the current tenant's branches, users, roles, items, accounting, tax, inventory, fleet, weighbridge, purchases, sales, reports, printing, and settings, subject to both subscription entitlements and company permissions.

Company actors cannot grant platform permissions, activate unlicensed modules, access another company, change platform settings, or silently bypass subscription controls.

## Trust boundaries

- Client-supplied `company_id`, `branch_id`, feature flags, permissions, and subscription state are untrusted.
- The backend derives context from the authenticated token and server-side records.
- Cross-company queries require an explicit platform endpoint or an active support session.
- Support bypasses must be recorded as break-glass decisions, not implied by role.
- Expiry, suspension, and downgrade never delete tenant data.

## Known baseline defects

- `DEF-PLAT-001`: platform and company permission catalogs are not structurally separated.
- `DEF-ENT-001`: no backend effective-entitlement gate exists.
- `DEF-LIMIT-001`: plan limits are displayed but not enforced by backend writes.
- `DEF-SUB-001`: TRIAL is administratively supported but rejected by login/company context.
- `DEF-SUB-002`: authentication mutates subscription state to EXPIRED.
- `DEF-ONB-001`: platform-created companies do not receive a primary company user.
- `DEF-ONB-002`: platform and public onboarding produce different baselines.
- `DEF-SUP-001`: support reason is optional.
- `DEF-SUP-002`: support receives all company permissions and full write access.
- `DEF-AUD-001`: platform/support audit is fail-open and not structurally complete.

