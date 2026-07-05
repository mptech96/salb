CREATE TABLE items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(50) UNIQUE,
    item_name VARCHAR(255) NOT NULL,
    unit_name VARCHAR(50) DEFAULT 'طن',
    default_buy_price DECIMAL(15,3) DEFAULT 0,
    default_sell_price DECIMAL(15,3) DEFAULT 0,
    notes TEXT,
    is_active TINYINT DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE cars (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    branch_id BIGINT UNSIGNED NULL,
    supplier_id BIGINT UNSIGNED NULL,
    driver_id BIGINT UNSIGNED NULL,

    car_number VARCHAR(100),
    plate_number VARCHAR(100),

    weight_card_number VARCHAR(100),

    gross_weight DECIMAL(15,3) DEFAULT 0,
    deduction_weight DECIMAL(15,3) DEFAULT 0,
    net_weight DECIMAL(15,3) DEFAULT 0,

    transport_cost DECIMAL(15,3) DEFAULT 0,
    extra_cost DECIMAL(15,3) DEFAULT 0,

    notes TEXT,

    car_status VARCHAR(50) DEFAULT 'OPEN',

    arrival_date DATETIME NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_cars_branch
        FOREIGN KEY (branch_id) REFERENCES branches(id),

    CONSTRAINT fk_cars_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id),

    CONSTRAINT fk_cars_driver
        FOREIGN KEY (driver_id) REFERENCES drivers(id)
);