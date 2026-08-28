# TASK.md

# Production Database & Cross-Access Integration Audit

## Goal

Audit and complete the Boarding House Management System so that **every
Admin and Tenant screen is connected to the production database, uses
existing APIs correctly, avoids duplicate endpoints, and stays
synchronized across the entire system.**

------------------------------------------------------------------------

# Phase 0 --- Architecture & Production Baseline

-   [ ] Identify the Admin application/access area.
-   [ ] Identify the Tenant application/access area.
-   [ ] Identify the backend/API application.
-   [ ] Identify the production database and ORM/data-access layer.
-   [ ] Identify authentication and role/access-control implementation.
-   [ ] Identify the production environment configuration.
-   [ ] Identify existing API routes/endpoints by resource.
-   [ ] Identify existing database models/tables and relationships.
-   [ ] Identify shared API clients/services/hooks used by both access
    areas.
-   [ ] Search for mock, hardcoded, demo, staging, and localhost data
    sources.
-   [ ] Document existing endpoints before creating or modifying any
    endpoint.
-   [ ] Confirm which endpoint is the source of truth for each shared
    resource.

------------------------------------------------------------------------

# Phase 1 --- ADMIN ACCESS

## 1. Admin Dashboard

-   [ ] Verify total tenants comes from production data.
-   [ ] Verify total rooms comes from production data.
-   [ ] Verify available/vacant spaces are calculated from current room
    occupancy.
-   [ ] Verify outstanding balance comes from current tenant/payment
    data.
-   [ ] Verify occupancy rate is calculated from current room data.
-   [ ] Verify payment overview uses real payment records.
-   [ ] Verify recent payments/transactions use real records.
-   [ ] Verify room status uses current room/tenant relationships.
-   [ ] Verify alerts/attention items use real pending, overdue, and
    availability data.
-   [ ] Verify dashboard updates after room, tenant, payment, and
    complaint changes.
-   [ ] Verify empty/loading/error states.
-   [ ] Verify no mock or hardcoded production values remain.

## 2. Admin Rooms

-   [ ] Verify room list loads from production database.
-   [ ] Verify room cards show current capacity and occupancy.
-   [ ] Verify room price/month comes from the room record.
-   [ ] Verify room status is derived from current occupancy/state.
-   [ ] Verify Add Room persists to production database.
-   [ ] Verify room validation and duplicate room handling.
-   [ ] Verify Edit Room updates the existing room.
-   [ ] Verify Delete/Deactivate behavior respects tenant assignments.
-   [ ] Verify clicking a room loads its current tenants.
-   [ ] Verify room changes are reflected in Dashboard.
-   [ ] Verify room changes affect Tenant room-assignment options.
-   [ ] Verify room changes affect Reports & Analytics.
-   [ ] Reuse existing room endpoints where possible.

## 3. Admin Tenants

-   [ ] Verify tenant list loads production records.
-   [ ] Verify tenant information is linked to the correct room.
-   [ ] Verify Create Tenant persists correctly.
-   [ ] Verify only appropriate/available rooms can be assigned.
-   [ ] Verify tenant update works.
-   [ ] Verify tenant activation/deactivation behavior if supported.
-   [ ] Verify tenant detail screen loads all related information.
-   [ ] Verify tenant balance is calculated from payment/ledger data.
-   [ ] Verify tenant transactions use the existing payment source of
    truth.
-   [ ] Verify tenant receipts use verified payment records.
-   [ ] Verify tenant changes update room occupancy.
-   [ ] Verify tenant changes update Dashboard and Reports.
-   [ ] Verify tenant authorization boundaries.

## 4. Admin Payments

-   [ ] Verify payment list loads production payment records.
-   [ ] Verify manual GCash submissions appear as pending verification.
-   [ ] Verify GCash reference numbers are stored and displayed
    correctly.
