<?php
// Supplemental reviewed SQL: scoped to the three NEW tables only.
$sql=[];
$sql[]= <<<'SQL'
CREATE TRIGGER trg_ias_scope BEFORE INSERT ON invoice_accounting_snapshots FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM accounts WHERE id=NEW.party_account_id AND company_id=NEW.company_id)
 OR (NEW.branch_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM branches WHERE id=NEW.branch_id AND company_id=NEW.company_id))
 OR NOT EXISTS(SELECT 1 FROM journal_entries WHERE id=NEW.journal_entry_id AND company_id=NEW.company_id AND branch_id<=>NEW.branch_id AND status='POSTED' AND BINARY source_type=BINARY NEW.invoice_type AND source_id=COALESCE(NEW.sales_invoice_id,NEW.purchase_invoice_id))
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Snapshot source/company/branch mismatch'; END IF;
 IF NEW.invoice_type='SALE' THEN
  IF NOT EXISTS(SELECT 1 FROM sales_invoices WHERE id=NEW.sales_invoice_id AND company_id=NEW.company_id AND branch_id<=>NEW.branch_id AND customer_id=NEW.customer_id AND BINARY currency_code=BINARY NEW.currency_code AND exchange_rate=NEW.exchange_rate AND COALESCE(base_total_amount,total_amount)=NEW.base_total AND total_amount=NEW.foreign_total)
  OR NOT EXISTS(SELECT 1 FROM customers WHERE id=NEW.customer_id AND company_id=NEW.company_id)
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Sale snapshot source mismatch'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM purchase_invoices WHERE id=NEW.purchase_invoice_id AND company_id=NEW.company_id AND branch_id<=>NEW.branch_id AND supplier_id=NEW.supplier_id AND BINARY currency_code=BINARY NEW.currency_code AND exchange_rate=NEW.exchange_rate AND COALESCE(base_total_amount,total_amount)=NEW.base_total AND total_amount=NEW.foreign_total)
  OR NOT EXISTS(SELECT 1 FROM suppliers WHERE id=NEW.supplier_id AND company_id=NEW.company_id)
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Purchase snapshot source mismatch'; END IF;
 END IF;
 IF NOT EXISTS(SELECT 1 FROM journal_entry_lines WHERE journal_entry_id=NEW.journal_entry_id AND company_id=NEW.company_id AND account_id=NEW.party_account_id AND BINARY party_type=BINARY IF(NEW.invoice_type='SALE','CUSTOMER','SUPPLIER') AND party_id=COALESCE(NEW.customer_id,NEW.supplier_id))
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Original party GL evidence mismatch'; END IF;
END
SQL;
$sql[]= <<<'SQL'
CREATE TRIGGER trg_ilas_scope BEFORE INSERT ON invoice_line_accounting_snapshots FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM accounts WHERE id=NEW.account_id AND company_id=NEW.company_id)
 OR NOT EXISTS(SELECT 1 FROM items WHERE id=NEW.item_id AND company_id=NEW.company_id)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Line snapshot account/item scope mismatch'; END IF;
 IF NEW.sales_invoice_line_id IS NOT NULL THEN
  IF NOT EXISTS(SELECT 1 FROM invoice_accounting_snapshots s JOIN sales_invoice_lines l ON l.sales_invoice_id=s.sales_invoice_id WHERE s.id=NEW.snapshot_id AND s.company_id=NEW.company_id AND s.invoice_type='SALE' AND l.company_id=NEW.company_id AND l.id=NEW.sales_invoice_line_id AND l.item_id=NEW.item_id)
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Line snapshot source sale mismatch'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM invoice_accounting_snapshots s JOIN purchase_invoice_lines l ON l.purchase_invoice_id=s.purchase_invoice_id WHERE s.id=NEW.snapshot_id AND s.company_id=NEW.company_id AND s.invoice_type='PURCHASE' AND l.company_id=NEW.company_id AND l.id=NEW.purchase_invoice_line_id AND l.item_id=NEW.item_id)
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Line snapshot source purchase mismatch'; END IF;
 END IF;
 IF NEW.journal_entry_line_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM invoice_accounting_snapshots s JOIN journal_entry_lines l ON l.journal_entry_id=s.journal_entry_id WHERE s.id=NEW.snapshot_id AND s.company_id=NEW.company_id AND l.company_id=NEW.company_id AND l.id=NEW.journal_entry_line_id AND l.account_id=NEW.account_id AND l.debit>=NEW.original_debit AND l.credit>=NEW.original_credit)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Line snapshot grouped GL mismatch'; END IF;
 IF NEW.journal_entry_line_id IS NULL AND (NEW.accounting_role<>'RETURN_ACCOUNT' OR NEW.original_debit<>0 OR NEW.original_credit<>0)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only explicit return policy may lack original GL line'; END IF;
END
SQL;
$sql[]= <<<'SQL'
CREATE TRIGGER trg_via_scope BEFORE INSERT ON voucher_invoice_allocations FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM invoice_accounting_snapshots s JOIN vouchers v ON v.id=NEW.voucher_id WHERE s.id=NEW.snapshot_id AND s.company_id=NEW.company_id AND v.company_id=NEW.company_id AND s.branch_id<=>NEW.branch_id AND v.branch_id<=>NEW.branch_id AND BINARY v.reference_type=BINARY IF(s.invoice_type='SALE','CUSTOMER','SUPPLIER') AND v.reference_id=COALESCE(s.customer_id,s.supplier_id) AND BINARY NEW.currency_code=BINARY s.currency_code AND BINARY NEW.currency_code=BINARY v.currency_code AND NEW.exchange_rate=s.exchange_rate AND NEW.exchange_rate=v.exchange_rate)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Allocation invoice/voucher scope mismatch'; END IF;
 IF NEW.entry_kind='ALLOCATE' THEN
  IF NOT EXISTS(SELECT 1 FROM journal_entries WHERE id=NEW.journal_entry_id AND company_id=NEW.company_id AND branch_id<=>NEW.branch_id AND status='POSTED' AND source_type='VOUCHER' AND source_id=NEW.voucher_id)
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Allocation journal mismatch'; END IF;
 ELSE
  IF NOT EXISTS(SELECT 1 FROM voucher_invoice_allocations a JOIN journal_entries j ON j.reversal_of_id=a.journal_entry_id WHERE a.id=NEW.reverses_allocation_id AND a.company_id=NEW.company_id AND a.entry_kind='ALLOCATE' AND a.voucher_id=NEW.voucher_id AND a.snapshot_id=NEW.snapshot_id AND a.base_amount=NEW.base_amount AND a.foreign_amount=NEW.foreign_amount AND BINARY a.currency_code=BINARY NEW.currency_code AND j.id=NEW.journal_entry_id AND j.company_id=NEW.company_id AND j.status='POSTED')
  THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Allocation reversal evidence mismatch'; END IF;
 END IF;
END
SQL;
foreach(['invoice_accounting_snapshots'=>'ias','invoice_line_accounting_snapshots'=>'ilas','voucher_invoice_allocations'=>'via']as$table=>$short){
    foreach(['UPDATE','DELETE']as$action){
        $sql[]="CREATE TRIGGER trg_{$short}_immutable_".strtolower($action)." BEFORE {$action} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical evidence is append-only'";
    }
}
return $sql;
