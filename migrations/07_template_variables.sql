-- Template-specific Variables
-- This table stores variable definitions that are specific to templates
CREATE TABLE IF NOT EXISTS template_variables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    var_name VARCHAR(128) NOT NULL,
    var_label VARCHAR(255),
    var_type ENUM('text', 'number', 'boolean', 'select', 'ip_address') DEFAULT 'text',
    default_value TEXT,
    is_required BOOLEAN DEFAULT FALSE,
    validation_rule VARCHAR(500),
    options TEXT COMMENT 'JSON array for select type',
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_template (template_id),
    UNIQUE KEY unique_template_var (template_id, var_name),
    FOREIGN KEY (template_id) REFERENCES config_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
