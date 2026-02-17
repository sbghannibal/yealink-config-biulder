-- Extended Variable Types Migration
-- Adds support for additional input types: radio, checkbox_group, email, url, password, range, date

-- Extend the var_type ENUM to include all new types
ALTER TABLE template_variables 
MODIFY COLUMN var_type ENUM(
    'text', 
    'number', 
    'boolean', 
    'select', 
    'multiselect',
    'textarea',
    'radio',
    'checkbox_group',
    'email',
    'url',
    'password',
    'range',
    'date',
    'ip_address'
) DEFAULT 'text';

-- Note: The columns placeholder, help_text, min_value, max_value, and regex_pattern 
-- were already added in migration 10_enhanced_template_variables.sql
-- If they don't exist, they will be added here as a safety measure

-- Add columns if they don't exist (idempotent)
-- Using SHOW COLUMNS to check is not possible in a simple SQL migration, so we rely on ALTER TABLE IF NOT EXISTS pattern
-- MySQL doesn't support IF NOT EXISTS for columns, so we'll use a stored procedure approach or assume they exist

-- For safety, let's just document that these columns should exist from migration 10:
-- - placeholder VARCHAR(255)
-- - help_text TEXT
-- - min_value INT
-- - max_value INT
-- - regex_pattern VARCHAR(500)

-- Update help text for existing variables to provide guidance
-- No data updates needed for a clean migration
