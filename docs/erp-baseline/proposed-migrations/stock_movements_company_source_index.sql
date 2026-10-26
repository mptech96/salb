-- REVIEW ONLY: not executed on development or production.
-- First inspect SHOW INDEX and engine/version/DDL permissions/space on the target.
-- Skip equivalent full-column prefix; reject conflicting same-name definition.
CREATE INDEX idx_stock_company_source
    ON stock_movements (company_id, source_type, source_id);
-- Rollback removes the index only, never data. It restores the broad-scan risk;
-- do not roll back while relying on independent-company posting without mitigation.
