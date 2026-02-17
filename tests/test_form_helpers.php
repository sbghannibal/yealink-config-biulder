<?php
/**
 * Test script for form rendering functions
 * Run with: php tests/test_form_helpers.php
 */

require_once __DIR__ . '/../includes/form_helpers.php';

echo "=== Testing Form Rendering Functions ===\n\n";

// Test 1: Text input
echo "Test 1: Text Input\n";
$text_var = [
    'var_name' => 'TEST_TEXT',
    'var_label' => 'Test Text',
    'var_type' => 'text',
    'is_required' => true,
    'placeholder' => 'Enter text'
];
$html = render_variable_input($text_var, 'default value');
echo "  Generated HTML: " . (strpos($html, 'type="text"') !== false ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Has required attribute: " . (strpos($html, 'required') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 2: Email input
echo "\nTest 2: Email Input\n";
$email_var = [
    'var_name' => 'TEST_EMAIL',
    'var_type' => 'email',
    'is_required' => true
];
$html = render_variable_input($email_var, 'test@example.com', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'type="email"') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 3: Number input with min/max
echo "\nTest 3: Number Input\n";
$number_var = [
    'var_name' => 'TEST_NUMBER',
    'var_type' => 'number',
    'min_value' => 0,
    'max_value' => 100,
    'is_required' => false
];
$html = render_variable_input($number_var, '50', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'type="number"') !== false ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Has min/max: " . (strpos($html, 'min=') !== false && strpos($html, 'max=') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 4: Range slider
echo "\nTest 4: Range Slider\n";
$range_var = [
    'var_name' => 'TEST_RANGE',
    'var_type' => 'range',
    'min_value' => 0,
    'max_value' => 10,
    'is_required' => false
];
$html = render_variable_input($range_var, '5', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'type="range"') !== false ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Has display element: " . (strpos($html, '_display') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 5: Select dropdown
echo "\nTest 5: Select Dropdown\n";
$select_var = [
    'var_name' => 'TEST_SELECT',
    'var_type' => 'select',
    'options' => json_encode([
        ['value' => 'opt1', 'label' => 'Option 1'],
        ['value' => 'opt2', 'label' => 'Option 2']
    ]),
    'is_required' => true
];
$html = render_variable_input($select_var, 'opt1', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, '<select') !== false ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Has options: " . (strpos($html, 'Option 1') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 6: Multiselect
echo "\nTest 6: Multiselect\n";
$multiselect_var = [
    'var_name' => 'TEST_MULTI',
    'var_type' => 'multiselect',
    'options' => json_encode(['opt1', 'opt2', 'opt3']),
    'is_required' => false
];
$html = render_variable_input($multiselect_var, 'opt1,opt2', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'multiple') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 7: Radio buttons
echo "\nTest 7: Radio Buttons\n";
$radio_var = [
    'var_name' => 'TEST_RADIO',
    'var_type' => 'radio',
    'options' => json_encode([
        ['value' => 'yes', 'label' => 'Yes'],
        ['value' => 'no', 'label' => 'No']
    ]),
    'is_required' => true
];
$html = render_variable_input($radio_var, 'yes', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'type="radio"') !== false ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Has multiple radios: " . (substr_count($html, 'type="radio"') >= 2 ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 8: Checkbox group
echo "\nTest 8: Checkbox Group\n";
$checkbox_var = [
    'var_name' => 'TEST_CHECKBOX',
    'var_type' => 'checkbox_group',
    'options' => json_encode(['feat1', 'feat2', 'feat3']),
    'is_required' => false
];
$html = render_variable_input($checkbox_var, 'feat1,feat2', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'type="checkbox"') !== false ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Has multiple checkboxes: " . (substr_count($html, 'type="checkbox"') >= 3 ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 9: Textarea
echo "\nTest 9: Textarea\n";
$textarea_var = [
    'var_name' => 'TEST_TEXTAREA',
    'var_type' => 'textarea',
    'placeholder' => 'Enter multiple lines',
    'is_required' => false
];
$html = render_variable_input($textarea_var, 'Sample text', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, '<textarea') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 10: Password input
echo "\nTest 10: Password Input\n";
$password_var = [
    'var_name' => 'TEST_PASSWORD',
    'var_type' => 'password',
    'is_required' => true
];
$html = render_variable_input($password_var, 'secret', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'type="password"') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 11: Date input
echo "\nTest 11: Date Input\n";
$date_var = [
    'var_name' => 'TEST_DATE',
    'var_type' => 'date',
    'is_required' => false
];
$html = render_variable_input($date_var, '2024-12-31', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'type="date"') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 12: URL input
echo "\nTest 12: URL Input\n";
$url_var = [
    'var_name' => 'TEST_URL',
    'var_type' => 'url',
    'is_required' => false
];
$html = render_variable_input($url_var, 'https://example.com', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'type="url"') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 13: IP address input
echo "\nTest 13: IP Address Input\n";
$ip_var = [
    'var_name' => 'TEST_IP',
    'var_type' => 'ip_address',
    'is_required' => true
];
$html = render_variable_input($ip_var, '192.168.1.1', ['show_label' => false]);
echo "  Generated HTML: " . (strpos($html, 'pattern=') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 14: Help text
echo "\nTest 14: Help Text\n";
$help_var = [
    'var_name' => 'TEST_HELP',
    'var_type' => 'text',
    'help_text' => 'This is helpful information',
    'is_required' => false
];
$html = render_variable_input($help_var, '', ['show_label' => false]);
echo "  Has help text: " . (strpos($html, 'This is helpful information') !== false ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 15: Display value function
echo "\nTest 15: Display Value Function\n";
$boolean_var = [
    'var_type' => 'boolean',
    'options' => json_encode([
        ['value' => '0', 'label' => 'Disabled'],
        ['value' => '1', 'label' => 'Enabled']
    ])
];
$display = get_variable_display_value('1', $boolean_var);
echo "  Boolean display: " . ($display === 'Enabled' ? '✓ PASS' : '✗ FAIL') . "\n";

$password_var = ['var_type' => 'password'];
$display = get_variable_display_value('secretpassword', $password_var);
echo "  Password masked: " . ($display === '••••••••' ? '✓ PASS' : '✗ FAIL') . "\n";

echo "\n=== All tests completed ===\n";
?>
