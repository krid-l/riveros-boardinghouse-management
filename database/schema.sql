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
    recovery_mobile VARCHAR(20),                      -- admins: where a password reset code is texted
    last_login_at TIMESTAMP NULL DEFAULT NULL,
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
    middle_name VARCHAR(50),
    gender VARCHAR(20),
    email VARCHAR(120),
    occupation VARCHAR(100),
    emergency_contact VARCHAR(100),                  -- older records: name and number in one field
    emergency_name VARCHAR(100),
    emergency_relationship VARCHAR(50),
    emergency_phone VARCHAR(20),
    address TEXT,
    date_of_birth DATE,
    profile_picture TEXT,
    announcements_seen_at TIMESTAMP NULL DEFAULT NULL,
    admin_notes TEXT,                                 -- the admin's notes on the tenant
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at TIMESTAMP NULL DEFAULT NULL,         -- first reply from the admin
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    priority VARCHAR(10) NOT NULL DEFAULT 'normal',   -- 'low' | 'normal' | 'high', set by the admin
    photo_path VARCHAR(255)                           -- optional photo from the tenant
);

CREATE TABLE IF NOT EXISTS announcements (
    id SERIAL PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Every text message, sent or not (PhilSMS). Shown under Admin -> Settings -> SMS.
CREATE TABLE IF NOT EXISTS sms_log (
    id SERIAL PRIMARY KEY,
    tenant_id INT REFERENCES tenants(id) ON DELETE SET NULL,
    recipient VARCHAR(30) NOT NULL,
    message TEXT NOT NULL,
    purpose VARCHAR(30) NOT NULL DEFAULT 'general',   -- payment_verified, reminder_due, announcement, test, ...
    status VARCHAR(10) NOT NULL,                      -- 'sent' | 'failed' | 'skipped' | 'unknown' (no reply)
    error VARCHAR(255),
    ref VARCHAR(80),                                  -- marks a reminder as sent, so it isn't repeated
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sms_log_ref ON sms_log (ref);

-- Forgot password: one-time codes texted to the account's mobile number.
CREATE TABLE IF NOT EXISTS password_resets (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash VARCHAR(255) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL
);

-- The admin's notification bell.
CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    type VARCHAR(40) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(255),
    link VARCHAR(255),
    ref VARCHAR(100),                                 -- stops the same notice being raised twice
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NULL DEFAULT NULL
);

-- A tenant asking for their deposit to pay for their last month; the admin approves it.
CREATE TABLE IF NOT EXISTS deposit_requests (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    billing_month VARCHAR(7) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',   -- 'pending' | 'approved' | 'declined'
    note VARCHAR(255),
    created_at TIMESTAMP NULL DEFAULT NULL,
    decided_at TIMESTAMP NULL DEFAULT NULL
);

-- Reports exported from Reports & Analytics (the Exported Reports tab)
CREATE TABLE IF NOT EXISTS report_exports (
    id SERIAL PRIMARY KEY,
    report_type VARCHAR(20) NOT NULL,                 -- financial | payments | unpaid | tenants | occupancy | complaints
    format VARCHAR(4) NOT NULL,                       -- csv | pdf
    date_from DATE NULL,
    date_to DATE NULL,
    row_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_notifications_ref ON notifications (ref);

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
    ('address', ''),
    ('contact_number', ''),
    ('gcash_name', ''),
    ('gcash_number', ''),
    ('gcash_instructions', 'Pay via GCash and send the reference number and screenshot through the payment submission form. Thank you!'),
    ('sms_api_key', ''),
    ('sms_sender_id', 'PhilSMS'),
    ('schema_version', '2')
ON CONFLICT (setting_key) DO NOTHING;
