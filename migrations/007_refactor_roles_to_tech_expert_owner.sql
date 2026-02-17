-- Migration: Refactor to 3-tier role system
-- Date: 2026-02-17

START TRANSACTION;

-- Step 1: Rename existing roles to new system
UPDATE roles SET 
    role_name = 'Owner',
    description = 'Full system access - can manage users, roles, and all features'
WHERE id = 1; -- Current Admin becomes Owner

UPDATE roles SET 
    role_name = 'Expert',
    description = 'Advanced access - everything except user/role management'
WHERE id = 2; -- Current Manager becomes Expert

UPDATE roles SET 
    role_name = 'Tech',
    description = 'Basic access - manage customers and devices'
WHERE id = 3; -- Current Operator becomes Tech

-- Step 2: Delete unused Viewer role
DELETE FROM role_permissions WHERE role_id = 4;
DELETE FROM admin_roles WHERE role_id = 4;
DELETE FROM roles WHERE id = 4;

-- Step 3: Clear existing permissions (we'll reassign properly)
DELETE FROM role_permissions WHERE role_id IN (1, 2, 3);

-- Step 4: Assign permissions to OWNER (role_id = 1)
-- Owner has EVERYTHING
INSERT INTO role_permissions (role_id, permission) VALUES
(1, 'admin.accounts.manage'),
(1, 'admin.audit.view'),
(1, 'admin.device_types.manage'),
(1, 'admin.manage'),
(1, 'admin.roles.manage'),
(1, 'admin.settings.edit'),
(1, 'admin.templates.manage'),
(1, 'admin.tokens.manage'),
(1, 'admin.users.create'),
(1, 'admin.users.delete'),
(1, 'admin.users.edit'),
(1, 'admin.users.view'),
(1, 'admin.variables.manage'),
(1, 'config.manage'),
(1, 'devices.create'),
(1, 'devices.edit'),
(1, 'devices.delete'),
(1, 'devices.view'),
(1, 'devices.manage'),
(1, 'customers.create'),
(1, 'customers.edit'),
(1, 'customers.delete'),
(1, 'customers.view'),
(1, 'variables.manage');

-- Step 5: Assign permissions to EXPERT (role_id = 2)
-- Expert: Everything EXCEPT user/role management
INSERT INTO role_permissions (role_id, permission) VALUES
(2, 'admin.accounts.manage'),
(2, 'admin.audit.view'),
(2, 'admin.device_types.manage'),
(2, 'admin.settings.edit'),
(2, 'admin.templates.manage'),
(2, 'admin.tokens.manage'),
(2, 'admin.variables.manage'),
(2, 'config.manage'),
(2, 'devices.create'),
(2, 'devices.edit'),
(2, 'devices.delete'),
(2, 'devices.view'),
(2, 'devices.manage'),
(2, 'customers.create'),
(2, 'customers.edit'),
(2, 'customers.delete'),
(2, 'customers.view'),
(2, 'variables.manage');

-- Step 6: Assign permissions to TECH (role_id = 3)
-- Tech: Only customers and devices
INSERT INTO role_permissions (role_id, permission) VALUES
(3, 'devices.create'),
(3, 'devices.edit'),
(3, 'devices.delete'),
(3, 'devices.view'),
(3, 'devices.manage'),
(3, 'customers.create'),
(3, 'customers.edit'),
(3, 'customers.view'),
(3, 'config.manage');  -- Can use config wizard

-- Step 7: Update current admin user to Owner role (already done, but ensure it)
-- admin_roles should already have admin (id=1) linked to role 1 (now Owner)

COMMIT;
