<?php
/*
 * Token Management Functions
 *
 * This file includes functions to manage download tokens for users.
 */

/**
 * Generate a new token for a user.
 *
 * @param string $username The username of the user.
 * @return string The generated token.
 */
function generate_token($username) {
    return bin2hex(random_bytes(16)) . '_' . base64_encode($username);
}

/**
 * Validate a token against a username.
 *
 * @param string $token The token to validate.
 * @param string $username The username to validate against.
 * @return bool True if valid, false otherwise.
 */
function validate_token($token, $username) {
    // Token format is expected to be "random_bytes_encoded_username"
    list($tokenPart, $usernamePart) = explode('_', $token);
    return base64_decode($usernamePart) === $username;
}

/**
 * Invalidate a token by removing it from the storage (if applicable).
 *
 * @param string $token The token to invalidate.
 * @return void
 */
function invalidate_token($token) {
    // Here you would implement the logic to invalidate a token in your storage
    // For example, removing it from a database or marking it as invalid.
}
?>