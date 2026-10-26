CREATE TABLE uom_units(id BIGINT UNSIGNED PRIMARY KEY,code VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,name_ar VARCHAR(100) NOT NULL,dimension VARCHAR(12) NOT NULL,standard_factor DECIMAL(18,6) NULL,allow_fractional BOOLEAN NOT NULL DEFAULT 1,kind VARCHAR(16) NOT NULL DEFAULT 'STANDARD',CHECK(dimension IN ('COUNT','MASS','LENGTH','AREA','VOLUME')),CHECK(kind IN ('STANDARD','ITEM_PACKAGING')),CHECK((kind='STANDARD' AND standard_factor>0) OR (kind='ITEM_PACKAGING' AND standard_factor IS NULL))) ENGINE=InnoDB;
-- statement
ALTER TABLE items ADD UNIQUE KEY uq_uom_item_scope(company_id,id),ADD base_uom_id BIGINT UNSIGNED NULL,ADD inventory_quantity_version VARCHAR(12) NOT NULL DEFAULT 'LEGACY_KG',ADD CONSTRAINT fk_uom_item_base FOREIGN KEY(base_uom_id) REFERENCES uom_units(id) ON DELETE RESTRICT,ADD CHECK(inventory_quantity_version IN ('LEGACY_KG','BASE_V1'));
-- statement
CREATE TABLE item_uom_mappings(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,item_id BIGINT UNSIGNED NOT NULL,unit_id BIGINT UNSIGNED NOT NULL,base_unit_id BIGINT UNSIGNED NOT NULL,revision INT UNSIGNED NOT NULL,factor_to_base DECIMAL(18,6) NOT NULL,purchase_allowed BOOLEAN NOT NULL DEFAULT 1,sale_allowed BOOLEAN NOT NULL DEFAULT 1,UNIQUE(company_id,id),UNIQUE(company_id,item_id,unit_id,revision),UNIQUE(company_id,item_id,id),CHECK(revision>0 AND factor_to_base>0),FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE RESTRICT,FOREIGN KEY(company_id,item_id) REFERENCES items(company_id,id) ON DELETE RESTRICT,FOREIGN KEY(unit_id) REFERENCES uom_units(id) ON DELETE RESTRICT,FOREIGN KEY(base_unit_id) REFERENCES uom_units(id) ON DELETE RESTRICT) ENGINE=InnoDB;
-- statement
CREATE TABLE item_uom_heads(company_id BIGINT NOT NULL,item_id BIGINT UNSIGNED NOT NULL,unit_id BIGINT UNSIGNED NOT NULL,revision INT UNSIGNED NOT NULL,PRIMARY KEY(company_id,item_id,unit_id),FOREIGN KEY(company_id,item_id,unit_id,revision) REFERENCES item_uom_mappings(company_id,item_id,unit_id,revision) ON DELETE RESTRICT) ENGINE=InnoDB;
-- statement
CREATE TABLE item_uom_prices(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT NOT NULL,mapping_id BIGINT UNSIGNED NOT NULL,currency_code VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,revision INT UNSIGNED NOT NULL,purchase_price DECIMAL(24,6) NULL,sale_price DECIMAL(24,6) NULL,UNIQUE(company_id,id),UNIQUE(company_id,mapping_id,currency_code,revision),CHECK(revision>0 AND (purchase_price IS NULL OR purchase_price>=0) AND (sale_price IS NULL OR sale_price>=0)),FOREIGN KEY(company_id,mapping_id) REFERENCES item_uom_mappings(company_id,id) ON DELETE RESTRICT,FOREIGN KEY(currency_code) REFERENCES currencies(currency_code) ON DELETE RESTRICT) ENGINE=InnoDB;
-- statement
CREATE TABLE item_uom_price_heads(company_id BIGINT NOT NULL,mapping_id BIGINT UNSIGNED NOT NULL,currency_code VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,revision INT UNSIGNED NOT NULL,PRIMARY KEY(company_id,mapping_id,currency_code),FOREIGN KEY(company_id,mapping_id,currency_code,revision) REFERENCES item_uom_prices(company_id,mapping_id,currency_code,revision) ON DELETE RESTRICT) ENGINE=InnoDB;
-- statement
CREATE TRIGGER uom_mapping_insert BEFORE INSERT ON item_uom_mappings FOR EACH ROW
BEGIN
 DECLARE ud VARCHAR(12); DECLARE bd VARCHAR(12); DECLARE sf DECIMAL(18,6); DECLARE bf DECIMAL(18,6); DECLARE k VARCHAR(16); DECLARE expected BIGINT UNSIGNED;
 SELECT dimension,standard_factor,kind INTO ud,sf,k FROM uom_units WHERE id=NEW.unit_id;
 SELECT dimension,standard_factor INTO bd,bf FROM uom_units WHERE id=NEW.base_unit_id;
 SELECT base_uom_id INTO expected FROM items WHERE company_id=NEW.company_id AND id=NEW.item_id;
 IF expected IS NULL AND EXISTS(SELECT 1 FROM items WHERE company_id=NEW.company_id AND id=NEW.item_id AND base_unit_code='KG' AND inventory_quantity_version='LEGACY_KG') THEN SELECT id INTO expected FROM uom_units WHERE code='KG'; END IF;
 IF expected IS NULL OR expected<>NEW.base_unit_id OR ud IS NULL OR bd IS NULL OR ud<>bd THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_BASE_OR_DIMENSION_MISMATCH'; END IF;
 IF NEW.unit_id=NEW.base_unit_id AND NEW.factor_to_base<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_BASE_FACTOR_ONE'; END IF;
 IF bf IS NULL OR (k='STANDARD' AND NEW.factor_to_base*bf<>sf) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_STANDARD_FACTOR_MISMATCH'; END IF;
