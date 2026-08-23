# Platform Security Risk Register

Status: Phase 1 baseline. Severity reflects SaaS control-plane impact.

| Defect | Severity | Risk | Required control |
|---|---|---|---|
| DEF-PLAT-001 | Critical | Platform and company permissions share a catalog and manager roles receive all permissions | Namespaced/separate catalogs and deny-by-default policies |
| DEF-ENT-001 | Critical | Licensed modules are not enforced by backend | Effective entitlement service and middleware |
| DEF-ENT-002 | Critical | Route access has no feature requirement | Explicit route/action entitlement map |
| DEF-LIMIT-001 | High | Plan resource limits can be exceeded | Transactional usage-limit guards |
| DEF-SUB-001 | High | TRIAL companies are blocked despite being administratively active | Central lifecycle/access policy |
| DEF-SUB-002 | High | Login mutates subscription state | Move lifecycle transitions to audited service/job |
| DEF-SUB-003 | Medium | PENDING exists but is not a valid admin transition state | One state enum and transition policy |
| DEF-SUB-004 | High | Highest subscription ID is treated as effective | Date/status/overlap resolver with database constraints |
| DEF-SUB-005 | High | Expiry/suspension blocks recovery and export | Explicit restricted read-only mode |
| DEF-ONB-001 | High | Platform-created tenant has no primary user | Unified provisioning service |
| DEF-ONB-002 | High | Two onboarding paths create different baselines | One idempotent orchestration path |
| DEF-ONB-003 | Medium | Platform creation activates without consistent billing | Activation/payment policy |
| DEF-DOWN-001 | High | Immediate downgrade has no retention/effective-date safeguards | Scheduled downgrade and retained-data contract |
| DEF-SUP-001 | High | Support can start without a reason | Mandatory reason/ticket |
| DEF-SUP-002 | Critical | Support receives unrestricted company writes | Scoped read-only default and break-glass writes |
| DEF-SUP-003 | High | No support session entity links actions | Durable support-session record |
| DEF-AUD-001 | Critical | Audit failures are swallowed for privileged actions | Fail-closed privileged audit and operational alerting |
| DEF-TEN-001 | High | Central tenant route-resource map omits newer modules | Complete policy/resource coverage and tests |

## Data-retention rule

Subscription expiry, suspension, cancellation, plan disablement, feature disablement, and downgrade never delete tenant business data. Deletion follows a separate, explicit legal retention and tenant-offboarding process.

## Phase 2 gate

No production implementation begins until the state machine, read-only allowlist, permission namespace, entitlement precedence, support access levels, and migration strategy are approved and backed by characterization tests.

