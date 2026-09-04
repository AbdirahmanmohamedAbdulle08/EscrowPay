-- ============================================================
-- ESCROW PAY APP — DATABASE SCHEMA
-- ============================================================

CREATE DATABASE IF NOT EXISTS escrow_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE escrow_db;

-- ------------------------------------------------------------
-- USERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('superadmin','buyer','seller','delivery') NOT NULL DEFAULT 'buyer',
    avatar VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    business_name VARCHAR(150) DEFAULT NULL,
    store_name VARCHAR(150) DEFAULT NULL,
    id_number VARCHAR(50) DEFAULT NULL,
    vehicle_type VARCHAR(50) DEFAULT NULL,
    vehicle_plate VARCHAR(30) DEFAULT NULL,
    balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
    last_login DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delivery_presence (
    delivery_id INT PRIMARY KEY,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (delivery_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TRANSACTIONS (Escrow Orders)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_code VARCHAR(20) NOT NULL UNIQUE,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    delivery_id INT DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    amount DECIMAL(15,2) NOT NULL,
    fee DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','funded','accepted','shipped','delivered','released','disputed','cancelled') NOT NULL DEFAULT 'pending',
    funded_at DATETIME DEFAULT NULL,
    accepted_at DATETIME DEFAULT NULL,
    shipped_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    released_at DATETIME DEFAULT NULL,
    disputed_at DATETIME DEFAULT NULL,
    dispute_reason TEXT DEFAULT NULL,
    cancelled_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (delivery_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MESSAGES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT DEFAULT NULL,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    attachment VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- AI moderation audit trail. No raw message is stored here; the message remains in messages only when allowed.
CREATE TABLE IF NOT EXISTS chat_moderation_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    action_taken ENUM('allow','review','block') NOT NULL,
    risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reason TEXT DEFAULT NULL,
    engine VARCHAR(30) NOT NULL DEFAULT 'gemini',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX chat_moderation_sender_idx (sender_id),
    INDEX chat_moderation_created_idx (created_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- NOTIFICATIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- PRODUCT RATINGS & REVIEWS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    buyer_id INT NOT NULL,
    rating TINYINT NOT NULL,
    review TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_rating_per_buyer (product_id, buyer_id),
    CONSTRAINT rating_buyer_fk FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT rating_range_chk CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- AUDIT LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(50) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SETTINGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- DELIVERY ASSIGNMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    delivery_id INT NOT NULL,
    pickup_address TEXT DEFAULT NULL,
    dropoff_address TEXT DEFAULT NULL,
    tracking_code VARCHAR(50) DEFAULT NULL,
    status ENUM('assigned','picked_up','in_transit','delivered') NOT NULL DEFAULT 'assigned',
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    picked_up_at DATETIME DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (delivery_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Delivery trust is calculated from completed assignments and delivery history.

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default users (password: Password123!)
INSERT INTO users (name, email, password, role, phone, balance, status) VALUES
('Super Admin',    'superadmin@escrow.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin', '+1-555-0001', 50000.00, 'active'),
('John Buyer',     'buyer@escrow.com',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'buyer',      '+1-555-0002', 10000.00, 'active'),
('Jane Seller',    'seller@escrow.com',     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'seller',     '+1-555-0003', 5000.00,  'active'),
('Mike Delivery',  'delivery@escrow.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'delivery',   '+1-555-0004', 2000.00,  'active'),
('Alice Buyer',    'alice@escrow.com',      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'buyer',      '+1-555-0005', 8000.00,  'active'),
('Bob Seller',     'bob@escrow.com',        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'seller',     '+1-555-0006', 3500.00,  'active'),
('Sara Delivery',  'sara@escrow.com',       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'delivery',   '+1-555-0007', 1500.00,  'active'),
('Tom Buyer',      'tom@escrow.com',        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'buyer',      '+1-555-0008', 15000.00, 'suspended');

-- Default settings
INSERT INTO settings (`key`, `value`, description) VALUES
('site_name',        'EscrowPay',      'Application name'),
('site_email',       'info@escrow.com','Contact email'),
('escrow_fee_pct',   '2.5',            'Escrow fee percentage'),
('currency',         'USD',            'Default currency'),
('currency_symbol',  '$',              'Currency symbol'),
('min_transaction',  '10.00',          'Minimum transaction amount'),
('max_transaction',  '100000.00',      'Maximum transaction amount'),
('maintenance_mode', '0',              'Maintenance mode (1=on)'),
('smtp_host',        'smtp.mailtrap.io','SMTP host'),
('smtp_port',        '587',            'SMTP port'),
('smtp_user',        '',               'SMTP username'),
('smtp_pass',        '',               'SMTP password');

-- Sample transactions
INSERT INTO transactions (ref_code, buyer_id, seller_id, delivery_id, title, description, amount, fee, net_amount, status, funded_at, accepted_at, shipped_at) VALUES
('ESC-00001', 2, 3, 4, 'Laptop Purchase', 'MacBook Pro 14 inch 2023', 2500.00, 62.50, 2437.50, 'shipped',   NOW(), NOW(), NOW()),
('ESC-00002', 5, 6, 4, 'Phone Deal',      'iPhone 15 Pro Max 256GB',  1200.00, 30.00, 1170.00, 'delivered', NOW(), NOW(), NOW()),
('ESC-00003', 2, 6, 7, 'Camera Lens',     'Sony 85mm f/1.8 Lens',      450.00, 11.25,  438.75, 'funded',    NOW(), NOW(), NULL),
('ESC-00004', 8, 3, NULL,'Rare Watch',    'Vintage Omega Seamaster',   3800.00, 95.00, 3705.00, 'pending',   NULL,  NULL,  NULL),
('ESC-00005', 2, 3, 4, 'Gaming PC',       'Custom built RTX 4090 rig', 4200.00,105.00, 4095.00, 'released',  NOW(), NOW(), NOW()),
('ESC-00006', 5, 3, 7, 'Tablet',          'iPad Pro 12.9 M2',          1100.00, 27.50, 1072.50, 'cancelled', NULL,  NULL,  NULL);

-- Sample messages
INSERT INTO messages (transaction_id, sender_id, receiver_id, message) VALUES
(1, 2, 3, 'Hi, I have funded the escrow. Please proceed with shipping.'),
(1, 3, 2, 'Great! I will ship the item tomorrow with tracking.'),
(1, 4, 2, 'Package picked up. Estimated delivery: 2 days.'),
(2, 5, 6, 'Payment secured. When can you ship?'),
(2, 6, 5, 'Will ship today. Tracking code will be sent shortly.'),
(3, 2, 6, 'Is the lens in perfect condition? Any scratches?'),
(3, 6, 2, 'Mint condition, comes with original box and caps.');

-- Sample notifications
INSERT INTO notifications (user_id, title, message, type, is_read) VALUES
(2, 'Escrow Funded',    'Your escrow ESC-00001 has been funded successfully.',    'success', 1),
(3, 'New Order',        'You have a new escrow order ESC-00001 from John Buyer.', 'info',    1),
(4, 'Delivery Assigned','You have been assigned delivery for ESC-00001.',         'info',    0),
(5, 'Item Delivered',   'Your order ESC-00002 has been marked as delivered.',     'success', 0),
(2, 'Funds Released',   'Funds for ESC-00005 have been released to the seller.',  'success', 0),
(1, 'New Dispute',      'A dispute has been raised on transaction ESC-00006.',    'danger',  0);

-- Sample audit logs
INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES
(1, 'LOGIN',              'Superadmin logged in',                  '127.0.0.1'),
(2, 'CREATE_TRANSACTION', 'Created escrow order ESC-00001',        '192.168.1.10'),
(3, 'ACCEPT_ORDER',       'Accepted escrow order ESC-00001',       '192.168.1.11'),
(4, 'MARK_DELIVERED',     'Marked ESC-00002 as delivered',         '192.168.1.12'),
(2, 'RELEASE_FUNDS',      'Released funds for ESC-00005',          '192.168.1.10'),
(1, 'SETTINGS_UPDATED',   'Updated escrow fee to 2.5%',            '127.0.0.1');
