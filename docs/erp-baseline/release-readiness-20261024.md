# Release readiness — 2026-10-24

> Updated during approved full-system implementation. The execution results at
> the end supersede the initial audit's "not integrated" statements. Scope is now
> ONE comprehensive release; no split release is being prepared or recommended.

## Decision

**RELEASE_DECISION: NO-GO for the complete current working tree.** No VPS access,
deployment, production migration, local MariaDB recovery, or business-data write
was performed. This is one consolidated release review, not another recovery phase.

## RELEASE_BASELINE / RELEASE_SCOPE

- Local HEAD: `db2b9d63ccda67aadaa0f49e087cc9e0137b42e7`, branch
  `wave2-entitlements-limits`. **Last deployed VPS revision: UNKNOWN.** Local HEAD
  and earlier local QA reports are not evidence of the running production revision.
- Incoming tree: 57 tracked modified files, 43 untracked runtime files in backend
  app/config and frontend app/components/lib. Do not deploy all untracked files:
  the tree also contains experimental tools, test databases, evidence and artifacts.
- Runtime changed/new paths under backend app/config/routes and frontend
  app/components/lib: 94. Sorted `relative-path SPACE file-SHA256`, joined by LF,
  UTF-8 aggregate SHA256:
  `1DD8EB89C58BE9B0EC05CB03A0EBFB1E0C2D322C63EADACC74F1B50F70DE9BF7`.
  This identifies reviewed bytes, not a signed/committed release or deployment.
- Groups: account selection/search/pagination and reports; expense metadata;
  worker/payroll safeguards; voucher/document numbering; inventory/LOT and shipment
  locking; trusted Support transaction ownership; shipment line identity;
  experimental allocation, UOM, historical-accounting and driver-settlement code.
  These groups are not one uniformly completed release.
- No changed/new automatic `backend/database/migrations` files. There are 18
  proposed migrations under documentation and two isolated UOM migration files.
  Their presence does not authorize execution or prove production compatibility.

## VERIFIED_TESTS — executed in this review

| Check | Actual result | Limits |
|---|---|---|
| Guarded backend runner: `php scripts/test_shipment_stage_one.php tests/Unit tests/Feature` | 297 tests, 2069 assertions; 296 PASS, 1 ERROR | Explicit SQLite `:memory:`, testing env, no business DB; not MariaDB lock acceptance |
| Targeted retry of failing PDF case | 1 test, 2 assertions, 1 ERROR | Same error, not fixed by allowed subprocess execution |
| Frontend native Node tests | 20/20 PASS | Mocked/static/component-level, not browser E2E |
| TypeScript `--noEmit --incremental false` | PASS | No output written to application build |
| Production Next build | PASS, 61 routes | Physically copied source/dependencies in Windows Temp; no project `.next` overwrite; does not validate VPS environment/API URL |
| PHP syntax, changed/new PHP app/config/routes/database/tests/scripts | 183/183 PASS | Syntax only |
| `git diff --check` | PASS | Existing line-ending warnings remain |

The first isolated frontend build setup used a dependency junction and was rejected
by Turbopack for escaping its filesystem root. A second isolated build with real
dependency copies completed successfully. No application workaround was applied.

## FAILED_TESTS / existing counterexamples

- Current PDF integration ERROR:
  `Tests\Feature\PhaseB\PrintBrandingSecurityTest::test_server_report_pdf_renders_test_branding_without_enabling_unselected_approval_assets`.
  ChromiumPdfRenderer raises a rendering RuntimeException with empty stderr.
  Application-versus-environment cause is not established. Do not label printing PASS.
- Existing Batch 3 B3-001/002: GL and LOT independently calculate residual allocation
  with different ordering/per-cost aggregation. Item-level reconciliation fails;
  balanced journals alone are insufficient. Shared allocation is not operationally
  connected. Diagnostic tests that reproduce these defects are not acceptance passes.
- Existing B3-003: real transfer/purchase 1213 reproduced three times. Transfer takes
  FIFO/stock locks before late LOT reservation, unlike purchase Q -> item locks.
- Existing B3-004: independent-company receipt blocked by transfer's FIFO footprint,
  actual 1205. The original independence acceptance remains failing.
- Tiny positive freight B3-005 can produce a rejected zero GL line; rollback is
  evidenced, but successful handling is not approved/closed.

These are documented existing counterexamples, not reruns in this review. Source
inspection found their relevant operational calculation/lock paths still present.

## Previous scoped evidence retained, not rerun

- Batch 2: 27/27, 1544 assertions, isolated MariaDB 10.4.32.
- L2: 17/17, 209 assertions; related normal/Support/void regression 27/27,
  1550 assertions; controlled VOID/cost experiment 3/3, 54 assertions.
- Support SALE and PURCHASE VOID closures prove their specified ownership and
  rollback boundaries only. They do not certify all generic Support write actions.
- No current isolated MariaDB server/concurrency suite was started for this review.
  Historical evidence is scoped to its tested snapshot; no blanket approval of
  newly combined code or all producers is inferred.

References: `inventory-batch-2-lock-integration-closure.md`,
`inventory-batch-3a-locking-l2-closure.md`,
`support-sale-rollback-safety-closure.md`,
`purchase-void-support-safety-closure.md`, `inventory-batch-3-producer-audit.md`.

## CRITICAL_DEFECTS / L3_DEPENDENCY

1. `InventoryPostingLocks::reserveLotScope` now requires a pre-existing company /
   calendar-year / branch-0 INVENTORY_LOT sequence, otherwise posting throws.
   Purchase/opening paths require it regardless of L3 activation. Production
   high-water preparation, uniqueness and all relevant years are NOT_VERIFIED.
   Do not invent a seed or run a production update as part of release review.
2. GL/LOT reconciliation and transfer/purchase lock compatibility above remain
   unresolved for a release containing all inventory changes.
3. L3 design gate remains NO-GO: tested candidate requires root-transaction-local
   READ COMMITTED before BEGIN and a shipment-cost parent FK; universal producer
   participation and operational FK design are not approved. Do not implement it
   implicitly. `SHIPMENT_COST_FRESH_READ` defaults false; keep it false and exclude
   RC/FK activation to omit L3. That does **not** remove defects 1–2.
4. Generic Support inventory/return failure responses have a code-derived outer
   transaction risk, not a newly proved partial-commit finding; real HTTP failure
   acceptance outside closed owners is NOT_TESTED.

The owner subsequently rejected a split release and authorized full-system local
completion. The implementation update below follows that decision. No smaller
release has been extracted. Financial policy gaps still require explicit decisions;
the full release remains blocked until all acceptance conditions are met.

## PRODUCTION_DB_COMPATIBILITY / BLOCKED_TESTS

- Production engine/version, deployed SHA, schema/indexes/constraints, sequence
  high-water, isolation/SQL modes, grants and feature/config-cache values UNKNOWN.
- Shipment identity writer supports absent identity schema, but partial identity
  schema rejects writes. Validate full expected column/unique/triggers or defer
  identity DDL entirely. Do not roll back to delete/reinsert writers after identity
  data has been used without a specific compatibility review.
- Do not activate UOM-4B, historical financial posting, driver settlements, fresh
  cost reads or allocation-ledger migrations from the mixed working tree.
- Local PHP 8.4.22 tests do not prove production PHP/extensions compatibility;
  Next package requires Node >=20.9.0. Build-time production API URL is unverified.
- Login/permissions/customer/supplier/sales/purchase/void/inventory/accounting API
  coverage exists in the passing backend suite, not full real-browser journeys.
  Full login -> /me -> company/permissions -> Dashboard, printing, returns and
  customer-environment smoke tests remain NOT_TESTED on a release staging target.
- MariaDB-specific affected concurrency, decimal behavior, schema compatibility,
  remaining inventory producers and production-equivalent migrations are BLOCKED
  pending an approved disposable target and scope; broken XAMPP is not required.
- Current production backup and a successful independent restore are NOT_VERIFIED.
  Local R4 preservation is not a production backup or a restore test.

## DEPLOYMENT_PLAN / ROLLBACK_PLAN — proposals only

1. Obtain operator-provided read-only deployed revision, runtime/schema/config
   evidence; select an explicit allowlist and immutable candidate artifact. Exclude
   `.env`, secrets, storage test databases, generated proofs and experimental tools.
2. Close blockers for that scope in disposable production-equivalent staging;
   retest affected HTTP/real concurrency and PDF/browser paths. No blind COMMIT retry.
3. Before any deployment, obtain an engine-appropriate consistent production backup,
   settings/grants/schema inventory and verified isolated restore. Approve migrations
   separately with data/DDL rollback limits. No automatic migrate on deployment.
4. Retain the exact previous code artifact/config and approve deployment separately.
   Post-deployment smoke: login, /me, tenant/role isolation, Dashboard, reports/PDF;
   approved test-company posting/void and GL/LOT/FIFO reconciliation only under a
   separate controlled production smoke authorization. No customer-data test writes.
5. Roll back code to the retained artifact only when schema/data compatibility is
   established. Do not restore an old DB over new customer transactions, drop ledger
   or identity data, or undo migrations blindly; stop rollout and use an approved
   forward repair/reconciliation where financial data has changed.

## Initial audit changes (before the subsequent implementation authorization)

Only this consolidated document was added. No ERP source, existing local changes,
Windows/MariaDB configuration, database, Git history or deployment was modified.
Temporary isolated frontend build copies remain in Windows Temp; none were deleted.

## Approved implementation progress — same release, 2026-10-24

### Environment and authorization

- The attached full-system request authorized local fixes and isolated tests, NOT
  production access/deployment or business-data test writes. The owner additionally
  approved local-only L3 parent-FK development and root READ COMMITTED selection,
  including Support linked PURCHASE, in a direct reply during this run.
- Laravel effective configuration: mysql, 127.0.0.1:3306, scrap_system, no cached
  configuration and no configured password. No password/key was printed or guessed.
  Existing-credential PDO attempts failed with driver 2002 both inside and outside
  sandbox restrictions. A direct TCP probe reported active refusal, not authentication
  rejection. No root/account/configuration change or local database repair performed.
- Independent QA MariaDB 10.4.32 was freshly initialized with synthetic system data
  at `C:\Users\mptec\AppData\Local\Temp\sulb-shipment-8f43fab47a824b049fdedd5514b99c9f`,
  loopback port 3339, initial process PID 50576, `--no-defaults`. Data, temp, error,
  PID and Aria paths are isolated. Each invocation checks exact @@datadir, testing
  environment and random owned schema before writes; invocation schemas are removed
  by the guarded runner. Original and R4/R5 file trees were not used for QA.

### Code implemented

1. **PDF:** the installed Edge executable exited 0 without a PDF, while installed
   Chrome generated one in an independent Temp probe. Renderer now prefers Chrome
   on Windows after any explicit configured binary. Failure messages include exit
   status/output presence instead of a blank diagnostic. No sandbox-disabling flag
   was introduced. Real Arabic QA PDF is two A4 pages; both pages rendered and were
   visually inspected for text, repeated table header, footer, totals and watermark.
2. **Transfer lock order:** existing operation/line locks, then existing LOT Q and
   sorted item locks before FIFO/stock writes. No FIFO arithmetic/order or GL amounts
   were changed. New real same-company acceptance observes a wait on Q then both
   transactions commit without 1213. Independent-company acceptance still FAILS.
   A FORCE INDEX experiment still blocked on the balance-index supremum and was
   removed; no speculative FIFO index hint remains in operational code.
3. **L3:** new ShipmentCostReadIsolation validates same-owner entry, FK checks,
   exact parent FK and InnoDB tables, selects only the next root's RC before BEGIN,
   and clears/restores its one-shot setting after completion or failed BEGIN. Wired
   into normal linked PURCHASE and trusted PURCHASE_POST owner only. SALE/VOID and
   plain unlinked purchase do not receive this setting. No SESSION/GLOBAL default
   isolation change, retry or new financial owner was added. FK candidate lives
   outside automatic migrations, guards the disposable engine, rejects orphan or
   cross-company existing rows rather than repairing history, uses RESTRICT and
   blocks rollback while fresh-read protocol is enabled. Applied only in QA.
4. **Shared allocation:** new request-scoped ShipmentAllocationLedger issues its
   plan after H/Q/I/R/current-cost reads and before inventory/GL/PAY writes. Each
   original cost uses the existing BCMath LR calculator at 0.001. Captured item code
   is nonblank/company-unique and frozen in the receipt; technical refs/IDs only
   route recipients and never rank ties. Source purchase-line values are read from
   the locked lines, not guessed from stale shipment cost values. Root/PDO binding,
   no same-root replan, changed-input rejection and receipt uniqueness protect use.
   GL uses the plan; LOT distribution reads the immutable persisted receipt and
   does NOT run a second allocation. Zero recipients remain in the receipt without
   creating invalid zero GL lines. VOID keeps original receipts unchanged and uses
   existing journal reversal. No frozen accounting service, REC/PAY rule, historical
   financial row, or FIFO valuation code was edited by this work.
5. **SQL disclosure:** actual Support SALE/VOID HTTP failure evidence exposed SQL,
   connection and schema details. ApiExceptionMessage now removes database details
   in invoice endpoints and the Support failure boundary, traversing nested
   QueryException wrappers while retaining recognized 1205/1213 codes and existing
   non-database business validation. No permission or rollback policy changed.
   This is scoped remediation, not certification of every API error handler.

All activation flags remain default OFF. The new paths were enabled only in guarded
test configuration. No operational migration, backfill, feature activation on the
original DB, VPS access, commit, push or deployment occurred.

### Actual executions in this implementation

| Suite | Result | Scope |
|---|---|---|
| PDF affected branding/security + new diagnostic tests | 15/15 PASS, 106 assertions | SQLite-memory; actual Chrome PDF generation |
| Transfer value/GL, real1205 exact rollback/retry, duplicate approval | 3/3 PASS, 20 assertions | Real disposable MariaDB |
| New transfer/purchase and independent-company acceptance | 1 PASS / 1 FAIL after Q/I correction | Real Q wait observed; independent company still blocked |
| Existing LOT preparation + UOM closure contracts | 20/20 PASS, 273 assertions | Real MariaDB; contracts, NOT operational UOM activation |
| L3 operational entry without test-supplied isolation | 15/15 PASS, 228 assertions | Real freshness, raw membership mutation, Support, rollback and next-transaction RR |
| New L3 FK/entry/failed-BEGIN safeguards | 5/5 PASS, 19 assertions | DDL only in owned disposable schema |
| Batch2 + Support SALE + PURCHASE VOID regression | 27/27 PASS, 1550 assertions | Actual isolated MariaDB, legacy allocation mode |
| Original rounding cases + prewrite tie/tiny-cost/receipt/VOID/staleness | 10/10 PASS, 72 assertions | Actual services with fresh-read and receipt paths enabled |
| L3 normal/Support acceptance expanded to legacy AND allocation-ledger modes | 26/26 PASS, 388 assertions | Real 1205/1213, update during observed wait, immutable captured inputs, no isolation leak |
| Full backend after owner/allocator/PDF changes | 299/299 PASS, 2075 assertions | SQLite-memory; before the later scoped error-message edits |
| API error-message unit regression, including nested driver wrapper | 3/3 PASS, 11 assertions | No database required |

Counts are per actual invocation, not added together as unique tests. Earlier
frontend20/20, TypeScript and production61-route build remain applicable: no frontend
code changed during this implementation. Targeted HTTP security and final expanded
rounding suite results are recorded below when complete; interim failures are not
hidden or treated as fixes.

### FIFO discrepancy — reproduced, then repaired for tracked LOTs

`test_fifo_issue_values_must_conserve_the_capitalized_lot_total` now exercises the
REAL InventoryLotService, not an arithmetic-only example. LOT total105.001, quantity1,
two separate 0.5 issues => issued value105.002 while remaining quantity0. Acceptance
The original failing acceptance was retained and became PASS only after the owner
explicitly approved local development of exact remaining-value tracking and final
issue residuals. New InventoryLotValue and an additive isolated-only balance-table
migration now preserve105.001 as52.501+52.500. New LOTs are tracked; capitalization
updates their exact unissued balance; returns/reversals restore the stored original
source value. Three issues of a10.001 LOT produce3.334+3.334+3.333. Runtime safeguards
reject missing schema, unowned writes and unknown historical remaining value.
Legacy LOTs were NOT backfilled or recosted. Reads retain legacy valuation when no
tracking row exists; writes with the new value flag require a reviewed balance.
Inventory summary, valuation endpoint and inventory/executive/shipment-profit value
expressions use the exact tracked balance. Mixed-history initialization, import
snapshot handling and all remaining writer coverage are still release gates.

### Subsequent approvals, verified results and outstanding work

- Owner explicitly approved a transfer-specific root RC protocol, normal and
  Support, in isolated QA only. Implemented InventoryTransferTransactionOwner with
  same-root/PDO identity, rollback-only including swallowed responses, physical root
  rollback, unknown-outer refusal and next-transaction isolation restoration.
  Classification is persisted company-scoped TRANSFER state, not client metadata.
  Controller propagates owned failure; Support records failure after confirmed
  rollback. Authorization/capabilities are unchanged. Activation is default OFF via
  `SULB_TRANSFER_ROOT_PROTOCOL`; QA workers enable it only after disposable guards.
  Independent company now commits while transfer holds FIFO; same-company purchase
  waits on Q then commits. Real1213 victim and1205 failures are atomic. The former
  expected-deadlock audit test was converted to a stronger repaired-path acceptance
  with three observed Q-wait schedules, not skipped or marked expected failure.
- Owner explicitly approved larger allocation basis as the next tie-breaker for
  same-item equal residuals. Implemented exact BCMath basis comparison after remainder
  and business code; no ID/UUID priority. Identical same-code/same-basis indivisible
  boundaries still reject. Both pure order independence and actual GL/separate-LOT
  consumption of the1-versus3 basis /0.002 case pass.
- Owner approved the nonweight UOM design: independent base quantity for new
  PCS/M/L items; no fictitious conversion to KG; WEIGHT only with documented physical
  weight, otherwise an explicit RELATIVE_VALUE choice. **This design is approved but
  the operational UOM implementation has not been built in this execution.**
- UOM contract arithmetic is tested, but OperationalWriteGate still deliberately
  rejects BASE_V1 writes. Actual purchase/sale/returns/stock/GL/reader/UI integration
  and mixed-history migration remain unfinished. This work is NOT deferred from the
  comprehensive release and must be completed before GO.
- LOT safe high-water preparation is tested only in isolated proof code. Reviewed
  operational preparation/deployment controls and production historical compatibility
  are still needed; no counter has been initialized on business data.
- Full real-browser login/Dashboard, mobile, complete cross-module business journey,
  exhaustive security/error-handler coverage and production-equivalent staging are
  NOT_TESTED. The disposable QA server is not production-equivalent staging.
- Production revision/schema/runtime/config and tested backup/restore remain unknown.
  R4 local preservation does not close production backup requirements.

**RELEASE_DECISION: NO-GO. DEPLOYMENT_READY: NO.** Substantial isolated fixes/tests
completed; full-system completion is not claimed. Keep one release and one summary.

### Latest consolidated test results (supersede intermediate failures above)

| Current check | Result |
|---|---|
| Full backend memory-only regression after transfer/return/value integration | **303/303 PASS,2090 assertions** |
| Full rounding + tracked FIFO + return/reversal + report + approved tie policy | **14/14 PASS,104 assertions** |
| Transfer concurrency + real Support HTTP authorization/failures/1213/isolation | **6/6 PASS,81 assertions** |
| Three repaired transfer/purchase schedules + timeout/duplicate regression | **3/3 PASS,66 assertions** |
| Exact LOT balance, rollback, legacy no-backfill and prewrite schema/root gates | **3/3 PASS,17 assertions** |
| SQL-disclosure HTTP regression, including real1205/1213 and financial snapshots | **4/4 PASS,350 assertions** |
| Nested API database-error message unit checks | **3/3 PASS,11 assertions** |
| Exact LR calculator with approved same-item basis tie-break | **7/7 PASS,17 assertions** |
| Normal/Support L3 with legacy AND shared allocation modes | **26/26 PASS,388 assertions** |
| Isolated parent-FK migration, missing-FK gate, orphan and failed-BEGIN cleanup | **5/5 PASS,19 assertions** |
| Earlier same-run Batch2/Support SALE/PURCHASE VOID regression | **27/27 PASS,1550 assertions** |
| UOM/LOT preparation contracts, not operational activation | **20/20 PASS,273 assertions** |

No combined all-Integration-directory PASS is claimed. Historic diagnostic tests
for the legacy/default-off rounding path intentionally reproduce old behaviour;
the new explicit-policy acceptance and current transfer acceptance are distinguished.
Frontend tests/build were not repeated after backend-only changes. Desktop/mobile
browser acceptance and production-equivalent staging remain NOT_TESTED.

### Files edited by this implementation (not the pre-existing dirty tree)

- Runtime existing: Services/Print/ChromiumPdfRenderer; InventoryOperationService;
  InventoryLotService; EnterpriseInvoiceService; ShipmentCostService;
  ShipmentCostDistributionService; ShipmentLargestRemainder; ShipmentTransactionOwner;
  CommercialReturnService; ReportCenterService; Api/PurchaseInvoiceController;
  Api/SalesInvoiceController; Api/InventoryOperationController; Api/InventoryController;
  Middleware/EnsureSupportAccess.
- New runtime: ShipmentCostReadIsolation, ShipmentAllocationLedger,
  InventoryTransferTransactionOwner, InventoryLotValue, Support/ApiExceptionMessage;
  config/inventory_value.php and config/inventory_transfer.php.
- Isolated candidate DDL: shipment_cost_parent_fk.php and
  inventory_lot_value_balances.php under proposed-migrations. No automatic migration
  or historical financial backfill was added.
- Tests/tools: new ReleaseTransferLockAcceptanceTest, TransferSupportBoundaryTest,
  ShipmentCostReadIsolationTest, InventoryLotValueAcceptanceTest,
  ChromiumPdfRendererDiagnosticTest, ApiExceptionMessageTest and
  release_transfer_support_probe.php; extended existing rounding/protocol/audit/
  SALE/VOID/LR tests and batch_three_producer_worker.php.
- Only this existing summary document was updated. No new per-fix report.

The frozen accounting posting service, VoucherPosting and SulbDocumentSequenceService
retain incoming changes: this implementation did not edit them. No frontend code,
environment secrets, original DB, R4/R5, customer data or VPS was modified.

QA teardown: no remaining invocation-owned schemas and no external clients were
found. A first conservative shutdown guard refused because it counted InnoDB's own
system-user daemons; read-only inspection identified those exact engine threads.
After excluding only those identified internal daemons, normal SHUTDOWN was sent
through PDO to the exact owned datadir on3339. No forced termination or directory
deletion was used. Current reviewed runtime scope has105 changed/new paths; the
initial94-path digest above identifies the incoming audit, not this modified candidate.

## Operational UOM implementation increment — 2026-10-24

This is executable code, not a new audit phase. The one-release target and all
previously approved policies remain unchanged. This increment does **not** close
operational UOM or declare a release candidate ready.

### Changed in this increment

- `backend/app/Http/Controllers/Api/ItemUnitController.php` (new): real tenant
  routes to read unit definitions and append mapping/price revisions. Company is
  taken only from the trusted context; foreign items remain inaccessible.
  Conversion factors/prices must be decimal strings with at most six fractional
  places. Historical revisions remain intact. No dictionary mutation, historical
  item conversion, stock, LOT, GL, or financial document write is performed.
- `backend/routes/api.php`: GET `items/{id}/units`, POST
  `items/{id}/units/mappings`, POST `items/{id}/units/prices`, within the existing
  complete company middleware stack.
- `backend/app/Http/Middleware/EnsureRoutePermission.php`: those exact routes map
  to existing `items.view`/`items.update`, not a new authorization bypass.
- `backend/app/Services/Uom/ItemUnitService.php`: optional current-revision check
  for new catalog prices is repeated **under the item lock**. Existing internal
  callers keep their previous default contract. No historical migration edited.
- `frontend/components/inventory/ItemUnitManager.tsx` (new), embedded in
  `frontend/app/items/page.tsx`: Arabic unit/revision/price interface, responsive
  forms, exact decimal strings, late-response cancellation, visible failures.
  Legacy items and absent-schema environments render non-writable explanations.
- New tests: `backend/tests/Integration/Uom/ItemUnitHttpTest.php` and
  `frontend/tests/item-unit-manager.test.mjs`.

### Tests actually run now

| Test | Actual result | Evidence / limits |
| --- | --- | --- |
| ItemUnitHttpTest | **5/5 PASS, 44 assertions** | Actual MariaDB **10.4.32**, same retained isolated QA datadir, port3339, random invocation-owned schema, real HTTP Kernel/routes and middleware; no bypass |
| Affected UomFoundationTest method `test_real_migration_and_services_without_stock_activation` | **1/1 PASS, 16 assertions** | Real foundation migration and existing contract behavior preserved; not proof of operational posting |
| ItemUnitManager component tests | **3/3 PASS** | React server rendering plus mocked-hook/API submission tests; exact decimal payloads, schema/legacy read-only states. **Not browser E2E** |
| TypeScript `tsc --noEmit` | **PASS** | Current modified frontend |
| Next.js production build | **PASS, 61 routes** | First sandbox build failed while spawning PostCSS child process; same build succeeded with approved local process permission, no source workaround/config change |
| PHP lint (controller, service, new HTTP test) | **PASS** | Actual PHP8.4.22 |
| `git diff --check` | **PASS** | Existing line-ending warnings only |

