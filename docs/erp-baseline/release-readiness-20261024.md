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
