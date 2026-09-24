-- MySQL / MariaDB schema, for running the system locally under WAMP or XAMPP.
-- The deployed system runs on PostgreSQL (see schema.sql); this file is the same
-- schema written in MySQL's dialect.
--
-- Import it from phpMyAdmin, or from the command line:
--   mysql -u root -p < database/schema_mysql.sql

CREATE DATABASE IF NOT EXISTS boardinghouse
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE boardinghouse;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    temp_password VARCHAR(255),
    role VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_users_role CHECK (role IN ('admin', 'tenant'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(20) UNIQUE NOT NULL,
    capacity INT NOT NULL,
    status VARCHAR(20) DEFAULT 'vacant',
    -- Total rent for the whole room. Each tenant living in it is billed an equal share.
    price_per_month DECIMAL(10,2) NOT NULL,
    CONSTRAINT chk_rooms_status CHECK (status IN ('vacant', 'occupied', 'maintenance'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    contact_number VARCHAR(20),
    room_id INT,
    balance DECIMAL(10,2) DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'active',     -- 'active' | 'deactivated'
    move_in_date DATE,                                -- set when the tenant first gets a room
    deactivated_at DATE,
    last_billed_month VARCHAR(7),                     -- 'YYYY-MM' of the latest rent posted
    occupation VARCHAR(100),
    emergency_contact VARCHAR(100),
    address TEXT,
    date_of_birth DATE,
    profile_picture VARCHAR(255),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Every rent charge posted to a tenant (tenants.balance = charges - payments credited)
CREATE TABLE IF NOT EXISTS charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    room_id INT,
    kind VARCHAR(20) NOT NULL DEFAULT 'rent',
    billing_month VARCHAR(7) NOT NULL,
    description VARCHAR(255),
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_charge (tenant_id, billing_month, kind),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    from_room_id INT,
    to_room_id INT,
    transferred_at DATE NOT NULL,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (from_room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    FOREIGN KEY (to_room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    reference_number VARCHAR(100) NOT NULL,
    screenshot_path VARCHAR(255),
    payment_method VARCHAR(50) DEFAULT 'gcash',
    pay_for_room BOOLEAN DEFAULT FALSE,
    status VARCHAR(20) DEFAULT 'pending',
    receipt_path VARCHAR(255),
    covered_by_payment_id INT,                        -- roommate's share of a room payment (not extra revenue)
    CONSTRAINT chk_payments_status CHECK (status IN ('pending', 'verified', 'rejected')),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (covered_by_payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    subject VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'Others',
    admin_response TEXT,
    status VARCHAR(20) DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_complaints_status CHECK (status IN ('open', 'resolved')),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB;

-- Default Admin User (username: admin, password: password)
INSERT INTO users (username, password_hash, role)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin')
ON DUPLICATE KEY UPDATE username = username;

-- Settings the admin pages read. Values are editable from Admin -> Settings.
INSERT INTO settings (setting_key, setting_value) VALUES
    ('boarding_house_name', 'RIVEROS BOARDING HOUSE'),
    ('address', '123 National Highway, Brgy. Poblacion, City of Naga, Cebu 6000'),
    ('contact_number', '032 123 4567'),
    ('gcash_name', 'Boarding House'),
    ('gcash_number', '0917 123 4567'),
    ('gcash_instructions', 'Pay via GCash and send the reference number and screenshot through the payment submission form. Thank you!'),
    ('sms_provider', 'Local SMS Gateway'),
    ('sms_api_key', ''),
    ('sms_sender_id', 'BOARDINGHOUSE'),
    ('rent_due_date', '30th'),
    ('currency', 'Philippine Peso (PHP)'),
    ('date_format', 'Aug 31, 2025 (MMM DD, YYYY)'),
    ('time_zone', '(UTC+08:00) Asia/Manila')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
