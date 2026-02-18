# Customer Management System Implementation

## Overview
This implementation replaces the PABX system with a comprehensive Customer Management System and adds pagination/search functionality to the device list.

## Changes Made

### 1. Database Migrations

#### `migrations/09_customer_system.sql`
- Creates new `customers` table with fields:
  - `customer_code` (unique identifier)
  - `company_name` (required)
  - `contact_person`, `email`, `phone`
  - `address`, `notes`
  - `is_active` flag
  - Timestamps
- Adds `customer_id` foreign key to `devices` table
- Migrates existing PABX data to customers automatically
- Updates device relationships from PABX to customers
- Adds RBAC permissions for customer management

#### `migrations/10_enhanced_template_variables.sql`
- Expands `var_type` enum to include:
  - `text`, `number`, `boolean`
  - `select`, `multiselect`
  - `ip_address`, `textarea`
- Adds fields for enhanced validation:
  - `placeholder`, `help_text`
  - `min_value`, `max_value` (for numbers)
  - `regex_pattern` (for validation)
- Updates existing boolean fields with proper options

### 2. Device List Enhancements (`devices/list.php`)

**Pagination Features:**
- Server-side pagination with configurable items per page (10, 25, 50, 100)
- Shows total count and current range
- "First", "Previous", "Next", "Last" navigation buttons
- Per-page selector maintains search/filter state

**Search & Filter Features:**
- Search by customer name or customer code
- Filter by device type
- Combined search and filter functionality
- "Clear filters" button

**Display Changes:**
- Added "Klant" (Customer) column showing:
  - Company name (bold)
  - Customer code (as secondary text)
- Replaced client-side filtering with server-side queries
- Better performance with large datasets

### 3. Device Forms

#### `devices/create.php`
- Added customer dropdown (optional)
- Shows customer code and company name
- Only displays active customers

#### `devices/edit.php`
- Added customer dropdown (optional)
- Pre-selects current customer if set
- Maintains existing customer relationship

### 4. Configure Wizard (`devices/configure_wizard.php`)

**Step 3: Variable Input**
Enhanced to support all variable types:
- **Boolean**: Dropdown with custom labels (e.g., "Aan/Enabled", "Uit/Disabled")
- **Select**: Single-choice dropdown with custom options
- **Multiselect**: Multi-select box (Ctrl/Cmd to select multiple)
- **Number**: Number input with optional min/max validation
- **Textarea**: Large text area for multi-line input
- **Text/IP Address**: Standard text input with placeholder

**Step 4: Customer Selection**
- Replaced PABX selection with Customer selection
- Shows preview of generated config
- Customer is required when editing an existing device
- Optional when creating standalone configs
- Automatically updates device's customer relationship

### 5. Customer Management Pages

#### `admin/customers.php`
- List all customers with search/sort
- Shows device count per customer
- Status indicators (active/inactive)
- Quick actions: Edit, Delete

#### `admin/customers_add.php`
- Create new customers
- Required fields: customer_code, company_name
- Optional fields: contact person, email, phone, address, notes
- Active/inactive toggle

#### `admin/customers_edit.php`
- Edit existing customer information
- All fields editable
- Shows current device count
- Link to delete customer

#### `admin/customers_delete.php`
- Confirmation page before deletion
- Shows device count warning
- Devices are preserved but unlinked (customer_id set to NULL)

### 6. Navigation & RBAC

#### `admin/_admin_nav.php`
- Added "Klanten" (Customers) menu item
- Positioned after "Rollen" and before "Instellingen"

#### RBAC Permissions
- New permission: `admin.customers.manage`
- Granted to `super_admin` and `admin` roles by default
- Fallback permission check to `devices.manage` for compatibility

## Migration Path

### For Existing Installations

1. **Backup Database**: Always backup before running migrations

2. **Run Migration 09** (`migrations/09_customer_system.sql`):
   ```sql
   SOURCE migrations/09_customer_system.sql;
   ```
   This will:
   - Create the customers table
   - Add customer_id to devices
   - Migrate PABX data to customers
   - Link existing devices to migrated customers

3. **Run Migration 10** (`migrations/10_enhanced_template_variables.sql`):
   ```sql
   SOURCE migrations/10_enhanced_template_variables.sql;
   ```
   This will:
   - Enhance template_variables table
   - Add new field types
   - Update existing boolean variables

4. **Verify Migration**:
   ```sql
   SELECT COUNT(*) FROM customers;
   SELECT COUNT(*) FROM devices WHERE customer_id IS NOT NULL;
   ```

### For New Installations

Simply run all migrations in order, including the new ones.

## Backward Compatibility

### PABX Table
- The PABX table remains in the database for now
- Config versions still reference pabx_id (backward compatibility)
- A default "Customer-Based" PABX entry is created automatically
- Future versions can fully deprecate PABX

### Devices
- Devices without customers are still supported
- customer_id is nullable
- Existing devices are automatically linked during migration

### Templates
- Existing templates work without modification
- New variable types are optional
- Old boolean/select variables still function

## Security Considerations

1. **CSRF Protection**: All forms include CSRF tokens
2. **SQL Injection**: All queries use prepared statements
3. **XSS Protection**: All output is properly escaped with `htmlspecialchars()`
4. **RBAC**: Customer management requires appropriate permissions
5. **Audit Logging**: All customer CRUD operations are logged

## Testing Checklist

- [ ] Database migrations run successfully
- [ ] PABX data migrated to customers correctly
- [ ] Device list pagination works (10, 25, 50, 100 per page)
- [ ] Customer search returns correct results
- [ ] Device type filter works correctly
- [ ] Can create new customer with all fields
- [ ] Can edit existing customer
- [ ] Can delete customer (devices preserved)
- [ ] Device create/edit shows customer dropdown
- [ ] Configure wizard Step 4 shows customers
- [ ] Configure wizard Step 3 handles all variable types:
  - [ ] Boolean with custom labels
  - [ ] Select dropdown
  - [ ] Multiselect
  - [ ] Number with min/max
  - [ ] Textarea
  - [ ] IP address/text fields
- [ ] Customer management visible in admin nav
- [ ] RBAC permissions working correctly
- [ ] Audit logs capture customer operations

## Known Limitations

1. **Template Variable UI**: The admin interface for managing template variables (defining new variables with types) is not included in this implementation. Template variables must be created directly in the database or via existing tools.

2. **Config Versions**: Config versions still use pabx_id internally for backward compatibility. A future migration could update this to use customer_id directly.

3. **Reporting**: Existing reports referencing PABX may need updates to use customer data instead.

## Future Enhancements

1. **Customer Portal**: Allow customers to view their devices and configs
2. **Advanced Reporting**: Customer-based analytics and reports
3. **Bulk Operations**: Import/export customers via CSV
4. **Customer Groups**: Organize customers into groups/categories
5. **Template Variable Management UI**: Full admin interface for creating/editing template variables with all field types
6. **Config Version Migration**: Remove pabx_id dependency from config_versions table
7. **API Access**: RESTful API for customer and device management

## Support

For issues or questions:
1. Check the audit logs for operation history
2. Review error logs for detailed error messages
3. Verify database migrations completed successfully
4. Ensure RBAC permissions are correctly assigned

## License

[Include your license information here]
