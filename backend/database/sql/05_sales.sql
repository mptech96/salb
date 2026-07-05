CREATE TABLE sales_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    customer_id BIGINT UNSIGNED NOT NULL,

    invoice_number VARCHAR(100) UNIQUE,
    invoice_date DATE NOT NULL,

    total_qty DECIMAL(15,3) DEFAULT 0,
    total_before_discount DECIMAL(15,3) DEFAULT 0,
    discount_amount DECIMAL(15,3) DEFAULT 0,
    commission_amount DECIMAL(15,3) DEFAULT 0,
    total_amount DECIMAL(15,3) DEFAULT 0,

    payment_status VARCHAR(50) DEFAULT 'UNPAID',
    notes TEXT,

    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_sales_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id),

    CONSTRAINT fk_sales_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id),

    CONSTRAINT fk_sales_user
        FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE sales_invoice_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_invoice_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,

    qty DECIMAL(15,3) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,3) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(15,3) DEFAULT 0,
    line_total DECIMAL(15,3) DEFAULT 0,

    notes TEXT,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_sales_line_invoice
        FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id) ON DELETE CASCADE,

    CONSTRAINT fk_sales_line_item
        FOREIGN KEY (item_id) REFERENCES items(id)
);

CREATE TABLE sales_line_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sales_invoice_line_id BIGINT UNSIGNED NOT NULL,
    car_id BIGINT UNSIGNED NOT NULL,

    qty DECIMAL(15,3) NOT NULL DEFAULT 0,
    cost_price DECIMAL(15,3) DEFAULT 0,
    total_cost DECIMAL(15,3) DEFAULT 0,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_sales_source_line
        FOREIGN KEY (sales_invoice_line_id) REFERENCES sales_invoice_lines(id) ON DELETE CASCADE,

    CONSTRAINT fk_sales_source_car
        FOREIGN KEY (car_id) REFERENCES cars(id)
);