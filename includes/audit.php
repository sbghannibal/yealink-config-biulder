<?php
// Audit logging functions

function logAudit($user, $action, $timestamp) {
    $logEntry = sprintf("%s: User %s performed action: %s\n", $timestamp, $user, $action);
    file_put_contents('audit.log', $logEntry, FILE_APPEND);
}

function viewAuditLogs() {
    if (file_exists('audit.log')) {
        return file_get_contents('audit.log');
    }
    return "No audit logs found.";
}

// Example Usage
// logAudit('sbghannibal', 'Login', '2026-02-15 18:48:25');
// echo viewAuditLogs();
?>