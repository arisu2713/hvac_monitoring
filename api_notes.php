<?php

/**
 * api_notes.php
 * Operator sticky notes endpoint for HVAC equipment.
 *
 * GET:
 *   List open (unresolved) notes.
 *   Optional query param: ?equip_type=AHU (validated against whitelist)
 *
 * POST:
 *   Save or resolve a note.
 *   MUST have Content-Type: application/json (415 otherwise).
 *   Same-origin check enforced (Origin/Referer vs HTTP_HOST, 403 otherwise).
 *   Session authentication required (401 if missing).
 *   Session username required (403 if missing).
 *   Action 'save': creates or updates active note for (equip_type, unit_id).
 *   Action 'resolve': sets resolved_at timestamp.
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

// 2. Verified session author
$sessionUsername = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';

// 3. Early session write close to release session file lock for concurrent requests
session_write_close();

header('Content-Type: application/json; charset=utf-8');

const ALLOWED_EQUIP_TYPES = [
    'AHU',
    'CHILLER',
    'CT',
    'CCP',
    'CHWP',
    'ROOM_TEMP_RH',
    'EXHAUST_FAN',
    'AHU_G8'
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$config = require __DIR__ . '/config.php';

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

    if ($method === 'GET') {
        $equipType = isset($_GET['equip_type']) ? trim((string)$_GET['equip_type']) : '';

        if ($equipType !== '') {
            if (!in_array($equipType, ALLOWED_EQUIP_TYPES, true)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Invalid equip_type'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->prepare(
                'SELECT id, equip_type, unit_id, note, author, created_at, updated_at
                 FROM unit_notes
                 WHERE resolved_at IS NULL AND equip_type = :equip_type
                 ORDER BY updated_at DESC'
            );
            $stmt->execute(['equip_type' => $equipType]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, equip_type, unit_id, note, author, created_at, updated_at
                 FROM unit_notes
                 WHERE resolved_at IS NULL
                 ORDER BY updated_at DESC'
            );
            $stmt->execute();
        }

        $notes = $stmt->fetchAll();

        echo json_encode([
            'success'     => true,
            'server_time' => date('Y-m-d H:i:s'),
            'notes'       => $notes
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
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

        // Author validation from verified session
        if ($sessionUsername === '') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => 'User session missing username'
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

        $action = isset($data['action']) ? trim((string)$data['action']) : 'save';
        $equipType = isset($data['equip_type']) ? trim((string)$data['equip_type']) : '';
        $unitId = isset($data['unit_id']) ? trim((string)$data['unit_id']) : '';

        if (!in_array($equipType, ALLOWED_EQUIP_TYPES, true)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid equip_type'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($unitId === '' || mb_strlen($unitId) > 64) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid unit_id'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $now = date('Y-m-d H:i:s');

        if ($action === 'resolve') {
            $stmt = $pdo->prepare(
                'UPDATE unit_notes
                 SET resolved_at = :now, updated_at = :now
                 WHERE equip_type = :equip AND unit_id = :unit AND resolved_at IS NULL'
            );
            $stmt->execute([
                'now'   => $now,
                'equip' => $equipType,
                'unit'  => $unitId,
            ]);

            echo json_encode([
                'success'    => true,
                'resolved'   => true,
                'equip_type' => $equipType,
                'unit_id'    => $unitId,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'save') {
            $noteText = isset($data['note']) ? trim((string)$data['note']) : '';

            if (mb_strlen($noteText) > 500) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Note exceeds maximum length of 500 characters'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            if ($noteText === '') {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => 'Note text cannot be empty'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Check if active note already exists for this unit
            $stmtFind = $pdo->prepare(
                'SELECT id, created_at
                 FROM unit_notes
                 WHERE equip_type = :equip AND unit_id = :unit AND resolved_at IS NULL
                 LIMIT 1'
            );
            $stmtFind->execute([
                'equip' => $equipType,
                'unit'  => $unitId,
            ]);
            $existing = $stmtFind->fetch();

            if ($existing) {
                $stmtUpdate = $pdo->prepare(
                    'UPDATE unit_notes
                     SET note = :note, author = :author, updated_at = :now
                     WHERE id = :id'
                );
                $stmtUpdate->execute([
                    'note'   => $noteText,
                    'author' => $sessionUsername,
                    'now'    => $now,
                    'id'     => $existing['id'],
                ]);

                echo json_encode([
                    'success' => true,
                    'note'    => [
                        'id'         => (int)$existing['id'],
                        'equip_type' => $equipType,
                        'unit_id'    => $unitId,
                        'note'       => $noteText,
                        'author'     => $sessionUsername,
                        'created_at' => $existing['created_at'],
                        'updated_at' => $now,
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // No existing open note: attempt INSERT, catching duplicate-key race condition
            if (isset($_SERVER['HTTP_X_CONCURRENCY_TEST'])) {
                usleep(25000); // 25ms window to test concurrent race condition on INSERT
            }

            try {
                $stmtInsert = $pdo->prepare(
                    'INSERT INTO unit_notes (equip_type, unit_id, note, author, created_at, updated_at)
                     VALUES (:equip, :unit, :note, :author, :now, :now)'
                );
                $stmtInsert->execute([
                    'equip'  => $equipType,
                    'unit'   => $unitId,
                    'note'   => $noteText,
                    'author' => $sessionUsername,
                    'now'    => $now,
                ]);

                $insertId = (int)$pdo->lastInsertId();

                echo json_encode([
                    'success' => true,
                    'note'    => [
                        'id'         => $insertId,
                        'equip_type' => $equipType,
                        'unit_id'    => $unitId,
                        'note'       => $noteText,
                        'author'     => $sessionUsername,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } catch (PDOException $e) {
                // Catch MySQL/MariaDB duplicate key error (SQLSTATE 23000 / Error 1062)
                $isDuplicate = ($e->getCode() === '23000') || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062);
                if ($isDuplicate) {
                    error_log('[' . basename(__FILE__) . '] Duplicate key 1062 caught on INSERT for ' . $equipType . ':' . $unitId . ', retrying as UPDATE');

                    // Retry once as UPDATE
                    $stmtRetry = $pdo->prepare(
                        'UPDATE unit_notes
                         SET note = :note, author = :author, updated_at = :now
                         WHERE equip_type = :equip AND unit_id = :unit AND resolved_at IS NULL'
                    );
                    $stmtRetry->execute([
                        'note'   => $noteText,
                        'author' => $sessionUsername,
                        'now'    => $now,
                        'equip'  => $equipType,
                        'unit'   => $unitId,
                    ]);

                    $stmtFetch = $pdo->prepare(
                        'SELECT id, created_at, updated_at
                         FROM unit_notes
                         WHERE equip_type = :equip AND unit_id = :unit AND resolved_at IS NULL
                         LIMIT 1'
                    );
                    $stmtFetch->execute([
                        'equip' => $equipType,
                        'unit'  => $unitId,
                    ]);
                    $row = $stmtFetch->fetch();

                    echo json_encode([
                        'success' => true,
                        'retried' => true,
                        'note'    => [
                            'id'         => $row ? (int)$row['id'] : 0,
                            'equip_type' => $equipType,
                            'unit_id'    => $unitId,
                            'note'       => $noteText,
                            'author'     => $sessionUsername,
                            'created_at' => $row['created_at'] ?? $now,
                            'updated_at' => $now,
                        ]
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }

                // Other PDO exception rethrown to outer catch
                throw $e;
            }
        }

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Unknown action'
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
                'error'   => 'notes_unavailable'
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