-   [ ] Verify uploaded GCash screenshots are stored and retrievable.
-   [ ] Verify screenshot/file storage works in production.
-   [ ] Verify Admin can review a payment submission.
-   [ ] Verify Admin can verify a valid payment.
-   [ ] Verify Admin can reject an invalid payment.
-   [ ] Verify verification updates tenant balance.
-   [ ] Verify verification creates/activates the correct digital
    receipt.
-   [ ] Verify rejection does not incorrectly reduce tenant balance.
-   [ ] Verify Admin can record cash payments.
-   [ ] Verify cash payments create the correct receipt/acknowledgment.
-   [ ] Verify duplicate payment submissions are handled safely.
-   [ ] Verify payment history is synchronized with Tenant access.
-   [ ] Verify Dashboard financial metrics update from payment changes.
-   [ ] Verify Reports use the same payment source of truth.
-   [ ] Ensure no duplicate payment endpoint exists.

## 5. Admin Complaints

-   [ ] Verify complaint list loads production records.
-   [ ] Verify each complaint belongs to the correct tenant.
-   [ ] Verify complaint subject/details/category are persisted.
-   [ ] Verify Admin can view complaint details.
-   [ ] Verify status changes persist.
-   [ ] Verify Admin responses/notes persist if supported.
-   [ ] Verify complaint resolution updates Tenant complaint history.
-   [ ] Verify complaint statistics update Dashboard/Reports where
    applicable.
-   [ ] Verify duplicate complaint APIs are not created.

## 6. Admin Reports & Analytics

-   [ ] Verify room occupancy reports use current room/tenant data.
-   [ ] Verify financial reports use current verified payment data.
-   [ ] Verify tenant reports use current tenant data.
-   [ ] Verify payment reports use the same payment records as Payments.
-   [ ] Verify date filters query real production data.
-   [ ] Verify revenue calculations are correct.
-   [ ] Verify outstanding balances are consistent with tenant/payment
    records.
-   [ ] Verify exported reports contain production data.
-   [ ] Verify no report uses independently duplicated calculations that
    conflict with the source of truth.

## 7. Admin Settings

-   [ ] Verify administrator profile loads from production account data.
-   [ ] Verify profile changes persist.
-   [ ] Verify password change works securely.
-   [ ] Verify boarding house name/details persist.
-   [ ] Verify GCash registered/account name persists.
-   [ ] Verify GCash payment number persists.
-   [ ] Verify optional GCash QR code can be stored and displayed.
-   [ ] Verify tenant-facing payment instructions persist.
-   [ ] Verify tenant payment screens use the configured GCash
    number/account details.
-   [ ] Verify SMS API configuration persists if implemented.
-   [ ] Verify notification settings persist if implemented.
-   [ ] Verify system preferences persist.
-   [ ] Verify settings are protected from unauthorized tenant access.
-   [ ] Never expose API keys/secrets in frontend responses.

------------------------------------------------------------------------

# Phase 2 --- TENANT ACCESS

## 8. Tenant My Space

-   [ ] Verify authenticated tenant information loads from production.
-   [ ] Verify current room is correct.
-   [ ] Verify room capacity and current occupancy are current.
-   [ ] Verify monthly rent comes from the correct room/tenant record.
-   [ ] Verify next due date is calculated correctly.
-   [ ] Verify outstanding balance is current.
-   [ ] Verify recent transactions use production payment data.
-   [ ] Verify digital receipt links correspond to verified payments.
-   [ ] Verify GCash payment information comes from Admin Settings.
-   [ ] Verify announcements come from the correct production source if
    implemented.
-   [ ] Verify Report Issue opens the correct complaint flow.
-   [ ] Verify changes made by Admin are reflected here.

## 9. Tenant Upload GCash Payment

