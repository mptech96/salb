-- UOM-3A experiment only. Outside operational migrations. Parser splits -- statement.
-- Apply only through guarded disposable runner against verified checked-in schema.
-- statement
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
ALTER TABLE purchase_invoice_lines ADD uom_version VARCHAR(12) NOT NULL DEFAULT 'LEGACY_KG',ADD uom_mapping_id BIGINT UNSIGNED NULL,ADD base_uom_id_snapshot BIGINT UNSIGNED NULL,ADD base_quantity DECIMAL(24,6) NULL,ADD uom_unit_price_snapshot DECIMAL(24,6) NULL,ADD uom_price_revision_id BIGINT UNSIGNED NULL,ADD CONSTRAINT fk_uom_purchase_mapping FOREIGN KEY(company_id,item_id,uom_mapping_id) REFERENCES item_uom_mappings(company_id,item_id,id) ON DELETE RESTRICT,ADD CONSTRAINT fk_uom_purchase_price FOREIGN KEY(company_id,uom_price_revision_id) REFERENCES item_uom_prices(company_id,id) ON DELETE RESTRICT,ADD CHECK(uom_version IN ('LEGACY_KG','BASE_V1')),ADD CHECK(uom_version='LEGACY_KG' OR (uom_mapping_id IS NOT NULL AND base_uom_id_snapshot IS NOT NULL AND base_quantity>0 AND quantity>0 AND unit_factor_to_base>0 AND uom_unit_price_snapshot>=0 AND uom_price_revision_id IS NOT NULL AND qty_kg=0 AND base_quantity=quantity*unit_factor_to_base));
-- statement
ALTER TABLE sales_invoice_lines ADD uom_version VARCHAR(12) NOT NULL DEFAULT 'LEGACY_KG',ADD uom_mapping_id BIGINT UNSIGNED NULL,ADD base_uom_id_snapshot BIGINT UNSIGNED NULL,ADD base_quantity DECIMAL(24,6) NULL,ADD uom_unit_price_snapshot DECIMAL(24,6) NULL,ADD uom_price_revision_id BIGINT UNSIGNED NULL,ADD CONSTRAINT fk_uom_sale_mapping FOREIGN KEY(company_id,item_id,uom_mapping_id) REFERENCES item_uom_mappings(company_id,item_id,id) ON DELETE RESTRICT,ADD CONSTRAINT fk_uom_sale_price FOREIGN KEY(company_id,uom_price_revision_id) REFERENCES item_uom_prices(company_id,id) ON DELETE RESTRICT,ADD CHECK(uom_version IN ('LEGACY_KG','BASE_V1')),ADD CHECK(uom_version='LEGACY_KG' OR (uom_mapping_id IS NOT NULL AND base_uom_id_snapshot IS NOT NULL AND base_quantity>0 AND quantity>0 AND unit_factor_to_base>0 AND uom_unit_price_snapshot>=0 AND uom_price_revision_id IS NOT NULL AND qty_kg=0 AND base_quantity=quantity*unit_factor_to_base));
-- statement
ALTER TABLE inventory_lots ADD uom_version VARCHAR(12) NOT NULL DEFAULT 'LEGACY_KG',ADD uom_company_id BIGINT NULL,ADD uom_mapping_id BIGINT UNSIGNED NULL,ADD base_uom_id_snapshot BIGINT UNSIGNED NULL,ADD received_base_qty DECIMAL(24,6) NULL,ADD remaining_base_qty DECIMAL(24,6) NULL,ADD sold_base_qty DECIMAL(24,6) NULL,ADD unit_cost_per_base DECIMAL(24,6) NULL,ADD CONSTRAINT fk_uom_lot_mapping FOREIGN KEY(uom_company_id,item_id,uom_mapping_id) REFERENCES item_uom_mappings(company_id,item_id,id) ON DELETE RESTRICT,ADD KEY idx_uom_fifo(company_id,branch_id,item_id,uom_version,lot_status,received_at,id),ADD CHECK(uom_version IN ('LEGACY_KG','BASE_V1')),ADD CHECK(uom_version='LEGACY_KG' OR (uom_company_id=company_id AND uom_company_id IS NOT NULL AND uom_mapping_id IS NOT NULL AND base_uom_id_snapshot IS NOT NULL AND received_base_qty>0 AND remaining_base_qty>=0 AND remaining_base_qty<=received_base_qty AND sold_base_qty>=0 AND unit_cost_per_base>=0 AND qty_received_kg=0 AND qty_remaining_kg=0 AND qty_sold_kg=0));
-- statement
ALTER TABLE inventory_lot_movements ADD uom_version VARCHAR(12) NOT NULL DEFAULT 'LEGACY_KG',ADD uom_company_id BIGINT NULL,ADD base_quantity DECIMAL(24,6) NULL,ADD base_uom_id_snapshot BIGINT UNSIGNED NULL,ADD uom_mapping_id BIGINT UNSIGNED NULL,ADD unit_cost_per_base DECIMAL(24,6) NULL,ADD CONSTRAINT fk_uom_movement_mapping FOREIGN KEY(uom_company_id,item_id,uom_mapping_id) REFERENCES item_uom_mappings(company_id,item_id,id) ON DELETE RESTRICT,ADD CONSTRAINT fk_uom_movement_lot FOREIGN KEY(inventory_lot_id) REFERENCES inventory_lots(id) ON DELETE RESTRICT,ADD CHECK(uom_version IN ('LEGACY_KG','BASE_V1')),ADD CHECK(uom_version='LEGACY_KG' OR (uom_company_id=company_id AND uom_company_id IS NOT NULL AND uom_mapping_id IS NOT NULL AND base_quantity>0 AND base_uom_id_snapshot IS NOT NULL AND unit_cost_per_base>=0 AND qty_kg=0));
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
ALTER TABLE uom_units ADD CHECK(kind<>'STANDARD' OR standard_factor IS NOT NULL);
-- statement
ALTER TABLE item_uom_prices ADD CHECK(purchase_price IS NOT NULL OR sale_price IS NOT NULL);

