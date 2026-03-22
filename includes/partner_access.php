<?php
/**
 * Partner Access Helper
 *
 * Central authorization functions for the partner/multi-tenant system.
 *
 * Rules:
 *  - Owner (role_name = 'Owner', case-insensitive) → sees everything.
 *  - Logged-in admin without any role → deny.
 *  - Logged-in admin with a role but without a partner company assigned → deny.
 *  - Non-owner with a partner company → sees only customers allowed for that partner.
 *  - If the partner company is inactive → treat as "no partner" → deny.
 */

/**
 * Check whether an admin holds the Owner role (case-insensitive).
 */
function is_owner(PDO $pdo, int $admin_id): bool
{
    try {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as cnt
            FROM admin_roles ar
            JOIN roles r ON r.id = ar.role_id
            WHERE ar.admin_id = ? AND LOWER(r.role_name) = ?
        ');
        $stmt->execute([$admin_id, 'owner']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && (int)$row['cnt'] > 0);
    } catch (Exception $e) {
        error_log('is_owner error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Redirect to /access_denied.php if the admin has no roles at all.
 */
function require_any_role(PDO $pdo, int $admin_id): void
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM admin_roles WHERE admin_id = ?');
        $stmt->execute([$admin_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['cnt'] === 0) {
            http_response_code(403);
            header('Location: /access_denied.php');
            exit;
        }
    } catch (Exception $e) {
        error_log('require_any_role error: ' . $e->getMessage());
        http_response_code(403);
        header('Location: /access_denied.php');
        exit;
    }
}

/**
 * Redirect to /access_denied.php unless the admin is either:
 *  - an Owner, or
 *  - assigned to an active partner company.
 *
 * Call AFTER require_any_role() so we know there is at least one role.
 */
function require_partner_or_owner(PDO $pdo, int $admin_id): void
{
    if (is_owner($pdo, $admin_id)) {
        return; // Owner always passes
    }

    $partner_id = _get_active_partner_company_id($pdo, $admin_id);
    if ($partner_id === null) {
        http_response_code(403);
        header('Location: /access_denied.php');
        exit;
    }
}

/**
 * Internal: fetch the partner_company_id for an admin only if the company is active.
 * Returns the integer ID, or null when not found / inactive.
 */
function _get_active_partner_company_id(PDO $pdo, int $admin_id): ?int
{
    $row = _get_active_partner_company($pdo, $admin_id);
    return $row ? (int)$row['partner_company_id'] : null;
}

/**
 * Internal: fetch the full active partner company record for an admin.
 * Returns an associative array with at least partner_company_id and is_master,
 * or null when not found / inactive.
 */
function _get_active_partner_company(PDO $pdo, int $admin_id): ?array
{
    try {
        $stmt = $pdo->prepare('
            SELECT apc.partner_company_id, pc.is_master
            FROM admin_partner_company apc
            JOIN partner_companies pc ON pc.id = apc.partner_company_id
            WHERE apc.admin_id = ? AND pc.is_active = 1
            LIMIT 1
        ');
        $stmt->execute([$admin_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !isset($row["is_master"])) { $row["is_master"] = 0; }
        return $row ?: null;
    } catch (Exception $e) {
        error_log('_get_active_partner_company error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Return the set of customer IDs the admin is allowed to see.
 *
 * Return values:
 *  - null  → Owner or master partner: no filter, show everything.
 *  - []    → non-owner without an active partner company: show nothing.
 *  - [1,2] → non-owner with partner: the customer IDs where can_view = 1.
 */
function get_allowed_customer_ids_for_admin(PDO $pdo, int $admin_id): ?array
{
    if (is_owner($pdo, $admin_id)) {
        return null; // no restriction
    }

    $partner = _get_active_partner_company($pdo, $admin_id);
    if ($partner === null) {
        return []; // no partner → deny everything
    }

    // Master partner sees all customers (like Owner), but without the Owner role.
    if ((int)$partner['is_master'] === 1) {
        return null;
    }

    $partner_id = (int)$partner['partner_company_id'];

    try {
        $stmt = $pdo->prepare('
            SELECT customer_id
            FROM partner_company_customers
            WHERE partner_company_id = ? AND can_view = 1
        ');
        $stmt->execute([$partner_id]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map('intval', $ids);
    } catch (Exception $e) {
        error_log('get_allowed_customer_ids_for_admin error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Build a SQL WHERE clause fragment to restrict a query to allowed customers.
 *
 * @param ?array  $allowed  Result of get_allowed_customer_ids_for_admin().
 * @param string  $col      Column reference, e.g. 'd.customer_id' or 'c.id'.
 * @param array  &$params   The params array for the prepared statement (will be extended).
 * @return string            SQL fragment to append (e.g. '' for owner, or ' AND d.customer_id IN (...)')
 *
 * If $allowed is an empty array the fragment forces no results: ' AND 1=0'.
 * If $allowed is null (owner) the fragment is empty string (no restriction).
 */
function build_customer_filter(?array $allowed, string $col, array &$params): string
{
    if ($allowed === null) {
        return ''; // owner – no filter
    }
    if (empty($allowed)) {
        return ' AND 1=0'; // nothing allowed
    }
    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    foreach ($allowed as $id) {
        $params[] = (int)$id;
    }
    return " AND {$col} IN ({$placeholders})";
}

/**
 * Redirect to /access_denied.php (HTTP 403) if the given customer is not
 * in the admin's allowed set.
 */
function assert_customer_allowed(PDO $pdo, int $admin_id, int $customer_id): void
{
    $allowed = get_allowed_customer_ids_for_admin($pdo, $admin_id);
    if ($allowed === null) {
        return; // owner
    }
    if (!in_array($customer_id, $allowed, true)) {
        http_response_code(403);
        header('Location: /access_denied.php');
        exit;
    }
}

/**
 * Redirect to /access_denied.php if the device's customer is not allowed.
 * Devices without a customer_id are hidden from non-owners.
 */
function assert_device_allowed(PDO $pdo, int $admin_id, int $device_id): void
{
    if (is_owner($pdo, $admin_id)) {
        return;
    }
    try {
        $stmt = $pdo->prepare('SELECT customer_id FROM devices WHERE id = ? LIMIT 1');
        $stmt->execute([$device_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['customer_id'] === null) {
            http_response_code(403);
            header('Location: /access_denied.php');
            exit;
        }
        assert_customer_allowed($pdo, $admin_id, (int)$row['customer_id']);
    } catch (Exception $e) {
        error_log('assert_device_allowed error: ' . $e->getMessage());
        http_response_code(403);
        header('Location: /access_denied.php');
        exit;
    }
}

/**
 * Redirect to /access_denied.php if the config version's device/customer is not allowed.
 * Resolution path: config_version → device_config_assignments → devices → customer.
 */
function assert_config_version_allowed(PDO $pdo, int $admin_id, int $config_version_id): void
{
    if (is_owner($pdo, $admin_id)) {
        return;
    }
    try {
        $stmt = $pdo->prepare('
            SELECT d.customer_id
            FROM device_config_assignments dca
            JOIN devices d ON d.id = dca.device_id
            WHERE dca.config_version_id = ?
            LIMIT 1
        ');
        $stmt->execute([$config_version_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['customer_id'] === null) {
            http_response_code(403);
            header('Location: /access_denied.php');
            exit;
        }
        assert_customer_allowed($pdo, $admin_id, (int)$row['customer_id']);
    } catch (Exception $e) {
        error_log('assert_config_version_allowed error: ' . $e->getMessage());
        http_response_code(403);
        header('Location: /access_denied.php');
        exit;
    }
}
