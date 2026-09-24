-- PostgreSQL schema for the Boarding House Management System
-- Works on Supabase or a Railway Postgres service.
--
-- Fresh install:  psql "<connection string>" -f database/schema.sql
-- The app fills in anything newer on its own (see includes/migrations.php).

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    temp_password VARCHAR(50),                        -- shown to the admin until the tenant changes it
    role VARCHAR(20) CHECK (role IN ('admin', 'tenant')) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rooms (
    id SERIAL PRIMARY KEY,
    room_number VARCHAR(20) UNIQUE NOT NULL,
    capacity INT NOT NULL,
    status VARCHAR(20) CHECK (status IN ('vacant', 'occupied', 'maintenance')) DEFAULT 'vacant',
    price_per_month DECIMAL(10,2) NOT NULL            -- rent PER TENANT; a full room earns this x capacity
);

CREATE TABLE IF NOT EXISTS tenants (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    contact_number VARCHAR(20),
    room_id INT REFERENCES rooms(id) ON DELETE SET NULL,
    balance DECIMAL(10,2) DEFAULT 0.00,               -- charges minus payments credited
    occupation VARCHAR(100),
    emergency_contact VARCHAR(100),
    address TEXT,
    date_of_birth DATE,
    profile_picture TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'active',     -- 'active' | 'deactivated'
    move_in_date DATE,                                -- set when the tenant first gets a room
    deactivated_at DATE,
    last_billed_month VARCHAR(7)                      -- 'YYYY-MM' of the latest rent posted
);

CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    reference_number VARCHAR(100),
    screenshot_path VARCHAR(255),                     -- empty for cash payments
    payment_method VARCHAR(100) DEFAULT 'gcash',
    pay_for_room BOOLEAN DEFAULT false,
    status VARCHAR(20) CHECK (status IN ('pending', 'verified', 'rejected')) DEFAULT 'pending',
    receipt_path VARCHAR(255),
    covered_by_payment_id INT REFERENCES payments(id) ON DELETE CASCADE, -- roommate's share of a room payment (not extra revenue)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Every rent charge posted to a tenant. tenants.balance = charges - payments credited.
CREATE TABLE IF NOT EXISTS charges (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    room_id INT REFERENCES rooms(id) ON DELETE SET NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'rent',
    billing_month VARCHAR(7) NOT NULL,
    description VARCHAR(255),
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tenant_id, billing_month, kind)
);

CREATE TABLE IF NOT EXISTS room_transfers (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    from_room_id INT REFERENCES rooms(id) ON DELETE SET NULL,
    to_room_id INT REFERENCES rooms(id) ON DELETE SET NULL,
    transferred_at DATE NOT NULL,
    note VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS complaints (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    subject VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'Others',
    admin_response TEXT,
    status VARCHAR(20) CHECK (status IN ('open', 'resolved')) DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS announcements (
    id SERIAL PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT
);

-- Default admin (username: admin, password: password) - change this after the first login
INSERT INTO users (username, password_hash, role)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin')
ON CONFLICT (username) DO NOTHING;

-- Default settings
INSERT INTO settings (setting_key, setting_value) VALUES
    ('boarding_house_name', 'RIVEROS BOARDING HOUSE'),
    ('address', E'123 National Highway, Brgy. Poblacion,\nCity of Naga, Cebu 6000'),
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
    ('time_zone', '(UTC+08:00) Asia/Manila'),
    ('schema_version', '2')
ON CONFLICT (setting_key) DO NOTHING;