-   [ ] Verify tenant sees the configured GCash account/name/number.
-   [ ] Verify optional configured QR code is displayed.
-   [ ] Verify payment amount is validated.
-   [ ] Verify payment date is validated.
-   [ ] Verify GCash reference number is required and validated.
-   [ ] Verify screenshot upload is required.
-   [ ] Verify screenshot is stored in production storage.
-   [ ] Verify submission creates exactly one pending payment record.
-   [ ] Verify duplicate submission protection.
-   [ ] Verify tenant can only submit payments for their own account.
-   [ ] Verify submitted payment appears in Tenant Payment History.
-   [ ] Verify submitted payment appears in Admin Payments as pending.
-   [ ] Verify Admin verification changes the Tenant payment status.
-   [ ] Verify rejection is reflected back to the Tenant.

## 10. Tenant Digital Receipts

-   [ ] Verify only the authenticated tenant's receipts are displayed.
-   [ ] Verify only verified/eligible payments become digital receipts.
-   [ ] Verify receipt number is unique.
-   [ ] Verify receipt amount matches the verified payment.
-   [ ] Verify receipt date matches the payment.
-   [ ] Verify payment method is correct.
-   [ ] Verify GCash reference is shown for manual GCash payments.
-   [ ] Verify cash receipt identifies the payment as Cash.
-   [ ] Verify View Receipt works.
-   [ ] Verify Download/Print works if implemented.
-   [ ] Verify receipt data is generated from the payment source of
    truth.
-   [ ] Verify rejected or unverified payments are not incorrectly
    presented as verified receipts.

## 11. Tenant Complaints & Concerns

-   [ ] Verify tenant can create a complaint.
-   [ ] Verify complaint is linked to the authenticated tenant.
-   [ ] Verify category/details/photo upload if implemented.
-   [ ] Verify complaint data persists in production.
-   [ ] Verify tenant sees their own complaint history.
-   [ ] Verify status changes made by Admin appear for the Tenant.
-   [ ] Verify Admin responses/updates appear for the Tenant.
-   [ ] Verify tenant cannot view another tenant's complaints.

## 12. Tenant Profile/Account

-   [ ] Verify tenant profile loads production data.
-   [ ] Verify editable fields persist where permitted.
-   [ ] Verify password/account security works.
-   [ ] Verify tenant cannot modify protected fields such as room
    assignment unless explicitly allowed.
-   [ ] Verify tenant cannot access Admin routes or Admin APIs.

------------------------------------------------------------------------

# Phase 3 --- CROSS-SCREEN SYNCHRONIZATION

## Room ↔ Tenant

-   [ ] Add room → room appears everywhere required.
-   [ ] Change room capacity → occupancy calculations update.
-   [ ] Assign tenant → room occupancy updates.
-   [ ] Remove/move tenant → old and new room occupancy update.
-   [ ] Room availability updates tenant assignment options.
-   [ ] Room details show the correct current tenants.

## Tenant ↔ Payment

-   [ ] Tenant balance reflects verified payments.
-   [ ] Payment history reflects all applicable records.
-   [ ] Pending GCash payments do not reduce balance before
    verification.
-   [ ] Verified payments reduce the correct balance.
-   [ ] Rejected payments do not reduce the balance.
-   [ ] Cash payments update balance correctly.
-   [ ] Receipts correspond to verified payments.

## Payment ↔ Dashboard ↔ Reports

-   [ ] Verified payment updates Dashboard.
-   [ ] Payment status changes update Dashboard.
-   [ ] Revenue reports use the same payment records.
-   [ ] Outstanding balance reports match Tenant balances.
-   [ ] No conflicting payment calculations exist between screens.

## Admin Settings ↔ Tenant

-   [ ] GCash number updates appear on Tenant payment screen.
-   [ ] GCash account name updates appear correctly.
-   [ ] QR code updates appear correctly.
-   [ ] Payment instructions update correctly.
-   [ ] Notification/SMS settings affect the intended tenant
    notifications if implemented.

## Complaints Admin ↔ Tenant

