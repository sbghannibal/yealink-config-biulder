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
        error_log('=== GENERATOR DEBUG START ===');
        error_log('Template ID: ' . $template_id);
        error_log('Variable values: ' . json_encode($variable_values));
        
        // Fetch template
        $stmt = $pdo->prepare('SELECT * FROM config_templates WHERE id = ? AND is_active = 1');
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log('Template found: ' . ($template ? 'YES' : 'NO'));
        if (!$template) {
            error_log('Template NOT found - returning error');
            return ['success' => false, 'content' => '', 'error' => 'Template niet gevonden'];
        }
        
        error_log('Template name: ' . $template['template_name']);
        error_log('Template content length: ' . strlen($template['template_content'] ?? ''));
        
        // Get global variables
        $stmt = $pdo->query('SELECT var_name, var_value FROM variables');
        $variables = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        error_log('Global variables loaded: ' . count($variables));
        
        // Get template-specific variables
        $stmt = $pdo->prepare('SELECT var_name, default_value FROM template_variables WHERE template_id = ?');
        $stmt->execute([$template_id]);
        $template_vars = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        error_log('Template variables loaded: ' . count($template_vars));
        
        // Merge variables: global < template defaults < user provided
        $variables = array_merge($variables, $template_vars, $variable_values);
        error_log('Total merged variables: ' . count($variables));
        error_log('Merged variables: ' . json_encode($variables));
        
        // Apply variables
        error_log('Before apply_variables_to_content - content length: ' . strlen($template['template_content']));
        $content = apply_variables_to_content($template['template_content'], $variables);
        error_log('After apply_variables_to_content - content length: ' . strlen($content));
        
        // Apply formatting
        error_log('Before apply_yealink_formatting');
        $content = apply_yealink_formatting($content);
        error_log('After apply_yealink_formatting - content length: ' . strlen($content));
        
        error_log('=== GENERATOR DEBUG END (SUCCESS) ===');
        
        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'template' => $template
        ];
        
    } catch (Exception $e) {
        error_log('=== GENERATOR EXCEPTION ===');
        error_log('Exception message: ' . $e->getMessage());
        error_log('Exception file: ' . $e->getFile());
        error_log('Exception line: ' . $e->getLine());
        error_log('Exception trace: ' . $e->getTraceAsString());
        error_log('=== GENERATOR EXCEPTION END ===');
        
        return ['success' => false, 'content' => '', 'error' => 'Fout bij genereren configuratie: ' . $e->getMessage()];
    }
}
?>
