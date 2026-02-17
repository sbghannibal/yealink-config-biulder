-- Migration: Refactor to 3-tier role system
-- Date: 2026-02-17

START TRANSACTION;

-- Step 1: Rename existing roles to new system
UPDATE roles SET 
    role_name = 'Owner',
    description = 'Full system access - can manage users, roles, and all features'
WHERE role_name = 'Admin'; -- Current Admin becomes Owner

UPDATE roles SET 
    role_name = 'Expert',
    description = 'Advanced access - everything except user/role management'
WHERE role_name = 'Manager'; -- Current Manager becomes Expert

UPDATE roles SET 
    role_name = 'Tech',
    description = 'Basic access - manage customers and devices'
WHERE role_name = 'Operator'; -- Current Operator becomes Tech

-- Step 2: Delete unused Viewer role (get its ID first)
DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE role_name = 'Viewer');
DELETE FROM admin_roles WHERE role_id IN (SELECT id FROM roles WHERE role_name = 'Viewer');
DELETE FROM roles WHERE role_name = 'Viewer';

-- Step 3: Clear existing permissions for the renamed roles (we'll reassign properly)
DELETE FROM role_permissions WHERE role_id IN (
    SELECT id FROM roles WHERE role_name IN ('Owner', 'Expert', 'Tech')
);

-- Step 4: Assign permissions to OWNER
-- Owner has EVERYTHING
INSERT INTO role_permissions (role_id, permission) 
SELECT id, 'admin.accounts.manage' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.audit.view' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.device_types.manage' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.manage' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.roles.manage' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.settings.edit' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.templates.manage' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.tokens.manage' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.users.create' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.users.delete' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.users.edit' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.users.view' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'admin.variables.manage' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'config.manage' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'devices.create' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'devices.edit' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'devices.delete' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'devices.view' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'devices.manage' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'customers.create' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'customers.edit' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'customers.delete' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'customers.view' FROM roles WHERE role_name = 'Owner'
UNION ALL SELECT id, 'variables.manage' FROM roles WHERE role_name = 'Owner';

-- Step 5: Assign permissions to EXPERT
-- Expert: Everything EXCEPT user/role management
INSERT INTO role_permissions (role_id, permission)
SELECT id, 'admin.accounts.manage' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'admin.audit.view' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'admin.device_types.manage' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'admin.settings.edit' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'admin.templates.manage' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'admin.tokens.manage' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'admin.variables.manage' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'config.manage' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'devices.create' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'devices.edit' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'devices.delete' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'devices.view' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'devices.manage' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'customers.create' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'customers.edit' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'customers.delete' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'customers.view' FROM roles WHERE role_name = 'Expert'
UNION ALL SELECT id, 'variables.manage' FROM roles WHERE role_name = 'Expert';

-- Step 6: Assign permissions to TECH
-- Tech: Only customers and devices
INSERT INTO role_permissions (role_id, permission)
SELECT id, 'devices.create' FROM roles WHERE role_name = 'Tech'
UNION ALL SELECT id, 'devices.edit' FROM roles WHERE role_name = 'Tech'
UNION ALL SELECT id, 'devices.delete' FROM roles WHERE role_name = 'Tech'
UNION ALL SELECT id, 'devices.view' FROM roles WHERE role_name = 'Tech'
UNION ALL SELECT id, 'devices.manage' FROM roles WHERE role_name = 'Tech'
UNION ALL SELECT id, 'customers.create' FROM roles WHERE role_name = 'Tech'
UNION ALL SELECT id, 'customers.edit' FROM roles WHERE role_name = 'Tech'
UNION ALL SELECT id, 'customers.view' FROM roles WHERE role_name = 'Tech'
UNION ALL SELECT id, 'config.manage' FROM roles WHERE role_name = 'Tech';  -- Can use config wizard

COMMIT;
