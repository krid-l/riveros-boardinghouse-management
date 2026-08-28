-- PostgreSQL Schema for Supabase

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) CHECK (role IN ('admin', 'tenant')) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rooms (
    id SERIAL PRIMARY KEY,
    room_number VARCHAR(20) UNIQUE NOT NULL,
    capacity INT NOT NULL,
    status VARCHAR(20) CHECK (status IN ('vacant', 'occupied', 'maintenance')) DEFAULT 'vacant',
    price_per_month DECIMAL(10,2) NOT NULL
);

CREATE TABLE tenants (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    contact_number VARCHAR(20),
    room_id INT,
    balance DECIMAL(10,2) DEFAULT 0.00,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
);

CREATE TABLE payments (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    reference_number VARCHAR(100) NOT NULL,
    screenshot_path VARCHAR(255) NOT NULL,
    status VARCHAR(20) CHECK (status IN ('pending', 'verified', 'rejected')) DEFAULT 'pending',
    receipt_path VARCHAR(255),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

CREATE TABLE complaints (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL,
    subject VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    admin_response TEXT,
    status VARCHAR(20) CHECK (status IN ('open', 'resolved')) DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Default Admin User (username: admin, password: password)
INSERT INTO users (username, password_hash, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
