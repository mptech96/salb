# Support Access Model

Status: Phase 1 target contract.

## Principle

Support access is a temporary, explicit delegation into one tenant. The actor remains a platform administrator. The target company, branch scope, reason, access level, expiry, and all actions are auditable.

## Required support session

A support session records a stable ID, platform actor, target company/branch, mandatory reason, ticket/reference, access level, request/start/expiry/end timestamps, token ID, IP, user agent, status, and end reason.

Access levels:

- `READ_ONLY` is the default.
- `OPERATIONAL_SUPPORT` permits an explicit feature/action allowlist.
- `ELEVATED_WRITE` is break-glass, short-lived, and requires stronger authorization.

## Enforcement

- Token abilities identify the support session, not merely a company ID.
- Platform routes remain unavailable while inside support context.
- Company subscription bypass is explicit and recorded.
- Support does not receive all company permissions automatically.
- Every audit row created in support includes actual actor, target company, support session ID, request ID, and result.
- Issuing a token fails if the support-session audit record cannot be persisted.
- Entry, exit, expiry, revocation, and every write are recorded.
- A persistent frontend banner displays company, access level, and expiry.

## Current strengths

- Only actual `SUPER_ADMIN` may request support access.
- Support has a separate two-hour token.
- Token abilities bind company and branch.
- Previous support tokens for the actor are revoked.
- Platform routes are blocked in support mode.
- Password change is blocked in support mode.

## Current defects

- `DEF-SUP-001`: reason is optional and no ticket is required.
- `DEF-SUP-002`: support receives all company permissions and write access.
- `DEF-SUP-003`: no durable support-session entity links all actions.
- `DEF-SUP-004`: support entry audit may fail without blocking token issuance.
- `DEF-SUP-005`: support exit/automatic expiry lacks a complete lifecycle audit.

