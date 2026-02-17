<?php
/**
 * Form Input Rendering Helpers
 * 
 * Provides functions to render different input types for template variables
 * in the configuration wizard and other forms.
 */

/**
 * Render an input field based on variable type
 * 
 * @param array $variable Variable definition from template_variables table
 * @param mixed $current_value Current value to display
 * @param array $options Additional options (e.g., 'name_prefix', 'show_label', 'css_class')
 * @return string HTML for the input field
 */
function render_variable_input($variable, $current_value = null, $options = []) {
    $var_type = $variable['var_type'] ?? 'text';
    $var_name = $variable['var_name'];
    $name_prefix = $options['name_prefix'] ?? 'var_';
    $show_label = $options['show_label'] ?? true;
    $css_class = $options['css_class'] ?? '';
    
    // Build input name
    $input_name = $name_prefix . htmlspecialchars($var_name);
    
    // Build container
    $html = '<div class="var-input ' . htmlspecialchars($css_class) . '">';
    
    // Render label if requested
    if ($show_label) {
        $label = $variable['var_label'] ?: $var_name;
        $html .= '<label>';
        $html .= '<strong>' . htmlspecialchars($label) . '</strong>';
        if ($variable['is_required']) {
            $html .= '<span style="color:red;">*</span>';
        }
        $html .= '</label>';
    }
    
    // Render input based on type
    switch ($var_type) {
        case 'boolean':
            $html .= render_boolean_input($variable, $current_value, $input_name);
            break;
            
        case 'select':
            $html .= render_select_input($variable, $current_value, $input_name);
            break;
            
        case 'multiselect':
            $html .= render_multiselect_input($variable, $current_value, $input_name);
            break;
            
        case 'radio':
            $html .= render_radio_input($variable, $current_value, $input_name);
            break;
            
        case 'checkbox_group':
            $html .= render_checkbox_group_input($variable, $current_value, $input_name);
            break;
            
        case 'textarea':
            $html .= render_textarea_input($variable, $current_value, $input_name);
            break;
            
        case 'number':
            $html .= render_number_input($variable, $current_value, $input_name);
            break;
            
        case 'range':
            $html .= render_range_input($variable, $current_value, $input_name);
            break;
            
        case 'email':
            $html .= render_email_input($variable, $current_value, $input_name);
            break;
            
        case 'url':
            $html .= render_url_input($variable, $current_value, $input_name);
            break;
            
        case 'password':
            $html .= render_password_input($variable, $current_value, $input_name);
            break;
            
        case 'date':
            $html .= render_date_input($variable, $current_value, $input_name);
            break;
            
        case 'ip_address':
            $html .= render_ip_address_input($variable, $current_value, $input_name);
            break;
            
        case 'text':
        default:
            $html .= render_text_input($variable, $current_value, $input_name);
            break;
    }
    
    // Add help text if provided
    if (!empty($variable['help_text'])) {
        $html .= '<small style="color:#6c757d;display:block;margin-top:4px;">' . 
                 htmlspecialchars($variable['help_text']) . '</small>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Render text input
 */
function render_text_input($variable, $current_value, $input_name) {
    $attrs = build_common_attributes($variable, $input_name, $current_value);
    return '<input type="text" ' . $attrs . '>';
}

/**
 * Render email input
 */
function render_email_input($variable, $current_value, $input_name) {
    $attrs = build_common_attributes($variable, $input_name, $current_value);
    return '<input type="email" ' . $attrs . '>';
}

/**
 * Render URL input
 */
function render_url_input($variable, $current_value, $input_name) {
    $attrs = build_common_attributes($variable, $input_name, $current_value);
    return '<input type="url" ' . $attrs . '>';
}

/**
 * Render password input
 */
function render_password_input($variable, $current_value, $input_name) {
    $attrs = build_common_attributes($variable, $input_name, $current_value);
    return '<input type="password" ' . $attrs . '>';
}

/**
 * Render IP address input
 */
function render_ip_address_input($variable, $current_value, $input_name) {
    $attrs = build_common_attributes($variable, $input_name, $current_value);
    $attrs .= ' pattern="^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$"';
    return '<input type="text" ' . $attrs . '>';
}

/**
 * Render date input
 */
function render_date_input($variable, $current_value, $input_name) {
    $attrs = build_common_attributes($variable, $input_name, $current_value);
    return '<input type="date" ' . $attrs . '>';
}

/**
 * Render number input
 */
function render_number_input($variable, $current_value, $input_name) {
    $attrs = build_common_attributes($variable, $input_name, $current_value);
    
    if (isset($variable['min_value']) && $variable['min_value'] !== null) {
        $attrs .= ' min="' . (int)$variable['min_value'] . '"';
    }
    if (isset($variable['max_value']) && $variable['max_value'] !== null) {
        $attrs .= ' max="' . (int)$variable['max_value'] . '"';
    }
    
    return '<input type="number" ' . $attrs . '>';
}

/**
 * Render range input (slider)
 */
function render_range_input($variable, $current_value, $input_name) {
    $min = $variable['min_value'] ?? 0;
    $max = $variable['max_value'] ?? 100;
    $value = $current_value ?: $min;
    
    $attrs = 'name="' . htmlspecialchars($input_name) . '" ';
    $attrs .= 'id="' . htmlspecialchars($input_name) . '" ';
    $attrs .= 'min="' . (int)$min . '" ';
    $attrs .= 'max="' . (int)$max . '" ';
    $attrs .= 'value="' . htmlspecialchars($value) . '" ';
    
    if ($variable['is_required']) {
        $attrs .= 'required ';
    }
    
    $html = '<div style="display:flex;align-items:center;gap:12px;">';
    $html .= '<input type="range" ' . $attrs . ' style="flex:1;" ';
    $html .= 'oninput="document.getElementById(\'' . htmlspecialchars($input_name) . '_display\').textContent = this.value">';
    $html .= '<span id="' . htmlspecialchars($input_name) . '_display" style="min-width:40px;text-align:right;font-weight:bold;">' . htmlspecialchars($value) . '</span>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Render textarea input
 */
function render_textarea_input($variable, $current_value, $input_name) {
    $attrs = 'name="' . htmlspecialchars($input_name) . '" ';
    $attrs .= 'id="' . htmlspecialchars($input_name) . '" ';
    $attrs .= 'rows="4" ';
    
    if ($variable['is_required']) {
        $attrs .= 'required ';
    }
    
    if (!empty($variable['placeholder'])) {
        $attrs .= 'placeholder="' . htmlspecialchars($variable['placeholder']) . '" ';
    }
    
    return '<textarea ' . $attrs . '>' . htmlspecialchars($current_value ?: '') . '</textarea>';
}

/**
 * Render boolean select dropdown
 */
function render_boolean_input($variable, $current_value, $input_name) {
    $options = json_decode($variable['options'], true) ?: [
        ['value' => '0', 'label' => 'Nee'],
        ['value' => '1', 'label' => 'Ja']
    ];
    
    $html = '<select name="' . htmlspecialchars($input_name) . '" ';
    $html .= 'id="' . htmlspecialchars($input_name) . '" ';
    if ($variable['is_required']) {
        $html .= 'required ';
    }
    $html .= '>';
    
    foreach ($options as $opt) {
        $value = $opt['value'];
        $label = $opt['label'];
        $selected = ($value == $current_value) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>' . 
                 htmlspecialchars($label) . '</option>';
    }
    
    $html .= '</select>';
    
    return $html;
}

/**
 * Render select dropdown
 */
function render_select_input($variable, $current_value, $input_name) {
    $options = json_decode($variable['options'], true) ?: [];
    
    $html = '<select name="' . htmlspecialchars($input_name) . '" ';
    $html .= 'id="' . htmlspecialchars($input_name) . '" ';
    if ($variable['is_required']) {
        $html .= 'required ';
    }
    $html .= '>';
    
    $html .= '<option value="">-- Selecteer --</option>';
    
    foreach ($options as $opt) {
        $value = is_array($opt) ? $opt['value'] : $opt;
        $label = is_array($opt) ? $opt['label'] : $opt;
        $selected = ($value == $current_value) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>' . 
                 htmlspecialchars($label) . '</option>';
    }
    
    $html .= '</select>';
    
    return $html;
}

/**
 * Render multiselect dropdown
 */
function render_multiselect_input($variable, $current_value, $input_name) {
    $options = json_decode($variable['options'], true) ?: [];
    $current_values = is_array($current_value) ? $current_value : array_filter(array_map('trim', explode(',', $current_value ?: '')));
    
    $html = '<select name="' . htmlspecialchars($input_name) . '[]" ';
    $html .= 'id="' . htmlspecialchars($input_name) . '" ';
    $html .= 'multiple size="5" ';
    if ($variable['is_required']) {
        $html .= 'required ';
    }
    $html .= '>';
    
    foreach ($options as $opt) {
        $value = is_array($opt) ? $opt['value'] : $opt;
        $label = is_array($opt) ? $opt['label'] : $opt;
        $selected = in_array($value, $current_values) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>' . 
                 htmlspecialchars($label) . '</option>';
    }
    
    $html .= '</select>';
    $html .= '<small style="color:#6c757d;display:block;margin-top:4px;">Houd Ctrl/Cmd ingedrukt om meerdere opties te selecteren</small>';
    
    return $html;
}

/**
 * Render radio buttons
 */
function render_radio_input($variable, $current_value, $input_name) {
    $options = json_decode($variable['options'], true) ?: [];
    
    $html = '<div style="display:flex;flex-direction:column;gap:8px;">';
    
    foreach ($options as $idx => $opt) {
        $value = is_array($opt) ? $opt['value'] : $opt;
        $label = is_array($opt) ? $opt['label'] : $opt;
        $checked = ($value == $current_value) ? 'checked' : '';
        $radio_id = htmlspecialchars($input_name . '_' . $idx);
        
        $html .= '<label style="display:flex;align-items:center;gap:8px;">';
        $html .= '<input type="radio" name="' . htmlspecialchars($input_name) . '" ';
        $html .= 'id="' . $radio_id . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        if ($variable['is_required'] && $idx === 0) {
            $html .= 'required ';
        }
        $html .= $checked . '>';
        $html .= '<span>' . htmlspecialchars($label) . '</span>';
        $html .= '</label>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Render checkbox group
 */
function render_checkbox_group_input($variable, $current_value, $input_name) {
    $options = json_decode($variable['options'], true) ?: [];
    $current_values = is_array($current_value) ? $current_value : array_filter(array_map('trim', explode(',', $current_value ?: '')));
    
    $html = '<div style="display:flex;flex-direction:column;gap:8px;">';
    
    foreach ($options as $idx => $opt) {
        $value = is_array($opt) ? $opt['value'] : $opt;
        $label = is_array($opt) ? $opt['label'] : $opt;
        $checked = in_array($value, $current_values) ? 'checked' : '';
        $checkbox_id = htmlspecialchars($input_name . '_' . $idx);
        
        $html .= '<label style="display:flex;align-items:center;gap:8px;">';
        $html .= '<input type="checkbox" name="' . htmlspecialchars($input_name) . '[]" ';
        $html .= 'id="' . $checkbox_id . '" ';
        $html .= 'value="' . htmlspecialchars($value) . '" ';
        $html .= $checked . '>';
        $html .= '<span>' . htmlspecialchars($label) . '</span>';
        $html .= '</label>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Build common HTML attributes for input fields
 */
function build_common_attributes($variable, $input_name, $current_value) {
    $attrs = 'name="' . htmlspecialchars($input_name) . '" ';
    $attrs .= 'id="' . htmlspecialchars($input_name) . '" ';
    $attrs .= 'value="' . htmlspecialchars($current_value ?: '') . '" ';
    
    if ($variable['is_required']) {
        $attrs .= 'required ';
    }
    
    if (!empty($variable['placeholder'])) {
        $attrs .= 'placeholder="' . htmlspecialchars($variable['placeholder']) . '" ';
    }
    
    if (!empty($variable['regex_pattern'])) {
        // Only add pattern for text-like inputs
        $pattern = $variable['regex_pattern'];
        // Ensure pattern doesn't have delimiters for HTML pattern attribute
        $pattern = trim($pattern, '/');
        $attrs .= 'pattern="' . htmlspecialchars($pattern) . '" ';
    }
    
    return $attrs;
}

/**
 * Get display value for a variable (for showing in summaries/previews)
 * 
 * @param mixed $value The value to format
 * @param array $variable Variable definition
 * @return string Formatted display value
 */
function get_variable_display_value($value, $variable) {
    $var_type = $variable['var_type'] ?? 'text';
    
    switch ($var_type) {
        case 'boolean':
            $options = json_decode($variable['options'], true);
            if (is_array($options)) {
                foreach ($options as $opt) {
                    if ($opt['value'] == $value) {
                        return $opt['label'];
                    }
                }
            }
            return $value ? 'Ja' : 'Nee';
            
        case 'password':
            return '••••••••';
            
        case 'multiselect':
        case 'checkbox_group':
            if (is_array($value)) {
                return implode(', ', $value);
            }
            return $value;
            
        case 'date':
            if (empty($value)) return '';
            $date = date_create($value);
            if ($date) {
                return date_format($date, 'd-m-Y');
            }
            return $value;
            
        default:
            return $value;
    }
}
?>
