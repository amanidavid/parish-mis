# Multitenancy + RBAC Architecture (BFF header, v1)
A versioned (v1) Laravel API using Spatie Multitenancy and Spatie Permission, with the Next.js BFF sending an `X-Tenant-Id` header (tenant UUID) for tenant resolution; normalized schemas for property rental; two API surfaces (Admin and App); UUID-first models (CHAR(36)); and the same JWT logic reused with an admin guard.

## Decisions (confirmed)
- UUIDs stored as CHAR(36) (BaseModel assigns uuid/uuid7; route binding by `uuid`).
- Reuse the same custom JWT (HS256) for App and Admin; add an `admin` guard.
- No subdomain/custom-domain resolver in this scope; BFF header (`X-Tenant-Id`, tenant UUID) is the only resolver for MVP.

## Scope & Goals
- Security-first MVP that’s versioned (`/api/v1/...`) and multi-tenant, enforcing RBAC per tenant.
- DRY services, explicit policies, validation via FormRequests, consistent JSON responses, and performance best practices.

## Folder Structure (Laravel)
- app/Models (extend BaseModel)
- app/Http/Controllers/Api/Admin/V1/* (platform admin)
- app/Http/Controllers/Api/App/V1/* (tenant-facing SaaS)
- app/Http/Requests/Api/Admin/V1/* and app/Http/Requests/Api/App/V1/*
- app/Services/V1/* (e.g., JwtService, InvoiceService, TenantProvisioner)
- app/Policies/* (explicit permissions, e.g., `properties.create`)
- database/migrations/landlord/* (base DB) and database/migrations/tenant/* (tenant DB)

## Routing
- routes/api.php
  - `/api/v1/admin/*` (platform admin)
  - `/api/v1/app/*` (tenant-facing; behind `tenant` middleware)
- Middlewares: `tenant` (header-based resolver), `jwt.auth`, throttles (`api`, `login`, `refresh`) scoped by tenant+user+IP.

## Tenant Discovery (MVP)
- Strategy: BFF sends `X-Tenant-Id: {tenant_uuid}`. A custom Spatie resolver finds the tenant in the base DB by UUID and switches to its DB for the request lifecycle.
- No domains in scope for now; subdomain/custom-domain can be added later.

## Databases
- Base (landlord) DB: `tenants`, `domains`, `plans`, `subscriptions`, `subscription_payments`, `trial_settings`, `system_admins`, `tenant_database_provision_logs`, `otp_logs`.
- Tenant DB: `users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `properties`, `property_blocks`, `property_floors`, `units`, `renters`, `leases`, `lease_charges`, `rent_invoices`, `rent_invoice_items`, `payments`, `payment_allocations`, `maintenance_requests`, `expenses`, `documents`, `notifications`, `audit_logs`, and `staff_property_assignments`.

## Normalization & Constraints (highlights)
- IDs: `id` (PK, bigint), `uuid` (CHAR(36)) unique indexed.
- Properties: lookup `property_types`; unique keys where appropriate (e.g., (`property_id`,`name`) for blocks, (`property_block_id`,`floor_number`) for floors, (`property_id`,`unit_number`) for units).
- Renters: unique nullable pairs for email/phone; index `status`.
- Leases: one active lease per unit; index `(unit_id, status)`.
- Invoices: unique (`lease_id`,`billing_period_start`,`billing_period_end`); index `status`, `due_date`.
- Payments: unique `payment_number`; `payment_allocations` unique (`payment_id`,`rent_invoice_id`).
- FKs with appropriate `on delete` rules; timestamps; creator fields where relevant.

## RBAC (per-tenant)
- Spatie Permission in each tenant DB (`guard_name=api`).
- Seed roles: owner, manager, accountant, cashier, property_supervisor, maintenance_officer, viewer.
- Permissions (explicit): `properties.view`, `properties.create`, `units.update`, `renters.create`, `leases.create`, `payments.record`, `reports.view`, `staff.manage`, `roles.manage`.
- Property-level access via `staff_property_assignments` (Role ≠ Property Access).

## API Surfaces (v1)
- Admin API (`/api/v1/admin/...`): tenants CRUD, plans, subscriptions, domains, provisioning, system admins.
- App API (`/api/v1/app/...`, tenant middleware): auth, properties/blocks/floors/units, renters, leases, invoices, payments, reports, notifications.

## Security & BFF
- Short-lived access tokens; HTTP-only cookies in BFF; auto-refresh on 401.
- BFF stores `tenant_uuid` in an HTTP-only cookie and sets `X-Tenant-Id` (tenant UUID) on each request; applies per-route throttles. Laravel rate limits by tenant+user+IP.
- Admin API reuses JWT with `admin` guard.

## Tenant identifier UX patterns (use UUID in API)
- Option A (RECOMMENDED): UI tenant selector for multi-tenant users
  1) User logs in with phone/password (global identifier in base DB).
  2) Backend returns the list of landlord workspaces the user can access, including display name and `tenant_uuid`.
  3) UI shows the names; user picks one (e.g., "Amani").
  4) BFF stores `tenant_uuid=550e8400-e29b-41d4-a716-446655440000` in an HTTP-only cookie and sends `X-Tenant-Id: 550e8400-e29b-41d4-a716-446655440000` on every App API call.
  5) User can switch companies later; BFF updates the cookie; subsequent calls target the selected tenant DB.
  Example: `GET /api/v1/app/properties` with `X-Tenant-Id: 550e8400-e29b-41d4-a716-446655440000` queries that tenant DB.
- Option B: Login scoped to a single tenant
  - Login takes/infers a specific tenant UUID (or is preset). On success, BFF stores that UUID and always sends `X-Tenant-Id: {tenant_uuid}`. Switching requires reselect/re-auth.

## Validation, Errors, Pagination
- FormRequests on writes; responses: `{ success, message, data, errors }`.
- Paginate 15+; index common filters; avoid `%m%` scans; safe sort allowlist; eager load to avoid N+1.

## MVP Phases
1) Foundation: Spatie Multitenancy + Permission; BaseModel; versioned routes; `tenant` middleware; rate limiters; UUIDs.
2) Auth & RBAC: tenant user auth; seed roles/permissions; policy gates; basic audit.
3) Core Domain: properties→blocks→floors→units; renters; leases; constraints/indexes.
4) Billing: recurring invoice generator (scheduler); payments + allocations; basic reports; overdue/expiry notifications (scheduler + queue).

## Acceptance Criteria
- Requests with `X-Tenant-Id: uuid` use the proper tenant DB.
- Per-tenant roles/permissions enforced by Spatie.
- IDs expose `uuid` and bind by `uuid`.
- `/api/v1/admin/*` and `/api/v1/app/*` available; rate limits effective; consistent JSON.