The first HTTP run had one failure: tenant middleware adds trusted `company_id`,
so prohibiting that field rejected legitimate requests. Fixed by never consuming
it from validated input, preserving existing middleware semantics. A positive
spoofing test now sends company2 and verifies the saved mapping still belongs to
company1. Final five-test result supersedes the earlier failed/intermediate run.
Tests also verify non-manager permission mapping, stale price-revision rejection,
foreign item404, read-only403, wrong dimension/precision rejection, and a forced
database failure returning409 without SQL details, head changes or open transaction.

### Explicit remaining release work — not claimed complete

1. **Operational UOM remains IN_PROGRESS:** creation/enrollment UX for new BASE
   items and unit-aware purchase/sale/stock/transfer/return/report adapters are not
   implemented by this increment. The existing `OperationalWriteGate` and closed
   stock triggers remain intact; they were not flipped merely to make UI pass.
   Therefore this interface is currently a definition-management increment, not
   a complete non-weight stock workflow.
2. No new operational quantity-schema migration or historical LOT backfill was
   executed. Verified historical LOT sequence/value upgrade with explicit
   exceptions is still **IN_PROGRESS**, not approved for production use.
3. Actual full-browser/mobile user journey after these changes is **NOT_TESTED**.
   Prior PDF evidence remains limited to its previously tested code; no new
   invoice/UOM PDF acceptance is claimed.
4. VPS deployed SHA/schema remain **UNKNOWN**. No VPS connection was attempted;
   separate read-only authorization and actual evidence are still required.

Earlier unaffected Batch2/Support/FIFO/accounting results are retained by
reference, not presented as reruns or as acceptance of new UOM posting.
No original DB/R4/R5, existing financial history, frozen accounting service,
Windows configuration, production database, migration on production, commit,
push or deployment was changed. **RELEASE_DECISION: NO-GO** until the remaining
implementation and Staging acceptance actually pass.

Increment teardown: the isolated QA server started as PID8840. After checking its
exact datadir, no remaining invocation-owned schemas and no other user clients,
normal SQL SHUTDOWN was requested successfully; PID8840 was no longer present.
The first teardown command failed PHP parsing in the .bat argument wrapper before
executing SQL; calling the same installed php84 executable directly succeeded.
No forced termination, QA directory deletion or original-server operation occurred.

## Resumed operational UOM — purchase / return / reversal acceptance

Continued from the saved implementation after the usage-limit interruption;
the earlier management and operational edits were preserved, not recreated.
This section supersedes the earlier statement that purchase/sale adapters have
not been implemented. It does **not** supersede the overall release NO-GO.

### Actual completed implementation

- Versioned invoice lines capture entered quantity, chosen unit, mapping revision
  identity, conversion factor, base quantity and price. New BASE_V1 stock uses
  independent decimal base quantities; kilogram fields stay zero. Existing
  LEGACY_KG paths are retained, not backfilled into another quantity contract.
- Receipt / FIFO / sale / purchase-return / sales-return / reversal adapters
  operate on the isolated operational schema. FIFO still orders received_at/id.
  Final issues and final returns preserve the captured source value at0.001.
- Returns follow the source invoice's immutable mapping and financial snapshots,
  not a subsequently changed mapping, price, item default or SQL row order.
  The entered unit and base unit are validated against the captured mapping.
- Resumed review found and reproduced a real financial rounding defect: three
  partial returns of a1.001 source produced0.334 +0.334 +0.334 =1.002, although
  the LOT cost residual already reconciled correctly. Fixed **BASE_V1 returns
  only**: subtract previously reserved/posted captured amounts; the final return
  takes the exact source residual. Result0.334 +0.334 +0.333 =1.001. Original
  documents/journals and legacy return rules are not recalculated or rewritten.
- Return quantity checks now use exact six-decimal quantities and include lines
  already inserted into the current draft. Duplicate source rows cannot silently
  over-reserve the source, including while editing a draft.
- A return cannot post against an original invoice that has since been VOIDed.
  A BASE sale with an active posted return cannot be voided first. Reverse the
  return first, then the sale. Reversal movement dates match the new reversal
  journal date rather than backdating them to the original return.
- Unknown outer transactions for the new BASE financial entry paths fail closed
  before financial/inventory changes. They are not adopted or rolled back on
  behalf of another owner. Existing trusted linked-invoice owners retain their
  contract; legacy Support acceptance below still passes. This is **not** a claim
  that every new BASE Support workflow is enabled (see remaining work).
- CommercialReturnController uses the existing safe exception formatter. Real
  database failures no longer disclose SQL/connection/schema details in its
  source/save/post/void responses. No automatic retry was introduced.

### Actual cycle values

| Scenario | Verified quantities / money |
| --- | --- |
| Buy1 CARTON at factor12, cost120; sell5 PCS | Receive12; remaining7; LOT value70; COGS50; GL balanced |
| Change current CARTON factor12 ->24, then return2 original sale pieces | Remaining9; value90; source factor/price unchanged |
| Return0.25 of original purchase carton after factor revision | Exactly3 base pieces, not6; remaining6; reversal restores previous balance |
| Reverse purchase return, sales return, then original sale | Original12 pieces /120 restored; movement quantities/values equal LOT and GL |
| Sell0.5 original carton at180 per carton, return0.25 after factor revision | Sell6 pieces /revenue90; return3 pieces /revenue45; correct original snapshot |
| Reverse that return and sale, then unused purchase | Remaining quantity0 and value0; historical entries retained with reversal journals |
| Two purchase LOTs12 pieces/120 and1 piece/20; sell13 and fully return | FIFO captures12/120 then1/20; returns restore each exact original LOT value |
| Three one-piece returns of a3-piece cost10.001 | Cost3.334 +3.334 +3.333; full source cost10.001 restored; revenue residual separately reconciles |
| Transfer5 PCS from12-piece receipt | Source7 /70, destination5 /50; total value120; repeated approval creates no duplicate LOT |

### Tests executed on the saved implementation in this resumed round

All database tests used the retained synthetic MariaDB **10.4.32** server at
127.0.0.1:3339 and the exact guarded datadir
`C:\Users\mptec\AppData\Local\Temp\sulb-shipment-8f43fab47a824b049fdedd5514b99c9f`.
Every runner owned a random `sulb_ship_test_*` schema, applied DDL only there and
removed only that invocation's synthetic schema afterward. No XAMPP original,
preservation copy, business data or VPS database was used. The startup attempt
refused an already occupied QA port; the runner then verified the existing
server's exact datadir before using it. No second server was started.

| Test | Final actual result | What it proves |
| --- | --- | --- |
| `OperationalCycleTest` | **9/9 PASS, 225 assertions** | Actual services plus full HTTP Kernel/controller/middleware cycle; frozen-factor returns, multiple LOTs, fractional money, duplicate POST/VOID protection, original-VOID rejection, unknown outer rejection, company ID spoofing/IDOR denials, movement/LOT/GL matching |
| Real BASE sale1205 /1213 | Included in9/9 | Independent worker waits on the locked financial year **after FIFO writes**; confirmed rollback restores complete table snapshot, no open transaction; explicit retry after confirmed rollback succeeds |
| Real HTTP return1205 | Included in9/9 | Independent connection holds FY; HTTP returns422 without SQL details; all draft/LOT/stock/GL/sequence snapshots unchanged, PDO/root closed; subsequent explicit request succeeds |
| `BatchTwoLockIntegrationTest`, `BatchTwoSaleSupportBoundaryTest`, `BatchTwoVoidSupportBoundaryTest`, `PurchaseVoidRollbackClosureTest`, `InventoryLotValueAcceptanceTest`, `BatchThreeARoundingAcceptanceTest` | **46/46 PASS, 1824 assertions**, one actual MariaDB run | Affected legacy purchase/void/SupportSALE, Batch2 concurrency, exact LOT values and shared GL/LOT distribution regression; includes inherited cases, not46 distinct business modules |
| `invoice-uom.test.mjs` + `item-unit-manager.test.mjs` | **6/6 PASS** | Exact string payloads without client base calculations; captured quantity/unit/price reopening and display; legacy KG/TON/service compatibility; component/unit tests, **not browser E2E** |
| TypeScript `tsc --noEmit` | **PASS** | Saved modified frontend compiles |
| PHP lint on resumed return/controller/invoice/test changes | **PASS** | Actual PHP8.4.22 parser |
| `git diff --check` | **PASS** | Existing line-ending notices only |

The earlier lock-test failure was an **observation failure**, not evidence that
no wait occurred: information_schema omitted the live worker while the current
InnoDB transaction monitor showed the exact worker thread in LOCK WAIT on the
financial_years PRIMARY record and the known holder. The test now accepts either
the exact waiter/holder edge from INNODB_LOCK_WAITS or the CURRENT transaction
section proving that worker/table/lock wait and holder; it never accepts elapsed
sleep, a SQL-request marker, or the historical latest-deadlock section alone.
Both real1205 and1213 acceptance runs recorded `CURRENT_INNODB_MONITOR` evidence.
The reproducible1.002 rounding failure and failed observation runs were retained
as pre-fix evidence; they are not reported as successful final acceptance.

### Files changed in the resumed round

1. `backend/app/Services/Uom/BaseReturns.php`: frozen mapping validation, exact
   quantity/money residual checks, current reversal date.
2. `backend/app/Services/CommercialReturnService.php`: integrate that calculation,
   current source-status check, sorted BASE item locks, fail-closed outer guards.
3. `backend/app/Services/EnterpriseInvoiceService.php`: BASE root guards and active
   return protection; correctly capture the existing trusted owner in the closure.
4. `backend/app/Http/Controllers/Api/CommercialReturnController.php`: safe database
   error messages, no change to authorization or business posting amounts.
5. `backend/tests/Integration/Uom/OperationalCycleTest.php`: extend the saved
   acceptance tests and repair the observer without weakening acceptance.
6. `frontend/tests/invoice-uom.test.mjs`: new focused tests for the saved adapter.
7. This existing unified release report only; no new report series.

The other saved UOM edits (schema, BaseInventory, metadata, transfers and invoice
UI adapters) remain in place. No frozen posting service, FIFO selection order,
REC/PAY rule, Batch2 lock protocol, Windows policy, original database or
production migration was changed in this resumed round.

### Remaining release work / truthful boundaries

- This closes the **tested normal purchase -> receipt -> stock -> sale -> return
  -> reversal -> GL/LOT cycle**, not full operational UOM across every producer.
- BASE shipment-linked rows still explicitly require their shipment adapter;
  non-weight processing/opening/adjustment paths and all stock readers/reports
  are not certified by these tests. Some readers still assume kilogram balances.
- New BASE financial calls inside an unknown outer/Support transaction are
  refused, not silently enabled. Complete new BASE Support integration needs its
  explicit trusted-owner path and dedicated tests. The46-test legacy regression
  is not proof of that new path.
- Full browser/mobile journey and new UOM invoice/PDF acceptance remain
  **NOT_TESTED**. Existing unaffected PDF evidence is retained, not rerun or
  relabelled as new UOM acceptance. No new production build result is claimed in
  this resumed round; TypeScript and the focused component/helper tests passed.
- Historical LOT sequence/value backfill and a representative-production upgrade
  rehearsal remain pending. No historical values were guessed or backfilled.
- VPS deployed SHA/schema remain UNKNOWN; no access or deployment attempted.

**TESTED_RETURN_CYCLE: PASS. FULL_UOM: IN_PROGRESS.
RELEASE_DECISION: NO-GO.** No deploy, commit, push or production migration.

Resumed-round teardown: exact QA datadir and version10.4.32-MariaDB were verified;
zero invocation-owned schemas and no other user clients remained. Normal SQL
SHUTDOWN was requested successfully on3339 only. No forced process termination,
directory deletion or original-server operation occurred.

## New continuation — operational UOM readers, invoice detail reports and transfer entry

These are **new changes and new acceptance cases**, not a rerun of the accepted
return-cycle, L2/L3 or Batch2 results above.

### Implemented now

- Added `backend/app/Services/Uom/InventoryQuantitySql.php`. Inventory summaries,
  valuation, executive stock value, LOT and movement reports now read BASE
  quantities separately from the retained legacy kilogram fields. A positive
  `0.000001 M` balance is not filtered out by the old kilogram threshold.
- `InventoryController` valuation returns `quantities_by_unit`; PCS, M and KG are
  not added into one dimensionless quantity. Existing monetary totals remain.
- Purchase/sale item-detail reports now expose the **saved** entered quantity,
  unit and six-place price, not the current conversion head. Legacy qty/price
  response keys remain intact. Read queries remain company scoped.
- Inventory page and invoice stock hints use exact decimal display. The invoice
  print component preserves saved six-place BASE quantity and unit price.
  This component test is not a browser/PDF acceptance claim.
- Transfer metadata supplies current company-scoped unit mappings. The transfer
  entry UI selects a unit and sends decimal-string quantity without stale
  `qty_kg`, client base quantity or client conversion factor. Legacy weight
  payloads retain their existing contract. Non-weight processing remains
  explicitly blocked rather than assigned an invented allocation policy.

### Files changed in this continuation

- Backend: `app/Services/Uom/InventoryQuantitySql.php` (new),
  `app/Services/Uom/OperationalDocument.php`, `app/Services/InventoryLotService.php`,
  `app/Services/ReportCenterService.php`,
  `app/Http/Controllers/Api/InventoryController.php`,
  `app/Http/Controllers/Api/InventoryOperationController.php`,
  `tests/Integration/Uom/OperationalReadTest.php` (new).
- Frontend: `lib/inventory-uom.ts` (new), `app/inventory/page.tsx`,
  `app/inventory-operations/page.tsx`, `app/reports/page.tsx`,
  `components/erp/InvoiceMasterPage.tsx`,
  `components/print/TaxInvoicePrint.tsx`,
  `tests/inventory-uom-read.test.mjs` (new).
- This same unified report. Incoming unrelated working-tree edits are retained.

### New executed tests

MariaDB **10.4.32**, loopback **3339**, fresh synthetic disposable server:
`C:\Users\mptec\AppData\Local\Temp\sulb-shipment-5d5bff2624b3449fb40e049633a8e443`.
The runner verifies the exact server datadir and creates/removes only its random
invocation-owned test schema.

1. `OperationalReadTest`: final first-three-case run **3 PASS / 34 assertions**:
   mixed-unit inventory/valuation and tiny quantities; saved invoice reporting
   after conversion revision; current company-scoped transfer metadata.
2. New fourth case, filtered separately: **1 PASS / 9 assertions**. The real
   transfer Controller validates one CARTON, saves 12 base PCS, then the mapping
   changes to24 before approval. Actual approval still moves12 PCS/value120,
   leaving12 PCS/value120 in the source. Total LOT value remains240 and no
   transaction leaks. This is direct Controller/service integration, **not an
   HTTP Kernel/browser test**.
3. `node --test tests/inventory-uom-read.test.mjs`: **3 PASS / 0 FAIL**. Exact
   six-place display; transfer payload strips stale weight/conversion; invoice
   table SSR preserves saved quantity/price. SSR emits styled-jsx attribute
   warnings outside the Next compiler; no visual/PDF result is inferred.
4. `npx tsc --noEmit`: **PASS**, after the transfer entry UI changes.

The previously retained QA directory failed to restart with an Aria checkpoint
error. It was **not repaired or modified for recovery**. A new empty synthetic
QA datadir was initialized instead; none of the original XAMPP data, R4/R5
preservation copies or production databases was used. A sandbox socket denial
also prevented one attempted rerun before PHPUnit began; the final actual QA
runs above used the approved local-process permission and passed.

### Still open — not converted into PASS

- Full UOM is **IN_PROGRESS**: shipment adapter, new-item BASE enrollment entry,
  opening/adjustment/non-weight processing and remaining operation print/detail
  surfaces are not closed by these changes. Existing non-weight writer guards
  remain in force. New BASE Support-owner integration remains unverified.
- Historical LOT value/sequence backfill is **NOT_IMPLEMENTED** as a production
  upgrade. Existing isolated sequence proofs are not a substitute for a
  reviewed value reconciliation with explicit historical exceptions. No cost
  was guessed and no historical business data was backfilled.
- Browser capability was discovered, but no new local UI journey, mobile run,
  authenticated browser acceptance or new rendered UOM PDF has completed in
  this continuation. These remain **NOT_TESTED**, not PASS.
- Complete tenant/security closure and representative-production upgrade
  rehearsal remain pending. Scoped tests above are not general authorization
  certification. Production deployed SHA/schema remain **UNKNOWN**; VPS was not
  accessed. Staging/deployment remain blocked pending these closures.

**NEW_FOCUSED_TESTS: PASS. FULL_UOM: IN_PROGRESS. RELEASE_DECISION: NO-GO.**
No original DB operation, production migration, deploy, commit or push.

## Continuation 2026-10-25 — saved-work acceptance, UOM surfaces and real PDF

This section records only this continuation's work and actual executions. The
incoming tree contained **73 tracked modified files**, rather than an isolatable
24-file checkpoint, plus saved untracked implementation/tests. No reset, clean,
checkout, commit, push or replacement of the saved work occurred. The final tree
has 77 tracked modified files. The previous accepted Support cycle and 17/17
transaction-owner acceptance are retained; they were not rerun or counted here.

### Fixes and closure on the saved implementation

- Dashboard already had the saved per-unit adapter. Its grouping now explicitly
  uses KG for legacy LOTs and the BASE unit for BASE LOTs, preserves decimal
  strings and nonzero tiny/negative balances, and keeps company/branch filters.
- Added a shared legacy-weight guard. LOT receive/FIFO, scalar stock writes,
  availability/cost reads and destructive legacy source reversal refuse BASE
  items. Legacy capitalization refuses BASE LOTs before changing either value
  field. Processing outputs and quotation/purchase-order entry likewise refuse
  unsupported BASE interpretations before saving/numbering. These are explicit
  safety closures, **not implementations of those missing BASE adapters**.
- Reports now retain BASE quantities in item profitability, executive stock,
  invoice summaries and transfer summaries. A mixed invoice reports separate
  HOUR/KG/PCS quantities. Saved service units are preserved in item-detail
  reports; historical stock detail retains its existing TON contract. The SQL
  executes with ONLY_FULL_GROUP_BY enabled; no SQL mode was weakened.
- Migration-center invoice exports now preserve saved BASE quantity/unit/price,
  including the original CARTON factor revision evidence. Inventory exports
  include unit-aware quantities and actual LOT values instead of multiplying
  zero weight fields. Tiny M balances survive export without a float round trip.
- Report run/export errors now use the existing database-message sanitizer.
  A real missing-table test proves SQL/schema/connection details are not returned.
- QA Backfill now detects inventory GL scopes with no matching LOT scope. Tests
  cover depleted/multiple LOTs, company separation, explicit historical
  exceptions, stale/altered plans, another real DB client, reconnect idempotency
  and audit-failure rollback. Nothing infers unresolved historical money.
- Test teardown disconnects retained connections. This fixed the combined-run
  OTHER_DATABASE_CLIENTS_PRESENT failure without weakening the offline gate.

### Actual new executions

Every MariaDB test ran on **10.4.32**, loopback **3339**, exact disposable datadir
`C:\Users\mptec\AppData\Local\Temp\sulb-shipment-05a812463b9f406c809619a1bb187601`.
The existing guarded runner verified @@datadir, created a random invocation-owned
schema, and removed only that schema. Installed XAMPP binaries were read to run
this isolated server; the original XAMPP configuration/datadir were not changed.

| Execution | Actual result | Boundary |
| --- | --- | --- |
| OperationalSurface + OperationalRead + HistoricalLotBackfill + OperationalPdf | **23 tests / 231 assertions PASS** | Combined run before the subsequent migration-export fix below; overlapping filtered checks are not extra distinct cases |
| OperationalRead after the final export fix | **5 tests / 80 assertions PASS** | Final saved report/export implementation; frozen factors, mixed HOUR/KG/PCS, tiny M, transfer summary, company scope |
| OperationalCycle | **9 tests / 225 assertions PASS** | Rerun because LOT entry changed; actual FIFO/returns/GL, real 1205/1213 rollback; CURRENT_INNODB_MONITOR waiter evidence recorded |
| ApiExceptionMessage + ChromiumPdfRendererDiagnostic + ItemUnitsSafety + TenantIsolation | **30 tests / 209 assertions PASS** | Memory-only isolated runner, no business DB |
| All frontend test files | **33 PASS / 0 FAIL** | Includes saved account/finance surfaces and UOM helpers; component tests are not browser claims |
| Production frontend build | **PASS; 61 pages generated** | Local build and TypeScript; no deployment |
| PHP syntax | **143 files PASS**, followed by lint of subsequent touched services/scripts | No syntax failure on the saved changes |
| git diff --check | **PASS** | Line-ending notices only |

Initial socket denial and Turbopack/PostCSS child-process failure under the
sandbox occurred before successful approved local executions. The PDF fixture
initially lacked the existing print-branding migrations; those migrations were
applied only to the disposable fixture before the real PDF test passed. A strict
SQL grouping failure was fixed with a projected subquery and tested under the
same strict mode. Those failed attempts are not counted as PASS.

### Actual browser/mobile and saved PDF evidence

- A fresh synthetic browser schema was used, not the previously accepted mobile
  sale fixture. At **390 x 844**, Dashboard showed 12 PCS. The browser created
  and posted a **0.25 CARTON purchase return**, displayed POSTED / value30 /
  inventory cost30, and Dashboard then showed **9 PCS**. A guarded read-only
  verification found LOT quantity9, LOT value90 and inventory GL90 exactly.
- Logout and login as a distinct company2 actor showed zero company2 stock and
  no company1 purchases. Opening company1's return print URL displayed **404**
  without the document. Separate HTTP acceptance tests denied foreign-branch
  source reads, draft updates, post and void, plus foreign-company access, and
  compared unchanged draft/LOT/stock/GL/sequence snapshots.
- Clicked the actual Reports **PDF** button and observed a completed download,
  not a print preview: `C:\Users\mptec\Downloads\SULB-inventory-2026-10-25.pdf`.
  A byte-identical evidence copy is
  `backend/storage/framework/testing/resume-uom-browser-download.pdf`.
  Poppler and pypdf confirm **one landscape A4 page, PDF1.4, 63009 bytes**;
  extracted text contains PCS and90.000. Rendered PNG inspected: Arabic text,
  table/unit/value are legible and unclipped. SHA256:
  `c6f36b1cf836bbd267745e29765381dd9c3acc49cac688c4cc517e5f817b8675`.
- The actual backend controller/Chromium test additionally verified PDF content
  type, attachment disposition, %PDF header, saved bytes and matching SHA256.
  Frontend return print displays0.25 CARTON correctly; **native Save-as-PDF for
  the invoice/return print dialog is not claimed as executed**.
- Evidence under `backend/storage/framework/testing/`: `resume-dashboard-mobile.png`,
  `resume-dashboard-after-return-mobile.png`, `resume-return-mobile.png`,
  `resume-company-isolation.png`, `resume-browser-db-proof.json`,
  `resume-uom-browser-download.png`, `resume-frontend-tests.log`, and
  `resume-source-manifest.json` (133 source/config/schema files and SHA256s;
  **not a deployment package**, no environment files or secrets).

### Remaining UOM paths and release gates

| Path | Current truthful state |
| --- | --- |
| BASE purchase/sale/return/reversal/FIFO/GL and interbranch transfer | Tested in isolated QA; prior accepted Support ownership preserved |
| Dashboard, inventory readers, report/transfer summaries, saved-unit print detail, exports | Tested as described above; legacy weight fields retained for compatibility |
| Shipment-linked BASE invoices and weighing/shipment item writers | **Unsupported / guarded**; shipment adapter still required; no guessed weight conversion |
| BASE opening/adjustment/non-weight processing/capitalization | **Unsupported / guarded**; writer/schema/accounting adapters still required |
| BASE quotation/purchase-order entry/conversion and migration imports | **Unsupported / guarded**, not certified for BASE round-trip; exports fixed and tested |
| LOT value/sequence backfill | QA rehearsal only. Explicit unresolved cases include opening/capitalized sources, FIFO mismatch, replay mismatch, GL mismatch, stale plans and other DB clients |
| Representative actual historical upgrade | **BLOCKED**: user confirmed no approved anonymized historical copy exists; synthetic rehearsal does not certify real history |
| Production-safe UOM schema/backfill executor and upgrade preflight | **OPEN**: saved operational migration is deliberately isolated/QA guarded, outside automatic migrations; must not be deployed as a production migration |
| Full review/certification of unrelated accumulated changes | **OPEN**: focused financial/UOM review and broader tests above do not certify every unrelated change in the 77-file accumulated tree |
| Physical mobile devices; all browser permission-role combinations | **NOT_TESTED**; responsive browser and scoped HTTP evidence are narrower |

The user's 2026-10-25 authorization permits continued synthetic QA and explicitly
does not authorize production access/deployment. No VPS access, production
migration, L3 production activation or real-history valuation occurred.

**FOCUSED_QA: PASS. FULL_UOM: IN_PROGRESS. RELEASE_DECISION: NO-GO.**
The work is preserved and reproducible; it is not yet a comprehensive release
certified as ready for a single VPS update.

