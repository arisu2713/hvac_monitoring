<?php

/**
 * api_maintenance.php
 * Preventive Maintenance (PM) endpoint for HVAC equipment.
 *
 * GET:
 *   List active maintenance entries or fetch specific unit maintenance.
 *   Optional query params:
 *     ?active=1               (only unexpired active maintenance)
 *     ?equip_type=EXHAUST_FAN (whitelist validated)
 *     ?unit_name=EF+1         (specific unit query)
 *   Returns maintenance list with server-calculated remaining_seconds.
 *   Returns pm_edit_enabled flag for UI state.
 *
 * POST:
 *   Toggle or set maintenance status for a unit.
 *   Enforces:
 *     - PM_EDIT_ENABLED check (403 if disabled on this instance)
 *     - Content-Type: application/json (415 otherwise)
 *     - Same-origin / Referer validation against HTTP_HOST (403 otherwise)
 *     - Verified session with user_id and username (401 / 403 otherwise)
 *     - Whitelisted equip_type and sanitized unit_name
 *   Timing:
 *     - Uses SQL NOW() and DATE_ADD() for started_at and expires_at
 *     - Auto-expires rows where expires_at <= NOW() before operations
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// 1. Session authentication
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Authentication required'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Permission and verified author check
if (!can_manage_maintenance()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'Permission denied'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sessionUsername = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
if ($sessionUsername === '') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error'   => 'User session missing username'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 3. Early session write close to release session file lock for concurrent requests
session_write_close();

header('Content-Type: application/json; charset=utf-8');

const ALLOWED_EQUIP_TYPES = [
    'EXHAUST_FAN',
    'AHU'
];

const DEFAULT_PM_DURATION_HOURS = 8;
const MIN_PM_DURATION_HOURS = 1;
const MAX_PM_DURATION_HOURS = 24;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$config = require __DIR__ . '/config.php';

// Determine if PM editing (POST) is enabled on this instance (control room PC / local)
$pmEditEnabled = false;
if (defined('PM_EDIT_ENABLED')) {
    $pmEditEnabled = (bool)PM_EDIT_ENABLED;
} elseif (isset($config['pm_edit_enabled'])) {
    $pmEditEnabled = (bool)$config['pm_edit_enabled'];
}

try {
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
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // 4. Auto-expire past maintenance rows before running GET or POST
    // Closes rows where expires_at <= NOW() so active_unit_key frees up unique constraint
    $stmtExpire = $pdo->prepare(
        "UPDATE unit_maintenance
         SET active = 0, ended_at = NOW(), ended_by = 'auto-expiry'
         WHERE active = 1 AND expires_at <= NOW()"
    );
    $stmtExpire->execute();

    if ($method === 'GET') {
        $activeOnly = isset($_GET['active']) && (string)$_GET['active'] === '1';
        $equipType = isset($_GET['equip_type']) ? trim((string)$_GET['equip_type']) : '';
        $unitName = isset($_GET['unit_name']) ? trim((string)$_GET['unit_name']) : '';

        $sql = "
            SELECT
                id,
                equip_type,
                unit_name,
                active,
                started_by,
                started_at,
                expires_at,
                ended_at,
                ended_by,
                reason,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS remaining_seconds,
                IF(active = 1 AND expires_at > NOW(), 1, 0) AS is_active
            FROM unit_maintenance
            WHERE 1=1
        ";
        $params = [];

        if ($activeOnly) {
            $sql .= " AND active = 1 AND expires_at > NOW()";
        }

        if ($equipType !== '') {
            if (!in_array($equipType, ALLOWED_EQUIP_TYPES, true)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Invalid equip_type'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $sql .= " AND equip_type = :equip_type";
            $params['equip_type'] = $equipType;
        }

        if ($unitName !== '') {
            $sql .= " AND unit_name = :unit_name";
            $params['unit_name'] = $unitName;
        }

        $sql .= " ORDER BY active DESC, expires_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Format integer types cleanly for API consumers
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['active'] = (int)$row['active'];
            $row['remaining_seconds'] = (int)$row['remaining_seconds'];
            $row['is_active'] = (int)$row['is_active'];
        }
        unset($row);

        echo json_encode([
            'success'         => true,
            'pm_edit_enabled' => $pmEditEnabled,
            'server_time'     => date('Y-m-d H:i:s'),
            'maintenance'     => $rows,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        // Enforce instance location check: PM can only be modified where PM_EDIT_ENABLED is true
        if (!$pmEditEnabled) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => 'pm_edit_disabled',
                'message' => 'PM hanya dapat diatur dari PC control room'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Content-Type MUST be application/json only
        $rawContentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $mimeParts = explode(';', $rawContentType);
        $mime = strtolower(trim($mimeParts[0]));

        if ($mime !== 'application/json') {
            http_response_code(415);
            echo json_encode([
                'success' => false,
                'error'   => 'Unsupported Media Type: Content-Type must be application/json'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Origin / Referer validation against HTTP_HOST
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        $originValid = false;
        if ($origin !== null && $origin !== '') {
            $parsedOriginHost = parse_url($origin, PHP_URL_HOST);
            $parsedOriginPort = parse_url($origin, PHP_URL_PORT);
            $originHostPort = $parsedOriginHost . ($parsedOriginPort ? ':' . $parsedOriginPort : '');
            if (strcasecmp($originHostPort, $host) === 0) {
                $originValid = true;
            }
        } elseif ($referer !== null && $referer !== '') {
            $parsedRefHost = parse_url($referer, PHP_URL_HOST);
            $parsedRefPort = parse_url($referer, PHP_URL_PORT);
            $refHostPort = $parsedRefHost . ($parsedRefPort ? ':' . $parsedRefPort : '');
            if (strcasecmp($refHostPort, $host) === 0) {
                $originValid = true;
            }
        }

        if (!$originValid) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => 'Forbidden: Cross-origin request rejected'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $inputRaw = file_get_contents('php://input');
        $data = json_decode($inputRaw, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid JSON payload'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $action = isset($data['action']) ? trim((string)$data['action']) : 'set';
        $equipType = isset($data['equip_type']) ? trim((string)$data['equip_type']) : 'EXHAUST_FAN';
        $unitName = isset($data['unit_name']) ? trim((string)$data['unit_name']) : '';
        $reason = isset($data['reason']) ? trim((string)$data['reason']) : null;
        if ($reason !== null && $reason !== '') {
            $reason = mb_substr($reason, 0, 255);
        } else {
            $reason = null;
        }

        if (!in_array($equipType, ALLOWED_EQUIP_TYPES, true)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid equip_type'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($unitName === '' || mb_strlen($unitName) > 64) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid unit_name'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Duration in hours (default 8, clamped between 1 and 24 hours)
        $durationHours = DEFAULT_PM_DURATION_HOURS;
        if (isset($data['duration_hours']) && is_numeric($data['duration_hours'])) {
            $durationHours = (int)$data['duration_hours'];
            if ($durationHours < MIN_PM_DURATION_HOURS) {
                $durationHours = MIN_PM_DURATION_HOURS;
            } elseif ($durationHours > MAX_PM_DURATION_HOURS) {
                $durationHours = MAX_PM_DURATION_HOURS;
            }
        }

        // Determine target active state
        // action 'toggle': inverts current active state
        // action 'set' / 'save': uses provided 'active' boolean/int
        // action 'disable': active = false
        // action 'enable': active = true
        $stmtFindActive = $pdo->prepare(
            "SELECT id, started_by, started_at, expires_at
             FROM unit_maintenance
             WHERE equip_type = :equip AND unit_name = :unit AND active = 1 AND expires_at > NOW()
             LIMIT 1"
        );
        $stmtFindActive->execute([
            'equip' => $equipType,
            'unit'  => $unitName,
        ]);
        $existingActive = $stmtFindActive->fetch();

        $targetActive = false;
        if ($action === 'toggle') {
            $targetActive = !$existingActive;
        } elseif ($action === 'enable') {
            $targetActive = true;
        } elseif ($action === 'disable') {
            $targetActive = false;
        } elseif (isset($data['active'])) {
            $targetActive = (bool)$data['active'];
        } else {
            $targetActive = true;
        }

        $resultRow = null;

        if ($targetActive) {
            // Activate or extend PM
            if ($existingActive) {
                // Update existing active row with fresh SQL NOW() and DATE_ADD()
                $stmtUpdate = $pdo->prepare(
                    "UPDATE unit_maintenance
                     SET active = 1,
                         started_by = :author,
                         started_at = NOW(),
                         expires_at = DATE_ADD(NOW(), INTERVAL :hours HOUR),
                         ended_at = NULL,
                         ended_by = NULL,
                         reason = :reason
                     WHERE id = :id"
                );
                $stmtUpdate->execute([
                    'author' => $sessionUsername,
                    'hours'  => $durationHours,
                    'reason' => $reason,
                    'id'     => $existingActive['id'],
                ]);
                $recordId = (int)$existingActive['id'];
            } else {
                // Insert new active maintenance row; catch 1062 race condition
                try {
                    $stmtInsert = $pdo->prepare(
                        "INSERT INTO unit_maintenance (equip_type, unit_name, active, started_by, started_at, expires_at, reason)
                         VALUES (:equip, :unit, 1, :author, NOW(), DATE_ADD(NOW(), INTERVAL :hours HOUR), :reason)"
                    );
                    $stmtInsert->execute([
                        'equip'  => $equipType,
                        'unit'   => $unitName,
                        'author' => $sessionUsername,
                        'hours'  => $durationHours,
                        'reason' => $reason,
                    ]);
                    $recordId = (int)$pdo->lastInsertId();
                } catch (PDOException $e) {
                    // Catch duplicate active unit race condition (SQLSTATE 23000 / Error 1062)
                    $isDuplicate = ($e->getCode() === '23000') || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062);
                    if ($isDuplicate) {
                        error_log('[' . basename(__FILE__) . '] Duplicate 1062 caught on PM INSERT for ' . $equipType . ':' . $unitName . ', retrying as UPDATE');
                        $stmtRetry = $pdo->prepare(
                            "UPDATE unit_maintenance
                             SET active = 1,
                                 started_by = :author,
                                 started_at = NOW(),
                                 expires_at = DATE_ADD(NOW(), INTERVAL :hours HOUR),
                                 ended_at = NULL,
                                 ended_by = NULL,
                                 reason = :reason
                             WHERE equip_type = :equip AND unit_name = :unit AND active = 1"
                        );
                        $stmtRetry->execute([
                            'author' => $sessionUsername,
                            'hours'  => $durationHours,
                            'reason' => $reason,
                            'equip'  => $equipType,
                            'unit'   => $unitName,
                        ]);

                        $stmtFetchId = $pdo->prepare(
                            "SELECT id FROM unit_maintenance WHERE equip_type = :equip AND unit_name = :unit AND active = 1 LIMIT 1"
                        );
                        $stmtFetchId->execute(['equip' => $equipType, 'unit' => $unitName]);
                        $found = $stmtFetchId->fetch();
                        $recordId = $found ? (int)$found['id'] : 0;
                    } else {
                        throw $e;
                    }
                }
            }
        } else {
            // Deactivate PM (Operator unticked / disabled)
            $stmtDeactivate = $pdo->prepare(
                "UPDATE unit_maintenance
                 SET active = 0,
                     ended_at = NOW(),
                     ended_by = :author
                 WHERE equip_type = :equip AND unit_name = :unit AND active = 1"
            );
            $stmtDeactivate->execute([
                'author' => $sessionUsername,
                'equip'  => $equipType,
                'unit'   => $unitName,
            ]);

            // Fetch last modified record id
            $stmtLast = $pdo->prepare(
                "SELECT id FROM unit_maintenance WHERE equip_type = :equip AND unit_name = :unit ORDER BY id DESC LIMIT 1"
            );
            $stmtLast->execute(['equip' => $equipType, 'unit' => $unitName]);
            $lastRow = $stmtLast->fetch();
            $recordId = $lastRow ? (int)$lastRow['id'] : 0;
        }

        // Fetch authoritative server state with remaining_seconds calculated by SQL
        if ($recordId > 0) {
            $stmtFetch = $pdo->prepare(
                "SELECT
                    id,
                    equip_type,
                    unit_name,
                    active,
                    started_by,
                    started_at,
                    expires_at,
                    ended_at,
                    ended_by,
                    reason,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS remaining_seconds,
                    IF(active = 1 AND expires_at > NOW(), 1, 0) AS is_active
                 FROM unit_maintenance
                 WHERE id = :id"
            );
            $stmtFetch->execute(['id' => $recordId]);
            $resultRow = $stmtFetch->fetch();
            if ($resultRow) {
                $resultRow['id'] = (int)$resultRow['id'];
                $resultRow['active'] = (int)$resultRow['active'];
                $resultRow['remaining_seconds'] = (int)$resultRow['remaining_seconds'];
                $resultRow['is_active'] = (int)$resultRow['is_active'];
            }
        }

        echo json_encode([
            'success'     => true,
            'action'      => $action,
            'active'      => $targetActive,
            'unit_name'   => $unitName,
            'equip_type'  => $equipType,
            'maintenance' => $resultRow,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method not allowed'
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    error_log('[' . basename(__FILE__) . '] ' . $e->getMessage());

    if ($e instanceof PDOException) {
        $sqlState = (string)($e->errorInfo[0] ?? $e->getCode());
        $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        // 42S02 / 1146: Table not found; 1142: Command denied / privilege missing
        if ($sqlState === '42S02' || $driverCode === 1146 || $driverCode === 1142) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'error'   => 'maintenance_unavailable'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Internal server error'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
