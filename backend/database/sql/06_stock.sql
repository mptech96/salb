CREATE TABLE stock_movements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    branch_id BIGINT UNSIGNED NULL,

    item_id BIGINT UNSIGNED NOT NULL,

    car_id BIGINT UNSIGNED NULL,

    movement_type VARCHAR(20) NOT NULL,
    /*
    IN  = دخول
    OUT = خروج
    */

    source_type VARCHAR(50) NOT NULL,
    /*
    PURCHASE
    SALE
    RETURN
    ADJUSTMENT
    */

    source_id BIGINT UNSIGNED NULL,

    movement_date DATETIME NOT NULL,

    qty DECIMAL(15,3) NOT NULL DEFAULT 0,

    unit_cost DECIMAL(15,3) DEFAULT 0,

    total_cost DECIMAL(15,3) DEFAULT 0,

    notes TEXT,

    created_by BIGINT UNSIGNED NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_stock_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id),

    CONSTRAINT fk_stock_item
        FOREIGN KEY (item_id) REFERENCES items(id),

    CONSTRAINT fk_stock_car
        FOREIGN KEY (car_id) REFERENCES cars(id),

    CONSTRAINT fk_stock_user
        FOREIGN KEY (created_by) REFERENCES users(id)
);