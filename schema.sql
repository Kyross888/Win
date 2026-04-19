-- ============================================================
--  schema.sql  —  Luna's POS Database Schema
--  For: freesqldatabase.com (sql12823569)
--  Go to https://www.phpmyadmin.co/ → login → SQL tab → paste & run
-- ============================================================

CREATE TABLE IF NOT EXISTS branches (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    address    VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO branches (id, name, address) VALUES
(1, 'Festive Mall',      'Festive Walk Mall, Iloilo'),
(2, 'SM Central Market', 'SM City Iloilo'),
(3, 'General Luna',      'General Luna St, Iloilo'),
(4, 'Jaro',              'Jaro, Iloilo City'),
(5, 'Molo',              'Molo, Iloilo City'),
(6, 'La Paz',            'La Paz, Iloilo City'),
(7, 'Calumpang',         'Calumpang, Iloilo City'),
(8, 'Tagbak',            'Tagbak, Jaro, Iloilo City');

CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    first_name  VARCHAR(60)  NOT NULL,
    last_name   VARCHAR(60)  NOT NULL,
    email       VARCHAR(120) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    phone       VARCHAR(30),
    role        ENUM('admin','staff') DEFAULT 'staff',
    employee_id VARCHAR(30),
    branch_id   INT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

INSERT IGNORE INTO users (id, first_name, last_name, email, password, role, employee_id, branch_id)
VALUES (1, 'Admin', 'User', 'admin@lunas.com',
        '$2y$12$6u5bU0v3q0JWmMvFuGaL0.qCCdj.V2G.yYdQr0G/zPT3L3NqJdgNy',
        'admin', 'POS-001', 1);

CREATE TABLE IF NOT EXISTS products (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    category   VARCHAR(60) DEFAULT 'Food',
    price      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stock      INT NOT NULL DEFAULT 0,
    image_path VARCHAR(255),
    icon       VARCHAR(60),
    branch_id  INT,
    is_active  TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

INSERT IGNORE INTO products (id, name, category, price, stock, icon) VALUES
(1,  'Special Batchoy',       'Food',    180.00, 50, 'fa-bowl-food'),
(2,  'Regular Batchoy',       'Food',    130.00, 50, 'fa-bowl-food'),
(3,  'Chicken Inasal',        'Food',    150.00, 30, 'fa-drumstick-bite'),
(4,  'Pork BBQ',              'Food',    120.00, 40, 'fa-fire'),
(5,  'Kare-Kare',             'Food',    200.00, 20, 'fa-bowl-food'),
(6,  'Pancit Molo',           'Food',    150.00, 25, 'fa-bowl-food'),
(7,  'La Paz Batchoy',        'Food',    160.00, 35, 'fa-bowl-food'),
(8,  'Dinuguan',              'Food',    140.00, 15, 'fa-bowl-food'),
(9,  'Halo-Halo',             'Dessert',  95.00, 20, 'fa-ice-cream'),
(10, 'Tapioca (Ube)',         'Dessert',  60.00, 30, 'fa-ice-cream'),
(11, 'Tapioca (Buko Pandan)', 'Dessert',  60.00, 30, 'fa-ice-cream'),
(12, 'Black Sambo (Small)',   'Dessert',  60.00, 25, 'fa-ice-cream'),
(13, 'Iced Tea',              'Drinks',   35.00, 60, 'fa-glass-water'),
(14, 'Softdrinks',            'Drinks',   35.00, 60, 'fa-glass-water'),
(15, 'Mineral Water',         'Drinks',   20.00, 80, 'fa-droplet');

CREATE TABLE IF NOT EXISTS customers (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    email       VARCHAR(120),
    phone       VARCHAR(30),
    branch_id   INT,
    visits      INT DEFAULT 0,
    total_spent DECIMAL(12,2) DEFAULT 0.00,
    last_visit  DATE,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    reference_no    VARCHAR(20) NOT NULL UNIQUE,
    branch_id       INT,
    user_id         INT,
    order_type      ENUM('Dine-in','Take-out','Coupon') DEFAULT 'Dine-in',
    payment_method  ENUM('Cash','GCash','Card') DEFAULT 'Cash',
    subtotal        DECIMAL(10,2) DEFAULT 0.00,
    discount        DECIMAL(10,2) DEFAULT 0.00,
    coupon_discount DECIMAL(10,2) DEFAULT 0.00,
    total           DECIMAL(10,2) DEFAULT 0.00,
    customer_id     INT,
    status          ENUM('completed','voided') DEFAULT 'completed',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id)   REFERENCES branches(id)  ON DELETE SET NULL,
    FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS transaction_items (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    product_id     INT,
    product_name   VARCHAR(120),
    unit_price     DECIMAL(10,2),
    quantity       INT,
    line_total     DECIMAL(10,2),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id)     REFERENCES products(id)     ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    code       VARCHAR(6) NOT NULL,
    token      VARCHAR(64),
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
