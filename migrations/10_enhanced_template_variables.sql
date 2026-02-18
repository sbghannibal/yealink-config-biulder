-- Enhance template_variables table with more field types and options

ALTER TABLE template_variables 
    MODIFY COLUMN var_type ENUM(
        'text', 
        'number', 
        'boolean', 
        'select', 
        'multiselect',
        'ip_address',
        'textarea'
    ) DEFAULT 'text';

-- Add columns for enhanced functionality
ALTER TABLE template_variables
    ADD COLUMN placeholder VARCHAR(255) COMMENT 'Placeholder text for input fields',
    ADD COLUMN help_text TEXT COMMENT 'Help text shown below field',
    ADD COLUMN min_value INT COMMENT 'Minimum value for number type',
    ADD COLUMN max_value INT COMMENT 'Maximum value for number type',
    ADD COLUMN regex_pattern VARCHAR(500) COMMENT 'Regex validation pattern';

-- Update existing boolean fields to have proper options
UPDATE template_variables 
SET options = JSON_ARRAY(
    JSON_OBJECT('value', '0', 'label', 'Uit/Disabled'),
    JSON_OBJECT('value', '1', 'label', 'Aan/Enabled')
)
WHERE var_type = 'boolean' AND (options IS NULL OR options = '');
