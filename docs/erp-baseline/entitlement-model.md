# Entitlement Model

Status: Phase 1 target contract. No tables or middleware are created in this phase.

## Resolution chain

`Plan -> Plan Features -> Company Subscription Snapshot -> Company Overrides -> Effective Entitlements -> Backend Route/Policy -> UI Projection`

## Feature catalog

Each feature has a stable code, module code, value type (`BOOLEAN`, `LIMIT`, or `ENUM`), default value, active flag, and platform/company scope. Examples include `sales.enabled`, `sales.post`, `inventory.fifo`, `inventory.processing`, `weighbridge.enabled`, `tax.vat`, `imports.enabled`, and `limits.users`.

## Plan features

A plan explicitly enables or disables catalog features and supplies limit/config values. Missing features are denied by default. Editing a plan does not silently rewrite existing subscription history.

## Subscription snapshot

Activation records an immutable snapshot of the commercial entitlements used by that subscription period. This makes invoices, audits, renewals, and historical access reproducible after a plan is edited.

## Company overrides

Platform-authorized overrides may `ALLOW`, `DENY`, or change a `LIMIT`. Every override requires a reason, actor, validity period, and audit record. Priority is:

1. Platform emergency deny.
2. Company explicit deny.
3. Time-bounded company allow/limit override.
4. Subscription snapshot.
5. Plan default.
6. Deny.

## Effective decision

For a company action to succeed:

- Subscription access mode must permit the HTTP/action class.
- Required module and feature must be enabled.
- Applicable limit must not be exceeded.
- Tenant/branch scope must be valid.
- Company user must hold the action permission.

Thus `sales.post` permission without the Sales entitlement is denied, and a Sales entitlement without `sales.post` permission is also denied.

## Usage limits

Limit checks happen in the backend transaction that creates the resource. Counts are company-scoped, concurrency-safe, and define whether inactive/archived resources count. Initial limits include users, branches, warehouses, cars, and documents; the catalog must support future metrics without schema changes per metric.

## Downgrade

A downgrade blocks new use above the new limit and hides/disables non-entitled workflows. It does not delete or rewrite old data. Historical data remains readable under the subscription access policy. A scheduled downgrade takes effect at a declared time and records current usage conflicts.

## Current defects

- `DEF-ENT-001`: no feature catalog or effective entitlement service exists.
- `DEF-ENT-002`: no backend route-to-feature enforcement exists.
- `DEF-ENT-003`: frontend receives plan limits but no authoritative entitlement projection.
- `DEF-LIMIT-001`: users, branches, cars, and invoice limits are not enforced.
- `DEF-DOWN-001`: plan changes are immediate and have no effective date or data-retention policy enforcement.

