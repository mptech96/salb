CREATE TABLE purchase_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    car_id BIGINT UNSIGNED NULL,

    invoice_number VARCHAR(100) UNIQUE,
    invoice_date DATE NOT NULL,

    total_qty DECIMAL(15,3) DEFAULT 0,
    total_before_discount DECIMAL(15,3) DEFAULT 0,
    discount_amount DECIMAL(15,3) DEFAULT 0,
    transport_cost DECIMAL(15,3) DEFAULT 0,
    extra_cost DECIMAL(15,3) DEFAULT 0,
    total_amount DECIMAL(15,3) DEFAULT 0,

    payment_status VARCHAR(50) DEFAULT 'UNPAID',
    notes TEXT,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_purchase_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id),

    CONSTRAINT fk_purchase_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id),

    CONSTRAINT fk_purchase_car
        FOREIGN KEY (car_id) REFERENCES cars(id),

    CONSTRAINT fk_purchase_user
        FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE purchase_invoice_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_invoice_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    car_id BIGINT UNSIGNED NULL,

    qty DECIMAL(15,3) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,3) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(15,3) DEFAULT 0,
    line_total DECIMAL(15,3) DEFAULT 0,

    notes TEXT,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_purchase_line_invoice
        FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE CASCADE,

    CONSTRAINT fk_purchase_line_item
        FOREIGN KEY (item_id) REFERENCES items(id),

    CONSTRAINT fk_purchase_line_car
        FOREIGN KEY (car_id) REFERENCES cars(id)
);