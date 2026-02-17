<?php
/**
 * Create config from template
 * 
 * @param PDO $pdo Database connection
 * @param int $template_id Template ID
 * @param array $variable_values Variable values to override
 * @return array ['success' => bool, 'content' => string, 'error' => string|null]
 */
function generate_config_from_template($pdo, $template_id, $variable_values = []) {
    try {
        // Fetch template
        $stmt = $pdo->prepare('SELECT * FROM config_templates WHERE id = ? AND is_active = 1');
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            return ['success' => false, 'content' => '', 'error' => 'Template niet gevonden'];
        }
        
        // Get global variables
        $stmt = $pdo->query('SELECT var_name, var_value FROM variables');
        $variables = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Get template-specific variables
        $stmt = $pdo->prepare('SELECT var_name, default_value FROM template_variables WHERE template_id = ?');
        $stmt->execute([$template_id]);
        $template_vars = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Merge variables: global < template defaults < user provided
        $variables = array_merge($variables, $template_vars, $variable_values);
        
        // Apply variables and formatting
        $content = apply_variables_to_content($template['template_content'], $variables);
        $content = apply_yealink_formatting($content);
        
        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'template' => $template
        ];
        
    } catch (Exception $e) {
        error_log('Config generator error: ' . $e->getMessage());
        return ['success' => false, 'content' => '', 'error' => 'Fout bij genereren configuratie'];
    }
}

/**
 * Apply variables to template content
 * Replaces {{VARIABLE_NAME}} placeholders with actual values
 * 
 * @param string $content Template content with placeholders
 * @param array $variables Associative array of variable names and values
 * @return string Content with placeholders replaced by actual values
 */
function apply_variables_to_content($content, $variables) {
    // Replace all {{VARIABLE}} placeholders with their values
    foreach ($variables as $key => $value) {
        $placeholder = '{{' . $key . '}}';
        $content = str_replace($placeholder, $value, $content);
    }
    
    // Log warning for unreplaced placeholders
    preg_match_all('/\{\{([A-Za-z0-9_]+)\}\}/', $content, $matches);
    if (!empty($matches[1])) {
        error_log('WARNING: Unreplaced template placeholders: ' . implode(', ', $matches[1]));
    }
    
    return $content;
}

/**
 * Apply Yealink-specific formatting to config content
 * Ensures proper .cfg file format with Unix line endings
 * 
 * @param string $content Configuration content to format
 * @return string Formatted content suitable for Yealink devices
 */
function apply_yealink_formatting($content) {
    // Convert to Unix line endings (LF only)
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    
    // Normalize whitespace around equals signs in key=value pairs
    $content = preg_replace('/^([a-zA-Z0-9._\\[\\]]+)\s*=\s*(.*)$/m', '$1=$2', $content);
    
    // Remove trailing whitespace from each line
    $content = preg_replace('/[ \t]+$/m', '', $content);
    
    // Ensure file ends with a single newline
    $content = rtrim($content) . "\n";
    
    return $content;
}
?>
