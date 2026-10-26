-- REVIEW ONLY. Execute preflight and verify the production engine before approval.
ALTER TABLE vouchers ADD UNIQUE KEY uq_vouchers_company_number (company_id,voucher_number);
