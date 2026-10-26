-- REVIEW / ISOLATED TEST ONLY. Not a Laravel operational migration.
-- NOT STAGING READY: ORIGIN provenance, shared-account ambiguity and global lock order remain blocked.
-- statement
CREATE TABLE shipment_cost_settlement_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 driver_id BIGINT UNSIGNED NOT NULL,
 shipment_cost_id BIGINT UNSIGNED NOT NULL,
 original_journal_id BIGINT UNSIGNED NOT NULL,
 original_line_id BIGINT UNSIGNED NOT NULL,
 original_account_id BIGINT UNSIGNED NOT NULL,
 operation VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_key VARBINARY(90) NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 hash_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 amount DECIMAL(15,3) NOT NULL,
 currency_code VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 financial_account_id BIGINT UNSIGNED NULL,
 effective_date DATE NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 actor_name VARCHAR(255) NOT NULL,
 status VARCHAR(10) NOT NULL,
 voucher_id BIGINT NULL,
 journal_entry_id BIGINT UNSIGNED NULL,
 paid_after DECIMAL(15,3) NULL,
 remaining_after DECIMAL(15,3) NULL,
 created_at DATETIME(6) NOT NULL,
 completed_at DATETIME(6) NULL,
 UNIQUE KEY scsr_request (company_id,operation,request_key),
 KEY scsr_origin (company_id,shipment_cost_id,operation),
 KEY scsr_scope (company_id,branch_id,driver_id,original_account_id),
 FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE RESTRICT,
 FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
 FOREIGN KEY(driver_id) REFERENCES drivers(id) ON DELETE RESTRICT,
 FOREIGN KEY(shipment_cost_id) REFERENCES shipment_costs(id) ON DELETE RESTRICT,
 FOREIGN KEY(original_journal_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 FOREIGN KEY(original_line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT,
 FOREIGN KEY(original_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY(financial_account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(voucher_id) REFERENCES vouchers(id) ON DELETE RESTRICT,
 FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 CHECK(operation IN ('ORIGIN','PAYMENT')),
 CHECK(status IN ('PENDING','COMPLETED')),
 CHECK(OCTET_LENGTH(request_key) BETWEEN 1 AND 90),
 CHECK(hash_version=1 AND request_hash REGEXP '^[0-9a-f]{64}$'),
 CHECK(BINARY currency_code=BINARY 'SAR'),
 CHECK((operation='ORIGIN' AND amount=0 AND voucher_id IS NULL AND financial_account_id IS NULL AND status='COMPLETED') OR (operation='PAYMENT' AND amount>0 AND MOD(amount*1000,10)=0 AND financial_account_id IS NOT NULL)),
 CHECK(status<>'COMPLETED' OR (journal_entry_id IS NOT NULL AND completed_at IS NOT NULL AND paid_after IS NOT NULL AND remaining_after IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- statement
CREATE TABLE shipment_cost_settlement_allocations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 company_id BIGINT NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 driver_id BIGINT UNSIGNED NOT NULL,
 shipment_cost_id BIGINT UNSIGNED NOT NULL,
 original_journal_id BIGINT UNSIGNED NOT NULL,
 original_line_id BIGINT UNSIGNED NOT NULL,
 original_account_id BIGINT UNSIGNED NOT NULL,
 voucher_id BIGINT NOT NULL,
 payment_journal_id BIGINT UNSIGNED NOT NULL,
 payment_line_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(15,3) NOT NULL,
 currency_code VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 effective_date DATE NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL,
 UNIQUE KEY scsa_request(request_id),
 UNIQUE KEY scsa_voucher(voucher_id),
 UNIQUE KEY scsa_payment_line(payment_line_id),
 KEY scsa_remaining(company_id,shipment_cost_id),
 KEY scsa_statement(company_id,branch_id,driver_id,effective_date),
 FOREIGN KEY(request_id) REFERENCES shipment_cost_settlement_requests(id) ON DELETE RESTRICT,
 FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE RESTRICT,
 FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
 FOREIGN KEY(driver_id) REFERENCES drivers(id) ON DELETE RESTRICT,
 FOREIGN KEY(shipment_cost_id) REFERENCES shipment_costs(id) ON DELETE RESTRICT,
 FOREIGN KEY(original_journal_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 FOREIGN KEY(original_line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT,
 FOREIGN KEY(original_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY(voucher_id) REFERENCES vouchers(id) ON DELETE RESTRICT,
 FOREIGN KEY(payment_journal_id) REFERENCES journal_entries(id) ON DELETE RESTRICT,
 FOREIGN KEY(payment_line_id) REFERENCES journal_entry_lines(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT,
 CHECK(amount>0 AND MOD(amount*1000,10)=0 AND BINARY currency_code=BINARY 'SAR')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- statement
CREATE TRIGGER scsr_insert BEFORE INSERT ON shipment_cost_settlement_requests FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM shipment_costs c JOIN shipments s ON s.id=c.shipment_id JOIN drivers d ON d.id=NEW.driver_id
   JOIN journal_entries e ON e.id=NEW.original_journal_id JOIN journal_entry_lines l ON l.id=NEW.original_line_id JOIN accounts a ON a.id=NEW.original_account_id JOIN users u ON u.id=NEW.actor_id
   WHERE c.id=NEW.shipment_cost_id AND c.company_id=NEW.company_id AND c.branch_id=NEW.branch_id AND s.company_id=NEW.company_id AND s.branch_id=NEW.branch_id
   AND c.cost_status='POSTED' AND c.payment_status='UNPAID' AND c.payee_type='DRIVER' AND c.payee_id=NEW.driver_id AND c.journal_entry_id=e.id
   AND d.company_id=NEW.company_id AND (d.branch_id IS NULL OR d.branch_id=NEW.branch_id)
   AND u.company_id=NEW.company_id AND (u.branch_id IS NULL OR u.branch_id=NEW.branch_id) AND u.is_active=1
   AND a.company_id=NEW.company_id AND l.account_id=a.id AND l.company_id=NEW.company_id AND l.branch_id=NEW.branch_id AND l.journal_entry_id=e.id AND l.party_type='DRIVER' AND l.party_id=NEW.driver_id AND l.credit=c.amount AND l.debit=0 AND l.financial_account_id IS NULL
   AND e.company_id=NEW.company_id AND e.branch_id=NEW.branch_id AND e.source_type='SHIPMENT_COST' AND e.source_id=c.id AND e.status='POSTED' AND e.reversed_by_id IS NULL)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid original scope/evidence'; END IF;
 IF NEW.operation='ORIGIN' AND NEW.request_key<>CONCAT('origin:',NEW.shipment_cost_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Canonical origin key required'; END IF;
 IF NEW.operation='ORIGIN' AND (NOT(NEW.journal_entry_id<=>NEW.original_journal_id) OR NOT(NEW.paid_after<=>0) OR NOT(NEW.remaining_after<=>(SELECT amount FROM shipment_costs WHERE id=NEW.shipment_cost_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid origin result snapshot'; END IF;
 IF NEW.operation='PAYMENT' AND NOT EXISTS(SELECT 1 FROM financial_accounts f WHERE f.id=NEW.financial_account_id AND f.company_id=NEW.company_id AND (f.branch_id IS NULL OR f.branch_id=NEW.branch_id) AND f.currency_code=NEW.currency_code) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Foreign financial account'; END IF;
 IF NEW.operation='PAYMENT' AND (NEW.status<>'PENDING' OR NOT EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r WHERE r.company_id=NEW.company_id AND r.branch_id=NEW.branch_id AND r.driver_id=NEW.driver_id AND r.shipment_cost_id=NEW.shipment_cost_id AND r.original_journal_id=NEW.original_journal_id AND r.original_line_id=NEW.original_line_id AND r.original_account_id=NEW.original_account_id AND BINARY r.currency_code=BINARY NEW.currency_code AND r.operation='ORIGIN' AND r.request_key=CONCAT('origin:',NEW.shipment_cost_id) AND r.status='COMPLETED')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled origin required'; END IF;
END;
-- statement
CREATE TRIGGER scsa_insert BEFORE INSERT ON shipment_cost_settlement_allocations FOR EACH ROW
BEGIN
 DECLARE original_amount DECIMAL(15,3);
 SELECT amount INTO original_amount FROM shipment_costs WHERE id=NEW.shipment_cost_id FOR UPDATE;
 IF NOT EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r JOIN vouchers v ON v.id=NEW.voucher_id JOIN journal_entries e ON e.id=NEW.payment_journal_id JOIN journal_entry_lines l ON l.id=NEW.payment_line_id
 WHERE r.id=NEW.request_id AND r.operation='PAYMENT' AND r.status='PENDING' AND r.company_id=NEW.company_id AND r.branch_id=NEW.branch_id AND r.driver_id=NEW.driver_id AND r.shipment_cost_id=NEW.shipment_cost_id AND r.original_journal_id=NEW.original_journal_id AND r.original_line_id=NEW.original_line_id AND r.original_account_id=NEW.original_account_id AND r.amount=NEW.amount AND r.currency_code=NEW.currency_code AND r.effective_date=NEW.effective_date AND r.actor_id=NEW.actor_id AND r.voucher_id=v.id
 AND v.company_id=NEW.company_id AND v.branch_id=NEW.branch_id AND v.reference_type='SHIPMENT_COST_SETTLEMENT' AND v.reference_id=NEW.shipment_cost_id AND v.amount=NEW.amount AND v.journal_entry_id=e.id
 AND e.company_id=NEW.company_id AND e.branch_id=NEW.branch_id AND e.source_type='DRIVER_SHIPMENT_SETTLEMENT' AND e.source_id=r.id AND e.status='POSTED'
 AND l.journal_entry_id=e.id AND l.company_id=NEW.company_id AND l.branch_id=NEW.branch_id AND l.account_id=NEW.original_account_id AND l.party_type='DRIVER' AND l.party_id=NEW.driver_id AND l.debit=NEW.amount AND l.credit=0 AND l.financial_account_id IS NULL)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid settlement allocation scope'; END IF;
 IF NEW.amount+COALESCE((SELECT SUM(amount) FROM shipment_cost_settlement_allocations WHERE shipment_cost_id=NEW.shipment_cost_id),0)>original_amount THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Allocation overpayment'; END IF;
END;
-- statement
CREATE TRIGGER scsr_update BEFORE UPDATE ON shipment_cost_settlement_requests FOR EACH ROW
BEGIN
 IF NEW.hash_version<>OLD.hash_version OR BINARY NEW.actor_name<>BINARY OLD.actor_name OR NEW.created_at<>OLD.created_at THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable request metadata'; END IF;
 IF OLD.status='COMPLETED' OR NEW.company_id<>OLD.company_id OR NEW.branch_id<>OLD.branch_id OR NEW.driver_id<>OLD.driver_id OR NEW.shipment_cost_id<>OLD.shipment_cost_id OR NEW.original_journal_id<>OLD.original_journal_id OR NEW.original_line_id<>OLD.original_line_id OR NEW.original_account_id<>OLD.original_account_id OR NEW.operation<>OLD.operation OR NEW.request_key<>OLD.request_key OR NEW.request_hash<>OLD.request_hash OR NEW.amount<>OLD.amount OR NEW.actor_id<>OLD.actor_id OR NEW.currency_code<>OLD.currency_code OR NEW.effective_date<>OLD.effective_date OR NOT(NEW.financial_account_id<=>OLD.financial_account_id)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Immutable request evidence'; END IF;
 IF NEW.status='COMPLETED' AND NOT EXISTS(SELECT 1 FROM shipment_cost_settlement_allocations a JOIN journal_entry_lines l ON l.id=a.payment_line_id JOIN journal_entries e ON e.id=l.journal_entry_id WHERE a.request_id=NEW.id AND a.voucher_id=NEW.voucher_id AND a.payment_journal_id=NEW.journal_entry_id AND l.journal_entry_id=NEW.journal_entry_id AND l.company_id=NEW.company_id AND l.branch_id=NEW.branch_id AND l.account_id=NEW.original_account_id AND l.party_type='DRIVER' AND l.party_id=NEW.driver_id AND l.debit=NEW.amount AND l.credit=0 AND l.financial_account_id IS NULL AND e.company_id=NEW.company_id AND e.branch_id=NEW.branch_id AND e.status='POSTED' AND e.source_type='DRIVER_SHIPMENT_SETTLEMENT' AND e.source_id=NEW.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Completed request requires matching allocation evidence'; END IF;
 IF NEW.status='COMPLETED' AND (NOT(NEW.paid_after<=>(SELECT COALESCE(SUM(amount),0) FROM shipment_cost_settlement_allocations WHERE company_id=NEW.company_id AND shipment_cost_id=NEW.shipment_cost_id)) OR NOT(NEW.remaining_after<=>(SELECT amount FROM shipment_costs WHERE id=NEW.shipment_cost_id)-NEW.paid_after)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid completed financial snapshot'; END IF;
 IF NEW.status='COMPLETED' AND ((SELECT COUNT(*) FROM journal_entry_lines WHERE journal_entry_id=NEW.journal_entry_id)<>2 OR (SELECT COALESCE(SUM(debit-credit),0) FROM journal_entry_lines WHERE journal_entry_id=NEW.journal_entry_id)<>0 OR NOT EXISTS(SELECT 1 FROM journal_entry_lines l JOIN financial_accounts f ON f.id=NEW.financial_account_id WHERE l.journal_entry_id=NEW.journal_entry_id AND l.company_id=NEW.company_id AND l.branch_id=NEW.branch_id AND l.account_id=f.gl_account_id AND l.financial_account_id=f.id AND l.credit=NEW.amount AND l.debit=0 AND l.party_type IS NULL)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid balanced payment counterpart'; END IF;
END;
-- statement
CREATE TRIGGER scsr_delete BEFORE DELETE ON shipment_cost_settlement_requests FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Request evidence cannot be deleted'; END;
-- statement
CREATE TRIGGER scsa_update BEFORE UPDATE ON shipment_cost_settlement_allocations FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Allocation immutable'; END;
-- statement
CREATE TRIGGER scsa_delete BEFORE DELETE ON shipment_cost_settlement_allocations FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Allocation cannot be deleted'; END;
-- statement
CREATE TRIGGER scs_gl_insert BEFORE INSERT ON journal_entry_lines FOR EACH ROW
BEGIN
 DECLARE company_lock BIGINT;
 SELECT id INTO company_lock FROM companies WHERE id=NEW.company_id FOR UPDATE;
 IF NEW.debit>0 AND EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r WHERE r.operation='ORIGIN' AND r.company_id=NEW.company_id AND r.branch_id=NEW.branch_id AND (NEW.party_type IS NULL OR (r.driver_id=NEW.party_id AND NEW.party_type='DRIVER')) AND r.original_account_id=NEW.account_id)
 AND NOT EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r JOIN journal_entries e ON e.id=NEW.journal_entry_id WHERE r.operation='PAYMENT' AND r.status='PENDING' AND e.source_type='DRIVER_SHIPMENT_SETTLEMENT' AND e.source_id=r.id AND e.company_id=r.company_id AND e.branch_id=r.branch_id AND NEW.company_id=r.company_id AND NEW.branch_id=r.branch_id AND NEW.party_type='DRIVER' AND NEW.party_id=r.driver_id AND NEW.account_id=r.original_account_id AND NEW.debit=r.amount AND NEW.credit=0 AND NEW.financial_account_id IS NULL)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unallocated controlled driver debit blocked'; END IF;
END;
-- statement
CREATE TRIGGER scs_gl_update BEFORE UPDATE ON journal_entry_lines FOR EACH ROW
BEGIN
 DECLARE company_lock BIGINT;
 SELECT id INTO company_lock FROM companies WHERE id=NEW.company_id FOR UPDATE;
 IF EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r WHERE r.original_journal_id=OLD.journal_entry_id OR r.journal_entry_id=OLD.journal_entry_id)
 OR EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r WHERE r.operation='ORIGIN' AND r.company_id=NEW.company_id AND r.branch_id=NEW.branch_id AND NEW.debit>0 AND (NEW.party_type IS NULL OR (NEW.party_type='DRIVER' AND r.driver_id=NEW.party_id)) AND r.original_account_id=NEW.account_id)
 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled GL immutable'; END IF;
END;
-- statement
CREATE TRIGGER scs_gl_delete BEFORE DELETE ON journal_entry_lines FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r WHERE r.original_journal_id=OLD.journal_entry_id OR r.journal_entry_id=OLD.journal_entry_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled GL cannot be deleted'; END IF;
END;
-- statement
CREATE TRIGGER scs_cost_update BEFORE UPDATE ON shipment_costs FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r WHERE r.shipment_cost_id=OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled cost immutable'; END IF;
END;
-- statement
CREATE TRIGGER scs_entry_update BEFORE UPDATE ON journal_entries FOR EACH ROW
BEGIN
 DECLARE company_lock BIGINT;
 SELECT id INTO company_lock FROM companies WHERE id=NEW.company_id FOR UPDATE;
 IF EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r WHERE r.original_journal_id=OLD.id OR r.journal_entry_id=OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Controlled journal immutable'; END IF;
 IF NEW.status='POSTED' AND EXISTS(SELECT 1 FROM journal_entry_lines l JOIN shipment_cost_settlement_requests r ON r.company_id=l.company_id AND r.branch_id=l.branch_id AND r.original_account_id=l.account_id AND r.operation='ORIGIN' WHERE l.journal_entry_id=OLD.id AND l.debit>0 AND (l.party_type IS NULL OR (l.party_type='DRIVER' AND l.party_id=r.driver_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Unallocated historical draft cannot post'; END IF;
END;
-- statement
CREATE TRIGGER scs_voucher_update BEFORE UPDATE ON vouchers FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM shipment_cost_settlement_requests r WHERE r.voucher_id=OLD.id AND r.status='COMPLETED') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Settlement voucher immutable'; END IF;
END;
