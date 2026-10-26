-- REVIEW TEMPLATE ONLY. NOT an operational migration or executable rollout tool.
-- Named parameters require a reviewed, parameterized offline executor.
-- REQUIRED before BEGIN: backup verified; ALL inventory writers stopped;
-- approved company/year manifest; duplicates/custom/year-conflict/orphan checks
-- passed; external-reference owner attestation. Unknown cases BLOCK the scope.
-- Prototype implementation tested separately in LotSequencePreparationProof.php.
START TRANSACTION;
INSERT INTO sulb_document_sequences
 (company_id,branch_id,document_type,document_year,next_number,created_at,updated_at)
SELECT :company_id,0,'INVENTORY_LOT',:namespace_year,
 GREATEST(1,
  (SELECT COUNT(*)+1 FROM inventory_lots WHERE company_id=:count_company AND YEAR(received_at)=:received_year),
  COALESCE((SELECT MAX(CAST(SUBSTRING(lot_number,10) AS UNSIGNED))+1 FROM inventory_lots
   WHERE company_id=:lot_company AND BINARY lot_number REGEXP CONCAT('^LOT-',:lot_year,'-[0-9]{6,18}$')),1),
  COALESCE((SELECT MAX(CAST(SUBSTRING(l.lot_number,10) AS UNSIGNED))+1
   FROM opening_inventory_lines l JOIN opening_balance_batches b ON b.id=l.batch_id AND b.company_id=l.company_id
   WHERE l.company_id=:opening_company AND BINARY l.lot_number REGEXP CONCAT('^LOT-',:opening_year,'-[0-9]{6,18}$')),1)),
 CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
ON DUPLICATE KEY UPDATE
 next_number=GREATEST(next_number,VALUES(next_number));
SELECT company_id,branch_id,document_type,document_year,next_number
FROM sulb_document_sequences
WHERE company_id=:verify_company AND branch_id=0 AND document_type='INVENTORY_LOT' AND document_year=:verify_year
FOR UPDATE;
-- Executor must compare this result with the approved manifest before COMMIT.
-- Roll back on ANY mismatch; do not silently approve a scope.
COMMIT;
-- No historical update, no lowering/reset, no DROP. Never run while writers live.