Continuation teardown: local API/frontend processes and the temporary browser
tab were closed. The exact QA datadir was reverified, no other database clients
remained, and normal SQL SHUTDOWN succeeded on3339. The QA directory and synthetic
browser schema `sulb_ship_test_f2596a648dc801d5` were preserved for resumption;
no directory or saved business/source evidence was deleted.

## Final-blocker continuation — 2026-10-25 (additional execution)

This section supersedes overlapping OPEN path rows above. Existing accepted
31/31 (284 assertions), earlier mobile/PDF/report evidence remain preserved;
unaffected scenarios were not repeated. This is still one comprehensive release,
not authorization for a partial deployment.

### Implemented and exercised adapters

- Commercial quotation/purchase-order BASE entry and conversion, and canonical
  migration import: frozen entered quantity, mapping, base unit, factor and price;
  conversion consumes server-loaded approved snapshots after mapping revisions.
- BASE opening and idempotent IN/OUT inventory adjustments: exact entered/base
  quantities, FIFO allocations, LOT value, item inventory GL, explicit reversal
  sources. Support transaction owners cover conversion/opening/adjustment/void;
  success-audit failure and real 1205 after writes roll everything back.
- Inventory adjustment now requires `inventory.process.post`, including void;
  a viewer cannot mutate stock. New adjustment IDs have company/branch resource
  scope. Controller database errors are sanitized.
- Opening browser save/reload/reopen retained **0.250000 CARTON / 30.001** in
  OPEN-2026-00001. Label and preview now use CARTON, not KG. Commercial browser
  saved PO-B1-2026-000001 with **0.25 CARTON / 120 / total30**. These were synthetic
  drafts; neither browser action posted stock. Screenshots:
  `backend/storage/framework/testing/final-base-opening-browser.png` and
  `final-base-order-browser.png`.
- Old-schema regression found and fixed: inventory summary/list selected missing
  `items.base_unit_code`. Only schemas without that column use the existing KG
  contract; BASE-enabled schemas retain their saved unit. Regression assertions
  cover both readers.
- Additional precision controls reject opening money that the existing float GL
  writer cannot preserve; adjustment posting checks persisted debit/credit totals
  against exact LOT cost before commit. No global financial-engine rewrite.
  Adjustments reject identical inventory/offset accounts. Reversal stock rows now
  link the reversal journal. Adjustment-schema restart checks columns, decimal
  precision and company/request unique scope instead of accepting any same-name
  table. Large-money rejection and retry are tested; arbitrary high-value financial
  precision across every legacy service is NOT certified.

### New execution evidence (overlapping suites are not added together)

| Execution | Result |
| --- | --- |
| HistoricalLotBackfill + UomFoundation + ItemUnitHttp + OperationalCycle, isolated MariaDB | **30/30,427 assertions PASS** |
| Old-schema opening reconciliation, SQLite memory, after reader fix | **7/7,90 assertions PASS** |
| Remaining unsupported processing/commercial-without-extension guard | **1/1,11 assertions PASS** |
| Opening + adjustments + mixed LOT upgrade, before final same-account guard | **22/22,179 assertions PASS** |
| Same inventory/offset account rejection, complete rollback | **1/1,3 assertions PASS** |
| Frontend commercial/opening/finance selection scope | **12/12 PASS** |
| TypeScript and optimized frontend build | **PASS;61 pages generated** |
| Syntax inventory | **255 changed/untracked PHP files PASS**, not semantic certification |
| Git whitespace check | **PASS** |

### LOT upgrade / dry run

`backend/scripts/qa_lot_upgrade_dry_run.php` is a guarded QA-only CLI with no apply
option. It opens a READ ONLY transaction, inventories missing/incompatible schema,
validates replay/source/GL/sequence evidence, returns exceptions and never invokes
an installer or backfill apply. Unknown/custom namespace history produces an
exception and null proposed next number, rather than guessing.

Actual persistent synthetic QA run first reported missing value/audit tables.
They were installed separately inside the verified disposable QA server using
`qa_uom_browser_seed.php --prepare-lot-upgrade`; no backfill was applied there.
The subsequent dry run reported:

- `writes_performed=false`, no schema/source/GL/sequence exceptions;
- preserved BASE LOT1 quantity9/value90 and inventory GL90;
- current/proposed LOT next number2;
- identical source hash before/after schema-only preparation:
  `6c700c7e39ce0499c98c3cab0107453aca7bcc50fca8d08841c49fad9322af91`;
- QA ready=true, **production_qualified=false**.

Evidence: `final-lot-dry-run.json` and `final-lot-dry-run-after-schema.json` under
`backend/storage/framework/testing/`. Mixed-history tests apply only proven legacy
rows, preserve existing BASE purchase/opening/transfer/adjustment values, reject
value drift, verify rerun/audit/rollback and match branch GL. Synthetic fixtures
are not approved actual historical evidence.

### Required release statuses

| Key | Truthful status |
| --- | --- |
| BASE_ADAPTERS_COMPLETED | **PARTIAL**: commercial/import/opening/adjustment now exercised; core purchase/sale/return/reversal/transfer accepted. Nonweight processing, shipment-linked documents/weighing adapters and capitalization remain guarded/uncompleted. |
| LOT_UPGRADE_READY | **QA_ONLY**: schema/restart/sequence/value rehearsals exercised; automatic production executor and actual-history qualification remain open. |
| BACKFILL_DRY_RUN | **PASS_IN_QA**: actual read-only CLI evidence above, including explicit missing-schema result. |
| HISTORICAL_DATA_VALIDATION | **BLOCKED**: user confirmed no representative approved anonymized actual history exists. No real records were assigned guessed values. |
| SECURITY_REVIEW | **FOCUSED_PASS / FULL_REVIEW_OPEN**: new scoped endpoints, exact Support capability, viewer denial, SQL sanitization and audit atomicity exercised. Syntax/targeted review do not certify every accumulated unrelated file. |
| FINAL_REGRESSION | **AFFECTED_SCOPE_PASS**, subject to final adjustment rerun recorded below; no claim that all comprehensive-release paths are finished. |
| PRODUCTION_COMPATIBILITY | **NOT_QUALIFIED**: no VPS schema/version/PHP extensions/Chromium/runtime/flag/client inventory or actual historical preflight obtained. BCMath is an application dependency; VPS availability unknown. Old-schema reader regression fixed locally. |
| REMAINING_BLOCKERS | Nonweight processing/shipment/capitalization adapters and policy; representative real history; production executor/preflight; complete accumulated-tree security/semantic review; comprehensive affected regression after those adapters. |
| RELEASE_DECISION | **NO-GO** |

Policy dependency is concrete: the saved UOM contract keeps physical weighing in
KG, requires an explicit admission policy for nonweight shipments and separately
defined recipes for cross-dimension processing. A clarification was requested;
PCS-to-KG conversion, recipe yields and allocation weights were not invented.

Git inspection found a larger accumulated tree than the latest32-file subset
(82 tracked changed files at inspection plus untracked work). No reset, clean,
revert, commit, push or wholesale replacement occurred. SHA256 source inventory
`final-source-manifest.json` contains288 scoped source/test/script files; it is
**evidence only, not a deployable package**, excludes environment files and does
not establish complete semantic review. Existing unrelated/untracked files remain.
No VPS/original XAMPP data, production migration, deployment or production L3
activation was accessed/performed.

Final affected adjustment rerun on the latest source: **11/11 PASS,97 assertions**
(includes same-account rejection, exact persisted GL check, reversal links, schema
restart validation, real duplicate-post/void races and post-FIFO1205 rollback).
Latest opening/adjustment/schema/QA-script PHP syntax and Git whitespace checks
also passed. Owned API/frontend processes and temporary browser tab were closed.
Exact QA @@datadir and absence of other DB clients were verified; normal SQL
SHUTDOWN succeeded. All source files, QA datadir and synthetic browser drafts
were preserved. RELEASE_DECISION remains **NO-GO**.

## Approved independent-dimension policy — execution checkpoint 2026-10-26

The user explicitly approved independent inventory/physical quantities, mixed
shipments, value/authorized-percent cost allocation, and explicit approved recipes.
The earlier policy-clarification blocker is **resolved**; implementation is ongoing.
No production/original XAMPP/VPS access, migration, deployment or push occurred.

New implemented QA paths:
- Shipment snapshot extension007: entered/base quantities, frozen unit/factor/price,
  optional actual weight. Mixed draft and frozen shipment-to-invoice draft tested.
- Independent BASE/mixed shipment admission: no scale/card is invented for counted
  goods; legacy weight lines require documented closed scale evidence. Real card
  weight updates only the independent actual-weight field of a BASE line.
  Manual percentages require `shipments.cost`, total100 and exact stored precision.
- Recipe/command extensions008/009: explicit input/output snapshots, immutable
  approved revisions and hashed posted FIFO evidence. Existing inventory-operation
  create/post API now dispatches recipe commands; a scoped void route restores
  original LOT quantities/costs and refuses consumed outputs. Unsupported additional
  operation overhead remains rejected explicitly; it is not silently ignored.
- Recipe HTTP endpoints and frontend `/inventory-operations/recipes` provide draft,
  approval and processing entry. Recipe approval remains with authorized company
  users; Support financial post/void uses the trusted owner and exact capabilities.
- Processing read-only historical plan now recognizes proven, hashed recipe output
  sources and reports missing sources; it never estimates values.

Executed evidence, overlapping runs are not summed:
- ShipmentBaseDraftTest: **7/7,46 assertions PASS** before the latest additional
  database completeness constraint (that constraint still needs its affected rerun).
- ProcessingRecipeTest: **2/2,13 assertions PASS**.
- BaseProcessingTest after immutable result hash: **6/6,49 assertions PASS**;
  separate new historical-source test **1/1,6 assertions PASS** and explicit
  no-output SCRAP test **1/1,6 assertions PASS**. Later combined rerun pending.
- Actual concurrent processing post and void: two independent connections observed
  waiting on the same source; each operation applies once and restores12PCS/value120.
- Support READ_ONLY/foreign-company denial and success-audit failure rollback pass.
- Legacy shipment save / L3 single-item controls affected by integration: **2/2,
  15 assertions PASS**. New processing HTTP scope after feature mapping: **2/2,
  19 assertions PASS**. These are focused checks, not complete L3 certification.
- TypeScript passed on the created recipe page; frontend build/browser regression
  after subsequent small UI changes remains pending.

Actual browser QA created and approved a clearly labeled synthetic recipe only,
then saved and posted processing from0.25CARTON (3PCS) to explicitly declared1.5KG.
Read-only DB verification confirms6PCS/value60 plus1.5KG/value30, **LOT=GL=90**.
The operation's actual saved date was2026-10-25; no2026-10-26 date claim is made.
The subsequent actual Dry Run preserves both LOTs, reports no exception, performs
no writes and proposes LOT next3. Source hash:
`5cdc7c372c600c7ab6e025d8a8e9f8e00819fb3675e39eb8df0adf29ee37fc1e`.
Evidence under `backend/storage/framework/testing/`:
`policy-processing-browser.png`, `policy-processing-browser-db.json`,
`policy-processing-dry-run.json`.

Still **IN_PROGRESS / NO-GO**: shipment-linked BASE stock posting/capitalization
remain guarded until complete L3/FIFO/return/reversal integration and concurrency
proof; additional processing overhead and remaining UI/security/regression review
are not certified. Representative actual history remains BLOCKED as previously
confirmed; production compatibility/executor/preflight are not qualified.
This checkpoint records real work for interruption recovery, not a completed release.

## Interrupted mixed-recipe closure — 2026-10-26

This section supersedes the earlier pending claims for shipment-linked BASE
capitalization and mixed recipe implementation only. Prior accepted results are
retained within their original scope; overlapping runs below are not added together.

### Interruption and actual source state

The interrupted patch did **not** execute. `LegacyRecipeInventory.php` was absent;
`ProcessingRecipeService` still accepted BASE items only; `OperationalTransactionOwner`
still required a BASE operation line for recipe post/void. All three belonged to
one atomic tool call whose automatic approval review could not complete because of
the usage limit. There was no per-file security rejection or unsafe-action verdict.
The required changes were reviewed and applied through normal approval, without
force, bypass, reset, clean, removal or replacement of saved work.

Implemented and verified:
- Explicit legacy KG/TON snapshots coexist with frozen BASE snapshots in approved
  recipes. Standard TON-to-KG conversion must be exact at the existing three-place
  legacy quantity precision; counted-to-mass conversion is never inferred.
- The narrow legacy recipe adapter consumes FIFO with the qualified
  `FINAL_RESIDUAL_V1` value ledger, writes exact movement costs, preserves legacy
  quantity mode, and refuses unqualified historical value. Legacy receipt float
  boundaries are checked before writing and against persisted quantities/value.
- Recipe post/void uses the durable command owner even when all recipe lines are
  legacy. Ordinary non-recipe legacy operations are not granted that owner.
  Existing Support recipe create/post/void ownership and audit rollback remain.
- Mixed input/output stock and original journals reverse atomically. Recipe and
  command snapshots, result hashes and idempotency remain authoritative.
- Backfill recognizes legacy recipe sources only with approved immutable recipe,
  hashed command/result, exact allocations/movements and posted original/reversal
  journal evidence. Explicit hashed zero-cost FIFO events may have no monetary
  journal; missing historical money is never interpreted as zero.
- A proven alternative SCRAP defect was fixed: a BASE output supplied as entered
  quantity is rejected before saving instead of being filtered out silently.
- Old-schema inventory export and inventory/LOT/movement reports now work when
  `items.base_unit_code` is absent, preserving the established KG contract.

### Newly executed evidence

- Affected processing/recipe regression: **17/17 PASS,144 assertions**, including
  mixed post/void, Support create and success-audit failure rollback, real two-worker
  InnoDB waits, item/branch/company denial, immutable recipe evidence and dry run.
- Additional alternative SCRAP rejection: **1/1 PASS,2 assertions**.
- Final-residual/zero-cost and mixed historical-source checks after the last evidence
  change: **2/2 PASS,20 assertions**. Three batches preserve `[0.000,0.000,0.001]`,
  exact reversal and source-backed Backfill. This overlaps one earlier recipe test.
- Affected existing historical Backfill/Dry Run/mixed LOT regression:
  **14/14 PASS,105 assertions**, covering restart, audit, rollback and exceptions.
- New invoice-header FX rounding/late-GL rollback/idempotency:
  **1/1 PASS,8 assertions**. `0.001 × 1.5` produces one saved `0.002` allocation;
  **LOT=GL=150.002**. Earlier accepted deterministic three-recipient allocation
  and unresolved economic tie rejection are retained without rerunning them.
- Linked BASE outbound shipment: purchase FIFO references and original LOT values
  restored on invoice reversal, **1/1 PASS,7 assertions**. Mixed paid L3 purchase
  real concurrency: **1/1 PASS,8 assertions**, observed two workers at the existing
  first company lock, exactly two LOTs, one payment, one receipt and one event.
  The first race probe used a later shipment lock and did not establish two waits;
  it was not counted PASS. The probe was aligned with the existing lock protocol.
  Mixed paid L3 HTTP Support: **1/1 PASS,12 assertions**, foreign/read-only denial,
  success-audit failure rollback, subsequent successful post and LOT=GL240.001.
- Export/alternate admission focused controls: **3/3 PASS,62 assertions**.
  After the further old-schema report fix, old-schema export/report plus tiny
  mixed-unit regression: **2/2 PASS,39 assertions**; these runs overlap.
- New frontend recipe item/unit contract: **1/1 PASS**; TypeScript passed.
  Complete Next build passed: **62/62 pages generated**, including recipes.
  The first sandboxed build failed spawning the CSS worker; the unchanged build
  succeeded with normal child-process approval. This was a local QA build with
  the QA API URL, not a production deployment artifact.
- Source inventory: **318 scoped code/schema/test files**, with **265 PHP files
  passing syntax checks**. Subsequent edited report/export/test files were checked
  again. `git diff --check` passed; no conflict markers were found in the scanned
  application/UOM sources. Syntax and source inventory do not certify the entire
  accumulated release semantically.

### Actual desktop/mobile and dry-run evidence

The preserved synthetic browser schema was reused. A synthetic existing KG item
received5KG/value50 without changing prior BASE stock. Browser UI created and
approved `QA-MIXED-UI-20261026` revision1 with explicit inputs1KG +3PCS and
outputs2PCS +0.5KG, cost percentages50/50. Desktop operation2 posted successfully.
Mobile viewport390×844 rejected99% before creating a command, then operation3
posted after correction to100%. The viewport was reset afterwards.

Read-only saved-data verification after mobile: remaining4PCS,1.5BASE KG and
4legacy KG, held separately; **LOT=GL=140.000**. Both mixed commands are POSTED
with saved result hashes. The initial browser attempt correctly refused posting
on an older QA API process without the value-ledger flag; the same draft succeeded
after known QA API processes were stopped and one explicitly configured local QA
server was started. No stock was committed by the refused attempt.

Actual CLI Dry Run after both browser posts:
`writes_performed=false`, `ready=true`, `production_qualified=false`, no schema or
history exceptions, preserved4BASE lots and proposed3legacy balances, LOT next8.
Source hash: `fb8f14f604a9188be4c340d62d89029138eadebfec213f28c3610d464d0e224a`.
No proposed balances or sequence were applied to that browser fixture.

Evidence in `backend/storage/framework/testing/`:
`resume-mixed-recipe-browser.png`, `resume-mixed-recipe-mobile.png`,
`resume-mixed-browser-db.json`, `resume-mixed-mobile-db.json`,
`resume-mixed-dry-run.json`, `resume-source-manifest.json`, `resume-php-syntax.json`.
`resume-mixed-mobile-result.png` is the compact visible mobile success evidence.
These are local QA evidence, not separate release reports or a deployment package.

### Current release status

| Requested status | Verified result |
|---|---|
| INTERRUPTED_CHANGES_RESOLVED | YES — actual absence/old state verified; narrow reviewed changes applied and tested. |
| MIXED_RECIPE_SUPPORT | QA PASS for explicit mixed recipes, FIFO/value ledger, post/void, concurrency, rollback, tenant isolation and desktop/mobile. Additional recipe overhead remains explicitly rejected pending its adapter. |
| SHIPMENT_COST_ROUNDING | QA PASS within executed scope: retained shared L3 receipt/capitalization proof; new exact FX header-cost case passes. Whole shipment concurrency matrix is not newly certified. |
| REGRESSION_RESULTS | The executed counts above pass. Prior accepted25/180, Support1/10, mixed shipment240.001 and actual mixed PDF remain accepted within scope. |
| SECURITY_STATUS | Scoped owner/permission/Support/branch/company checks PASS. Complete semantic security review of all accumulated accounting/payroll/driver/L3 and untracked sources remains open. |
| LOT_BACKFILL_STATUS | Synthetic qualified history/Dry Run PASS, source exceptions fail closed; actual representative approved history remains BLOCKED. |
| REMAINING_BLOCKERS | See below; none is represented as a passing test. |
| RELEASE_DECISION | **NO-GO** — comprehensive release is not yet certified for production. |

Remaining blockers:
1. Approved representative actual historical data is unavailable, as confirmed by
   the user. Synthetic evidence cannot qualify the production LOT upgrade.
   Unsupported historical capitalization/return/cancellation sources remain
   explicit review exceptions; no guessed cost, weight or conversion is supplied.
2. VPS MariaDB/runtime/schema/BCMath/Chromium compatibility, exact migration order,
   preflight/executor and flag activation have not been independently verified.
   No production connection, migration, deployment, L3 activation or Git push ran.
3. Additional processing overhead on the recipe path still needs a typed,
   independently tested accounting/payment/rounding/reversal adapter; current
   rejection prevents silent loss or double capitalization.
4. Complete accumulated semantic/security review and the full mixed-shipment L3
   permutations are not certified by these focused results. Linked outbound
   reversal, one real mixed paid purchase race and mixed paid Support audit
   rollback now pass as recorded above. The318-file inventory is not a blanket
   approval of all accumulated accounting/payroll/driver and untracked sources.

All work remains preserved in the current checkout, including untracked files.
Environment backups, stray pre-existing files and temporary database/media
directories must not be included by blindly packaging all untracked files.
At this checkpoint Git reports83modified tracked paths and222untracked entries
(directory-collapsed status, not a source-file count). Owned browser/API/frontend
QA sessions were closed; the exact temporary MariaDB server was shut down normally
only after confirming no other clients. All QA files/schema and saved edits remain.

## Additional processing costs and final security closure — 2026-10-26

This section supersedes the preceding checkpoint's pending recipe-overhead item.
Earlier accepted results remain accepted within their scope; counts below are
separate executed runs and must not be summed as distinct tests.

### Implemented and exercised

Recipe costs now require a documented source reference, cost type, exact decimal
amount, currency and explicit payment status. Foreign currency requires an explicit
positive exchange rate; base currency requires rate one. Unpaid costs use the
configured active accrued-liability account; paid costs require an active, scoped
treasury account with the matching currency and asset ledger account. Posting
revalidates captured evidence and account scope. No inferred weight or conversion
between PCS and KG participates in allocation.

The approved recipe's captured output percentages allocate input FIFO cost plus
overhead using deterministic largest remainders and business-code ordering. One
atomic posting writes output LOT values, balanced GL, cost posting metadata and
an immutable company/source claim. Account locks precede inventory locks; a
current locking read prevents repeatable-read snapshots from missing a concurrent
source claim. A second order cannot capitalize the same source. Void reverses
the original combined GL and output value and restores original input FIFO value.
Historical commands are not silently recalculated. Legacy/no-cost commands retain
their original hash shape. Output inventory credit-account conflicts fail closed.

New QA-only migrations012/013/014 cover immutable cost-source claims and explicit
write-permission registration. Restart checks reject missing uniqueness; no roles
receive the new permissions automatically. These guarded QA migrations are not
an approved production executor.

Automatic review rejected an initial broad overhead-guard/source-validation edit.
The rejected patch was not assumed applied or forced. The narrower accepted
implementation requires source evidence at capture and posting and retains a
guard against cost rows without a frozen command snapshot. No action remains
blocked by that review.

### New executed evidence

| Scope | Actual result |
|---|---|
| Affected processing integration suite after initial adapter |22/22 PASS,190 assertions|
| Cost-specific normal/failed, Support, rollback and history runs |7/7 PASS,69 assertions; later paid/FX/history recheck3/3 PASS,31 assertions|
| Distinct orders competing for one cost source; migration restart/immutable source/missing unique index |2/2 PASS,12 assertions; two real InnoDB waiters; one source capitalized; LOT=GL180.001|
| Permission routing and baseline registration, including named worker routes |26/26 PASS,91 assertions;114 registered permissions, existing231 role grants retained|
| Earlier focused manual security runs |3/3 PASS,10 assertions; HTTP authorization/IDOR1/1 PASS,10 assertions; SQL-failure rollback/sanitization1/1 PASS,5 assertions|
| Final combined manual security regression |5/5 PASS,25 assertions; actual InnoDB lock-wait evidence and invocation-only QA database removal|
| Frontend manual-journal permission checks |2/2 PASS|
| TypeScript and isolated QA Next build |PASS;62/62 pages; separate QA output, not a production artifact|
| Actual report/PDF integration |1/1 PASS,12 assertions; Chromium-produced PDF saved and rendered; Arabic A4 landscape inspected|

The paid/FX boundary includes0.001×1.5 rounding to0.002 and rejection of excess
precision, overflow and floating-point rates. Initial failing probes are not PASS:
one exposed a stale-read source race, subsequently fixed and verified above;
another reproduced two committed manual reversal IDs for one original journal.

The manual journal fix locks the scoped original MANUAL entry and returns its
verified existing reversal on retry. Operational journals remain under their
inventory/financial owner. Database errors are sanitized. View permission no longer
authorizes manual journal writes, fixed-asset writes, worker writes or payroll
writes: explicit permissions are `journals.post`, `journals.reverse`,
`fixed_assets.manage`, `workers.manage`, `payroll.manage`. Existing manager and
durable scoped Support checks remain. Before production, an authorized company
administrator must review intended delegated roles and assign permissions
explicitly; the release must not silently grant financial authority.

Actual desktop and390×844 mobile browser recipe posts saved documented costs
10.001 and0.001 on the preserved synthetic QA fixture. Final LOT=GL150.002.
The latest mobile processing journal is45.002, including its actual FIFO residual.
A read-only journal actor could view entries but had zero create buttons. Evidence
includes `extra-cost-browser-db.json`, `extra-cost-mobile-result.png`,
`extra-cost-journal-viewer.png`, `extra-cost-inventory.pdf` and its rendered
`extra-cost-inventory-page.png` under `backend/storage/framework/testing/`.

### LOT, production evidence and staging

The actual post-browser CLI Dry Run reported `writes_performed=false`,
`ready=true`, `production_qualified=false`, no exceptions and next LOT12.
Source SHA256: `41e186190fa84edc2d69714313f2e360ae9e43322bcf181de9f2c8860646c0c3`.
Backfill verifies captured processing overhead, immutable claims, original journal
linkage and total output value. Synthetic historical successes and failures do
not substitute for approved representative history, which is still unavailable.

Prepared and locally executed metadata collectors:

* `backend/scripts/release_runtime_baseline.php --metadata-only`: standalone PHP
  runtime/extensions/PDO drivers, without Laravel bootstrap, environment dump or
  business records. Local PHP8.4.22 includes BCMath and pdo_mysql.
* `backend/scripts/release_schema_baseline_readonly.sql`: read-only transaction,
  server settings and information_schema tables/columns/indexes/foreign keys plus
  trigger-body hashes; no business rows, credentials or trigger bodies. QA probe
  returned six result sets and completed without writes.

