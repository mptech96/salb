-- Review/isolated-test proposal only. Not an operational migration.
CREATE TABLE worker_operation_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 worker_id BIGINT NOT NULL,
 operation VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 hash_version SMALLINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 loan_id BIGINT UNSIGNED NULL,
 commission_id BIGINT UNSIGNED NULL,
 result_json LONGTEXT NOT NULL,
 created_at TIMESTAMP NOT NULL,
 UNIQUE KEY worker_request_identity(company_id,operation,request_key),
 UNIQUE KEY worker_request_loan(loan_id),
 UNIQUE KEY worker_request_commission(operation,commission_id),
 KEY worker_request_worker(company_id,worker_id,created_at),
 FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE RESTRICT,
 FOREIGN KEY(branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
 FOREIGN KEY(worker_id) REFERENCES workers(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(loan_id) REFERENCES worker_loans(id) ON DELETE RESTRICT,
 FOREIGN KEY(commission_id) REFERENCES worker_commissions(id) ON DELETE RESTRICT,
 CONSTRAINT worker_request_kind CHECK (
 (operation='CREATE_LOAN' AND loan_id IS NOT NULL AND commission_id IS NULL) OR
 (operation IN ('CREATE_COMMISSION','APPROVE_COMMISSION','PAY_COMMISSION') AND loan_id IS NULL AND commission_id IS NOT NULL)),
 CONSTRAINT worker_request_json CHECK (JSON_VALID(result_json)),
 CONSTRAINT worker_request_hash_version CHECK (hash_version=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
