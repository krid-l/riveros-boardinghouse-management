AGENT.md

Project: Boarding House Management System

Objective

Ensure the entire Boarding House Management System is fully connected to
the production database and that the Admin and Tenant access
areas work correctly as one synchronized system.

The implementation must prioritize existing architecture, existing API
endpoints, database relationships, and production behavior. Do not
create duplicate endpoints or duplicate business logic when an existing
endpoint/service already provides the required functionality.

Access Control

The system has exactly two primary access levels:

Admin

Tenant

Work through the system in this order:

Complete and verify the Admin access.

Complete and verify the Tenant access.

Perform a final cross-access synchronization and
production-readiness verification.

Core Rules

1. Database-First Verification

For every screen:

Identify every piece of data displayed.

Trace where the data comes from.

Verify the frontend is using the correct backend/API/service.

Verify the backend endpoint actually reads/writes the production
database.

Verify database records, relationships, constraints, and status
values are correct.

Remove mock, hardcoded, demo, staging, or placeholder data where
production data should be used.

Verify create, read, update, delete, and status-changing operations
where applicable.

Verify loading, empty, error, and success states.

Do not consider a screen complete merely because it renders
successfully.

2. Reuse Existing Endpoints

Before creating any endpoint:

Search the backend for an existing endpoint/service that already
provides the required data or operation.

Check whether another screen already consumes that endpoint.

Reuse and extend the existing endpoint when appropriate.

Only create a new endpoint when the required operation genuinely
does not exist.

Do not create duplicate endpoints for the same resource or operation.

3. Cross-Screen Synchronization

Screens must remain synchronized through the shared backend/database.

Examples:

Adding a room must update room lists, available capacity, tenant
assignment options, dashboard metrics, and reports.

Assigning a tenant to a room must update the room occupancy and
tenant records.

Recording/verifying a payment must update the tenant balance,
payment history, receipts, dashboard financial data, and reports.

Rejecting a GCash payment must not incorrectly reduce the tenant
balance.

Resolving a complaint must update its status everywhere it is
displayed.

Changing system settings, such as the boarding house GCash number,
must be reflected wherever tenants are instructed to pay.

Do not solve synchronization by duplicating data in unrelated frontend
state when the source of truth should be the backend/database.

4. Production Environment

The final implementation must work against the production environment.

Verify:

Production API/base URL.

Production database connection.

Production authentication and authorization.

Production database schema and migrations.

Environment variables.

CORS and allowed origins where applicable.

File/image upload storage for payment screenshots and other uploads.

Database transactions for operations that update multiple related
records.

Error handling and validation.

No development-only URLs, localhost endpoints, mock data, test
credentials, or staging references remain in production paths.

Never expose secrets, API keys, passwords, or database credentials in
frontend code or committed files.

5. Preserve Existing Business Logic

Do not unnecessarily rewrite working architecture.

Before modifying behavior:

Understand the current implementation.

Preserve existing business rules unless they are incorrect or
incomplete.

Reuse existing models, services, utilities, hooks, API clients, and
components when appropriate.

Keep naming and data contracts consistent across Admin and Tenant
access.

Admin Verification Order

Review every Admin screen in this order:

Dashboard

Rooms

Tenants

Payments

Complaints

Reports & Analytics

Settings

Admin authentication/account flows, if present

For each screen, inspect:

UI/data source

API calls

backend endpoint

database tables/models

create/update/delete operations

relationships

status transitions

validation

loading/empty/error states

navigation to related screens

production configuration

After each screen, verify its effect on related Admin screens.

Tenant Verification Order

After Admin is verified, review every Tenant screen in this order:

My Space

Upload/Submit Payment

Digital Receipts

Complaints & Concerns

Tenant Profile/account screens, if present

Tenant navigation/authentication flows

For each screen, verify:

Data belongs only to the authenticated tenant.

Tenant data is loaded from production APIs/database.

Tenant actions persist correctly.

Tenant cannot access Admin-only operations.

Payment submission creates the correct pending record.

GCash reference and screenshot are stored correctly.

Verified payments update balances and receipts.

Rejected payments do not incorrectly affect balances.

Complaints are connected to the correct tenant.

Admin changes are reflected in the Tenant access area.

Payment Rules

GCash payments are manual external payments, not an integrated GCash
gateway.

The tenant:

Sends payment through GCash outside the system.

Enters the amount.

Enters the payment date.

Enters the GCash reference number.

Uploads the GCash screenshot/proof.

Submits the payment for verification.

The admin:

Reviews the submission.

Checks the reference number and screenshot.

Verifies or rejects the payment.

Verification updates the tenant's balance and receipt records.

Cash payments are recorded by the admin and should produce a system
payment record and digital payment receipt/acknowledgment.

Do not represent manual GCash as a payment gateway transaction.

Definition of Done

A screen is complete only when:

It uses the correct production data source.

Its API calls are functional.

Its backend endpoint is correct and non-duplicated.

Database reads/writes work.

Related screens reflect changes.

Authentication/authorization is enforced.

Validation and error states work.

No mock/staging data remains.

Production configuration has been verified.

The feature has been tested through the real user flow.

A final end-to-end pass must verify Admin → Database/API → Tenant
synchronization and Tenant → Database/API → Admin synchronization.