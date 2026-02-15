<?php
/**
 * Role-Based Access Control (RBAC)
 * Manage user roles and permissions
 */

function has_permission($pdo, $admin_id, $permission) {
    $stmt = $pdo->prepare(''
        SELECT COUNT(*) as count FROM role_permissions rp
        INNER JOIN admin_roles ar ON rp.role_id = ar.role_id
        WHERE ar.admin_id = ? AND rp.permission = ?
    ');
    
    $stmt->execute([$admin_id, $permission]);
    $result = $stmt->fetch();
    
    return $result['count'] > 0;
}

function get_admin_roles($pdo, $admin_id) {
    $stmt = $pdo->prepare(''
        SELECT r.* FROM roles r
        INNER JOIN admin_roles ar ON r.id = ar.role_id
        WHERE ar.admin_id = ?
    ');
    
    $stmt->execute([$admin_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_admin_permissions($pdo, $admin_id) {
    $stmt = $pdo->prepare(''
        SELECT DISTINCT rp.permission FROM role_permissions rp
        INNER JOIN admin_roles ar ON rp.role_id = ar.role_id
        WHERE ar.admin_id = ?
        ORDER BY rp.permission
    ');
    
    $stmt->execute([$admin_id]);
    $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $permissions;
}

function check_permission($admin_id, $required_permission) {
    if (!has_permission($GLOBALS['pdo'], $admin_id, $required_permission)) {
        http_response_code(403);
        die('Insufficient permissions');
    }
}

function assign_role($pdo, $admin_id, $role_id) {
    $stmt = $pdo->prepare('INSERT IGNORE INTO admin_roles (admin_id, role_id) VALUES (?, ?)');
    return $stmt->execute([$admin_id, $role_id]);
}

function revoke_role($pdo, $admin_id, $role_id) {
    $stmt = $pdo->prepare('DELETE FROM admin_roles WHERE admin_id = ? AND role_id = ?');
    return $stmt->execute([$admin_id, $role_id]);
}
?>