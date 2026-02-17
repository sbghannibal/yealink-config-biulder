<?php
/**
 * Variable Validation Functions
 * 
 * Provides server-side validation for different variable types
 * used in templates and configuration wizard.
 */

/**
 * Validate a variable value based on its type and constraints
 * 
 * @param mixed $value The value to validate
 * @param array $variable Variable definition from template_variables table
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validate_variable_value($value, $variable) {
    $var_type = $variable['var_type'] ?? 'text';
    $is_required = $variable['is_required'] ?? false;
    
    // Check required fields
    if ($is_required && (is_null($value) || $value === '')) {
        return [
            'valid' => false,
            'error' => 'Dit veld is verplicht'
        ];
    }
    
    // Empty non-required fields are valid
    if (!$is_required && (is_null($value) || $value === '')) {
        return ['valid' => true, 'error' => null];
    }
    
    // Type-specific validation
    switch ($var_type) {
        case 'email':
            return validate_email($value);
            
        case 'url':
            return validate_url($value);
            
        case 'ip_address':
            return validate_ip_address($value);
            
        case 'number':
        case 'range':
            return validate_number($value, $variable);
            
        case 'date':
            return validate_date($value);
            
        case 'multiselect':
        case 'checkbox_group':
            return validate_array_value($value, $variable);
            
        case 'password':
            return validate_password($value, $variable);
            
        case 'text':
        case 'textarea':
        case 'boolean':
        case 'select':
        case 'radio':
        default:
            // Apply regex pattern if specified
            if (!empty($variable['regex_pattern'])) {
                return validate_regex($value, $variable['regex_pattern']);
            }
            return ['valid' => true, 'error' => null];
    }
}

/**
 * Validate email address
 */
function validate_email($value) {
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return [
            'valid' => false,
            'error' => 'Ongeldig e-mailadres'
        ];
    }
    return ['valid' => true, 'error' => null];
}

/**
 * Validate URL
 */
function validate_url($value) {
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return [
            'valid' => false,
            'error' => 'Ongeldige URL (bijv. https://example.com)'
        ];
    }
    
    // Check if URL has a valid scheme
    $parsed = parse_url($value);
    if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https', 'ftp', 'ftps'])) {
        return [
            'valid' => false,
            'error' => 'URL moet beginnen met http://, https://, ftp:// of ftps://'
        ];
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate IP address (v4 or v6)
 */
function validate_ip_address($value) {
    if (!filter_var($value, FILTER_VALIDATE_IP)) {
        return [
            'valid' => false,
            'error' => 'Ongeldig IP-adres'
        ];
    }
    return ['valid' => true, 'error' => null];
}

/**
 * Validate number with min/max constraints
 */
function validate_number($value, $variable) {
    if (!is_numeric($value)) {
        return [
            'valid' => false,
            'error' => 'Waarde moet een getal zijn'
        ];
    }
    
    $num = floatval($value);
    
    // Check min value
    if (isset($variable['min_value']) && $variable['min_value'] !== null) {
        if ($num < $variable['min_value']) {
            return [
                'valid' => false,
                'error' => 'Waarde moet minimaal ' . $variable['min_value'] . ' zijn'
            ];
        }
    }
    
    // Check max value
    if (isset($variable['max_value']) && $variable['max_value'] !== null) {
        if ($num > $variable['max_value']) {
            return [
                'valid' => false,
                'error' => 'Waarde mag maximaal ' . $variable['max_value'] . ' zijn'
            ];
        }
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate date format
 */
function validate_date($value) {
    // Try to parse the date
    $date = date_parse($value);
    
    if ($date === false || $date['error_count'] > 0 || !checkdate($date['month'], $date['day'], $date['year'])) {
        return [
            'valid' => false,
            'error' => 'Ongeldige datum (gebruik formaat YYYY-MM-DD)'
        ];
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate array values (for multiselect and checkbox_group)
 */
function validate_array_value($value, $variable) {
    // Value should be comma-separated string or array
    if (is_array($value)) {
        $values = $value;
    } else {
        $values = array_filter(array_map('trim', explode(',', $value)));
    }
    
    if (empty($values)) {
        return ['valid' => true, 'error' => null];
    }
    
    // If options are defined, validate against allowed values
    if (!empty($variable['options'])) {
        $allowed_values = [];
        $options = json_decode($variable['options'], true);
        
        if (is_array($options)) {
            foreach ($options as $opt) {
                if (is_array($opt)) {
                    $allowed_values[] = $opt['value'];
                } else {
                    $allowed_values[] = $opt;
                }
            }
            
            // Check if all selected values are in allowed values
            $invalid = array_diff($values, $allowed_values);
            if (!empty($invalid)) {
                return [
                    'valid' => false,
                    'error' => 'Ongeldige selectie: ' . implode(', ', $invalid)
                ];
            }
        }
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate password
 */
function validate_password($value, $variable) {
    // Basic password validation
    // Minimum length check if min_value is set
    if (isset($variable['min_value']) && $variable['min_value'] !== null) {
        if (strlen($value) < $variable['min_value']) {
            return [
                'valid' => false,
                'error' => 'Wachtwoord moet minimaal ' . $variable['min_value'] . ' tekens bevatten'
            ];
        }
    }
    
    // Apply regex pattern if specified
    if (!empty($variable['regex_pattern'])) {
        return validate_regex($value, $variable['regex_pattern']);
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate against regex pattern
 */
function validate_regex($value, $pattern) {
    // Ensure pattern has delimiters
    if (substr($pattern, 0, 1) !== '/' && substr($pattern, 0, 1) !== '#') {
        $pattern = '/' . $pattern . '/';
    }
    
    if (@preg_match($pattern, $value) === false) {
        return [
            'valid' => false,
            'error' => 'Ongeldige reguliere expressie in validatiepatroon'
        ];
    }
    
    if (!preg_match($pattern, $value)) {
        return [
            'valid' => false,
            'error' => 'Waarde voldoet niet aan het verwachte formaat'
        ];
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate multiple variables at once
 * 
 * @param array $values Associative array of variable_name => value
 * @param array $variables Array of variable definitions
 * @return array ['valid' => bool, 'errors' => array of field_name => error_message]
 */
function validate_variables($values, $variables) {
    $errors = [];
    $all_valid = true;
    
    foreach ($variables as $variable) {
        $var_name = $variable['var_name'];
        $value = $values[$var_name] ?? null;
        
        $result = validate_variable_value($value, $variable);
        
        if (!$result['valid']) {
            $all_valid = false;
            $errors[$var_name] = $result['error'];
        }
    }
    
    return [
        'valid' => $all_valid,
        'errors' => $errors
    ];
}
?>