-   [ ] Tenant complaint submission appears in Admin.
-   [ ] Admin status update appears for Tenant.
-   [ ] Admin response appears for Tenant.
-   [ ] Resolved complaint status remains consistent.

------------------------------------------------------------------------

# Phase 4 --- API & DATABASE AUDIT

-   [ ] Create an endpoint inventory.
-   [ ] Map each screen operation to an existing endpoint.
-   [ ] Identify duplicate endpoints.
-   [ ] Remove/reuse duplicate endpoints where safe.
-   [ ] Verify endpoint authorization by role.
-   [ ] Verify tenant endpoints scope queries to the authenticated
    tenant.
-   [ ] Verify Admin endpoints require Admin privileges.
-   [ ] Verify foreign-key relationships.
-   [ ] Verify required indexes for frequently queried fields.
-   [ ] Verify unique constraints such as room identifiers and receipt
    numbers.
-   [ ] Verify payment status transitions.
-   [ ] Verify complaint status transitions.
-   [ ] Verify room occupancy logic.
-   [ ] Verify transaction/atomicity requirements for multi-record
    updates.
-   [ ] Verify production migrations/schema are up to date.
-   [ ] Verify no endpoint silently falls back to mock data.

------------------------------------------------------------------------

# Phase 5 --- PRODUCTION VERIFICATION

-   [ ] Confirm production API URL.
-   [ ] Confirm production database connection.
-   [ ] Confirm production environment variables.
-   [ ] Confirm CORS/allowed origins.
-   [ ] Confirm production authentication.
-   [ ] Confirm production authorization.
-   [ ] Confirm file storage.
-   [ ] Confirm uploaded payment screenshots can be retrieved in
    production.
-   [ ] Confirm HTTPS/secure requests where applicable.
-   [ ] Search source code for localhost URLs.
-   [ ] Search source code for staging URLs.
-   [ ] Search source code for demo/mock data.
-   [ ] Search source code for hardcoded tenant/room/payment records.
-   [ ] Verify secrets are not committed or exposed.
-   [ ] Verify production error logging.
-   [ ] Verify database backups/recovery strategy where applicable.

------------------------------------------------------------------------

# Phase 6 --- END-TO-END TESTING

## Admin → Tenant

-   [ ] Admin creates a room.
-   [ ] Tenant-facing room information reflects the new room where
    applicable.
-   [ ] Admin creates/assigns a tenant.
-   [ ] Tenant can log in and see the correct room.
-   [ ] Admin configures GCash details.
-   [ ] Tenant sees the same GCash details.
-   [ ] Tenant submits a GCash payment.
-   [ ] Admin sees the pending submission.
-   [ ] Admin verifies payment.
-   [ ] Tenant sees verified status.
-   [ ] Tenant balance updates.
-   [ ] Digital receipt becomes available.
-   [ ] Dashboard and reports update correctly.
-   [ ] Tenant submits a complaint.
-   [ ] Admin sees the complaint.
-   [ ] Admin updates/resolves it.
-   [ ] Tenant sees the updated status.

## Final Quality Gate

-   [ ] All Admin screens verified.
-   [ ] All Tenant screens verified.
-   [ ] All shared resources have a clear source of truth.
-   [ ] No unnecessary duplicate endpoints.
-   [ ] No mock/staging/localhost production paths.
-   [ ] Database synchronization verified.
-   [ ] Authentication and authorization verified.
-   [ ] Payment verification flow verified.
-   [ ] Receipt flow verified.
-   [ ] Complaint flow verified.
-   [ ] Room/tenant synchronization verified.
-   [ ] Dashboard/report calculations verified.
-   [ ] Production end-to-end test completed.
-   [ ] No known blocking production issues remain.

------------------------------------------------------------------------

## Final Rule

**Do not mark the project complete because the screens load. Mark it
complete only after the data flow has been traced and tested from UI →
API → database → related screens → production user flow.**
