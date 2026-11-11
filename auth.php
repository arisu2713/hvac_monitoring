<?php

session_start();

$config = require __DIR__ . '/config.php';

function db(): PDO
{
    global $config;

    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        $pdo = new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    return $pdo;
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'is_admin' => $_SESSION['is_admin'],
    ];
}

/**
 * Centralized permission check for managing PM (Preventive Maintenance).
 * Currently returns true for any authenticated user with a valid username.
 * Prepared for future role-based access control (e.g. admin-only) without modifying callers.
 */
function can_manage_maintenance(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['username']);
}