-- statement
ALTER TABLE purchase_invoice_lines ADD CHECK(uom_version='LEGACY_KG' OR (company_id IS NOT NULL AND quantity IS NOT NULL AND unit_code IS NOT NULL AND unit_factor_to_base IS NOT NULL AND base_quantity IS NOT NULL AND uom_unit_price_snapshot IS NOT NULL));
-- statement
CREATE TRIGGER uom_purchase_insert BEFORE INSERT ON purchase_invoice_lines FOR EACH ROW
BEGIN
 DECLARE f DECIMAL(18,6); DECLARE b BIGINT UNSIGNED; DECLARE code_value VARCHAR(20); DECLARE frac BOOLEAN;
 IF NEW.uom_version='BASE_V1' THEN
  SELECT m.factor_to_base,m.base_unit_id,u.code,bu.allow_fractional INTO f,b,code_value,frac FROM item_uom_mappings m JOIN uom_units u ON u.id=m.unit_id JOIN uom_units bu ON bu.id=m.base_unit_id WHERE m.id=NEW.uom_mapping_id AND m.company_id=NEW.company_id AND m.item_id=NEW.item_id;
  IF f IS NULL OR NEW.unit_factor_to_base<>f OR NEW.base_uom_id_snapshot<>b OR BINARY NEW.unit_code<>BINARY code_value OR (frac=0 AND NEW.base_quantity<>FLOOR(NEW.base_quantity)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_SNAPSHOT_MISMATCH'; END IF;
  IF NOT EXISTS(SELECT 1 FROM purchase_invoices WHERE id=NEW.purchase_invoice_id AND company_id=NEW.company_id) OR NOT EXISTS(SELECT 1 FROM item_uom_prices WHERE id=NEW.uom_price_revision_id AND company_id=NEW.company_id AND mapping_id=NEW.uom_mapping_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_DOCUMENT_PRICE_SCOPE'; END IF;
 END IF;
END;
-- statement
CREATE TRIGGER uom_purchase_immutable BEFORE UPDATE ON purchase_invoice_lines FOR EACH ROW
BEGIN IF OLD.uom_version='BASE_V1' OR NEW.uom_version<>OLD.uom_version THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_SNAPSHOT_IMMUTABLE'; END IF; END;
-- statement
CREATE TRIGGER uom_purchase_parent_delete BEFORE DELETE ON purchase_invoices FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM purchase_invoice_lines WHERE purchase_invoice_id=OLD.id AND uom_version='BASE_V1') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_DOCUMENT_EVIDENCE_RESTRICT'; END IF; END;

-- statement
ALTER TABLE sales_invoice_lines ADD CHECK(uom_version='LEGACY_KG' OR (company_id IS NOT NULL AND quantity IS NOT NULL AND unit_code IS NOT NULL AND unit_factor_to_base IS NOT NULL AND base_quantity IS NOT NULL AND uom_unit_price_snapshot IS NOT NULL));
-- statement
CREATE TRIGGER uom_sale_insert BEFORE INSERT ON sales_invoice_lines FOR EACH ROW
BEGIN
 DECLARE f DECIMAL(18,6); DECLARE b BIGINT UNSIGNED; DECLARE code_value VARCHAR(20); DECLARE frac BOOLEAN;
 IF NEW.uom_version='BASE_V1' THEN
  SELECT m.factor_to_base,m.base_unit_id,u.code,bu.allow_fractional INTO f,b,code_value,frac FROM item_uom_mappings m JOIN uom_units u ON u.id=m.unit_id JOIN uom_units bu ON bu.id=m.base_unit_id WHERE m.id=NEW.uom_mapping_id AND m.company_id=NEW.company_id AND m.item_id=NEW.item_id;
  IF f IS NULL OR NEW.unit_factor_to_base<>f OR NEW.base_uom_id_snapshot<>b OR BINARY NEW.unit_code<>BINARY code_value OR (frac=0 AND NEW.base_quantity<>FLOOR(NEW.base_quantity)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_SNAPSHOT_MISMATCH'; END IF;
  IF NOT EXISTS(SELECT 1 FROM sales_invoices WHERE id=NEW.sales_invoice_id AND company_id=NEW.company_id) OR NOT EXISTS(SELECT 1 FROM item_uom_prices WHERE id=NEW.uom_price_revision_id AND company_id=NEW.company_id AND mapping_id=NEW.uom_mapping_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_DOCUMENT_PRICE_SCOPE'; END IF;
 END IF;
END;
-- statement
CREATE TRIGGER uom_sale_immutable BEFORE UPDATE ON sales_invoice_lines FOR EACH ROW
BEGIN IF OLD.uom_version='BASE_V1' OR NEW.uom_version<>OLD.uom_version THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_SNAPSHOT_IMMUTABLE'; END IF; END;
-- statement
CREATE TRIGGER uom_sale_parent_delete BEFORE DELETE ON sales_invoices FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM sales_invoice_lines WHERE sales_invoice_id=OLD.id AND uom_version='BASE_V1') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_DOCUMENT_EVIDENCE_RESTRICT'; END IF; END;

-- statement
ALTER TABLE inventory_lots ADD CHECK(uom_version='LEGACY_KG' OR (received_base_qty IS NOT NULL AND remaining_base_qty IS NOT NULL AND sold_base_qty IS NOT NULL AND unit_cost_per_base IS NOT NULL)),ADD CONSTRAINT fk_uom_lot_branch FOREIGN KEY(uom_company_id,branch_id) REFERENCES branches(company_id,id) ON DELETE RESTRICT;
-- statement
ALTER TABLE inventory_lot_movements ADD CHECK(uom_version='LEGACY_KG' OR (base_quantity IS NOT NULL AND unit_cost_per_base IS NOT NULL)),ADD CONSTRAINT fk_uom_movement_branch FOREIGN KEY(uom_company_id,branch_id) REFERENCES branches(company_id,id) ON DELETE RESTRICT;
-- statement
CREATE TRIGGER uom_lot_insert BEFORE INSERT ON inventory_lots FOR EACH ROW
BEGIN DECLARE b BIGINT UNSIGNED; DECLARE frac BOOLEAN;
 IF NEW.uom_version='BASE_V1' THEN
 SELECT m.base_unit_id,u.allow_fractional INTO b,frac FROM item_uom_mappings m JOIN uom_units u ON u.id=m.base_unit_id WHERE m.id=NEW.uom_mapping_id AND m.company_id=NEW.uom_company_id AND m.item_id=NEW.item_id;
 IF b IS NULL OR b<>NEW.base_uom_id_snapshot OR (frac=0 AND (NEW.received_base_qty<>FLOOR(NEW.received_base_qty) OR NEW.remaining_base_qty<>FLOOR(NEW.remaining_base_qty))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_LOT_BASE_OR_FRACTION'; END IF;
 END IF;
END;
-- statement
CREATE TRIGGER uom_lot_update BEFORE UPDATE ON inventory_lots FOR EACH ROW
BEGIN DECLARE frac BOOLEAN; IF NEW.uom_version<>OLD.uom_version THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_NO_HISTORICAL_RETAG'; END IF; IF OLD.uom_version='BASE_V1' THEN
 SELECT allow_fractional INTO frac FROM uom_units WHERE id=OLD.base_uom_id_snapshot;
 IF frac=0 AND (NEW.remaining_base_qty<>FLOOR(NEW.remaining_base_qty) OR NEW.sold_base_qty<>FLOOR(NEW.sold_base_qty)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_FRACTION_REJECTED'; END IF;
 IF NEW.uom_version<>OLD.uom_version OR NOT(NEW.uom_mapping_id<=>OLD.uom_mapping_id) OR NOT(NEW.base_uom_id_snapshot<=>OLD.base_uom_id_snapshot) OR NEW.item_id<>OLD.item_id OR NEW.company_id<>OLD.company_id OR NEW.received_base_qty<>OLD.received_base_qty THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_LOT_PROVENANCE_IMMUTABLE'; END IF; END IF; END;
-- statement
CREATE TRIGGER uom_movement_insert BEFORE INSERT ON inventory_lot_movements FOR EACH ROW
BEGIN
 DECLARE frac BOOLEAN;
 IF NEW.uom_version='BASE_V1' THEN SELECT allow_fractional INTO frac FROM uom_units WHERE id=NEW.base_uom_id_snapshot; IF frac=0 AND NEW.base_quantity<>FLOOR(NEW.base_quantity) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_FRACTION_REJECTED'; END IF; END IF;
 IF NEW.uom_version='BASE_V1' AND NOT EXISTS(SELECT 1 FROM inventory_lots WHERE id=NEW.inventory_lot_id AND company_id=NEW.company_id AND branch_id=NEW.branch_id AND item_id=NEW.item_id AND uom_version='BASE_V1' AND base_uom_id_snapshot=NEW.base_uom_id_snapshot) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_MOVEMENT_LOT_SCOPE'; END IF;
END;
-- statement
CREATE TRIGGER uom_movement_immutable BEFORE UPDATE ON inventory_lot_movements FOR EACH ROW
BEGIN IF OLD.uom_version='BASE_V1' OR NEW.uom_version<>OLD.uom_version THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_MOVEMENT_IMMUTABLE'; END IF; END;
-- statement
CREATE TRIGGER uom_price_currency BEFORE INSERT ON item_uom_prices FOR EACH ROW
BEGIN IF NOT EXISTS(SELECT 1 FROM company_currencies WHERE company_id=NEW.company_id AND BINARY currency_code=BINARY NEW.currency_code AND is_active=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_PRICE_CURRENCY_SCOPE'; END IF; END;
-- statement
ALTER TABLE inventory_lots ADD CHECK(uom_version='LEGACY_KG' OR (base_cost>=0 AND allocated_cost>=0 AND total_cost=base_cost+allocated_cost));
-- statement
CREATE TRIGGER uom_purchase_line_delete BEFORE DELETE ON purchase_invoice_lines FOR EACH ROW BEGIN IF OLD.uom_version='BASE_V1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_SNAPSHOT_DELETE_RESTRICT'; END IF; END;
-- statement
CREATE TRIGGER uom_sale_line_delete BEFORE DELETE ON sales_invoice_lines FOR EACH ROW BEGIN IF OLD.uom_version='BASE_V1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_SNAPSHOT_DELETE_RESTRICT'; END IF; END;
-- statement
CREATE TRIGGER uom_movement_delete BEFORE DELETE ON inventory_lot_movements FOR EACH ROW BEGIN IF OLD.uom_version='BASE_V1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='UOM_MOVEMENT_DELETE_RESTRICT'; END IF; END;
