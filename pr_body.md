# Database Migration: devices.model → devices.device_type_id

## Overview

This PR implements a safe migration from the legacy `devices.model` column (VARCHAR) to a proper relational foreign-key `devices.device_type_id` referencing the `device_types` table. This migration improves data integrity, reduces redundancy, and enables better device type management.

## Changes Made

### 1. Database Migration Script
- **File**: `db/migrations/2026_02_migrate_devices_to_device_type_id.sql`
- **Features**:
  - Creates `device_types` and `variables` tables if they don't exist
  - Adds nullable `device_type_id` column to `devices` table
  - Migrates existing `devices.model` values to `device_types` table
  - Populates `device_type_id` for all devices
  - Creates 'Unknown' fallback type for devices without a model
  - Includes validation queries and commented optional final steps (NOT NULL constraint, FK, DROP model)

### 2. CLI Migration Script
- **File**: `scripts/apply_migration_and_permissions.php`
- **Features**:
  - Dry-run mode by default (use `--yes` to execute)
  - Executes SQL migration within transaction
  - Adds RBAC permissions for Admin role:
    - `admin.device_types.manage`
    - `admin.variables.manage`
    - `variables.manage`
    - `admin.tokens.manage`
  - Comprehensive error handling and logging
  - Custom SQL file support via `--sql` parameter

### 3. Updated PHP Files
All device CRUD files already use `device_type_id`:
- ✅ `devices/create.php` - Uses device_type_id dropdown and inserts
- ✅ `devices/edit.php` - Joins device_types and updates device_type_id
- ✅ `devices/list.php` - LEFT JOINs device_types to display model_name
- ✅ `devices/delete.php` - Uses joined model_name for display and audit
- ✅ `admin/device_types_edit.php` - Checks both device_type_id and legacy model before deletion
- ✅ `admin/variables.php` - Uses `admin.variables.manage` permission
- ✅ `seed.php` - Updated to use device_type_id when available, with fallback to legacy model

## Migration Instructions

### Prerequisites
1. **Backup your database** using mysqldump:
   ```bash
   mysqldump -u [user] -p [database] > backup_$(date +%Y%m%d_%H%M%S).sql
   ```

2. **Test on staging environment first** before running on production

### Step 1: Dry-run Review
Review what the migration will do (no changes made):
```bash
php scripts/apply_migration_and_permissions.php
```

### Step 2: Execute Migration
Run the migration on your database:
```bash
php scripts/apply_migration_and_permissions.php --yes
```

### Step 3: Validation
Run these validation queries to verify the migration:

```sql
-- Check device_types were created
SELECT COUNT(*) FROM device_types;

-- Verify no devices have NULL device_type_id
SELECT COUNT(*) FROM devices WHERE device_type_id IS NULL;

-- Inspect first 50 devices with their types
SELECT d.id, d.device_name, d.mac_address, dt.type_name 
FROM devices d 
LEFT JOIN device_types dt ON d.device_type_id = dt.id 
LIMIT 50;

-- Check RBAC permissions were added
SELECT rp.permission FROM role_permissions rp
JOIN roles r ON rp.role_id = r.id
WHERE r.role_name = 'Admin';
```

Expected results:
- All devices should have a valid `device_type_id`
- Device types should match original model values
- Admin role should have the 4 new permissions

### Step 4: Test UI Pages
Manually test the following pages to ensure they work correctly:
- `/devices/list.php` - View devices list (should show model names)
- `/devices/create.php` - Create new device (dropdown should work)
- `/devices/edit.php?id=X` - Edit device (dropdown should be pre-selected)
- `/devices/delete.php?id=X` - Delete device (should show model name)
- `/admin/device_types.php` - Manage device types
- `/admin/variables.php` - Manage variables (permission check)
- `/config/builder.php` - Config builder (should work with device types)

### Step 5: Final Steps (OPTIONAL - After Verification)
⚠️ **Only run these after thorough testing and verification**

After you've verified the migration worked correctly and tested all functionality, you can optionally:

1. Make `device_type_id` NOT NULL:
   ```sql
   ALTER TABLE devices MODIFY device_type_id INT NOT NULL;
   ```

2. Add foreign key constraint:
   ```sql
   ALTER TABLE devices ADD CONSTRAINT fk_devices_device_type 
   FOREIGN KEY (device_type_id) REFERENCES device_types(id) 
   ON DELETE RESTRICT ON UPDATE CASCADE;
   ```

3. Drop legacy `model` column:
   ```sql
   ALTER TABLE devices DROP COLUMN model;
   ```

**Important**: Make another backup before running these final steps!

## RBAC Permissions Added

The migration script automatically adds these permissions to the Admin role:

| Permission | Purpose |
|------------|---------|
| `admin.device_types.manage` | Manage device types (create, edit, delete) |
| `admin.variables.manage` | Manage global variables |
| `variables.manage` | Alternative permission for variables |
| `admin.tokens.manage` | Manage download tokens |

## Safety Features

✅ Migration runs in a transaction (will rollback on error)  
✅ Uses `IF NOT EXISTS` for table creation  
✅ `device_type_id` is initially nullable for safety  
✅ Creates 'Unknown' fallback type for devices without models  
✅ Dry-run mode by default in CLI script  
✅ Commented optional final steps (NOT NULL, FK, DROP)  
✅ seed.php automatically detects and uses correct column  

## Rollback Plan

If issues occur after migration:

1. Restore from backup:
   ```bash
   mysql -u [user] -p [database] < backup_file.sql
   ```

2. Remove added RBAC permissions (if needed):
   ```sql
   DELETE FROM role_permissions 
   WHERE permission IN (
     'admin.device_types.manage',
     'admin.variables.manage', 
     'variables.manage',
     'admin.tokens.manage'
   );
   ```

## Testing Checklist

- [ ] Backup database created
- [ ] Migration tested on staging environment
- [ ] Dry-run executed and reviewed
- [ ] Migration executed successfully with `--yes`
- [ ] Validation queries run and verified
- [ ] All UI pages tested manually
- [ ] RBAC permissions verified
- [ ] No NULL device_type_id values remain
- [ ] Device types match original model values
- [ ] Config builder works with device types

## Notes

- The migration is designed to be **idempotent** - safe to run multiple times
- Existing code already uses `device_type_id`, so no breaking changes
- The `model` column is NOT dropped automatically for safety
- Legacy `model` column can be removed later after thorough verification
- All device CRUD operations have been verified to use the new schema

## Questions?

If you encounter any issues during migration, please:
1. Check error logs in the database and PHP error log
2. Review the validation queries output
3. Ensure database user has proper ALTER/CREATE permissions
4. Contact the development team for assistance
