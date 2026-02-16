<?php
/**
 * Yealink Config Generator
 * 
 * Core functions for generating Yealink .cfg configuration files
 * with proper formatting and variable resolution.
 */

/**
 * Generate device-specific configuration
 * 
 * @param PDO $pdo Database connection
 * @param int $device_id Device ID
 * @param int|null $config_version_id Optional config version ID. If null, uses assigned config
 * @return array ['success' => bool, 'content' => string, 'error' => string|null]
 */
function generate_device_config($pdo, $device_id, $config_version_id = null) {
    try {
        // Fetch device info
        $stmt = $pdo->prepare('
            SELECT d.*, dt.type_name, p.pabx_name, p.pabx_ip, p.pabx_port
            FROM devices d
            LEFT JOIN device_types dt ON d.device_type_id = dt.id
            LEFT JOIN pabx p ON d.pabx_id = p.id
            WHERE d.id = ?
        ');
        $stmt->execute([$device_id]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$device) {
            return ['success' => false, 'content' => '', 'error' => 'Device niet gevonden'];
        }
        
        // Get config version (either specified or assigned)
        if ($config_version_id) {
            $stmt = $pdo->prepare('SELECT * FROM config_versions WHERE id = ?');
            $stmt->execute([$config_version_id]);
            $config_version = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Get assigned config
            $stmt = $pdo->prepare('
                SELECT cv.* 
                FROM device_config_assignments dca
                JOIN config_versions cv ON dca.config_version_id = cv.id
                WHERE dca.device_id = ?
                ORDER BY dca.assigned_at DESC
                LIMIT 1
            ');
            $stmt->execute([$device_id]);
            $config_version = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$config_version) {
            return ['success' => false, 'content' => '', 'error' => 'Geen configuratie toegewezen aan dit device'];
        }
        
        // Get global variables
        $stmt = $pdo->query('SELECT var_name, var_value FROM variables');
        $variables = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Resolve device-specific variables
        $variables = resolve_device_variables($pdo, $device_id, $variables);
        
        // Add device-specific data to variables
        $variables['DEVICE_NAME'] = $device['device_name'];
        $variables['DEVICE_MAC'] = $device['mac_address'] ?? '';
        $variables['DEVICE_MODEL'] = $device['type_name'] ?? '';
        $variables['PABX_NAME'] = $device['pabx_name'] ?? '';
        $variables['PABX_IP'] = $device['pabx_ip'] ?? '';
        $variables['PABX_PORT'] = $device['pabx_port'] ?? '';
        
        // Apply variables to config content
        $content = apply_variables_to_content($config_version['config_content'], $variables);
        
        // Apply Yealink formatting
        $content = apply_yealink_formatting($content);
        
        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'config_version' => $config_version,
            'device' => $device
        ];
        
    } catch (Exception $e) {
        error_log('Config generation error: ' . $e->getMessage());
        return ['success' => false, 'content' => '', 'error' => 'Fout bij genereren configuratie'];
    }
}

/**
 * Apply Yealink .cfg formatting to content
 * 
 * Ensures proper format for Yealink phones:
 * - Section headers in [SECTION] format
 * - Key=Value pairs
 * - Proper line endings (Unix style LF)
 * 
 * @param string $content Raw config content
 * @return string Formatted .cfg content
 */
function apply_yealink_formatting($content) {
    // Normalize line endings to Unix (LF)
    $content = str_replace("\r\n", "\n", $content);
    $content = str_replace("\r", "\n", $content);
    
    // Trim whitespace from each line
    $lines = explode("\n", $content);
    $formatted_lines = [];
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Skip empty lines unless they separate sections
        if (empty($trimmed)) {
            continue;
        }
        
        // Preserve section headers [SECTION]
        if (preg_match('/^\[.+\]$/', $trimmed)) {
            // Add blank line before section (except first)
            if (!empty($formatted_lines)) {
                $formatted_lines[] = '';
            }
            $formatted_lines[] = $trimmed;
        }
        // Preserve comments starting with # or ;
        elseif (preg_match('/^[#;]/', $trimmed)) {
            $formatted_lines[] = $trimmed;
        }
        // Format key=value pairs
        elseif (preg_match('/^([^=]+)=(.*)$/', $trimmed, $matches)) {
            $key = trim($matches[1]);
            $value = trim($matches[2]);
            $formatted_lines[] = "$key=$value";
        }
        // Keep other lines as-is
        else {
            $formatted_lines[] = $trimmed;
        }
    }
    
    // Join with newlines and add final newline
    return implode("\n", $formatted_lines) . "\n";
}

/**
 * Resolve device-specific variable overrides
 * 
 * Merges global variables with device-specific overrides
 * 
 * @param PDO $pdo Database connection
 * @param int $device_id Device ID
 * @param array $variables Base variables to merge with
 * @return array Merged variables
 */
function resolve_device_variables($pdo, $device_id, $variables) {
    // Check if device_variables table exists (future extension)
    // For now, just return the base variables
    
    // Future: Check for device-specific overrides
    // SELECT var_name, var_value FROM device_variables WHERE device_id = ?
    
    return $variables;
}

/**
 * Apply variable substitution to content
 * 
 * Replaces {{VAR_NAME}} placeholders with actual values
 * 
 * @param string $content Content with variables
 * @param array $variables Key-value pairs for substitution
 * @return string Content with variables replaced
 */
function apply_variables_to_content($content, $variables) {
    if (empty($variables)) {
        return $content;
    }
    
    // Replace {{VAR_NAME}} tokens (case sensitive)
    return preg_replace_callback(
        '/\{\{\s*([A-Z0-9_]+)\s*\}\}/',
        function($matches) use ($variables) {
            $key = $matches[1];
            return array_key_exists($key, $variables) ? $variables[$key] : $matches[0];
        },
        $content
    );
}

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
        
        // Apply variables
        $content = apply_variables_to_content($template['template_content'], $variables);
        
        // Apply formatting
        $content = apply_yealink_formatting($content);
        
        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'template' => $template
        ];
        
    } catch (Exception $e) {
        error_log('Template generation error: ' . $e->getMessage());
        return ['success' => false, 'content' => '', 'error' => 'Fout bij genereren configuratie'];
    }
}
?>
