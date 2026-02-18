<?php
/**
 * Test script for validation functions
 * Run with: php tests/test_validator.php
 */

require_once __DIR__ . '/../settings/validator.php';

echo "=== Testing Variable Validation Functions ===\n\n";

// Test 1: Email validation
echo "Test 1: Email Validation\n";
$email_var = ['var_type' => 'email', 'is_required' => true];
$result = validate_variable_value('test@example.com', $email_var);
echo "  Valid email: " . ($result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$result = validate_variable_value('invalid-email', $email_var);
echo "  Invalid email: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 2: URL validation
echo "\nTest 2: URL Validation\n";
$url_var = ['var_type' => 'url', 'is_required' => true];
$result = validate_variable_value('https://example.com', $url_var);
echo "  Valid URL: " . ($result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$result = validate_variable_value('not-a-url', $url_var);
echo "  Invalid URL: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 3: IP address validation
echo "\nTest 3: IP Address Validation\n";
$ip_var = ['var_type' => 'ip_address', 'is_required' => true];
$result = validate_variable_value('192.168.1.1', $ip_var);
echo "  Valid IPv4: " . ($result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$result = validate_variable_value('999.999.999.999', $ip_var);
echo "  Invalid IPv4: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 4: Number validation with min/max
echo "\nTest 4: Number Validation\n";
$number_var = ['var_type' => 'number', 'is_required' => true, 'min_value' => 1024, 'max_value' => 65535];
$result = validate_variable_value('5060', $number_var);
echo "  Valid port number: " . ($result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$result = validate_variable_value('100', $number_var);
echo "  Too low number: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$result = validate_variable_value('70000', $number_var);
echo "  Too high number: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 5: Date validation
echo "\nTest 5: Date Validation\n";
$date_var = ['var_type' => 'date', 'is_required' => true];
$result = validate_variable_value('2024-12-31', $date_var);
echo "  Valid date: " . ($result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$result = validate_variable_value('2024-13-32', $date_var);
echo "  Invalid date: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 6: Array values (multiselect/checkbox_group)
echo "\nTest 6: Array Value Validation\n";
$multi_var = [
    'var_type' => 'multiselect',
    'is_required' => false,
    'options' => json_encode([
        ['value' => 'opt1', 'label' => 'Option 1'],
        ['value' => 'opt2', 'label' => 'Option 2'],
        ['value' => 'opt3', 'label' => 'Option 3']
    ])
];
$result = validate_variable_value('opt1,opt2', $multi_var);
echo "  Valid selections: " . ($result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$result = validate_variable_value('opt1,invalid', $multi_var);
echo "  Invalid selection: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 7: Password validation
echo "\nTest 7: Password Validation\n";
$password_var = ['var_type' => 'password', 'is_required' => true, 'min_value' => 8];
$result = validate_variable_value('mypassword123', $password_var);
echo "  Valid password: " . ($result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$result = validate_variable_value('short', $password_var);
echo "  Too short password: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 8: Regex pattern validation
echo "\nTest 8: Regex Pattern Validation\n";
$regex_var = ['var_type' => 'text', 'is_required' => true, 'regex_pattern' => '^[A-Z0-9]+$'];
$result = validate_variable_value('ABC123', $regex_var);
echo "  Valid pattern: " . ($result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$result = validate_variable_value('abc123', $regex_var);
echo "  Invalid pattern: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

// Test 9: Required field validation
echo "\nTest 9: Required Field Validation\n";
$required_var = ['var_type' => 'text', 'is_required' => true];
$result = validate_variable_value('', $required_var);
echo "  Empty required field: " . (!$result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

$optional_var = ['var_type' => 'text', 'is_required' => false];
$result = validate_variable_value('', $optional_var);
echo "  Empty optional field: " . ($result['valid'] ? '✓ PASS' : '✗ FAIL') . "\n";

echo "\n=== All tests completed ===\n";
?>