END;
-- statement
CREATE TRIGGER uom_mapping_immutable BEFORE UPDATE ON item_uom_mappings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_MAPPING_APPEND_REVISION_ONLY';
-- statement
CREATE TRIGGER uom_price_immutable BEFORE UPDATE ON item_uom_prices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_PRICE_APPEND_REVISION_ONLY';
-- statement
CREATE TRIGGER uom_unit_used_update BEFORE UPDATE ON uom_units FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM item_uom_mappings WHERE unit_id=OLD.id OR base_unit_id=OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_USED_DICTIONARY_IMMUTABLE'; END IF; END;
-- statement
CREATE TRIGGER uom_item_used_base BEFORE UPDATE ON items FOR EACH ROW
BEGIN IF NOT(NEW.base_uom_id<=>OLD.base_uom_id) OR NOT(NEW.base_unit_code<=>OLD.base_unit_code) THEN IF EXISTS(SELECT 1 FROM inventory_lots WHERE item_id=OLD.id AND company_id=OLD.company_id) OR EXISTS(SELECT 1 FROM stock_movements WHERE item_id=OLD.id AND company_id=OLD.company_id) OR EXISTS(SELECT 1 FROM purchase_invoice_lines WHERE item_id=OLD.id AND company_id=OLD.company_id) OR EXISTS(SELECT 1 FROM sales_invoice_lines WHERE item_id=OLD.id AND company_id=OLD.company_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_USED_ITEM_BASE_IMMUTABLE'; END IF; END IF; END;
-- statement
ALTER TABLE items ADD CHECK(inventory_quantity_version='LEGACY_KG' OR base_uom_id IS NOT NULL);
-- statement
ALTER TABLE branches ADD UNIQUE KEY uq_uom_branch_scope(company_id,id);
-- statement
CREATE TRIGGER uom_price_currency BEFORE INSERT ON item_uom_prices FOR EACH ROW
BEGIN IF NOT EXISTS(SELECT 1 FROM company_currencies WHERE company_id=NEW.company_id AND BINARY currency_code=BINARY NEW.currency_code AND is_active=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_PRICE_CURRENCY_SCOPE'; END IF; END;
-- statement
ALTER TABLE uom_units ADD CHECK(kind<>'STANDARD' OR standard_factor IS NOT NULL);
-- statement
ALTER TABLE item_uom_prices ADD CHECK(purchase_price IS NOT NULL OR sale_price IS NOT NULL);
