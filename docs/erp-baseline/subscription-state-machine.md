# Subscription State Machine

Status: Phase 1 target contract. Existing field names remain unchanged until an approved implementation phase.

## States

| State | Meaning | Access mode |
|---|---|---|
| `PENDING` | Provisioned but awaiting payment or approval | Onboarding and billing only |
| `TRIAL` | Time-bounded trial with an entitlement set | Read/write within trial entitlements |
| `ACTIVE` | Paid or administratively active subscription | Read/write within effective entitlements |
| `SUSPENDED` | Temporarily restricted by platform action | Restricted read-only, billing, export |
| `EXPIRED` | Subscription period ended | Restricted read-only, billing, export |
| `CANCELLED` | Commercial relationship ended | Retained archival access per retention policy |

`company.is_active` must represent a separate operational/security decision and must not be used as a synonym for subscription status.

## Allowed transitions

- `PENDING -> ACTIVE`: confirmed payment or explicit platform activation.
- `PENDING -> TRIAL`: explicit trial approval.
- `PENDING -> CANCELLED`: abandoned or rejected onboarding.
- `TRIAL -> ACTIVE`: conversion.
- `TRIAL -> EXPIRED`: trial period elapsed.
- `TRIAL -> SUSPENDED|CANCELLED`: explicit platform action.
- `ACTIVE -> ACTIVE`: renewal or extension.
- `ACTIVE -> SUSPENDED`: explicit platform action.
- `ACTIVE -> EXPIRED`: period elapsed through a lifecycle service/job.
- `ACTIVE -> CANCELLED`: explicit cancellation.
- `SUSPENDED -> ACTIVE`: reinstatement.
- `SUSPENDED -> EXPIRED|CANCELLED`: expiry or cancellation.
- `EXPIRED -> ACTIVE`: renewal.
- `EXPIRED -> CANCELLED`: cancellation.
- `CANCELLED`: terminal; reactivation requires a new subscription record.

## Transition rules

- Authentication reads lifecycle state; it never changes it.
- A single service owns lifecycle transitions and audit.
- Automatic expiry is idempotent and records actor `SYSTEM`.
- Overlapping effective subscriptions for one company are rejected.
- Scheduled future subscriptions do not replace the currently effective subscription early.
- Renewal, plan change, and payment confirmation use row locks.
- Historical subscriptions, invoices, payments, and entitlement snapshots remain immutable.

## Read-only contract

For `SUSPENDED` and `EXPIRED`, the backend uses an explicit allowlist. It may allow login, safe reports, exports, profile security, subscription display, invoices, payments, and renewal workflow. It denies document creation, mutation, posting, voiding, imports, inventory changes, accounting changes, and administrative expansion.

Downgrade or expiry never removes rows, attachments, lots, journal entries, or historical reports.

## Current incompatibilities

- `DEF-SUB-001`: only ACTIVE passes authentication and company middleware; TRIAL is blocked.
- `DEF-SUB-002`: login changes non-ACTIVE/elapsed subscriptions to EXPIRED.
- `DEF-SUB-003`: PENDING is created by public registration but omitted from administrative status validation.
- `DEF-SUB-004`: the latest row by ID is treated as effective without date/overlap resolution.
- `DEF-SUB-005`: suspended/expired tenants have no read-only recovery mode.

