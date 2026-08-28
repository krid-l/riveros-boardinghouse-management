# AI Agents Definition for Development

This document outlines the specialized AI agent roles and responsibilities required to build the Digital Boarding House Management System according to the capstone requirements and modified constraints.

## 1. 🏗️ Lead Architect (System & Database)
**Role:** Designs the foundational structure, data flow, and database schema of the application.
**Responsibilities:**
- Define the MySQL database schema (Users, Rooms, Tenants, Payments, Complaints, Receipts).
- Enforce business logic constraints (e.g., linking tenants strictly via Admin assignment, no customer-facing room selection).
- Oversee the architectural shift from a local SMS dongle to a cloud-based SMS API provider.

## 2. 🔐 Backend Developer (PHP/MySQL)
**Role:** Handles server-side logic, data processing, APIs, and security.
**Responsibilities:**
- Develop the Admin and Tenant authentication systems.
- Build the **Manual GCash Payment** processing logic (secure file upload handling for screenshots, reference number storage, and Admin verification status updates).
- Integrate the **SMS API** (e.g., Semaphore, Twilio) for dynamic notifications such as due dates and payment approvals.
- Generate **Digital Receipts** using server-side PDF generation libraries (e.g., FPDF, TCPDF, or DOMPDF).

## 3. 🎨 Frontend Developer (UI/UX)
**Role:** Creates the user interface, ensuring strict mobile responsiveness and intuitive user experiences.
**Responsibilities:**
- Develop the Admin Dashboard and Tenant Portal using HTML, CSS, and vanilla JavaScript.
- Enforce strict **Mobile Responsiveness** using frameworks like Bootstrap or Tailwind CSS, ensuring the Tenant Portal is easily accessible on smartphones.
- Build intuitive forms for admins to create tenants and assign rooms, completely omitting any room selection interface for tenants.
- Design a clean, readable layout for the generated Digital Receipts.

## 4. 🧪 QA & Security Tester
**Role:** Ensures the system is robust, secure, mobile-friendly, and bug-free.
**Responsibilities:**
- Test mobile responsiveness across various device screen sizes (phones, tablets, desktops).
- Verify access controls: ensure tenants cannot access admin routes or select rooms independently.
- Validate the manual GCash payment flow: `Tenant Uploads Screenshot` -> `Admin Verifies` -> `System Generates Digital Receipt` -> `System Sends SMS`.
- Secure file uploads (preventing malicious files from being uploaded in the GCash screenshot upload feature).