The user will run these collectors and send metadata only. No VPS access has been
performed. Do not send passwords or `.env`. Compare actual VPS PHP/extensions,
MariaDB version/sql mode/collations, table/index/trigger fingerprints and Chromium
availability with the candidate requirements before qualifying an executor or
flag plan. Any additional VPS inspection requires a separately specified read-only
scope. No production migration, deployment, L3 activation or push is authorized.

Representative history must be selected and approved by the owner, anonymized by
an authorized operator while preserving relational links, UOM snapshots, exact
quantities, dates and financial reconciliation, then restored only to isolated
QA. Run exception-only Dry Run first; missing source evidence remains an explicit
exception. Do not fabricate historical monetary values. Verify restart, audit,
rollback and LOT/GL reconciliation against that approved fixture before sign-off.

Production-equivalent staging remains BLOCKED pending actual VPS metadata and
approved historical evidence. Current local MariaDB10.4.32/PHP8.4.22 browser and
PDF evidence is QA evidence only. Staging must reproduce the verified runtime,
schema and Chromium, use synthetic/approved anonymized records, rehearse the
reviewed ordered upgrade with rollback and finish browser/mobile/report/PDF UAT.

### Current requested release status

| Key | Status |
|---|---|
| PROCESSING_EXTRA_COSTS |IMPLEMENTED; isolated QA PASS for documented mixed KG/BASE costs, exact allocation, GL/LOT, reverse, rollback, concurrency and Support|
| SECURITY_AUDIT |Proven manual-reversal race and view-to-write permission defects fixed; affected tests PASS; complete accumulated semantic audit remains open|
| ACCUMULATED_DIFF_REVIEW |Tracked and untracked source inventory, syntax checks and targeted semantic review performed; inventory is not blanket security approval; all existing work preserved|
| LOT_HISTORICAL_VALIDATION |Synthetic QA and non-writing Dry Run PASS; representative approved historical validation BLOCKED|
| VPS_BASELINE |Collectors ready and locally verified; awaiting metadata the user will supply; actual compatibility UNKNOWN|
| STAGING_STATUS |Local QA exercised; production-equivalent staging not yet qualified|
| CRITICAL_BLOCKERS |Approved history; actual VPS baseline and ordered production executor/rollback validation; production-equivalent staging/UAT; remaining accumulated semantic/security and mixed-shipment L3 permutations review|
| RELEASE_DECISION |**NO-GO** pending those gates; one comprehensive candidate is retained, no partial production release|

Environment backups, stray files, QA databases and local evidence must be excluded
from any future release package. No reset, clean, revert, production/XAMPP database
write, production migration, deployment or Git push was performed.

Final source inventory:334 scoped changed/new source files, including279 PHP
files; all279 passed syntax validation, no failures. Refreshed SHA256 manifest:
`extra-cost-source-manifest.json`; validation: `extra-cost-php-syntax.json`.
`git diff --check` passed (line-ending notices only). Owned API/frontend servers
were stopped; the exact temporary MariaDB datadir was checked, no other clients
were present, and normal shutdown returned `DATA_PRESERVED`. All QA schema, data,
evidence and uncommitted changes remain available for continuation.

## Comprehensive GitHub release candidate — 2026-10-27

Candidate branch: `codex/sulb-complete-rc-20261027`. This is a comprehensive source
candidate for inspection and staging, **NOT APPROVED FOR DEPLOYMENT**. It starts
from the existing ERP source base `db2b9d63ccda67aadaa0f49e087cc9e0137b42e7`, retaining
the32 existing commits ahead of main plus the accumulated Backend/Frontend changes.
It does not replace the working checkout or merge into main. The separate baseline
tools branch remains separate. No VPS connection, production root login, deployment,
production migration or service change was performed.

### Newly executed checks only

* Full QA schema order001–014, with stock source index, cost-parent FK, allocation
  receipts and LOT value tables before010: **1/1 PASS,16 assertions**. Existing item
  field values and journals remain unchanged; new item columns retain LEGACY_KG,
  no base unit is inferred, and no historical value balance is initialized. All
  five explicit write permissions register without new role grants. An initial
  test compared SELECT* across an additive ALTER and failed because of intended
  new columns; comparison now verifies all original fields and the new defaults.
* Actual logical backup using single-transaction/routines/triggers/events/hex-blob
  and restore through the MariaDB client into a separately created QA database:
  **1/1 PASS,135 assertions** on the final portability-adjusted test. Table counts
  and all53 trigger-body hashes match the snapshot. A new
  source transaction added after backup remains in the source:180.002 versus the
  separately restored180.001. No restore was performed over the source database.
  Final QA dump SHA256: `81f80812efba67ed7282f6d1b7435e68de8495026a1f1c67d679ac4415a51370`.
  This demonstrates the QA procedure, not a production backup qualification.
* New mixed paid KG/BASE L3 purchase-void race: **1/1 PASS,14 assertions**, two real
  InnoDB waiters; one reversal wins, the second is rejected by the existing state
  contract. Inventory and treasury journals reverse once; LOT=GL0.000; one clean
  replacement cost draft and immutable original receipts remain.
* That new race initially expected every historical source to qualify. It exposed
  the existing explicit legacy-capitalization exception. The final assertion
  requires `SOURCE_OR_CAPITALIZATION_REVIEW_REQUIRED` for the legacy capitalized
  LOT and `ready=false`; **this is not a historical-validation PASS**. BASE evidence
  cannot be substituted for a missing legacy proof. This remains a release blocker.
* New preflight CHECK-expression hashes, FK delete/update rules and generated-column
  expression hashes: **3/3 SELECT queries PASS**, result counts70/70/0 on preserved
  synthetic QA, inside READ ONLY; no business values queried or writes performed.
* Changed/new candidate inventory:294 PHP files passed syntax checks; no conflict
  markers or private-key/GitHub-token/AWS-key patterns found in selected sources.
  This static check is not certification that every accumulated semantic/security
  path is correct. Broad accepted ERP, browser, mobile and PDF tests were not rerun.
* Approval gate and deployment `--execute`/`--plan` stop with exit78 before production
  paths, Composer, rsync, migration, build or service operations. Bash/PHP syntax
  checks pass. Actual deployment/restore on Linux VPS remains **NOT EXECUTED**.

### Migration review and ordering

Machine-readable catalog: `ops/sulb-rc/migration-catalog.json`, with source hashes,
ordered UOM001–014, proposed scripts and approval state. The standard Laravel
migration repository must first be compared to the real VPS `migrations` rows.
Never run a generic migrate to assume production is caught up.

001 installs definitions/composite tenant keys, guards and immutable item modes;
002 adds frozen document/cost contracts;003 adds operational quantities and LOT
value fields;004 commercial snapshots;005 openings;006 adjustments;007 shipment
snapshots and independent physical weight;008 approved recipes;009 hashed commands;
010 capitalization after cost FK/receipt/value prerequisites;011 invoice-header
cost receipts;012 unique immutable cost source claims;013–014 explicit write
permissions without automatic role grants. Existing fractional quantities, key
types/signs/collations, duplicate references, orphans, partial installations and
missing historical source/value evidence require preflight exceptions.

The001–003 chain is not generally restartable. Later partial-schema checks do not
make every DDL atomic. MariaDB DDL may implicitly commit; a transaction is not a
schema rollback. Most new files deliberately use the isolated QA guard and some
require MariaDB10.4.32. They remain outside automatic migrations. Their production
executor is **NOT APPROVED**; guards were not removed to force production support.
Proposed historical accounting/worker/identity/sequence SQL is not approved merely
because it is included for reproducible inspection. No feature flags are activated.

### Read-only VPS preflight and same deployment workflow

`ops/sulb-rc/preflight.sh` collects evidence without Laravel bootstrap, `.env`,
application credentials, root account or production mutations. It authenticates
only the dedicated `sulb_metadata_ro`, reuses its structured privilege and role
verification, and reads schema plus `migration,batch` only from the migration
repository. Password input uses `/dev/tty`, not stdin or environment variables.
No account creation or grant is attempted. If the reader is missing/unsupported,
collection stops and no readiness result is certified.

It records CLI/tool versions, safe service state, staging checkout SHA (explicitly
not proof of live files), live source hashes, schema/check/FK/trigger fingerprints
and migration names. Results are in a private temporary folder. Successful evidence
capture still exits78/NO-GO because compatibility approval is absent. Read-only
SELECT privileges may not expose complete trigger metadata; the report explicitly
does not certify trigger visibility, FPM or active UOM/LOT/L3 flags. No secrets are
read from cached config to fill those gaps.

`deploy-reviewed-release.sh` follows the user-confirmed existing workflow:
GitHub -> pinned clean `/home/sulb-release` -> private backup/write fence -> rsync
Backend/Frontend -> Composer -> explicitly approved migrations -> Next build ->
`sulb-frontend.service` restart -> HTTP smoke. In this candidate it is **disabled**
by `release.json` and `check-approval.php`. It has no force option. A separate
reviewed approval commit must supply the real baseline, approved ordered executor,
historical/staging/security evidence and backup qualification before execution.
The draft execution sequence itself is not a tested deployment or authorization.

### Backup and rollback policy

Use a separately provisioned protected600 backup-client configuration and dedicated
backup authority, never root or credentials embedded in Git. Before an approved
deployment, fence every writer (web, queue, scheduled imports and integrations),
capture the actual pinned revisions/configuration privately, take InnoDB-consistent
logical DB backup plus code/upload/config backups, checksum them and rehearse
restore to a different database on production-equivalent staging. A successful dump
alone is not a restoration proof. Storage capacity, ownership, definer behavior,
restore time and the complete write fence must be verified on that environment.

Rollback never runs migrate:rollback or imports the old dump over `sulbprod` after
new transactions. If compatibility with new schema/data has been demonstrated,
restore only approved prior application code/dependencies/build while preserving
the live database, `.env`, uploads and storage. Otherwise keep the write fence,
preserve all post-release journals/LOT evidence and perform a reviewed forward
repair. Disaster recovery restores to a separate instance and requires a reconciled
cutover including new transactions; it is not an automatic deployment error trap.

### Remaining release gates

**NO-GO** remains explicit in the candidate. Still missing: actual VPS/runtime/
schema/migration evidence; complete trigger/FPM/flag evidence; approved representative
history and legacy-capitalized-source qualification; approved production executor;
production-equivalent staging and complete business UAT; complete accumulated
semantic/security review and remaining L3 permutations; production backup/write-
fence/compatible rollback rehearsal. Local source review and focused tests do not
manufacture those missing approvals. The comprehensive candidate may be inspected
from GitHub, but no partial or complete production release is authorized.

## Critical closure continuation — 2026-10-27

This continuation updates the same comprehensive candidate and supersedes the
earlier blanket legacy-capitalization exception only for the narrow proven scope
below. No Git/path baseline or accepted UOM/browser/PDF suite was repeated.

### Proven security defects fixed

1. Actual HTTP Worker failure exposed SQLSTATE, database name, SQL and bound values
   including identity/bank-field positions. Replaced raw exception output across
   the changed Worker/Payroll/InventoryOperation/ShipmentCost/Account/AccountStatement/
   FinancialAccount/Voucher controllers with the shared safe formatter. Legacy
   adapters that discard the PDO cause but keep a SQL diagnostic string are also
   redacted. Ordinary business errors and safe1205/1213 retry codes are preserved.
   Voucher late financial failures are caught only after transaction rollback.
2. Actual branch-actor payroll generation included another branch's worker. New
   drafts now require an active company branch and select workers only from it;
   commission/loan calculations use the run's branch. The existing company/month
   uniqueness is deliberately unchanged: this fix does not introduce a new
   multi-branch/month payroll contract or silently migrate old payroll records.
3. Actual foreign-branch salary-slip request returned200. Added scoped run lookup
   for the nested runId route and controller queries; foreign branch now gets404.
   A historical local run containing foreign-branch worker lines returns an explicit
   review error; no full mixed run or foreign worker slip is disclosed or recomputed.

Final new HTTP security group: **9/9 PASS,76 assertions**, real MariaDB failure
triggers and complete route middleware (no bypass). Covers Worker, Payroll,
ShipmentCost INSERT/UPDATE/DELETE, inventory draft delete/child rollback, Account,
FinancialAccount and late Voucher GL failure/sequence rollback. First reproductions
failed as described; fixture entitlement omissions were corrected before assessing
the payroll/processing/shipment paths. Neither failure nor denial was counted PASS.
Additional new string-only/wrapped-PDO formatter tests: **2/2 PASS,11 assertions**.
Affected tenant checks: **3/3 PASS,18 assertions**. Affected existing Wave6 source
contract check: **1/1 PASS,30 assertions**, explicitly a source-contract check.
Changed18 PHP files passed syntax; raw getMessage output is absent from controllers
listed in the candidate source manifest. This does not certify every production
configuration or every accumulated semantic path; the complete-security gate stays
false pending the remaining review and staging evidence.

### Fourteen migrations plus Backfill: new integration evidence

A new combined QA case installs001–014 and dependencies, posts a synthetic legacy
purchase, takes a non-writing Dry Run, injects an actual balance INSERT failure,
verifies no partial balances/audit rows or financial mutation, applies/restarts the
same approved plan, then posts a FIFO sale with the value ledger enabled. Final
replayed LOT value and inventory GL are both60.000. LOT sequence preparation also
passes. This is a new combined scenario, not a rerun of the accepted schema-only
or backup tests. It does not qualify the actual production migration executor.

New `LegacyCapitalizedLotEvidence` is QA-only and requires original purchase line/
quantity/base value, one frozen L3 recipient per LOT/cost, exact receipt hash and
allocated sum, original inventory GL by account and the original/reversal source
chain. It supports only an untouched LOT or a wholly purchase-reversed LOT with
the exact two movements and matching opposite GL. It never recalculates allocation
percentages or infers weights, unit values or missing money. Partial issue ordering,
missing source/receipt, changed values and other unsupported history stay exceptions.

Final affected capitalization/history run: **3/3 PASS,34 assertions**, including
the previously blocked mixed paid purchase-void race and the unchanged missing-
evidence exception matrix. Untouched mixed inventory qualifies at LOT=GL240.001;
after full reversal both are0.000. Apply restart is idempotent; a changed allocated
amount is refused. This closes that narrowly documented synthetic-history gap,
not representative production-history validation.

### Approval and production action

Manifest remains **NO-GO**, deployment_authorized=false. The existing single
deployment workflow additionally pins a separate `sulb_backup_ro` account and
checks nonempty logical backup plus archive checksums. It still cannot execute
before the existing schema/history/staging/security/backup compatibility gates;
checksums are not represented as a production restore rehearsal. No live database
restore or destructive schema rollback is added.

Production-equivalent Staging cannot yet be certified: actual VPS runtime/schema/
migration metadata and an approved representative anonymized historical fixture
have not been supplied. Earlier user approval covers synthetic QA, not a claim
that it represents real history. Neither absent input is manufactured. FPM runtime,
active flags, complete trigger visibility, partial legacy history, write fencing
and the production executor/restore rehearsal still need explicit evidence.

The one immediate operator action is to run the dedicated-reader read-only
preflight from the staging checkout and return its protected metadata archive.
It does not use MySQL root, application credentials, migration or service changes.
The final deployment command remains withheld until evidence-based GO and separate
human deployment approval. All edits, QA records and the one comprehensive release
candidate are preserved; no production action was performed.

For the backup-account/checksum draft change, automatic review refused invoking
the deployment script with `--execute` even as a negative local test, because the
mode could touch production if gates changed. That invocation did not run and was
not bypassed. The safe alternative passed: Bash syntax plus the standalone
read-only approval checker returning78/NO-GO. No production execution is claimed.

## MySQL/Percona engine discovery — 2026-10-27

The operator reports mysql.service/Percona and client8.4.8-8. This is **not proof
of SELECT VERSION() or @@version_comment on sulbprod**. Production server version,
SQL mode, collations, FK settings, schema, triggers and migration repository still
require the dedicated-reader preflight. Manifest remains **NO-GO**.

### Actual local MySQL8.4 evidence

No Docker/Podman runtime or installed WSL is available locally. Instead Oracle's
official archived mysql-8.4.8-winx64.zip was downloaded to the ignored QA storage
folder; mysqld.exe has a valid Oracle America Authenticode signature. ZIP SHA256:
`53685bb61a1efc5ecc1b35c1b6bd0d30ba799f4943a1ef2c34df464f5a774c7f`.
The server was initialized in a new unique owned QA datadir, bound only to127.0.0.1:
3339 with X protocol disabled. No service was installed. Original XAMPP3306/data
and VPS were not touched. This proves MySQL Community8.4.8 on Windows, not Percona
on Linux and not production equivalence. lower_case_table_names differs by OS and
is explicitly left for Linux qualification.

Executed MySQL8.4.8 checks (separate runs, overlapping tests are not additive):

| Scope | Actual result |
|---|---|
| Full001–014 QA schema order |1/1 PASS,16 assertions|
| Full chain plus Dry Run/Backfill/rollback/restart/FIFO and LOT=GL60 |1/1 PASS,17 assertions|
| Enforced tenant FK1452, CHECK3819, restrict_fk_on_non_standard_key=ON, no DDL inside financial transaction |1/1 PASS,9 assertions|
| Dedicated-reader metadata/roles, including mandatory/nested roles, no business-row output |11/11 checks PASS;11 metadata result sets|
| Real L3 purchase race plus distinct processing orders competing for one cost source |2/2 PASS,15 assertions; performance_schema.data_lock_waits evidence|
| Mixed processing cost post/void, Support audit rollback and schema restart |3/3 PASS,29 assertions|
| Additional identity/late GL rollback/mixed shipment return run |3 tests passed, one initial mixed-recipe setup error from unreleased prior PDO; the failed setup is not PASS. Strict offline DDL guard retained; unreachable PDO cycles collected; mixed processing rerun passed above|
| Unused schema rollback plus payroll branch isolation and late Voucher GL failure |4/4 PASS,19 assertions|
| Logical backup using this exact MySQL8.4 client and separate restore |1/1 PASS,135 assertions; source retains later synthetic transaction|
| Same-company transfer/purchase and other-company independence |2/2 PASS,35 assertions; real MySQL lock graph, no deadlock|

### Proven engine differences repaired

* The metadata gate previously rejected non-MariaDB servers. It now reads actual
  VERSION()/version_comment, handles MySQL NONE current-role semantics, checks
  available/enabled/mandatory roles and retains strict SELECT/SHOW VIEW-only scope.
  No role or write privilege is allowed to qualify a reader.
* tx_isolation is unavailable on MySQL8.4. The two affected operational root owners
  now read only the available SESSION isolation variables and preserve the existing
  next-transaction READ COMMITTED policy and cleanup. Preflight uses the same
  narrow SHOW SESSION VARIABLES query, with no new permission requirement.
* MySQL CHECK_CONSTRAINTS has no TABLE_NAME column; metadata joins the constraint
  repository to TABLE_CONSTRAINTS. Generated-expression hashing excludes empty
  expressions; FK rules and restrictive FK settings are recorded without changing
  them. CHECK bodies and trigger bodies are never exported, only hashes.
* MySQL does not support CREATE OR REPLACE TRIGGER. QA-only migration adaptation
  preserves every trigger body; MySQL DROP/CREATE is permitted only in the exact
  disposable owned datadir, outside transactions and with no other DB clients.
  There is no production activation or relaxed constraint. Integer display widths
  are normalized only for integer COLUMN_TYPE comparison, retaining signedness,
  decimal precision and all uniqueness checks.
* A real identity INSERT failed with MySQL3995 because BINARY REGEXP is incompatible
  with ICU regex. The ascii_bin column remains case-sensitive without binary
  casting. Existing checks still reject lowercase, spaces, newlines and tabs,
  duplicates and cross-company reassignment. The LOT preparation SQL template uses
  explicit utf8mb4_bin instead; it remains a reviewed template, not an executor.
* MySQL lock proof uses performance_schema.data_lock_waits; MariaDB keeps its
  original InnoDB metadata proof. No timing-only substitute is accepted.

