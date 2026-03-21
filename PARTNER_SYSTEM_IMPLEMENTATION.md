# Partner System Implementation

Multi-tenant partner access control for Yealink Config Builder.

## Overview

Non-owner admin accounts are linked to a **partner company**. Each partner company
has a configurable set of **customers** it may view. Owner accounts always see
everything. Accounts without any role, or without a partner assignment, are denied
access.

## Database (migration 15)

Run `migrations/15_partner_system.sql` once against your database.

| Table | Purpose |
|---|---|
| `partner_companies` | Partner organisations (name, is_active) |
| `admin_partner_company` | 1-to-1 link admin → partner company |
| `partner_company_customers` | Allowed customers per partner (can_view flag) |

## Access Rules

1. **Owner** (role_name = 'Owner', case-insensitive) → sees everything, no filter.
2. **No roles** → redirect `/access_denied.php`.
3. **Role but no active partner** → redirect `/access_denied.php`.
4. **Non-owner with active partner** → sees only customers where `partner_company_customers.can_view = 1`.

## New Files

| File | Description |
|---|---|
| `migrations/15_partner_system.sql` | Database migration |
| `includes/partner_access.php` | Authorization helpers |
| `admin/partners.php` | CRUD for partner companies |
| `admin/partner_rights.php` | Manage customer access per partner |

## Modified Files

| File | Change |
|---|---|
| `admin/_header.php` | Added Partners dropdown menu (Owner / partners.manage) |
| `admin/roles_edit.php` | Added `partners.manage` permission |
| `admin/users_edit.php` | Added partner company dropdown for user assignment |
| `admin/customers.php` | List filtered by allowed customers |
| `admin/dashboard.php` | Stats scoped to allowed customers |
| `devices/list.php` | List & bulk-delete filtered + ownership checks |
| `devices/create.php` | Customer dropdown filtered; customer assert on POST |
| `devices/edit.php` | Ownership check on load + customer assert on POST |
| `settings/builder.php` | Device list filtered; assert on device/config actions |
| `settings/device_mapping.php` | Device list filtered; assert on device/config actions |
| `download_device_config.php` | `assert_device_allowed` before serving file |

## Helper Functions (`includes/partner_access.php`)

```php
is_owner($pdo, $admin_id)                           // bool
require_any_role($pdo, $admin_id)                   // redirect if no roles
require_partner_or_owner($pdo, $admin_id)           // redirect if not owner and no partner
get_allowed_customer_ids_for_admin($pdo, $admin_id) // null | [] | [ids]
build_customer_filter($allowed, $col, &$params)     // SQL fragment helper
assert_customer_allowed($pdo, $admin_id, $cid)      // redirect if denied
assert_device_allowed($pdo, $admin_id, $device_id)  // redirect if denied
assert_config_version_allowed($pdo, $admin_id, $cv_id) // redirect if denied
```

## Admin UI

### Partners menu (header)
Visible for Owner or `partners.manage` permission.

- **Partner Bedrijven** (`/admin/partners.php`) – create/edit partner companies and toggle active.
- **Partner Rechten** (`/admin/partner_rights.php`) – select a partner and tick which customers it may see.

### User edit (`/admin/users_edit.php`)
Owner or `partners.manage` users see a "Partner Bedrijf" dropdown when editing an account.

### Roles edit (`/admin/roles_edit.php`)
`partners.manage` permission is now available in the *Partners* category.

## Manual Test Checklist

### Setup
- [ ] Run `migrations/15_partner_system.sql`
- [ ] Create a partner company via `/admin/partners.php`
- [ ] Assign some customers to that partner via `/admin/partner_rights.php`
- [ ] Create (or edit) a non-Owner admin and assign it to the partner via `/admin/users_edit.php`

### Owner account
- [ ] Login as Owner → sees all customers, devices, dashboard shows global stats
- [ ] Partners menu visible in header

### Non-Owner with partner
- [ ] Login as non-Owner with partner assignment
- [ ] `/admin/customers.php` → only assigned customers visible
- [ ] `/devices/list.php` → only devices for assigned customers visible
- [ ] `/settings/builder.php` → only devices for assigned customers visible
- [ ] `/settings/device_mapping.php` → only devices for assigned customers visible
- [ ] `/admin/dashboard.php` → stats reflect only partner customers
- [ ] Accessing a device URL with a non-allowed `device_id` → redirect to `/access_denied.php`
- [ ] Partners menu NOT visible (unless `partners.manage` permission is added to role)

### Non-Owner without partner
- [ ] Login as non-Owner with NO partner assignment → redirect to `/access_denied.php` on all protected pages

### No-role account
- [ ] Login as account with no role assigned → redirect to `/access_denied.php`

### Partner management
- [ ] Create partner company → appears in list
- [ ] Edit partner company (rename, toggle inactive)
- [ ] Assign customers via checkboxes → save → verify correct rows in `partner_company_customers`
- [ ] Set partner company to inactive → linked non-Owner admin gets denied

### Download
- [ ] `download_device_config.php` for an allowed device → serves config
- [ ] `download_device_config.php` for a non-allowed device → redirect to `/access_denied.php`
