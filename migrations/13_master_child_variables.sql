-- Master/Child Variable Relationships
-- Adds support for conditional display of child variables based on parent variable values

ALTER TABLE template_variables
    ADD COLUMN variable_group VARCHAR(128) DEFAULT NULL COMMENT 'Group name for related variables',
    ADD COLUMN is_group_master TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether this variable controls child visibility',
    ADD COLUMN parent_variable_id INT DEFAULT NULL COMMENT 'ID of the parent (master) variable',
    ADD COLUMN show_when_parent ENUM('always', 'true', 'false', 'not_empty') NOT NULL DEFAULT 'always' COMMENT 'Condition under which this child is shown',
    ADD CONSTRAINT fk_parent_variable
        FOREIGN KEY (parent_variable_id) REFERENCES template_variables(id) ON DELETE SET NULL;

-- Index for efficient parent lookups
ALTER TABLE template_variables
    ADD INDEX idx_parent_variable (parent_variable_id);