Reference syntax/behavior: [MySQL8.4 CREATE TRIGGER](https://dev.mysql.com/doc/refman/8.4/en/create-trigger.html),
[FK restrictions](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html),
[CHECK metadata](https://dev.mysql.com/doc/refman/8.4/en/information-schema-check-constraints-table.html).

### MariaDB preservation and Percona qualification

After normal ownership-verified MySQL QA shutdown, the preserved MariaDB10.4.32 QA
datadir was restarted on3339. New metadata/reader check: **10/10 PASS**,10 result
sets. Affected full-chain/FK/CHECK/DDL, mixed processing and case-sensitive identity
regression: **4/4 PASS,53 assertions**. Earlier accepted MariaDB results remain
accepted within their original scope; no broad repeat is claimed.

An isolated GitHub workflow is prepared for official percona/percona-server8.4.8-8
image manifest digest `sha256:eaa4cf955f8a01a43faa6ef656bf8fb69a17c17c278a3b0514212291ca0448b1`.
Actions are pinned to commit hashes; token permissions are contents:read and checkout
does not persist credentials. It uses a new ephemeral datadir, loopback-only3339,
strict nonstandard-FK rejection, synthetic fixtures and client binaries from that
same server image. No production credentials/data or deployment steps are used.
**Percona CI is pending until its actual run result is recorded; preparing the
workflow is not a test PASS.**

Still required: actual production server/schema metadata, completed Percona/Linux
evidence, approved representative historical data, production-equivalent Staging,
approved production migration executor/write fence/backup-restore qualification
and remaining comprehensive security sign-off. A passing upstream MySQL run never
substitutes for these gates. No Manifest GO, VPS change, deployment or production
migration was performed.

### Final Percona Linux run — actual result

The isolated run [40430204531](https://github.com/mptech96/salb/actions/runs/40430204531)
completed **SUCCESS** on tested source commit
`9e4de7be2722bb622108a4db0e90712172788d03`.
Server metadata read through PDO reports **8.4.8-8**, distribution
**Percona Server (GPL), Release8, Revision1c288264**. This is QA-server evidence,
not the VPS server's version.

| Percona Linux scope | Actual result |
|---|---|
| All14 migrations, restrictive FK/CHECK/DDL, Backfill/FIFO and separate backup restore |4/4 PASS,177 assertions|
| Mixed processing/overhead, late failure rollback, source race, Support and restart |5/5 PASS,46 assertions|
| Mixed L3 shipment/return/reversal, race and qualified historical receipts |4/4 PASS,53 assertions|
| Case-sensitive identity constraints and unused schema rollback |2/2 PASS,15 assertions|
| Manual journal and new critical privacy/branch/financial failure cases |14/14 PASS,101 assertions|
| Transfer/company concurrency with actual server lock evidence |2/2 PASS,35 assertions|
| Dedicated-reader authorization/metadata, including inherited and mandatory roles |11/11 checks PASS;11 metadata result sets; no business-row output|

Total PHPUnit result: **31/31 PASS,427 assertions**. No skipped step was included.
Percona backup proof retains53 trigger hashes and confirms later source transactions
survive restore to a different QA schema; dump SHA256:
`a2be574e6125052e56727b1b5250436ea9400fbf81681c28f1e883c2bdb9e3e5`.
The first CI run40429789962 failed at QA reader authentication because Docker's
observed client was its bridge IP, not127.0.0.1. Its subsequent suites were skipped
and are not PASS. The QA-only fixture now binds its reader to the exact observed
client address, never a wildcard; production reader remains unchanged. The
successful rerun above verifies that correction. The owned container was stopped.

Additional native MySQL8.4 report/inventory/actual PDF test: **1/1 PASS,12 assertions**.
The pre-existing MariaDB PDF was preserved; engine-specific copies are retained in
ignored local `engine-evidence/`. These files and downloaded MySQL binaries are not
part of the GitHub release candidate.

The MySQL/Percona dialect gap now has actual Linux vendor evidence. It does not
close the remaining production gates: actual VPS VERSION()/version_comment and
schema/flags/FPM evidence, approved representative anonymized history, full
production-equivalent staging/UAT, approved production executor, write fence,
backup/rollback rehearsal and comprehensive security sign-off. All production
approval fields remain false and the decision remains **NO-GO**.

### sulbmetadata reader qualification — 2026-10-27
Preflight and standalone collector now authenticate only as the CloudPanel-approved
`sulbmetadata` account. Privilege, grant-option, cross-schema, routine and inherited/
mandatory-role rejection is unchanged. Password input remains hidden on /dev/tty.
Actual isolated Percona Server 8.4.8-8 verification: **11/11 PASS**, 11 metadata result
sets; synthetic business sentinel was not exported and collector performed no writes.
Evidence: https://github.com/mptech96/salb/actions/runs/40433563016
Tested source commit: d244fbe3a895ee610c81c2eb603ecc634981d812.
Previously qualified ERP suites were intentionally skipped; their prior results remain
within their tested scope. No VPS access, production migration or deployment occurred.
Production metadata review and existing staging/history gates remain open: **NO-GO**.

### Actual VPS metadata review — 2026-10-27
Evidence supplied by operator: metadata.tar.gz, staging checkout 36bc0ad2465d7701b6c767bd96b7c57b1b7bf17b.
Actual SERVER (not client inference): Percona 8.4.8-8, Linux case-sensitive table names,
REPEATABLE-READ, strict SQL mode, restrict_fk_on_non_standard_key=1. PHP CLI 8.4.22
and required extensions verified. Schema SHA256:
550ce437236b4b8f10df2d438eb179ae9335ee298147b84a5a185bf5f796ee9b.
111 InnoDB tables,1919 columns,567 index entries,51 FK action rules,40 migration rows.

Offline comparison to the schema actually used by accepted Percona QA:
110/110 baseline tables,1886 column type/nullability definitions,561 index entries,
51 named FK definitions AND their delete/update rules match (integer display width
normalized only; signedness and decimal precision remain significant). No baseline
mismatch detected. Additional production objects: road_waybills (26 columns,6 index
entries), company_settings header_image_path/footer_image_path/print_header_texts/
print_footer_texts/print_options/watermark_path and official_documents.print_metadata.
These correspond to the already recorded branding/central-print migrations 026/027.
All40 ordinary migration filenames are already recorded; ordinary artisan migrate
alone cannot install the14 separate UOM migrations. Additional objects must be kept.
Offline comparator passed actual-evidence comparison plus4 injected negative cases:
missing column, signedness change, altered index uniqueness and wrong FK target.
This is5 focused checks, not another ERP regression run.

MIGRATION_BLOCKERS: all14 remain production_approved=false. 003..014 explicitly
require the isolated UomStageAProof guard (testing/loopback3339/owned schema). LOT
value balances and shipment-parent FK proposals are likewise QA-only. They cannot
be executed on production by merely setting a flag; do not remove/bypass guards.
The reviewed production executor remains unfinished pending complete schema/history
qualification. Ordering remains001..009, the four declared L3/value/FK dependencies
before010, then010..014. MySQL implicit DDL commit and populated-evidence rollback
restrictions remain. Metadata cannot prove duplicate/orphan sequences, cross-company
history, valid receipt/GL sources or safe legacy-value backfill. Never invent costs.

TRIGGER_VISIBILITY:0 visible is UNKNOWN, not0 existing. This blocks approval because
existing trigger bodies/order can interact with financial and inventory writes and
new UOM triggers. Smallest safe check: EXISTING CloudPanel administrator executes
ops/sulb-rc/admin-schema-gap-readonly.sql (SELECT only) and returns result sets.
Do NOT grant TRIGGER to sulbmetadata; it also authorizes trigger creation/removal.
Column-level charset/collation was omitted from original metadata; the same query
collects it, especially currencies.currency_code for foundation FK compatibility.
Defaults, generated/check definitions and full trigger semantics still require a
private schema-only export for a faithful rehearsal; hashes alone cannot recreate
unknown trigger bodies. FPM8.3 and8.4 binaries both exist; active site FPM unproven.

STAGING_RESULT: BLOCKED, not PASS. Existing31/427 Percona synthetic results remain
valid in scope. No approved representative anonymized historical dataset is available.
Required single handoff: operator-approved PRIVATE staging input bundle containing
schema-only DDL including triggers/routines/events/checks/defaults/collations and a
representative anonymized relational history (all identifiers consistently remapped;
remove names/contact/identity/notes/secrets; retain approved exact quantities, units,
cost/FX/GL links and sequence relationships). Do not upload raw customer dumps or.env.
Return administrator gap-query results in that handoff. Restore only into a new
isolated Percona8.4 schema, no external services/queues/mail, then rehearse the14+
dependencies, dry-run exceptions, failure/restart/concurrency, LOT=GL reconciliation,
private backup restore into ANOTHER schema, mobile/PDF and authorization UAT.
No current customer database may be used as the test target or overwritten on rollback.

SECURITY_APPROVAL: pending final sign-off. Account gate actually verified sulbmetadata,
no applicable/enabled roles,2 allowed grants. Prior security tests retained. New
comparator opens files only and never connects to DB or executes input SQL; supplied
archive contains only preflight.json/runtime.txt. Private raw metadata and negative
fixtures remain ignored locally, not in GitHub. No VPS access or production writes.
RELEASE_GO_OR_NO_GO: NO-GO. Receipt of real metadata closes server-version uncertainty,
not the approved migration executor, full trigger/schema/history, active FPM, staging,
write-fence or production backup/rollback approval gates. Existing deploy script
remains gated; there is no authorized deployment command yet.

### Final staging preparation tools — 2026-10-27
Added ops/sulb-rc/staging-prepare.php and its local contract test.
--plan never connects. --policy-template reads only previously supplied metadata:
1919 explicit UNREVIEWED column policies generated in ignored local testing storage.
No classification is inferred from names. No row exporter/importer exists or is enabled.
--execute-schema-only requires independent approval typed through /dev/tty, existing
administrator username and hidden password, direct global visibility evidence and
no active roles. It executes only fixed SELECT metadata / SHOW CREATE / SHOW GRANTS;
never emits grants or passwords. Private schema JSON includes definitions for tables,
views,triggers,procedures,functions,events and must remain private: DDL can contain
sensitive literals/definers. No CREATE/ALTER/GRANT/LOCK/SET/data SELECT is issued.
No new privileges for sulbmetadata and no root login assumption.

New tests:9/9 LOCAL CONTRACT CHECKS PASS using fake metadata reader, plus --plan PASS,
unknown/data-export mode rejected, full1919-column template generated with export=false.
These are NOT live Percona capture or production/history staging passes. Existing engine
results remain unchanged. Full capture integration and actual history rehearsal remain
pending approved access/input. The9 checks cover policy completeness, invalid/duplicate
metadata, schema-only capture contract, grant non-disclosure and role/visibility denial.

Reliable anonymization cannot be certified from schema names/types: immutable receipt
payloads/hashes, JSON/audit/free-text references may embed identities and business keys.
An automated name substitution could leak PII or break source evidence/GL relationships.
Therefore production-row extraction is intentionally STOPPED, not considered complete.
Safe alternative: approved operator-managed isolated staging enclave, no public endpoints,
no production credentials, outbound mail/queue/integrations disabled, no raw transfer to
GitHub/chat. A privacy reviewer must approve per-column transformations and consistent
identifier mapping before any portable dataset. Exact approved quantities/FX/GL values
and relational closure must survive; any unverifiable field blocks export.

Next independent approval is schema-only capture with existing administrator; no row
extraction/deploy/migration implied. Actual representative-history staging still requires
its own approved input. Fourteen migrations/backfill/LOT=GL/concurrency/restore are NOT
rerun or claimed passed on unavailable history. Comprehensive final security approval
and production migration executor remain open; no gates weakened. Manifest stays NO-GO.

### CloudPanel administrative schema authentication — 2026-10-27
Official supported command verified in CloudPanel documentation:
https://www.cloudpanel.io/docs/v2/cloudpanel-cli/root-user-commands/
New --execute-schema-only-cloudpanel mode requires OS root and invokes only
/usr/bin/clpctl db:show:master-credentials --no-ansi through an argv array.
Credentials are captured in process memory, never printed, saved, passed via argv/env,
or obtained from application.env. Returned Connect Command is NEVER executed/evaluated.
Parser permits only exact local endpoint, valid port, nonapplication username and
unambiguous labelled output. Unrecognized formats/authentication/role/global visibility
failures stop generically; no fallback credentials, privilege changes or reset.
Existing schema-only approval prompt remains. Fixed schema reads/SHOW CREATE only.
Private schema JSON0600/directory0700 remain outside htdocs. Safe verification.json
contains object counts/hash/false row-export flag only; no DDL, grants or credentials.
Local15/15 contract checks PASS (six new credential parser cases, synthetic passwords),
PHP lint PASS. Not a live CloudPanel CLI or engine integration result. No VPS connection
or production extraction was performed by Codex. Deployment remains NO-GO.

### Operator schema capture received; zero-object verification — 2026-10-27
Operator reports111 TABLE definitions and capture SHA256
901f01f5069078cc1174f5bdd913609abbf72f2cadab9852cff05133a5a9047f;
private DDL remains on VPS, not received by Codex or stored in GitHub.
Previous summary omitted absent kind keys rather than reporting explicit zeros.
Added verify-private-schema.php: checks operator-pinned file hash, valid nonempty
CREATE definition for every captured object, obtains CloudPanel credentials in memory,
reuses strict direct global administrative visibility check, compares six explicit
counts against independent SHOW FULL TABLES/TRIGGERS/PROCEDURE STATUS/FUNCTION STATUS/
EVENTS metadata. No SHOW CREATE recapture or business reads; no DDL/grants printed.
A mismatch stops without accepting absence as zero. Metadata SHOW results may contain
sensitive definitions in process memory only, never emitted or persisted.
Local17/17 contract checks PASS including explicit zero keys and incomplete DDL denial.
Live VPS count verification not yet received; no zero-object conclusion claimed.
Actual complete DDL compatibility cannot be inferred from a hash/count alone. Previous
structural comparison remains valid; QA-only migration executor, exact collations/
CHECK/DDL history rehearsal and approved anonymized staging remain pending. NO-GO.

### Private schema contract and isolated rehearsal preparation — 2026-10-27
Operator PASS on efb0483:111 tables, explicit0 VIEW/TRIGGER/PROCEDURE/FUNCTION/EVENT,
all captured CREATE definitions present, hash901f01f5069078cc1174f5bdd913609abbf72f2cadab9852cff05133a5a9047f.
Trigger visibility gap CLOSED from actual operator verification. Do not rerun account,
engine, credentials or metadata capture. Private DDL stays solely on VPS.

Added private-migration-contract.php: FILE-ONLY, no connection/SQL execution, pinned
capture hash, existing administrative capture format, explicit zero non-table objects,
parent signedness/type prerequisites, actual currency FK column/table collation and
unique parent, existing UOM column/table collisions and L3 evidence collisions,
all14 catalog source hashes/order. Emits counts/reason codes only, no DDL/grants.
Static prerequisite PASS is deliberately not a successful migration rehearsal/GO.
Local7/7 focused checks PASS, including signedness/collation/parent-unique/UOM collision
negative cases. Production static contract result still pending operator execution.

Added PrivateSchemaMigrationRehearsalTest: accepts pinned schema-only JSON, refuses
nonempty target, owned loopback3339 test DB/datadir enforced; no production connection
accepted. Imports table definitions only, refuses qualified cross-schema references/
external data-directory connections. FK checks disabled ONLY for empty QA import and
restored in finally, all imported tables checked empty, installs14 plus L3 dependencies,
checks original tables retained and LOT values not invented. No row imports.
Actual native MySQL8.4.8 isolated synthetic empty-schema test:1/1 PASS,229 assertions.
New harness exercised110 baseline tables; this is NOT proof for private111-table VPS
DDL, actual Percona staging or historical Backfill. Owned QA DB removed, server stopped,
previous data retained. An initial sandbox/server-start attempt failed to connect;
no migration ran in those failed attempts. Successful run used only owned QA.

SECURITY: new offline tool cannot read secrets via DB or execute DDL; only count/reason
summaries leave VPS. Rehearsal remains under existing testing-only ownership guards;
no guard weakened and no production executor enabled. Prior authorization tests retained.
Full security signoff remains pending actual staged data flows/UAT and deployment executor.
BACKUP/ROLLBACK: existing synthetic separate-schema restore proof remains valid; private
production-equivalent restore must be tested before approval. Preserve post-cutover
customer transactions; no automatic live DB restore or destructive migration down.
Do not rely on code tar/hash verification as a database-restore proof. No extraction of
customer rows authorized or implemented. Approved representative history still missing.

NEXT ACTION ONLY: run offline contract against existing private schema with its pinned
hash; return safe JSON only. Then execute prepared test on isolated Percona staging
with approved input, not on sulbprod. Production-compatible migration executor, history
Backfill/FIFO/LOT=GL/concurrency/failure/restore and security UAT remain NO-GO gates.

### Network-isolated Percona schema-only staging harness — 2026-10-27
Operator actual private static prerequisites PASS on5445f5f,14 migration source hashes
verified,0 DB connections. This closes static prerequisites only, not execution/history.
Added rehearse-private-schema.sh and run-private-socket-rehearsal.php, with a focused
synthetic-only CI workflow. No production DDL/rows/credentials are sent to GitHub.
Actual full harness PASS on Percona Server8.4.8-8:
https://github.com/mptech96/salb/actions/runs/40441611002
Tested source5710f4673a79157bc357b40ebace419d7964be8d.
14 UOM migrations +4 declared LOT/L3 dependencies applied on the synthetic empty schema.
Owned database removed and owned ephemeral container removed. This does NOT certify
execution on the private111-table capture yet. No previous ERP suites rerun.

Isolation: pinned official Percona image digest, Docker --network none/no port bindings,
--skip-networking and mysqlx disabled, only fresh private Unix-socket directory mounted.
Server datadir is new random sulb-shipment32hex on container tmpfs, no production mounts.
512MB memory/1CPU/256PID limits. Fails if Docker/unshare/PHP8.4/Composer unavailable,
free memory<1.5GiB or free disk<2GiB. Never installs/starts Docker or host DB services.
PHP also runs in a new empty network namespace, verifies namespace identity/no nonlo
routes, DSN unix_socket only, datadir/server/empty-instance checks before creating DB.
Database name is generated sulb_ship_test16hex internally; caller cannot supply sulbprod.
No application credentials, CloudPanel auth or metadata collection. Fresh private source
archive rejects.env/cache; env-i, private Composer home/cache, no plugins/scripts, no live
vendor/storage. No source index ownership mutation (git --no-optional-locks status).
Private logs may contain DDL and are NEVER printed by operational wrapper; only safe JSON.
Container removed on exit; failure never produces a public PASS summary. Private source/
logs remain outside htdocs under root0700 for authorized local diagnosis only.

Initial harness CI attempts STOPPED, not PASS: a proc-route line-count guard rejected
network namespace readiness. Corrected using actual namespace identity plus trimmed
route semantics, preserving isolation. Initialization readiness now requires final PID1
mysqld, not the transient initialization server. Generic errors sanitized to allowlisted
codes/SQLSTATE numbers, never SQL text. Bash syntax/PHP lint passed. Final actual full
CI above passed all steps. No VPS contact by Codex.

RESTORE PLAN: schema-only runner creates only disposable DB and removes its container;
keep pinned input JSON unchanged for replay. No destructive migration down/live DB restore.
After private schema run PASS, separately approve an operator-controlled private history
staging enclave with outbound integrations disabled. Prefer in-enclave approved relational
history rather than exporting raw customer rows; no anonymizer has been certified. Preserve
exact approved source IDs/relations, quantities/UOM/FIFO order,FX/cost/GL and immutable
receipts. Any PII in immutable payloads/hashes requires a reviewed transformation or blocks
portable export. Next historical rehearsal must dry-run exceptions, apply/backfill under
controlled failure/restart/concurrency, reconcile LOT/GL and restore a verified backup into
ANOTHER new staging DB. Confirm newer source transactions remain intact. Return aggregate
counts/reconciliation only. No production row extraction authorized by schema-only approval.

SECURITY: new path reviewed for production credential/env leakage, TCP fallback, DB naming,
namespace/datadir ownership, private logging, source preservation, bounded resource use.
All these harness gates passed the synthetic integration. Comprehensive release signoff
still requires staged authorization/security UAT and review/qualification of a production
migration executor. Production backup account/executor and actual restore/write-fence
approval remain open. Existing deployment script remains locked. Actual111-table execution,
approved history Backfill/FIFO/LOT=GL/concurrency/rollback, security signoff and production
executor approval are remaining GO gates. Manifest remains NO-GO.

### Docker-unavailable production host; separate staging handoff — 2026-10-27
Operator reports DOCKER_UNAVAILABLE,PHP8.4.22. Rehearsal did not run: BLOCKED by runtime
prerequisite, NOT a proven migration defect. Do not repeat schema/accounts/engine/static
checks. No Docker installation, host service modification or native instance launch.

Prepared staging-runtime-readonly.php: reads PHP/QA extension presence, sanitized Composer
version with plugins/scripts/network disabled, unshare executable availability and memory/
filesystem free space. No DB/schema/env dumps or installers.5 local resource-boundary tests
PASS; PHP lint/local Windows inventory PASS. Actual VPS Composer/resources await operator
read-only execution. Local metrics are not claimed as VPS metrics.

Chosen execution target: SEPARATE operator-controlled Linux staging, existing Docker,
PHP8.4+QA extensions,Composer,unshare; preserve the tested pinned8.4.8-8 image/harness.
The existing harness requires >=1.5GiB available RAM and >=2GiB free working space, caps
container512MiB/1CPU/256PID and uses no published ports/host database mounts. Native tarball
execution is possible per official Percona8.4 binary-tarball installation documentation,
but would additionally require matching architecture/glibc/dependencies, private datadir/
socket/log/pid/tmp paths, --no-defaults/skip-networking, least-privilege OS identity,
namespace/limits and lifecycle tests. It is NOT approved or started on production, and
would share production resources. No systemd/unit/installed mysql.service changes.

Private handoff procedure (not executed): operator supplies staging hostname/IP and verifies
its SSH host-key fingerprint out-of-band. On staging create root-only0700 directory matching
/home/mgye-api-sulb/sulb-private-schema-88b4a61422be64ba8a53bdee, copy ONLY schema-private.json
through direct private SSH/SFTP (StrictHostKeyChecking=yes,ForwardAgent=no,no forwarded
ports). Redirect transfer into a new0600 temporary file; verify SHA256
901f01f5069078cc1174f5bdd913609abbf72f2cadab9852cff05133a5a9047f before final rename, refuse an
existing destination. No raw DDL in command arguments/stdout/chat/GitHub/public storage.
Obtain SOURCE CODE from the pinned RC through GitHub on staging; no production.env,
credentials,uploads,storage,vendor or row dumps. Create staging-only parent directories;
run the already tested schema-only harness on staging with the transferred pinned file.
Never repoint it to production or enable Docker there to bypass a prerequisite.

No target staging host/access/fingerprint has been supplied, so no concrete SSH destination
is invented and no transfer/rehearsal is claimed. Smallest external action: provide a
separate authorized staging host (address + verified public SSH fingerprint, no passwords
or private keys in chat). Actual private111-table execution/history/restore/security UAT/
production executor remain open. Production dump/row extraction still not authorized.
Release remains NO-GO; report/manifest updated only with actual evidence.

### Requested no-migration deployment assessment — 2026-10-27
Operator requests CURRENT candidate-vs-live Backend/Frontend comparison and assessment
of a possible schema-compatible subset. Prior capture contains347 raw hash differences
(292 backend/55 frontend within its selected source manifest), but is older evidence,
includes QA/new files and lacks live normalized hashes. Do NOT label these347 functional
changes or current live state. No VPS connection performed by Codex.

Added live-code-diff-readonly.php: current tracked runtime app/config/routes/resources/
package source paths compared to fixed live roots; hashes only, exact/line-endings-only/
content-different/candidate-only classifications, live-only source inventory. No Laravel,
DB,env/cache/vendor/storage/uploads/backup/private/public-data reads. No file contents
exported; private code-diff.json0600/root-only directory0700 outside htdocs. Static assets,
QA and generated trees excluded explicitly; this is not an authorization to rsync all.
Realpath boundary checks and symlink refusal;16MiB source cap/20k file cap. Source files
and live app are not modified. New14 filter/fingerprint tests PASS; PHP lint PASS.
Actual current report still requires operator execution; no fabricated diff review.

No production runtime subset is approved yet. Preparation/audit tools can be updated in
/home/sulb-release without deploying either app. Legacy-compatible fixes are CANDIDATES
only until exact live source baseline and dependency closure are established. Do not
copy whole modified controllers/pages based on a few apparently independent lines.
Deferred: all14 UOM migrations and4 LOT/L3 dependencies; BASE item/unit/snapshot/processing/
shipment/capitalization/header-cost paths and enabling flags; LOT sequence/historical
value backfill. Legacy recipe mixing requires qualified LOT value ledger. New permission
checks journals.post/reverse, fixed_assets.manage,workers.manage,payroll.manage are linked
to seed migrations013/014; existence/authorized role assignments are NOT proven by schema
metadata, so no claim that replacing controllers preserves current access.
Known guards (ItemController meta/create, OperationalDocument/TransactionOwner,
InventoryLotValue sqlValue etc.) are useful but not equivalent to whole-release
compatibility. Frontend items imports NewBaseItem/ItemUnitManager; UI/backend API/contracts
must travel as a reviewed pair, not a blind frontend-only replacement.

Next action ONLY: operator runs file comparison and returns the fingerprint JSON (no code
contents,credentials or customer rows). Unknown live-only modifications block replacement
until resolved; hashes can be matched to known repository source without exposing live
contents. Then prepare exact allowed-path/patch dependency set and run targeted legacy-
schema authentication/branch/company/rollback/report/PDF/mobile tests. If no independent
subset can be proved, defer runtime deployment to the single comprehensive release.

Comprehensive release remains pinned GitHub -> separate Percona staging -> all14+L3,
approved historical dry-run/Backfill/FIFO/LOT=GL/concurrency/rollback/authorization UAT ->
verified private backup restored into ANOTHER schema and new source transactions retained
-> approved exact production executor/write fence -> one Backend+Frontend deployment.
No migration down or old DB restore over post-cutover customer transactions. Existing
execution gate remains NO-GO; production paths/services/DB untouched. A final deploy
command is not certified until an exact artifact and its evidence gates are approved.

### Actual-content decision and independent migration-free patch — 2026-10-27
Received existing operator code-diff.json:359 identical,80 content-different,87 candidate-
only,live-only frontend/next-env.d.ts. No production comparison requested/repeated.
All80 live content hashes resolve to repository blobs (169 historical blobs examined).
The clean db2b9d63ccda67aadaa0f49e087cc9e0137b42e7 tree matches ALL526 reported runtime
path states, including87 absent files. Actual old-vs-new content can be reviewed locally
without reading VPS source or uploading secrets. This is more than filename/hash-only
triage: e.g.account parent is_active guard, report catch-only redaction, tree/lookup/group
ledger changes, payroll branch/accrual/loan changes, voucher numbering/writer locks,
Support financial owners and UOM shipment/invoice/recipe snapshot contracts examined.

Classification for EACH167 changed/new runtime source file is in
ops/sulb-rc/partial-content-review.json:30 COMPATIBLE_WITHOUT_MIGRATION (schema-level,
not blanket deployment approval),92 MIGRATION_DEPENDENT feature/mixed-file bundles,
45 UNRESOLVED financial/API dependency qualification. Per-file reasons and all80 old
blob identities are recorded. Pure helper classification is distinguished from feature
activation; mixed files can contain compatible hunks but are deferred as whole files.
359 identical files need no change; preserve generated live-only next-env.d.ts.

Created separate managed worktree and branch codex/sulb-schema-compatible-rc-20261027
FROM db2b9d6, not from the comprehensive RC. Original dirty checkout/full RC retained.
Exactly FOUR runtime files selected:
backend/app/Domain/Accounting/Services/AccountService.php — inactive parent guard;
frontend/app/accounts/AccountForm.tsx — only active parent groups selectable;
backend/app/Support/ApiExceptionMessage.php — pure SQL exception redaction helper;
backend/app/Http/Controllers/Api/AdvancedReportController.php — two catch responses only.
No routes/middleware/UOM/LOT/L3/permission/schema/package-lock changes, no Migration.
Existing companies/accounts/journal/inventory column definitions used by this scope
match the previously accepted production metadata; extra printing tables are untouched.
AccountRepository remains the ORIGINAL production version; no hierarchy feature sneaked in.

Focused tests on isolated native MySQL8.4 legacy schema:6/6 PASS,59 assertions. Active/root/
group accounts, inactive/foreign/missing/analytical parent refusal, duplicates, outer
rollback, no inventory/GL mutation, PDO-chain redaction and actual report run/CSV/XLS/PDF
failure responses covered.2/2 frontend static-render tests cover active/inactive/leaf
options and existing string-flag serialization. New tests do not claim mobile browser UAT.
Local Windows build failed due cross-drive node_modules junction resolution; no code
compiler failure conclusion. Clean Linux dependency install/build verified below.

Actual isolated Percona8.4+Linux focused CI PASS:
https://github.com/mptech96/salb/actions/runs/40447864166
Runtime source tested43d89108ac6a23fc426f5403acd5f2a92b12ec8c.
6 tests/59 assertions,2 frontend tests,Next.js production webpack build+TypeScript,
61/61 static pages PASS. No accepted broad ERP/Schema suites were repeated. QA databases/
container removed. Native local QA stopped, source datadir preserved.

DECISION: YES, a LIMITED four-runtime-file partial candidate is technically READY FOR
USER APPROVAL. This is not broad security approval for the rest of the old production
code and not authorization to deploy. Manifest records exact old/new file hashes and
allowlist; no whole-RC rsync, no blind deletion, no old builds/.env/storage copied.
Backend autoload must be refreshed for new helper; fresh frontend build must preserve
existing production build settings. Live file drift must STOP replacement. Back up the
old source/build privately, verify hashes and perform code/build-only rollback if needed;
no customer DB restoration. Final executor and operator approval still required.

COMPREHENSIVE SINGLE PLAN (still NO-GO): provision authorized separate staging with
existing Docker/Percona8.4; privately transfer the already captured pinned schema via
verified SSH; run the tested networkless schema-only14+4 harness; independently approve
representative private history in an operator-controlled enclave; verify dry-run exceptions,
Backfill restart/rollback, FIFO/LOT=GL, concurrency/company/branch/Support authorization,
reports/mobile/PDF; rehearse verified backup restore into ANOTHER staging database while
preserving newer source transactions; review security and exact production migration
executor/write fence; only then approve one pinned Backend+Frontend+migration deployment.
No anonymous-history exporter or production executor has been silently certified.

### FULL RELEASE ONLY; native same-VPS isolation assessment — 2026-10-27
User explicitly cancels partial deployment. Preserve its evidence/branch, but perform no
further partial release work. No second VPS purchase or Docker-on-production prerequisite.

Native independent mysqld on same OS is architecturally supported by MySQL8.4 documentation:
https://dev.mysql.com/doc/refman/8.4/en/multiple-servers.html
Use separate data/socket/pid/error/tmp/secure-file paths, --no-defaults FIRST, networking
and mysqlx off, owned fresh datadir/schema names, separate least-privilege OS identity,
mount/PID/network isolation for test server AND PHP, bounded memory/CPU/PIDs/IO. No reuse
of production datadir/config/socket/account, no modifications to mysql.service, no host
service replacement. Namespace/cgroup/MAC/resource feasibility must be established before
launch. Same-host isolation cannot eliminate all shared CPU/IO/kernel impact; do not claim
otherwise. A temporary schema inside the running production instance is weaker isolation
(global server state/DDL/resource coupling) and is not the proposed test target.

Added native-staging-feasibility.sh: read-only memory/disk/CPU/cgroup/controller/tool/MAC
presence and an ephemeral unshare child /bin/true capability test. No mysqld execution,
DB connection, mounts/cgroup changes, install, service change or schema reads. Bash syntax
PASS. No live probe result received; host capacity and enforceable isolation UNKNOWN.
If namespaces/cgroups denied, insufficient headroom, or MAC forbids owned paths and would
require forbidden policy changes, STOP native staging. Do not weaken policy or bypass it.
Composer previously observed in runtime metadata; tool availability only is checked here.
No successful ERP/Percona/metadata/static comparison tests rerun.

Reviewed order for the currently catalogued QA chain:001 foundation ->002 contracts ->003
operational ->004 commercial ->005 opening ->006 adjustments ->007 shipment ->008 recipes
->009 commands -> stock_movements_company_source_index.php -> shipment_cost_parent_fk.php
-> shipment_cost_allocation_receipts.php -> inventory_lot_value_balances.php ->010 BASE
capitalization ->011 header costs ->012 processing costs ->013 journal permissions ->014
resource write permissions. This is the tested dependency order, NOT approval to run these
files in production. Several require Tests/Support UomStageAProof, owned3339 schema/datadir;
all14 are outside ordinary automatic Laravel migrations, and catalog/executor NOT_APPROVED.
Permission registration does not approve assigning every role; plan existing-user grants
explicitly before enforcement. Other experimental driver/loan/historical-accounting
proposals are not implicitly approved by the14+4 chain; qualify or keep unreachable.

SELECT-only history screening can identify duplicate company/lot numbers and vouchers,
orphan/cross-company shipment-cost/LOT/movement links, invalid quantity states, unbalanced
POSTED journals, missing/reversed/mismatched source GL, stock-vs-movement discrepancies,
ambiguous/missing numbering and immutable receipt references. Counts/exceptions only may
leave server. They cannot manufacture original unit cost, infer unknown receipt valuation,
prove full backfill readiness from aggregates, or guarantee a stable write-free snapshot.
Unknown sources/partial issues without authoritative value remain explicit blockers.
Do not call the QA-only HistoricalLotBackfill::apply on production or remove its guard.

Complete deployment plan: verify native staging isolation/headroom; schema-only actual
capture rehearsal (still not executed); approve private representative history and restore
only into the independent instance; run historical exceptions/restart/concurrency/FIFO/LOT=GL
and security acceptance there. Capture a consistent InnoDB backup using matching Percona8.4
client with hidden approved credentials, plus private code/env/storage/uploads/assets and
version manifest; verify hashes AND restore into another isolated DB. During rehearsal add
a synthetic newer source transaction after snapshot and prove source retained it. Before
production DDL approve a write fence covering web,API,workers,imports/schedulers/integrations,
then final backup/delta. No claim that artisan down alone fences background writers.

Failure: DDL may commit implicitly. Stop on first failure, retain fence, record completed
steps/schema fingerprints, no blind migrate:fresh/down/retry or automatic down rollback.
Use tested idempotent forward repair or offline restore only if there have been NO accepted
new writes since snapshot. If newer transactions exist, preserve them; restore into ANOTHER
DB and reconcile/replay via reviewed procedure, never overwrite live with old snapshot.
After reopening, prefer compatible code rollback or reviewed forward repair. A completed
staging restore is not proof of the future production write fence.

Existing one-shot deployment script remains approval-gated; it is NOT a qualified final
executor for QA-only14+4. Do not advertise it as ready or enable GO from static checks.
Production executor/manifest/native harness/history/restore/security signoff remain pending.
No production change performed. Decision NO-GO; practical next action ONLY host isolation/
resource probe, not purchase, extraction, migration or deployment.

### Native staging AppArmor review prepared — 2026-10-27
Operator feasibility PASS:13,983,888KiB available memory,188,614,428KiB home free,4 CPUs,
cgroup2fs,ephemeral mount/net/PID namespace test PASS,mysqld/unshare/setpriv/prlimit present.
No mysqld started/no DB connections/no service changes. Preserve these results; no repeat
of capacity,Schema,metadata,Percona or previous QA suites. cgroup controller delegation/
IO enforcement is NOT established merely from cgroup2fs and still needs launch qualification.

Added review-mysqld-apparmor.php. READ ONLY: systemctl show MainPID for existing mysql.service,
read /proc/PID/exe and attr/current, identify on-disk source candidates, use AppArmor parser
-N/-p with -Q(no kernel load),-K(no cache),-T(no cache read). No -r,-R,-a,-W or profile reload.
Only known infrastructure path rules/hash/deny/variable markers leave server; full policy,
other-user rules,commands with credentials,DB rows and DDL are not printed. PID consistency
checked. On-disk source declaring a live label does NOT prove byte equality to loaded policy;
summary explicitly retains that uncertainty and cannot approve access from regex alone.
Local synthetic rule/deny/variable/no-false-authorization/privacy-filter checks and PHP lint
PASS. Actual AppArmor behavior was NOT tested locally or on VPS by Codex.

Potential no-policy-change design: keep current executable and its enforced AppArmor profile;
private mount/PID/network namespace with fresh owned directories bound over already-allowed
/var/lib/mysql,/run/mysqld,/var/log/mysql ONLY inside child namespace after private propagation.
Actual prod data/socket paths must be hidden and this must be proven BEFORE initialization.
No unconfined copied executable,profile unload/complain/reload,production file mutation,
shared live datadir or TCP. Empty QA-owned DB name only; dedicated low-privilege execution,
all capabilities dropped before server,matching private ownership for owner rules. Any deny/
variable/symlink/attachment ambiguity or inaccessible required path stops launch; never weaken
policy to continue. This is a design for review, not a runnable/approved native harness yet.

Initial schema-only proposed limits:mysqld512MiB,total experiment2GiB,1CPU,256PID,private5GiB
working budget. Bounded IO must be validated against actual device/cgroup controller before
launch; a prlimit address-space limit is not a substitute for enforced memory cgroups.
Historical dataset/storage limits require separate sizing/approval. Same-host resource sharing
remains real despite namespaces. No purchase or Docker installation required by this plan.

NEXT ACTION ONLY: operator runs AppArmor read-only inspector and returns filtered JSON. It
will identify actual label/source candidates and relevant expanded rules for deciding private
path mapping without touching production policy. Native instance approval remains FALSE.
Comprehensive release,actual schema execution/history/backup-restore/security executor remain
NO-GO pending their existing gates. No partial releases resumed.

### Combined native isolation gate prepared — 2026-10-27
Operator observes /usr/sbin/mysqld(complain),disk preprocessing PASS with deny/variables;
effective permissions/source equality unproven. Complain does NOT disable explicit denies:
https://www.apparmor.net/man/4.0/aa-complain/
Do not change enforce/complain mode,load/reload/remove profile or rename executable.

Added close-native-isolation.py, one bounded gate for all three pending proofs. No mysqld,
DB connection,profile modification or service command other than MainPID read. Uses existing
Python3/libapparmor only, no installer. Loaded label queried directly via documented
AA file-path query API for intended new datadir/socket paths and required access masks.
Disk source byte equality is NOT claimed/required for that runtime decision: loaded kernel
permissions,including denies/variables,are authoritative at query time. This does not recover
human-readable rules from kernel DFA. No stale on-disk allow inference. See AppArmor upstream
libapparmor aa_query_file_path_len API/header. Query absence/error/deny means BLOCKED.

Observed production data root derived from OPEN ibdata1 path (readlink only, no file bytes),
actual mysqld filesystem sockets from owned FD inodes+/proc/net/unix. Unknown/custom/abstract
mapping fails closed. Separate mount/net/PID namespace, private propagation first, bind only
NEW owned empty backing folders over those paths, remount proc, verify private PID1/no routes,
actual mounted inode identities, production data/socket absence in child and their original
inode/socket presence on host afterwards. Namespace exits before cleanup; no host mounts or
production datadir writes. Metadata-only marker is created inside private masked data path,
never production. Original service PID/label must remain the same through all checks.

Creates ONLY one new cgroup under an already-delegated root with existing memory/cpu/pids/io
controllers. It NEVER enables parent controllers or changes existing limits/membership.
Probe256MiB,swap0,CPU0.25core,32PIDs and device-specific1MiB/s I/O; exact kernel readback+
owned child attachment verified. A4MiB O_DIRECT/fsync file in private workspace must be charged
to that device in io.stat AND show bounded throttle delay. Overlay/unknown device,missing
controllers/permissions/readback/accounting/throttle => BLOCKED. Only its own EMPTY cgroup
removed; never move/kill production processes. No stress/OOM/PID-exhaustion test performed.
These checks prove kernel control acceptance/child membership and direct IO behavior, not a
production load benchmark or elimination of all shared-resource impact.

Local Python syntax and5 mocked kernel-query/deny/error/guard unit tests PASS. No live Linux
namespace/cgroup/AppArmor integration test was executed by Codex; do not call these gates PASS
before operator runs it. No old QA/Percona/Schema/GitHub inventory rerun. Combined summary emits
NATIVE_STAGING_GO only if ALL three pass, otherwise BLOCKED with concrete reason code(s).
No profile/DDL/credentials/customer data exported. Temporary namespace/cgroup/workspace writes
are intentional only within new owned test resources; this is NOT a strictly read-only probe.

NATIVE_STAGING_GO authorizes proposing the bounded schema-only native harness, not production
GO or business history extraction. Actual server startup/least-privilege capability drop and
schema migration execution remain a subsequent explicitly reviewed action. Current full
manifest remains NO-GO and no native Percona instance has been launched. If any gate blocks,
repair/approval must address that specific isolation constraint; no purchase or Docker demand.

### Resume comprehensive release; actual mapping defect fixed — 2026-10-27
Operator combined proof stopped at DATADIR_MAPPING_REQUIRES_REVIEW BEFORE any mysqld/DB/
policy/service change. Actual cause: production_paths required canonical ibdata1 directory
start with /var/lib/. That is a deployment-location assumption, not proof of a wrong DB
path. Actual VPS datadir is NOT supplied and NOT guessed in this change.

Replaced that assumption with metadata-only identity validation: ibdata1 AND mysql.ibd
must both be OPEN by the existing service PID, named files must retain the same device+
inode as those FD references, canonical roots must agree; working-directory observation
recorded separately. Broad OS/site/source/htdocs roots still refused. Standard/custom and
symlink-resolved data locations can pass only with those anchors. Missing/ambiguous/deleted
anchors or changed identity STOP. Evidence now returns resolved root/cwd without file bytes.
Socket discovery now selects only LISTENING Unix socket inodes owned by that process,
checks actual filesystem socket type and collapses nested private masks. Removed arbitrary
/run-or-/tmp-only socket restriction; a proven socket beside a proven custom datadir is
valid. Journal/client-connected sockets are not mistaken for DB listeners. Abstract sockets
remain blocked. No AppArmor mode/policy/cgroup controls were weakened.

New17/17 focused Python mapping regressions PASS: standard/home/srv custom paths, broad/site/
htdocs rejection, missing/ambiguous/deleted anchor refusal, alias resolution, private mask
collapse, broad socket parent rejection, actual listener-vs-connected/foreign filtering,
abstract socket refusal and FD identity-change rejection. Real Linux host isolation result
is still PENDING; no native server started. Existing successful industrial QA not rerun.

Local state inspected without reset/clean/deletion: original wave2 checkout90 modified and
242 untracked entries at inspection, comprehensive RC clean before this work, partial branch
preserved but NOT progressed/deployed. All349 current source-manifest paths in original and
comprehensive RC agree after line-ending normalization. Live local ports3000/8000 and original
3306 observed; no DB connection to3306. Source Axios fallback matches Laravel localhost8000,
no frontend.env override file observed;10 new API controller methods exist and route/permission
source wiring checked. This is static linkage, NOT fresh authenticated runtime PASS. Backend
.env is NOT an owned QA DB, so the running app was NOT used for write/DB tests. No secrets
printed and no original XAMPP data accessed. Frontend instructions read, no frontend code
changed; accepted prior UI/API/authorization tests retained in their exact tested scope.

One-shot deploy script hardening (still disabled): approved migration list captured in parent
shell so failure cannot be swallowed by process substitution; rejects unqualified/non8.4
mysqldump instead of preferring MariaDB client; existing approved private client config must
name user, no fabricated sulb_backup_ro override. --no-tablespaces and GTID-purgedOFF avoid
unnecessary global privilege/restore side effects; database argument without --databases
avoids CREATE DATABASE/USE sulbprod in the base backup, allowing restore into an owned schema.
Qualified/cross-schema routines/triggers must still be reviewed during actual restore.
Bash syntax PASS; synthetic shell check proves list-command exit78 prevents subsequent work
and matching8.4 client versions pass/MariaDB is rejected. Actual check-approval exits78.
No --execute invocation, SQL dump, Composer install, service change or deploy was performed.

Production executor is NOT magically ready:001..009 ->4 accepted dependencies ->010..014
remains the exact schedule. Several scripts still require QA-only Tests/Support guards and
are excluded from ordinary Laravel migration path; --no-dev deployment excludes those test
classes. Do not remove/forge QA guards or just set schema_install_approved=true on production.
A dedicated reviewed production installation context plus step journal/postconditions and
interruption handling must replace this dependency before certification. This turn preserves
the safety guards and exposes that blocker in Manifest, rather than publishing an unsafe
"ready" claim. Financial SQL/business accounting was not changed without new evidence.

Complete gates still open: actual combined VPS namespace/AppArmor/cgroup proof after mapping
fix; approved native startup with nonproduction instance/limits; execution on private111-table
schema; approved representative history/dry-run exceptions/Backfill FIFO LOT=GL; consistent
backup restored into ANOTHER isolated schema with newer source transactions retained; final
security and qualified production executor/write fence. Received production metadata had
log_bin=0, so do NOT assume binlog PITR/replay is available. After accepting newer writes an
old dump must never overwrite live; preserve those writes and use compatible code rollback/
reviewed forward repair or separately qualified reconciliation. DDL may commit implicitly.

Full release ONLY. Native mapping proof is the one next operator action; it starts no DB.
No new VPS/Docker/partial deployment required. Manifest stays NO-GO with all production
approval fields false. Tools/report changes only are prepared for the same GitHub RC branch.

### Two verified staging blockers; single targeted resolution — 2026-10-27
Operator3c81906:datadir/home/mysql;private mount/PID/network+host data/socket hiding PASS;
no mysqld,DB connections,production policy/service changes. Reuse that explicit operator
namespace receipt; do not rerun successful Schema/migration/Backend/Frontend/industrial QA.
Remaining labels alone do NOT identify a loaded deny rule or the failed cgroup operation.

Confirmed diagnostic defect: libapparmor allowed=false means requested mask is not fully
in (allow & ~deny), so it can indicate MISSING ALLOW rather than EXPLICIT DENY, especially
relevant to complain mode. Prior LOADED_POLICY_DENIES_REQUIRED_PATH wording overclaimed.
Upstream reference libraries/libapparmor/src/kernel.c query_label, AA_CLASS_FILE header.
Now query same loaded .access interface directly with exact full FILE path payload; emit
per-path requested operations/allow+deny masks/explicit-denied and missing-allow operations.
No kernel policy loader/cache/profile mode/source edits. Kernel interface does not expose
source-file line numbers; do not fabricate a matching source line from an unequal disk file.
Complain mode is never treated as an override to grant missing explicit permission.

Only three bounded policy-compatible plans are considered: actual data/socket paths;
actual data with canonical/run/mysqld socket; canonical/var/lib/mysql+/run/mysqld.
Alternate paths require strict loaded-policy allowance AND a NEW CHILD-ONLY private parent
mount proof. Mask original/home/mysql/sockets, create real (not symlink) private/var/lib
and/or/run views within isolated mount/PID/network namespace, confirm canonical target
identities and unchanged host data/varlib/run/socket identities. No copy-to-unconfined
binary,deny exception,mode change or production mount. No alternative passes merely because
its on-disk rule looks permissive. New mapping proof runs only when selecting a different
logical path and only after policy+cgroup pass; original unchanged namespace proof reused.

Confirmed cgroup diagnostic defects: broad exception catch hid phase/errno; cleanup could
mask primary failure; preexec_fn obscured attachment errors; sleep-based .05sec membership
sampling could race. Fixed structured phase/error fields for delegation,device resolution,
create group,each memory/cpu/pids/io/swap write/readback,child attach,direct IO/accounting/
throttle and cleanup. Preserve original error plus cleanup detail; clean ONLY group actually
created by invocation. New child shell self-attaches before exec, never move host PIDs.
No preexisting controller/limit/production group modifications.

Potential device-selection defect corrected: partition MAJ:MIN is mapped via sysfs partition
metadata to its parent block device for io.max; mapper devices retained,overlay/unknown major0
refused. This was a supported code fix, NOT a claim that it caused the VPS failure. Missing
swap controller accepted ONLY when actual host SwapTotal=0; otherwise block. All actual
memory/CPU/PID/I/O readbacks and4MiB direct write/accounting/throttle requirements retained.
EINVAL is explicitly NOT interpreted as proof the host lacks support. EACCES/EROFS/EPERM
reported as host permission/read-only constraint; missing device/interface separately.

Local18/18 NEW focused tests PASS: raw reply completeness/duplicate/missing rejection,
full path encoding/NUL refusal,missing-allow vs explicit deny,strict current/alternative/no
allowed plans,unconfined rejection,partition/mapper/overlay handling,phase+errno sanitization,
child stderr privacy and actual mocked create-permission failure with no invalid cleanup.
Python syntax PASS. No actual Linux resource/AppArmor runtime result fabricated. Existing
successful QA not repeated. No Percona start,Schema/data import,migration,deploy or partial
release. One commit contains whole resolution; manifest remains NO-GO/native approval false.

NEXT operator action ONLY: close-native-isolation.py --resolve-blockers. Output either
NATIVE_STAGING_GO for controlled schema-only preparation, or BLOCKED with exact path/
operation/masks and cgroup phase/errno/device/readback evidence. An unsupported query,
no strictly permitted candidate,unwritable/readonly cgroup hierarchy or missing usable
controller/device remains a concrete blocker under current no-policy/service-change rules.
No automatic root controller enabling or policy workaround; do not demand additional probes
for checks already evidenced. No native Schema-only launch script is certified until this
host-specific gate passes; production full release remains NO-GO regardless of native gate.

### Final exact socket namespace gate — 2026-10-27
Operator d3ab1a13 evidence accepts memory/CPU/PIDs/I/O/swap limits, child attachment and observed I/O throttle; no existing cgroups modified. Reused without rerunning cgroup.
New targeted resolver queries loaded AppArmor for bounded exact observed/canonical socket names plus .lock, requiring explicit allow and no deny even in complain. Production name reuse is child-mount-only: existing namespace mask runs before bind, target must be absent, fresh private Unix socket/lock are created, and host datadir/parent/socket device+inode and production process label remain unchanged. No profile/service change, mysqld or DB connection. Five new local unit tests PASS; actual Linux private socket proof pending. No schema/ERP suite repeated. NATIVE_STAGING_GO, if returned, authorizes isolation feasibility only; production remains NO-GO, no migration/deployment approval.


### Socket PID file permission correction — 2026-10-27
Reviewed official Percona-Server-8.4.8-8 sql/conn_handler/socket_connection.cc (SHA256 e874c52e9c028c5714c36892eb52386ae4581c6cde5350814c72e547a62a797f). Complete Unix_socket::create_lockfile body verified: open O_RDWR|O_CREAT|O_EXCL, fallback O_RDONLY/read, PID checks, unlink, write/fsync/close; no flock/fcntl/lockf/my_lock calls. AppArmor advisory lock permission was an erroneous extra requirement for this PID marker. Removed only that requirement; retain read/write/create/open and require delete for actual unlink. InnoDB datadir lock requirement unchanged. Child probe now exercises exclusive creation, PID write/read/fsync/close/unlink; no mysqld. Targeted socket tests 7/7 PASS (five affected previous cases plus two regressions proving absent/denied advisory lock accepted and missing delete rejected). No cgroup/namespace/ERP suites rerun. Operator reported datadir/socket PASS and sole missing permission lock; blocker is scanner overconstraint, not proven host limitation. Corrected host result pending; no server start/migration/deploy authorized. Production remains NO-GO.
Source: https://raw.githubusercontent.com/percona/percona-server/Percona-Server-8.4.8-8/sql/conn_handler/socket_connection.cc

### Approved native schema-only launcher — 2026-10-27
Operator receipt c925a5963339cf40d75c61a251a66e244ca3da75: NATIVE_STAGING_GO, strict AppArmor PASS, private socket/PID-file creation PASS, original socket inodes unchanged. Reuse namespace/cgroup evidence; no I/O benchmark or earlier infrastructure proof repeated.
Prepared rehearse-native-schema.py --execute-schema-only for the next authorized VPS action. Exact private capture SHA256 901f01f5069078cc1174f5bdd913609abbf72f2cadab9852cff05133a5a9047f is required before any server launch. Only 111 empty table definitions, zero other objects and business_rows_exported=false are accepted. Archive tracked RC into a new 0700 directory outside htdocs; Composer uses private HOME/cache, no scripts/plugins and no production vendor/config/.env. Keep all raw DDL, source and logs private; export only safe-summary.json and error identifiers.
Use newly created cgroup only: memory256MiB, CPU0.25,32PIDs,I/O1MiB/s,swap0. Limits are applied/read back for execution (not a repeat throttle benchmark); no parent controller changes. Explicit private mount/net/PID namespaces hide actual /home/mysql, all owned production socket parents, live htdocs and administrator defaults. Run /usr/sbin/mysqld --no-defaults under existing mysql OS UID with capabilities removed, no networking/mysqlx/binlog and small owned buffers; never change mysql.service/AppArmor. Initialize ONLY a new sulb-shipment-32hex private datadir. PHP verifies root-private native context, server executable/PID/network identity, socket inode, actual Percona8.4/datadir/skip_networking and an otherwise empty instance before any CREATE. Only a fresh sulb_ship_test_16hex database may be created; production sulbprod is never connected.
Rehearsal preserves QA guards. Schedule:001..009, then stock_movements_company_source_index,shipment_cost_parent_fk,shipment_cost_allocation_receipts,inventory_lot_value_balances, then010..014. All18 source hashes are checked against catalog. Native context handles private PID1 sharing the private netns using distinct saved host net/PID identities; old Docker guard remains unchanged. Log migration step separately without DDL. Always stop owned server and remove only the owned cgroup; retain private staging artifacts outside public files.
Local new validation:19 Python contract tests PASS;16 PHP context/order/hash checks PASS, including tampered dependency rejection. PHP/Python syntax checks PASS. No DB connection, original XAMPP access, production access or ERP suites used. The changed private-schema PHP harness triggers its existing synthetic-only CI regression; this is not evidence of actual production-schema execution.
Actual VPS schema-only execution is PENDING, not PASS. Historical data/backfill/financial validation, restore rehearsal, production executor and security approval remain separate unclosed gates. Release manifest remains NO-GO; deployment_authorized=false. Native isolation approval is not production deployment approval.

### Native socket argv correction - 2026-10-27
Operator 7fb00be rehearsal started and stopped only isolated Percona; production identities unchanged, no production connection or business rows. migration-private.log reports OWNED_SOCKET_REQUIRED, no migration checkpoint or SQLSTATE; memory.events max226 with oom0/oom_kill0 is not assigned as root cause.
Confirmed code defect: Python passes script/socket/datadir/capture/sha/proof/native-context (seven PHP argv entries, native context index6); PHP read argv[7], silently skipped native identity verification and subjected /home/mysql/sulb-shipment-* to Docker-only /var/lib/mysql guard. Thus rejection occurred before PDO and before migrations; socket availability itself was not disproven.
Fix: strict six/seven argument parser selects argv[6]; native execution must still verify root-private context, matching server/socket dev+inode, private PID/network distinct from host, owned datadir and offline fresh Percona. Preserve legacy Docker validation. Reject extra/missing/empty/NUL inputs. No guard, migration, resource or AppArmor relaxation. Safe-summary now includes allowlisted STOP_CODE values, no raw logs/DDL.
Focused new validation:3 Python tests PASS, including actual generated Python command -> PHP parser and a mutation proving old index7 fails;12 PHP argv/native-versus-host rejection checks PASS. Zero DB connections in these local checks. Existing affected private PHP harness CI covers old Docker compatibility; no AppArmor/cgroup probes or ERP suites repeated. Native retry on actual private schema is pending. Release stays NO-GO; historical validation/restore/production executor remain unapproved.

### Operator actual private Schema-only PASS - 2026-10-27
Accepted operator result for dcd26450875432caa9135c5f4c78a20da613f595: Percona8.4.8-8,14 UOM plus4 LOT/L3 dependencies applied, COMPLETE; no production connection or business rows; owned DB removed/server stopped, original host identities unchanged. No Schema-only, namespace, AppArmor or cgroup rerun required. This closes actual empty-production-schema execution, not historical/backfill/restore qualification. Remaining sequence: independently approved private history snapshot on the same VPS, historical invariants and Backfill/FIFO/LOT=GL, verified isolated backup/restore and newer-transaction protection, qualified production executor/security and one full deploy approval. Existing staging-prepare.php does not export business rows and is not a history or restore executor. Do not represent it as ready to run a history snapshot. A separate precise privacy/snapshot approval is required before production row extraction; no such extraction or deployment performed. Production release stays NO-GO.

### Encrypted history tool preparation - 2026-10-27
Conditional authorization covers development/synthetic QA and VPS metadata-only readiness. Production row extraction remains disabled in release.json and is rejected BEFORE credentials/resources when --capture-approved is attempted. No production capture, native history launch, production migration or deployment performed by Codex.
Prepared history-stage.py: --check-readiness checks current dedicated sulbmetadata authorization using existing strict structured/role/grant verification plus schema-wide SELECT/SHOW VIEW, pinned private capture and present table shape, InnoDB-only types, PHP sodium/PDO and conservative free-disk/memory estimates. It reads metadata, not business rows; no MySQL administrator/app credentials or .env. Hidden password uses controlling /dev/tty, never argv/env/file; terminal echo restored after timeout. Collector is a new owned cgroup at256MiB/CPU0.25/32PIDs/1MiB/s/swap0, no prior cgroup/AppArmor/namespace proof rerun or services changed. These client/restore limits do not throttle the production server's SQL work: session metadata-lock/query timeouts and a one-hour capture budget are additional guards; source load remains unqualified until reviewed actual readiness evidence.
Future separately approved capture: one nonbuffered PDO connection, REPEATABLE READ + WITH CONSISTENT SNAPSHOT READ ONLY, UTF8/UTC, no table/global write locks or source DML. Compare schema before/after ignoring only table AUTO_INCREMENT; fail on DDL drift/non-InnoDB/unsupported spatial/vector/oversized rows. Preserve every row/ID/NULL/binary/JSON/DECIMAL/UOM value and generated-value evidence; BIT64 and floating fields use server string conversion to avoid PHP float/integer loss. Row-count plus order-independent SHA256 multiset digests retain FIFO identifiers/timestamps, without inventing financial values. Close active unbuffered cursor before rollback on failure.
Encrypted authenticated streaming archive SULBHST1 uses libsodium secretstream with final tag, ciphertext SHA256 and keyed manifest MAC. fsync encrypted payload/key and atomically publish root-private manifest; incomplete/tampered/truncated/wrong-key bundles rejected before restore DDL. Snapshot and separate key directories remain0700 outside htdocs; files0600; no raw data/key/log upload. Raw historical data is NOT claimed anonymized.
Prepared explicit history-only native restore scope (does not reuse Schema-only approval): same proven masks/offline Percona/owned cgroup, existing mysql OS UID, --no-defaults and no TCP. Native context requires separate HISTORY mode and current process/socket/PID/net identity. Restore only new sulb_hist_restore_16hex databases on a proven sulb-shipment-32hex datadir, with parameterized owned-qualified INSERT, no overwrite of existing DB/tables, no sulbprod restore. Read/decrypt/verify complete archive before any DDL. Restore twice, compare all row digests and FK orphans; invalid references remain explicit exceptions, never repaired by guessed values. Stop only owned server/group; retain private artifacts. No old Schema-only/ERP suites rerun.
New local actual MySQL8.4.8 synthetic QA:31 checks PASS (read-only account write rejection, consistent concurrent update, exact high-precision LOT=GL sample, uint64/zero IDs/BIT64/double/Unicode/BLOB/JSON/generated columns, two verified restores, newer source transaction untouched, corrupt/truncated/wrong-key/manifest rejection, aborted snapshot rollback, explicit orphan detection and DDL-change rejection).22 offline PHP contracts PASS;8 Python orchestration contracts PASS (capture permission, no host startup, TTY/timeout, no credential argv/env and no false PASS after cleanup failure). A separate new Percona8.4 CI runs only these new tools, not accepted ERP/isolation/Schema-only suites. Actual production-history validation, VPS snapshot restore, Backfill/FIFO/LOT=GL history, final security and production executor remain unapproved; NO-GO.

History tools CI follow-up: first Percona run40568889756 reached the synthetic QA reader connection and stopped1045 before snapshot (contracts22/Python8 passed). This was a QA authentication fixture host mismatch: container sees the client's actual NAT origin, not127.0.0.1. Fix only the temporary synthetic QA account to the exact host returned by USER(), rejecting wildcard host values; production reader/grants unchanged. Added per-job core-dump disabling and require the collector's owned cgroup before credential input; no machine-wide limits/service changes. QA cleanup now runs even on assertion failure. Await replacement Percona run; no production/history PASS inferred.

New history-tool Percona CI40569774943 PASS on1c702f7c3c77e327d4ab16b024ab24f8362aba04 (official pinned8.4.8-8 image): synthetic snapshot/concurrent update/two restores31 checks plus contracts22 and Python8. QA host binding fix confirmed; no Schema-only or accepted ERP suites rerun. Added explicit capture approval binding to the SHA256 of a root-private readiness receipt, exact work directory and24-hour validity. Permission flag and approved receipt hash both remain unset/false; no production extraction possible. New local receipt-binding contracts increase PHP checks to26, including rejection of tampered/moved/expired receipts. Production source load/actual history/restore remain unqualified. Local standalone QA server was gracefully stopped after PID/binary/datadir verification; artifacts retained, XAMPP untouched.

### History minimum read privilege correction - 2026-10-27
Operator0888346 readiness stopped at HISTORY_SCHEMA_WIDE_READ_REQUIRED before business rows; old code required both SELECT and SHOW VIEW and did not identify which was absent. Actual VPS grants are not available from that code alone. Confirmed unnecessary condition: this implementation exports only InnoDB BASE TABLE and inventory still rejects VIEW; MySQL SHOW CREATE TABLE needs some table privilege, satisfied by SELECT. SHOW VIEW is for SHOW CREATE VIEW and is optional for this verified zero-view source. No production grant change is justified solely from the old blocker.
Corrected gate requires direct schema-wide SELECT on sulbprod.*, accepts already approved optional SHOW VIEW, and preserves all existing dedicated-account/structured-privilege/roles/mandatory-role/write/global/GRANT OPTION checks unchanged. New parser rejects write/global/other-schema/table-only/role/unknown grants and exports only boolean evidence, never grant text/password/hash. If SELECT is actually absent, return HISTORY_SCHEMA_SELECT_REQUIRED with exact GRANT SELECT ON sulbprod.* TO CURRENT_USER-derived account/host for review only; no SQL executor, account creation, privilege mutation or host guessing. Source capture still needs no PROCESS/FILE/RELOAD/LOCK TABLES/BACKUP_ADMIN/TRIGGER/write privileges. Restore writes are confined to the fresh offline native instance; no restore privileges granted on production.
New local regression:33 read-privilege checks PASS (SELECT alone, optional/split SHOW VIEW, missing SELECT, exact localhost/percent/IP account suggestions, write/global/role/GRANT OPTION rejection, no GRANT queries);26 existing affected history contracts PASS. Change only synthetic QA reader grant to SELECT alone to prove snapshot/SHOW CREATE TABLE/two restores on Percona with minimum privileges. No Schema-only/migration/AppArmor/namespace/cgroup probe reruns. Row extraction remains false and approved_readiness_sha256 remains null; fresh readiness evidence and independent approval still mandatory. Unified release NO-GO.
References: https://dev.mysql.com/doc/refman/8.4/en/show-create-table.html and https://dev.mysql.com/doc/refman/8.4/en/privileges-provided.html#priv_show-view .

Minimum-privilege CI40573565128 PASS on f288d76c2438047425d3872cc62abf3b58946841: Percona synthetic snapshot and two verified restores succeeded with SELECT-only QA reader (31 checks); new read gate33 plus history contracts26/orchestration8 passed. No SHOW VIEW was needed for BASE TABLE definitions or source rows. Actual VPS SELECT presence is still to be reported by corrected metadata-only readiness; no grants or extraction performed.

### Reviewed history experiment preparation - 2026-10-28
Accepted operator History Readiness PASS on6a8f15c,111 tables,SELECT present,no rows read. Bound immutable readiness SHA8ede8362220b5bba9d794272f4ddcc2d4837dc8e8b67c91b36b43c7cfd3d8565; do not rerun Readiness/Schema-only/isolation. Execution and row extraction remain false pending review and separate explicit approval.
Prepared one execute-history-plan.py entry: find exact root-private receipt by SHA (no path guessing or production connection), require both authorization flags and a one-hour approval window, reuse complete authenticated snapshot rather than automatically extracting twice, capture under source pressure supervision, two isolated restores, then ordered14+4 on the populated second restore and historical Backfill/FIFO/LOT=GL/sequence/rollback/restart audit. Old QA guards remain intact; upgrade target uses the exact sulb_ship_test_16hex naming required by existing guards. Never call destructive synthetic TestCase setup on restored history. Source ledger columns are digested before/after; permission seeds are the only whitelisted appended rows and sequence preparation is explicitly derived/offline.
Source load qualification is NOT claimed before approved execution. Prepare keyset pages <=128 (LOB1), consumer512KiB/s,per-SELECT30s,one-hour snapshot; small unkeyed tables only, larger unkeyed cases fail closed. One RR READ ONLY transaction remains across all pages. Host observation before/during capture: baselineCPU<=70%,abort sustainedCPU>85% for5samples,MemAvailable>=1GiB,disk>=2GiB; abort only owned collector, never production mysqld. These controls observe host headroom and bound SQL/client work; they do not impose a hard production mysqld IO cap. Revalidate source identity/current restricted credentials/live shape in the actual operation without issuing another Readiness artifact. Preserve the original approved receipt unchanged.
History restore/audit reviewed budget: own cgroup768MiB (capture stays256MiB),PHP512MiB,CPU0.25,32PIDs,IO1MiB/s,swap0;require3GiBhostavailable before capture/restore. Only new owned cgroup limit changes,no services/parent policy changes. Immutable encrypted bundles are hardlinked within the protected filesystem, avoiding unbounded bulk copies. Private source/vendor/cache installed without scripts/plugins/.env; no production vendor/config/storage. Audit complete plans for all companies before any Backfill; any unresolved evidence prevents all financial backfill writes. Failed/partial attempts and detailed monetary evidence remain private, not Terminal/GitHub/chat. Sales FIFO basis is recorded event order; ambiguous/interleaved/unknown evidence is an explicit blocker. Not production executor qualification.
New local pure checks:15 FIFO/paging policies and5 pipeline/pressure safety tests PASS;26 affected contract checks PASS. New populated-history CI is pending and distinct from the already accepted Schema-only test. Production history/source-load/restore and final release remain NO-GO; no VPS access or extraction performed.

History preparation qualification completed: f4db98ce3dc6bfd878798f9d50b7f7ac01a6c98b, Percona8.4.8-8 CI40590696956 PASS. Snapshot/concurrent-source-update/two-restores31 checks; NEW populated legacy history14+4 migrations, two-company Backfill, FIFO/LOT=GL, forced rollback/idempotency and preserved newer source transaction8 checks. Pure policies15/pipeline pressure5/read grants33/contracts26/orchestration8 passed. Initial preparation CI exposed a paging variable overwriting the archive authentication key; corrected variable scope and affected archive roundtrip revalidated. Local owned MySQL8.4.8 populated chain also PASS8. These are synthetic TOOL qualification only, not actual production history, source-load qualification, restore acceptance or production executor approval. Both execution/extraction flags remain false, exact readiness SHA unchanged; no VPS connection, capture or deployment. Full release remains NO-GO.

### Explicit operator history extraction approval - 2026-10-28
Operator authorized the consistent SELECT-only sulbmetadata production snapshot and private same-VPS encrypted archive, followed only by isolated restore/migrations/Backfill/FIFO/LOT=GL. Bound exact readiness SHA 8ede8362220b5bba9d794272f4ddcc2d4837dc8e8b67c91b36b43c7cfd3d8565. Existing one-hour start window expires 2026-10-28T14:20:26+00:00; unchanged hash/reader/identity/resource/isolation guards. Authorization is not evidence of execution: actual history, source-load and restore acceptance remain pending, production executor unqualified, deployment and production migrations unauthorized, global NO-GO. No VPS access/extraction performed by this agent and no accepted suites rerun.

### Operator history execution window renewal - 2026-10-28
Previous window expired before execution per operator. Renewed existing one-hour start window only, expires 2026-10-28T16:57:25+00:00 (Riyadh 2026-10-28T19:57:25+03:00). Same approved immutable readiness SHA 8ede8362220b5bba9d794272f4ddcc2d4837dc8e8b67c91b36b43c7cfd3d8565, SELECT-only sulbmetadata/private encrypted same-VPS snapshot and isolated history/restore scope. Local policy-diff assertions verify only approval timestamp/expiry/source changed; previous PASS evidence and every resource/isolation/identity/hash guard unchanged. Actual private receipt SHA and live source identity/current read-only grants remain mandatory pre-row checks on VPS, not remotely attested by this renewal. No accepted tests rerun, no VPS connection or production extraction performed; historical result pending, production writes/migrations/deployment unauthorized, full release NO-GO.

### Authentication-first history retry approval - 2026-10-28
Operator reports MySQL1045 after entering the dedicated-reader password. Inspected historyHiddenReader: hidden /dev/tty input, exact sulbmetadata/localhost/sulbprod PDO connection, no password argv/env; connection failure precedes key creation and any capture. 1045 alone does not distinguish incorrect password from server-side account/host authentication configuration; no live authentication claim made. Renewed same readiness-bound one-hour start window until 2026-10-28T17:05:32+00:00. Supplied authentication-only PHP connection plus existing strict metadata role/SELECT gates first; any failure exits before history pipeline. On PASS, extraction still requires its separate interactive confirmation. No account/password/grant changes, no production access by agent, no accepted tests rerun; actual history and restore pending, full release NO-GO.

### Actual historical snapshot/restore accepted; six account exceptions - 2026-10-28
Accepted operator d7348ad evidence: authentication/capture/consistent encrypted archive/integrity PASS, two restores PASS, populated14+4 applied, original financial columns preserved, restore production disconnected and host identities unchanged. Historical validation remains false: INVENTORY_ACCOUNT_EVIDENCE_REQUIRED6, backfill0, idempotency NOT_RUN; no production executor qualification. Disabled new row extraction and pipeline execution: existing archive must be reused.
Confirmed blocker semantics: HistoricalLotBackfill::plan requires item.inventory_account_id before any financial replay. Runtime ItemAccountingResolver supports item/category-parent/group/company INVENTORY_ACCOUNT inheritance. This mismatch is a diagnostic lead only; no mapping or historical value inferred. Added review-account-evidence.php: locate latest root-private six-exception audit bound to approved readiness, verify signed/encrypted existing archive, report company/branch/item/LOT numeric IDs, missing-direct/missing-item condition, same-company chart validation of configured candidates, source-header-linked posted-GL account IDs/directions, no names/customer fields/amounts/DDL/password/key, and explicit no auto-approval. No DB driver connection or restore/migration. Private bounded report retained locally; safe ID-only evidence may be reviewed. New pure/crypto tests17 PASS including inheritance/global category, wrong company/branch, missing entity, cycle, no auto-mapping and tampered-key rejection. Existing accepted suites not rerun. Need operator safe evidence report to identify actual six mappings, then accountant-approved historical scope and full LOT/GL validation; full release NO-GO.

### Six LOT account180 proposal verification preparation - 2026-10-28
Accepted operator evidence: company3 six DIRECT_ITEM_ACCOUNT_UNSET LOTs3/34/35/36/37/38, company INVENTORY_ACCOUNT180 active same-company ASSET, purchase posted debit; journal1 also debits184. No accounting mapping approval inferred. Confirmed diagnostic defect: review tool read optional is_postable only; actual services use is_active=1,is_group=0,allow_posting=1. Corrected report to collect actual posting fields. No runtime resolver/backend financial behavior changed.
Added propose-account-180.php: authenticate existing encrypted archive via existing private reader, no PDO/no database connection/no extraction/no repeated restore. Review inheritance chain and strict actual account180 postability/branch scope, exact six company/branch/item/journal identities; all company3 LOTs inheriting180 replay quantity/value with source purchase-line/receipt checks, no split receipt or unsupported BASE guessing. Sum original LOT costs by source journal (including all journal113 LOTs) against account180 debit only;184 and other debits excluded, whole source journal must balance. Compare remaining LOT values to all posted account180 GL per branch; run recorded-order FIFO evidence. Any missing/changed/ambiguous/mismatched evidence blocks. Detailed amounts stay in root-private proposal, public summary only IDs/booleans/reasons and SHA256. Proposal is unapproved/unapplied and historical_validation_passed=false even if READY_FOR_ACCOUNTING_APPROVAL.25 new pure tests PASS including184 separation, whole journal balance, scope, inheritance precedence, missing permission flags and LOT/GL mismatch;17 affected diagnostic checks PASS. Actual snapshot proposal run pending operator, isolated Backfill/idempotency still pending explicit accounting approval. Full release NO-GO, no production writes/deployment authorized.

### Historical partial-scope GL diagnostic defects corrected - 2026-10-28
Operator16a27d: actual account180 postable/FIFO PASS and journal1 inventory component equal; proposal BLOCKED, only LOT3 shown. Code confirms two diagnostic defects: source guard continue dropped five cases from output; remaining subtotal was then compared with complete GL. Thus old branch3/8 false cannot establish actual historical imbalance. No data/account correction inferred.
All six requested cases now retained even if source rejected, with observed header/line/journal references and separate checks for source type, header/line presence, posted/nonvoid status, branch/header/item links, qty/value/capitalization. Do not relax failed source checks. Source groups include all associated LOT IDs plus validated IDs; GL aggregated once per journal, never per LOT. Incomplete groups expose equality null, not a false financial verdict; complete journal1 evidence remains independently computable. Partial LOT subtotal cannot be declared equal to full GL. Added ledger decomposition for all company3 account180 posted journals and branch scopes, documentary purchase LOT references, stock movement LOT links and return header/source invoice/line evidence; no-link documents/unsupported allocations explicit, no guessing whole journal/whole account belongs to six LOTs. Detailed values remain private; safe summary references/booleans only. Existing actual FIFO evidence reused only from root-private proposal with same archive/readiness hash; no actual FIFO/restore/schema/extraction rerun.
22 new diagnostic regression checks PASS plus25 affected proposal checks: rejected LOT remains present, failure reason exact, partial comparison null, three LOTs grouped with one GL debit, manual/return journal not attributed without LOT proof, no auto-approval. Operator must run corrected offline reader on existing encrypted archive to distinguish exact failed predicate/historical deficiency; raw archive stays VPS. Mapping and historical acceptance remain false, global NO-GO, no production writes or deployment.

### Purchase base versus capitalized LOT contract correction - 2026-10-28
Operator e305aef confirms all six cases visible; LOT3 source accepted; LOT34/35/36/37/38 quantities match but line base != LOT total and allocated_cost nonzero. This is not proof of corruption. Existing LegacyCapitalizedLotEvidence/BaseLotCapitalization establish legitimate purchase base_cost plus separately posted allocated_cost=total_cost, with original purchase movement/GL at base value. Previous narrow proposal incorrectly compared purchase line to capitalized total.
Added pure purchase-lot-cost-evidence.php to existing offline proposal entry, authenticated existing encrypted archive only. Capture recorded LOT base/allocated/total, line pre-tax/base VAT/after-tax and discount presence without recalculating discounts or FX; inspect linked shipment/item recorded costs and all shipment cost docs/GL, hash-verified immutable recipient receipts, totals/account-specific posted balanced SHIPMENT_COST GL, recipient quantity/item/branch/shipment/account bindings and duplicates. No historical allocation recalculation. Report each missing recipient proof separately from recorded shipment aggregate conservation; posted cost/net inventory GL totals alone do not prove per-LOT attribution. Preserve all five cases in one result. Detailed amounts private, safe output booleans/source IDs only.
Only fully evidenced capitalization may change comparison to purchase base and add proven allocated value to unissued LOT replay; purchase source groups113 sum base once and capitalize separately, never multiply GL by LOT count. Issued/reversed/ambiguous capitalization still blocks for event-order evidence; no relaxation of Backfill or production services. Actual FIFO PASS reused by same archived hash, no extraction/restore/schema/FIFO rerun.34 new synthetic cost-evidence tests PASS, plus22 affected diagnostic/25 lightweight proposal checks. Includes three capitalized LOTs sharing journal113, separate184 tax debit, balanced independent cost GL, missing/tampered/duplicate receipt, wrong company, void/unposted costs, partial issue rejection, tax identity, no disclosure of amounts. Actual archive evidence result and explicit accountant mapping approval remain pending; no real Backfill applied, global NO-GO.

### Scoped legacy allocation accounting attestation prepared - 2026-10-28
Accepted operator31465e: all six purchase bases and LOT cost component identities match; LOT3 accepted, five LOTs lack allocation_receipts, posted shipment costs and recorded aggregate allocations/net inventory GL agree. Aggregate equality alone does not prove historical per-LOT shares. Old ShipmentCostDistributionService persists shipment_item/LOT allocated totals and overwrites incoming stock movement cost; general AuditService records optional descriptive rows and swallows failures, not an immutable per-cost allocation ledger. Do not fabricate an LR receipt or rerun historical allocation weights.
Added legacy-allocation-approval.php --prepare: existing authenticated encrypted snapshot only; reuse matching private capitalization proposal; inspect alternative audit/allocation/capitalization/snapshot tables for scoped structured LOT/shipment links, retain row hashes/references only, no generic log automatically qualifies and no assertion all free text was interpreted. Prepare root-private JSON containing exact company3 LOT34/35/36/37/38 branch/item/journal scope, recorded received/remaining quantities, base/allocated/total amounts, source documents/GL and alternative evidence, source proposal/archive/readiness hashes. Values remain VPS private. Must resolve non-allocation source/GL mismatches, changed values, issued/reversed histories before this limited attestation can be accepted.
Explicit contemporary human accounting attestation only after review of each recorded value and packet SHA; reviewer and review reference required, exact five cases only, aggregate conservation not treated as automatic approval. --record-approved remains policy-locked and rechecks packet/cipher SHA, private paths, scope and no production flags; creates an idempotent append-only decision file with recording UTC and file SHA, no financial table/receipt mutation. Declared reviewer authority is human-supplied, not cryptographic identity verification. Separate inventory-account mapping approval and isolated Backfill/LOT=GL/idempotency still required; neither packet preparation nor recording claims historical_validation_passed.28 new pure safety tests PASS (tamper/hash/scope/duplicate/privilege/unresolved-source rejection), no DB/production/restore/FIFO/schema reruns. Capture/extraction remain disabled, global release NO-GO.

### Single full-release closure checkpoint - 2026-10-28
BLOCKED/NO-GO. Froze786 tracked Backend+Frontend source files (Git-blob SHA256, no environment/storage/vendor/build data) and exact18 source hashes: UOM001..009, stock movement company/source index, shipment parent FK, allocation receipts, LOT value balances, UOM010..014. full-release-scope.json is the one complete candidate scope, not a partial release or production build certification. Preserve all prior accepted native isolation, actual Schema-only14+4, encrypted historical capture/two restores, FIFO evidence without rerun. Received accounting review packet SHA e6591276adf1a442406ac2c810f6e9c5f3b9f2b6f5ffbe4ca04bd036cc5a23f8 ready for review with no packet blocking reasons, but human approval still absent. No automatic account180 mapping beyond company3, no mapping applied even there.
Added read-only --inventory to existing deploy-reviewed-release.sh entry, not a cleanup executor: report /home filesystem free bytes and narrowly owned private work/cache/vendor sizes; preserve encrypted snapshots/keys/backup/evidence and isolated databases until closure. Only reproducible cache/vendor candidates, process/mount checks fail closed, hardlinked files excluded from reclaim estimate, no deletion code or authorization. Owned datadir footprint only if root-private launch references exact /home/mysql/sulb-shipment-32hex; never scan live sulbprod/datadir root. Actual VPS reclaim is NOT measured from local workspace; approved_delete_bytes=0. Source-only local test execution initially encountered sandbox temp constraints and Windows DirEntry device behavior; use os.lstat and run owned temp tests outside restricted temp mapping. New targeted result5 PASS,1 SKIPPED (Windows symlink creation privilege unavailable);3 synthetic deployment rejection tests PASS, Bash syntax PASS. No ERP, schema, restore or native isolation suites rerun.
Concrete production blockers, not approvals alone: current path gate cannot accept QA UOM/proposed dependency paths; migrations require QA-only UomStageAProof, no qualified production executor; deploy script does not integrate approved historical Backfill. Existing full deploy entry remains guarded before production action. Added explicit production_executor_qualified/write_fence_qualified/deployment_backup_restore_qualified gates (all false) so manifest cosmetics cannot authorize execution. Remaining ordered closure: explicit scoped accounting + inventory mapping decisions, isolated approved Backfill/LOT=GL/idempotency using existing archive, qualify production executor/final security/tenant write fence and exact deployment backup recovery, then independent deploy approval. Actual historical restore PASS is retained; it is not falsely treated as qualification of the different deployment mysqldump/code backup procedure. Current command deploy-reviewed-release.sh --execute is a blocked entry point, NOT a ready authorized production command. No VPS connection, production writes, subscriber interruption, deployment or deletion performed.

### Explicit production executor code closure - 2026-10-28
Original786 Backend/Frontend files and all18 source migrations/QA guard unchanged. Added deterministic separate production adapters under ops, each bound to original catalog SHA plus adapter SHA, in exact001..009+4 dependencies+010..014 schedule. Production adapters use a distinct explicit ProductionUpgradeContext, not APP_ENV=testing, a class alias, evaluation or runtime patch to QA guards. Original up() SQL payloads unchanged (normalized line endings), all production down() paths hard-reject before destructive code. Updated check-approval to exact full18 source-path list rather than incompatible Laravel-only regex; existing deploy entry now calls ordered executor, not artisan migrate on QA sources.
New production context requires independent GO/deploy/history/security/backup/write-fence gates, root-private one-hour receipt pinned to commit/server/account/current-backup hashes, direct PROCESS visibility, same PDO/server, advisory lock, exclusive DB clients, no nested transaction and approved release source. All conditions still false/unqualified for actual production. Journal fsync START/DONE/FAILED retains evidence across implicit DDL commits; record18 in Laravel migration repository while preserving prior rows; reject blind partial reruns and journal reuse, never automatically restore DB over newer transactions. Scoped isolated rehearsal mode reuses actual original QA guard and rejects production target.
Focused new qualification:74 no-DB adapter/SQL identity/guard contracts PASS,20 executor checks PASS on owned local MySQL8.4.8 and pinned Linux Percona8.4.8-8 CI40763826311 on3d1ce368c40fc3a2d75b027afd049135f53b1e65. Includes full18 application, original business columns/old migration rows preserved, cross-company FK rejection, simultaneous-client rejection, pre-context/up and destructive-down rejection, deliberately partial implicit DDL failure retained and retry refused. Local first readiness attempt occurred before QA startup, and test assumption on old migration row count/stale test PDO was corrected without weakening guards; final outcomes above only. No previous ERP/actual Schema-only/history restore/FIFO/infrastructure suite rerun, no production connection, no actual historical value changed.
This closes the code/order/QA-dependency defect, NOT accounting/history/operational deployment certification. Explicit unresolved operational integration: current backup must be bound to the actual upgrade receipt, and its qualification lifecycle plus all SaaS write paths/final security need proof before GO; simply toggling manifest is forbidden. Accounting packet e6591276adf1a442406ac2c810f6e9c5f3b9f2b6f5ffbe4ca04bd036cc5a23f8 remains unsigned/unauthorized. Preserve LOT34/35/36/37/38 values and original GL exactly; no derived Backfill applied. Post-deploy report review is additional UAT, never replacement for pre-deploy financial invariants. Global NO-GO; no final deployment command issued while gates pending.


## 2026-10-29 — One-shot closure implementation against e5416f46

Full scope retained: all original 786 application files remain; two application guards were added, and only provider/bootstrap wiring changed. The ordered 14 UOM + 4 LOT/L3 source migrations and original QA guards were not modified. No partial release was created. No production connection, extraction, account/grant change, deployment or production migration was performed.

Implemented:
- Independent allocation-cost and company3/account180 mapping decisions, bound to packet e6591276adf1a442406ac2c810f6e9c5f3b9f2b6f5ffbe4ca04bd036cc5a23f8, original archive, readiness, private authorized reviewer identity and reference. Wrong role, decision, packet, tenant, extra LOT, scope or financial value is rejected. Each issued decision has its own private timestamped audit record and SHA. Both authorization flags remain FALSE; no real approval exists.
- A guarded existing-archive-only closure path. It restores once because the prior instance was removed, executes the exact 18-adapter schedule inside owned QA, derives six company3 balances, verifies original business columns unchanged, validates grouped purchase/capitalization accounting without inventing historical receipts, checks rollback/idempotency and prepares scoped LOT counters. Account180 is an in-memory company3-only resolution; item accounts and original LOT/GL values are never updated. Other companies use their own scoped accounts/plans.
- A global first HTTP middleware plus Laravel beforeExecuting callbacks for existing/new named connections, CLI boot checks and privileged root-private entry/hash permits. All SQL is fenced, including SELECT/CALL with side effects; no HTTP-header exemption. The owned native QA exception requires the already proven socket/process/namespace context and exact PDO/datadir/database. It never authorizes sulbprod.
- Read-only closed-set writer/process verification before production context and every DDL step. Role/proxy/event uncertainty, unknown writers, unlocked ordinary writers, unreviewed dynamic/account-management privileges, existing sessions (including DB=NULL), old FPM workers and live CLI writers block. These checks do not lock accounts, change grants or stop services. The actual VPS writer/FPM profile is NOT yet qualified; the software fence alone is not proof of production quiescence.
- Fixed new-mode result aggregation: historical PASS cannot override failed owned-server shutdown. No old infrastructure test was repeated.

New/affected local results (synthetic only): MySQL8.4 approved-history engine 11 checks PASS, including independent company4/account280 and company3/account180 separation; approval policy 18 PASS; low-level fence 14 PASS; actual Laravel middleware/provider/CLI integration 11 PASS without PDO connection; direct-writer policy 11 PASS; modified orchestration 10 PASS. The affected production context/executor 20 checks PASS on owned MySQL8.4.8 after the new runtime checks. Prior real encrypted capture/two restores/native isolation/schema-only18/FIFO PASS remain accepted, not rerun. Percona confirmation for the new closure code is pending CI at this entry.

Actual historical execution remains BLOCKED until two explicit decisions exist. The new synthetic approval fixtures are not human approvals and do not qualify actual historical Backfill.

Human decisions still required:
1. Authorized accountant: attest only the existing recorded allocations for LOT34/35/36/37/38, without changing their values. Independently authorize account180 resolution for company3/items1,39 and the six reviewed LOTs. Review the exact private packet on the operator-controlled private VPS channel; keep amounts and reviewer identity out of GitHub/chat. Complete the private accounting-authority.json template beside the packet, mode0600/root, and provide only its SHA plus authorization confirmation for binding. The example has null reviewer/reference and is not an approval.
2. Final independent deployment approval AFTER technical qualification; current messages authorize neither production DDL nor deployment nor account-status changes.

Remaining technical gates (not represented as completed): actual approved-archive Backfill/LOT=GL/idempotency; actual writer exclusion and old process drain; same-run encrypted deployment backup/restore/receipt integration; production derived-balance executor and postchecks protected against financial drift since the archived snapshot. The old deployment script still uses a pre-existing receipt tied to a newly generated backup and a plaintext dump format; this lifecycle is NOT qualified and must not be run. Do not flip GO flags to work around it. Historical restore evidence does not automatically certify a different fresh deployment backup.

MySQL DDL partial failure must retain the write fence and step journal; no automatic down() or restore of an old database over newer customer transactions. Preserve the encrypted archive, keys, private evidence and rollback backups. No VPS cleanup size is claimed without a measurement; nothing was deleted. Unified manifest remains NO-GO; there is no qualified production deployment command yet.


CI confirmation: https://github.com/mptech96/salb/actions/runs/40780227587 PASS on Percona8.4.8-8 for the new approval policy, actual Laravel fence integration, direct-writer policy, affected 18-adapter executor and new two-company approved-history engine. Tested code commit e627e13. The existing history workflow also ran automatically because history-stage.py changed and passed: https://github.com/mptech96/salb/actions/runs/40780227555 . These are synthetic CI results, not a new real VPS history approval. Independent explicit gates production_derived_balance_executor_qualified=false and deployment_pipeline_qualified=false additionally block before application bootstrap; no flag flip is authorized.


## 2026-10-29 — Follow-up technical closure against ab741bea

Actual changes: private accounting audit creation now uses exclusive xb creation, private mode0600, fflush/fsync and read-back comparison. An existing decision/authority conflict, including a racing creator, cannot overwrite the approved record. A partial/invalid record stays BLOCKED, not silently recreated. Approval scopes must be known/nonempty. If the separately approved policy requires distinct reviewers, the two bound identities must differ even when only one scope is being recorded. No distinct-person business policy was invented or enabled and no approval issued.

New local authorization checks:22 PASS (the previous18 plus four new scope/separation acceptance/rejection checks). Six new Linux-root private-file audit cases are added to synthetic CI: exclusive creation, identical repeat, changed decision, changed authority, malformed evidence path and private permissions. CI result pending until observed; fixtures are explicitly SYNTHETIC ONLY.

Source writer inventory: ops/sulb-rc/source-writer-scope.json records 115 candidate source files and500 callsite lines with source hashes and line numbers, plus56 raw-PDO review sites. This is conservative static candidate enumeration, not a semantic assertion that each create/save is SQL or proof of all production writers. Raw PDO review found transaction identity/rollback checks, session-isolation SET and metadata reads, with no new raw business DML path. Laravel SQL still passes through the accepted central beforeExecuting fence. Source review does not establish external CLI/accounts/old FPM or queue processes; actual closed-set production writer proof remains BLOCKED.

No actual archive is available in this local checkout. The existing encrypted real archive and keys remain only on the private VPS and were not accessed, extracted, moved or deleted. close-approved-history.py was NOT invoked. Actual Backfill/LOT=GL remains BLOCKED by unissued independent human decisions and unavailable private execution; prior actual capture/two-restores/schema-only/FIFO evidence and prior synthetic engine11 checks are accepted historical PASS, not new runs in this follow-up.

Mandatory gates still open: actual approved historical reconciliation; complete live-writer exclusion/profile evidence; integrated same-run encrypted backup/actual isolated restore/upgrade receipt; production derived-balance executor with fresh-source drift protection; complete deployment qualification/security authorization. The old backup/receipt lifecycle defect has not been repaired by these approval changes. No production command is qualified, and no GO is issued. No new backup, production restore or deployment occurred. Prior backup and migration PASS results are not relabeled as new evidence.


Follow-up CI observed PASS: https://github.com/mptech96/salb/actions/runs/40863828725 ; tested code commit f5982301cdc71e11aeff3793fd14505c5317c342. New approval policy22 and root-private audit6 passed, with no real approval, production connection or customer data. The workflow also reran its included synthetic fence/18-adapter/two-company suites successfully; these are distinct from actual historical validation. This evidence-only follow-up changes no executable code or authorization.

Closure accounting: two concrete defects repaired (audit overwrite race/durability and scope/separation bypass); five required release gates remain unclosed (actual approved history, complete live writer proof, current deployment backup/restore/receipt integration, production derived-balance executor, final deployment/security qualification). This is NO-GO — TECHNICAL BLOCKERS REMAIN, not technically ready pending only human approval.


## Final technical closure — 2026-10-29 (new code evidence only)

The full release remains **NO-GO**. No accounting decision, production write, fresh production extraction, service change or deployment was performed. All 786 original application files are retained; the frozen scope now includes three protective runtime additions (789 files). Accepted native isolation, actual schema-only 14+4, actual encrypted snapshot/two restores and FIFO evidence are reused.

Implemented the missing same-run deployment lifecycle: immutable candidate bootstrap for privileged operations; unprivileged frontend build including build-time development tools; root-reviewed writer inventory and site-worker drain; separately authorized temporary ordinary-writer account locks; SELECT-only consistent encrypted database backup plus streaming encrypted Backend/Frontend assets (including private configuration/storage); an owned-instance restore of the exact new backup; dynamic SHA-bound receipt issued only after verified restore and before application copy. The receipt is bound to the issuer implementation and fresh server/commit evidence, removing the impossible requirement to precommit a future receipt hash.

The production path now executes the same derived-balance implementation qualified inside the approved isolated history workflow. It verifies all company plans, rejects cross-company LOT links, detects financial/source/counter drift before DDL, writes only the new value ledger and scoped LOT counters in one transaction, and preserves original LOT costs, item account fields and GL. Company 3/account 180 remains an explicit evidence interpretation, never a default for other companies. A reviewed derived-only recovery requires the exact completed 18-migration START/DONE journal; partial DDL never triggers automatic down() or database restore. Original QA guards and SQL migration payloads remain unchanged.

Root-only activation publishes only five reviewed feature flags after successful postchecks, with no QA/schema-install flags and no .env changes. The revised full deployment protects .env, storage/uploads and the live-only frontend next-env.d.ts. Failures retain evidence and any active write fence; after fence release, failures explicitly warn that newer customer writes may exist. Old-code/schema compatibility remains unproved, so the prepared recovery policy is forward repair only, never an automatic older-database restore.

New local checks: approval policy 22; source fence 14 plus Laravel integration 11; direct writer policy 12; runtime flag policy 9; partial-DDL recovery 12; encrypted-backup contracts 10; exact-backup receipt 20; original adapter payload identity 74. Additional changed-code MySQL/Percona and Linux/build results are recorded below once executed. A local repeat of the account metadata test correctly refused a pre-existing synthetic QA schema with 53 triggers; no unrelated QA schema was deleted and no production policy was relaxed. The clean owned Percona CI instance provides the isolated result for that test.

Remaining operational gates: two real accounting decisions and private authorized reviewer identity/reference bound to packet e6591276adf1a442406ac2c810f6e9c5f3b9f2b6f5ffbe4ca04bd036cc5a23f8; approved existing-archive Backfill/LOT=GL/idempotency; actual private writer/scheduler/admin profile and reviewed hashes; separate permission for temporary account status locks and maintenance; actual same-run backup/restore and final independent deployment authorization. No production profile, runtime activation, accounting approval or readiness flag was fabricated. Cross-schema executables, inherited roles, unknown privileged writers, unsupported maintenance drivers or missing source/backup evidence fail closed. No VPS disk inventory was run here; reclaimable bytes remain unmeasured and cleanup unauthorized.


### Verified closure results and remaining decisions

Tested code commit: `e7e6e115dea7060b859961d738d452ca63ec1de4`. [Affected release CI: PASS](https://github.com/mptech96/salb/actions/runs/40889911787), actual owned Percona `8.4.8-8`. Results: ordered executor 20; approved history synthetic fixture 11; derived executor/tenant isolation/drift/rollback/idempotency 10; real owned-QA account lock/session drain 7; private writer profile binding 7; encrypted application asset capture and actual synthetic restore 8; exact backup receipt 20; backup contracts 10; partial-DDL recovery 12; runtime activation policy 9; relocated fake-toolchain deployment ordering/failure scenarios 6; changed history orchestration 11. The entire revised Frontend production build passed and generated 62 static pages. The new Linux run exposed a missing required proc_open pipes argument; the helper and synthetic restore fixture were corrected and passed. [Changed encrypted history tools QA: PASS](https://github.com/mptech96/salb/actions/runs/40889610935); this synthetic check did not repeat the actual accepted VPS snapshot/restore or schema-only rehearsal.

Accounting recording remains BLOCKED: no actual cost attestation or separate account-mapping decision was received. Private authority identities/references and the matching authority-file SHA must be registered before the existing-archive closure command can run. The one post-approval command is `python3 ops/sulb-rc/close-approved-history.py e6591276adf1a442406ac2c810f6e9c5f3b9f2b6f5ffbe4ca04bd036cc5a23f8 --review-writers` from the reviewed VPS checkout. It fails before restore when either decision is absent; when authorized it uses the existing encrypted archive only, runs the affected approved-history/derived checks in the owned instance, then collects read-only private writer metadata. It neither enables deployment nor approves that writer profile automatically. Raw authority documents, accounting values, archives, keys and profiles stay off GitHub/chat.

The next human decisions are the two accounting approvals, the actual writer/maintenance review (including distinct explicit permission for temporary account locks, without password/grant changes), and final deployment authorization after the real isolated result. Actual archive validation and live writer qualification are still required evidence, not facts inferred from CI. No qualified production deployment command is issued while these gates are absent. The full deployment entry is prepared and refuses NO-GO before any application bootstrap or live side effect. No production connection, production DML, production migration or deploy occurred during this work. **Final decision: NO-GO.**


## الإغلاق التشغيلي قبل النشر — 2026-10-29

المرجع: e3fbe330e8772a5ed0095faabda4feb6d0513f68. القرار **NO-GO**. لم يتوفر وصول مباشر إلى VPS، ولم تُشغّل أوامر عليه ولم يحدث استخراج صفوف أو Backfill أو ترحيل أو نشر.

أُكمل وضع القراءة الموجود `release-fence.php --review-profile`، دون إنشاء مسار نشر بديل. يستخدم اتصال الإدارة المحلي الآمن المتاح مسبقًا في CloudPanel في الذاكرة، ثم معاملة READ ONLY لاستعلامات الحسابات والصلاحيات والأدوار والعمليات فقط. لا تُعرض بيانات الاعتماد أو نصوص SQL الجارية. يُحفظ ملف خاص root:0700/0600 خارج htdocs؛ يظهر في Terminal المسار والبصمة والعدادات فقط. لا تُنفذ أوامر Artisan ولا تُحمّل ملفات إعدادات Laravel ككود.

المجال الذي يجمعه المشغّل عند تشغيله بواسطة المشغّل المخوّل: الحسابات القادرة على الكتابة، الجلسات المرئية دون SQL/Host، عمليات PHP/FPM/Artisan المرئية، تعريفات Cron وsystemd timers/drop-ins وSupervisor وFPM كبصمات وتصنيفات بلا أوامر خام، تصريحات إعدادات Queue/Cache/Session المسموح عرضها فقط، ووجود config cache. القيم المخصصة أو الديناميكية لا تُنشر. القائمة المسموح بها في Manifest فارغة وغير معتمدة؛ المقارنة تصفها NOT_CONFIGURED ولا تملؤها أو تعتمدها تلقائيًا. وجود أدوار أو منفذات عبر قواعد أخرى غير مراجعة يبقى مانعًا صريحًا.

ما فُحص فعليًا محليًا: سلامة بصمات ملفات الكتابة الـ115 في الإصدار المرشح، وتصنيف/حجب أسرار باستخدام ملفات إعدادات صناعية لم تُنفّذ. ليست هذه إثباتًا للكتّاب الأحياء. لم تُفحص فعليًا حسابات VPS أو FPM أو Cron أو Queues أو إعدادات Laravel الفعّالة في هذه الجولة. الإعدادات المخبأة والديناميكية والبرامج الفرعية والكتّاب المتوقفون أو الخارجيون تحتاج مراجعة فعلية؛ الأداة تبقيها unverified. لا يُعتبر عدم ظهور عملية إثباتًا لغيابها.

العزل بين الشركات: تُعتمد نتائج Percona السابقة الخاصة بالشركة الأخرى والتراجع وعدم التكرار دون إعادة. لم يتغير كود الأعمال أو نطاق شركة 3 والحساب 180؛ لا يُدعى إثبات عزل إنتاجي جديد. الاختبارات الجديدة تختص بوضع القراءة والسياسات فقط.

أُضيف نموذجان غير موقّعين: accounting-cost-unsigned.json للحالات الخمس، وaccounting-mapping-unsigned.json لربط الحساب 180 بالشركة 3 وحدها. الدليل الإرشادي accounting-forms-ar.md يحدد النص والنطاق وكيفية العرض الخاص. كلاهما يحمل بصمة الأدلة المعتمدة ويترك الهوية والتاريخ والمرجع والقرار والتوقيع فارغًا. يُشترط تاريخ قرار UTC صحيح غير مستقبلي وبصمة وثيقته الموقعة عند التسجيل الفعلي، مع فصل هوية المخوّلين وسجل مستقل لكل قرار. لا تمثل بصمة الوثيقة تحققًا تشفيريًا من هوية الموقّع.

جاهزية Backfill: الكود مؤهل ضمن دليل QA السابق؛ الأرشيف الحقيقي غير مشغّل، وتظل بوابتاه المحاسبيتان مقفلتين. المطلوب من المشغّل الآن تشغيل الفحص للقراءة فقط، ثم مراجعة الملف الخاص محليًا. تُعاد إلى المحادثة العدادات والحالة والبصمة فقط؛ لا يُرسل الملف الخام أو النماذج الموقّعة أو الأسرار. المطلوب من المخوّلين إصدار القرارين بصورة مستقلة إن وافقا. بعدهما تبقى تجربة الأرشيف المعزولة واعتماد ملف الكتّاب والصيانة ثم تصريح النشر المستقل. لا يوجد تصريح نشر أو قفل حسابات في هذه الجولة.


نتيجة الاختبارات الخاصة بالتعديل: [CI PASS](https://github.com/mptech96/salb/actions/runs/40918442913)، للكود 8426419c8fba3b9932533176f0553d04f07a1125. نجحت 13 حالة لتصنيف الكتّاب وحجب الأسرار وفحص ملفات الإعدادات كبيانات دون تنفيذ، و4 حالات إضافية لرفض التاريخ أو بصمة التوقيع غير الصالحين. نجحت السياسات المتأثرة على Linux؛ لا تُضاف هذه كدليل فحص VPS. لم يُعد تشغيل Schema-only أو نسخ الأرشيف الحقيقي أو استعادته أو Backfill الحقيقي.

مراجعة المصدر التشغيلية: routes/console.php يعرّف subscriptions:expire يوميًا عند 00:05 مع withoutOverlapping(30). لا يوجد مجلد app/Jobs مخصص ولا نتائج ShouldQueue أو dispatch في app/routes ضمن البحث المنفذ؛ لا يعني ذلك غياب Workers إطار العمل أو مهام على VPS. أمر sulb:demo-install يمثل مسار CLI قادرًا على الكتابة ويجب إبقاؤه ضمن حراسة الصيانة. خدمات المخزون والمحاسبة والمشتريات والمبيعات والشحنات والمعالجة والرسملة ما زالت ضمن جرد مصدر الكتابة الموجود؛ لم تُعد مراجعة أعمال ERP أو اختبارات QA الناجحة. الخلاصة التشغيلية تبقى غير محسومة حتى وصول نتيجة الفحص الخاص، ولا تُعتمد قائمة حسابات أو عزل إنتاجي بالاستنتاج.


## مراجعة كتّاب الإنتاج — إغلاق نواقص الربط

اعتمدت نتائج المشغّل للـCommit 14c4349: 9 حسابات، منها 5 قادرة على الكتابة و4 عامة/إدارية، و4 مقفلة؛ 115 ملف مصدر مطابقًا و324 ملف سطح تشغيل؛ دون قراءة صفوف أعمال أو تغيير قاعدة/خدمات. لا تُجمع هذه الفئات باعتبارها منفصلة، ولا تُعتمد الحسابات الخمسة تلقائيًا. لم يتوفر الملف الخاص نفسه لهذه الجلسة، ولم يُنفذ اتصال VPS من هنا.

أسباب عدم الاكتمال: الأداة السابقة جمعت البصمات وتصريحات الإعدادات لا الإعدادات الفعالة؛ لم تربط user@host مصادقًا بخدمة أو Worker؛ جرد العمليات لحظي؛ أوامر البرامج المتفرعة والكتّاب الخارجيون والامتيازات الإدارية تحتاج مراجعة؛ allowlist لم تُعتمد. لذلك تبقى effective_configuration_verified وsource_inventory_is_exhaustive_live_proof وexternal_writers_and_schedules_reviewed على false.

أُكمل الأمر الحالي --review-profile بأدلة الصلاحيات المحددة، وقراءة literal آمنة لـconfig cache دون تنفيذ، وروابط مرشحة بين الحسابات وقرائن الخدمات والمهام وPID/UID/cgroup. أي cache غير literal يبقى غير مثبت ولا يُستبدل ضمنيًا بـ.env. يُنتج الملف الخاص قائمة لكل حساب وصلاحية وخدمة مع مبرر ومالك وقرار ومرجع وتاريخ فارغة؛ لا موافقات مصطنعة. أسماء الحسابات والمضيف وكلمات المرور ونصوص الأوامر لا تظهر في ملخص المشاركة. أسماء الحسابات المراجعة تبقى داخل ملف خاص فقط؛ لا تُنسخ قيم كلمات المرور أو DSN أو host حتى إليه.

اختبارات محلية جديدة: 27 حالة لقراءة literal ورفض تنفيذ الكود والغموض بين user@host وحجب الأسرار وبقاء الموافقات معلّقة. لم تُعد اختبارات ERP أو VPS أو Schema-only أو الأرشيف الحقيقي. دليل المراجعة الخاص وخانات القرار: ops/sulb-rc/writer-linkage-review-ar.md. الخطوة التالية تشغيل القراءة الإضافية ثم مراجعة المسؤول للروابط والامتيازات والبرامج المتفرعة؛ لا تغيير إنتاجي. الإصدار NO-GO حتى الدليل الفعلي والاعتمادات والتجربة التاريخية المطلوبة.

أضيف اختباران لملخص سطح التشغيل المحدود: تُحفظ بصمات جميع الملفات وعدّ الأسطر غير المصنّفة، ولا يُعامل اختصار التقرير أو تطابق المصدر كإثبات مراجعة تلك الأسطر. لا تُرفع حدود حجم ملف الاعتماد ولا تُخفف حواجز المراجعة.
