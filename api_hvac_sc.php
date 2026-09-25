<?php

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Authentication required'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close(); // Release session lock — rest of script is read-only

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=hvac_current;charset=utf8mb4',
        $config['host'],
        $config['port']
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $sql = "
        SELECT
            p.point_id,
            p.point_name,
            p.equipment_type,
            p.equipment_name,
            p.role,
            p.obj_type,
            CASE
                WHEN p.obj_type = 'AI' THEN ar.value
                WHEN p.obj_type = 'BI' THEN br.value
                ELSE NULL
            END AS value,
            CASE
                WHEN p.obj_type = 'AI' THEN ar.read_at
                WHEN p.obj_type = 'BI' THEN br.read_at
                ELSE NULL
            END AS read_at
        FROM hvac_current.points p
        LEFT JOIN hvac_current.ai_current ar
            ON ar.point_id = p.point_id
           AND p.obj_type = 'AI'
        LEFT JOIN hvac_current.bi_current br
            ON br.point_id = p.point_id
           AND p.obj_type = 'BI'
        WHERE p.equipment_type IN ('CHILLER', 'CCP', 'CHWP', 'CT')
        ORDER BY
            FIELD(p.equipment_type, 'CHILLER', 'CCP', 'CHWP', 'CT'),
            CAST(p.equipment_name AS UNSIGNED),
            p.equipment_name,
            p.role
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll();

    $equipment = [
        'CHILLER' => [],
        'CCP'     => [],
        'CHWP'    => [],
        'CT'      => [],
    ];

    foreach ($rows as $row) {
        $type  = $row['equipment_type'];
        $name  = $row['equipment_name'];
        $role  = strtolower(trim($row['role'] ?? ''));
        $obj   = strtoupper(trim($row['obj_type'] ?? ''));
        $val   = $row['value'] !== null ? ($obj === 'BI' ? (int)$row['value'] : (float)$row['value']) : null;
        $time  = $row['read_at'];
        $pName = strtoupper(trim($row['point_name'] ?? ''));

        // Initialize equipment structure based on verified inventory roles
        if (!isset($equipment[$type][$name])) {
            if ($type === 'CHILLER') {
                $equipment[$type][$name] = [
                    'name'                   => $name,
                    'run'                    => null,
                    'alarm'                  => null,
                    'status_alt'             => null,
                    'setpoint'               => null,
                    'rla'                    => null,
                    'rla_active_setpoint'    => null,
                    'evap_leaving_temp'      => null,
                    'evap_entering_temp'     => null,
                    'cond_entering_temp'     => null,
                    'cond_leaving_temp'      => null,
                    'chw_supply_temp'        => null,
                    'chw_return_temp'        => null,
                    'cond_supply_temp'       => null,
                    'cond_return_temp'       => null,
                    'evap_sat_temp'          => null,
                    'cond_sat_temp'          => null,
                    'evap_refr_pressure'     => null,
                    'cond_refr_pressure'     => null,
                    'evap_water_flow_status' => null,
                    'cond_water_flow_status' => null,
                    'oil_tank_temp'          => null,
                    'igv_position'           => null,
                    'run_hours'              => null,
                    'winding_temp_r'         => null,
                    'winding_temp_s'         => null,
                    'winding_temp_t'         => null,
                    'last_update'            => null,
                ];
            } elseif ($type === 'CT') {
                $equipment[$type][$name] = [
                    'name'        => $name,
                    'run'         => null,
                    'alarm'       => null,
                    'status_alt'  => null,
                    'frequency'   => null,
                    'current'     => null,
                    'return_temp' => null,
                    'last_update' => null,
                ];
            } else {
                // CCP, CHWP
                $equipment[$type][$name] = [
                    'name'        => $name,
                    'run'         => null,
                    'alarm'       => null,
                    'frequency'   => null,
                    'current'     => null,
                    'last_update' => null,
                ];
            }
        }

        if (array_key_exists($role, $equipment[$type][$name])) {
            $equipment[$type][$name][$role] = $val;
        }

        if ($time !== null) {
            if ($equipment[$type][$name]['last_update'] === null || $time > $equipment[$type][$name]['last_update']) {
                $equipment[$type][$name]['last_update'] = $time;
            }
        }

        // Sub-unit mapping for CT where individual cells A/B are noted in point_name (e.g. CT-1A, CT-1B)
        if ($type === 'CT' && preg_match('/CT[ -]?(\d+[A-B])/i', $pName, $m)) {
            $subName = strtoupper($m[1]);
            if (!isset($equipment['CT'][$subName])) {
                $equipment['CT'][$subName] = [
                    'name'        => $subName,
                    'run'         => null,
                    'alarm'       => null,
                    'status_alt'  => null,
                    'frequency'   => null,
                    'current'     => null,
                    'return_temp' => null,
                    'last_update' => null,
                ];
            }
            if (array_key_exists($role, $equipment['CT'][$subName])) {
                $equipment['CT'][$subName][$role] = $val;
            }
            if ($time !== null) {
                if ($equipment['CT'][$subName]['last_update'] === null || $time > $equipment['CT'][$subName]['last_update']) {
                    $equipment['CT'][$subName]['last_update'] = $time;
                }
            }
        }
    }

    // Pass shared VSD parameters from parent tower number to sub-units (e.g. CT 1 -> 1A, 1B)
    foreach ($equipment['CT'] as $k => &$ctUnit) {
        if (preg_match('/^(\d+)([AB])$/', $k, $m)) {
            $parentKey = $m[1];
            if (isset($equipment['CT'][$parentKey])) {
                $parent = $equipment['CT'][$parentKey];
                if ($ctUnit['frequency'] === null && $parent['frequency'] !== null) {
                    $ctUnit['frequency'] = $parent['frequency'];
                }
                if ($ctUnit['current'] === null && $parent['current'] !== null) {
                    $ctUnit['current'] = $parent['current'];
                }
                if ($ctUnit['run'] === null && $parent['run'] !== null) {
                    $ctUnit['run'] = $parent['run'];
                }
                if ($ctUnit['alarm'] === null && $parent['alarm'] !== null) {
                    $ctUnit['alarm'] = $parent['alarm'];
                }
                if ($parent['last_update'] !== null && ($ctUnit['last_update'] === null || $parent['last_update'] > $ctUnit['last_update'])) {
                    $ctUnit['last_update'] = $parent['last_update'];
                }
            }
        }
    }
    unset($ctUnit);

    // Natural sorting for equipment keys
    $sortUnits = function (&$array) {
        uksort($array, function ($a, $b) {
            preg_match('/^(\d+)(.*)$/', (string)$a, $ma);
            preg_match('/^(\d+)(.*)$/', (string)$b, $mb);
            $numA = isset($ma[1]) ? (int)$ma[1] : 0;
            $numB = isset($mb[1]) ? (int)$mb[1] : 0;
            if ($numA !== $numB) {
                return $numA - $numB;
            }
            return strcmp($ma[2] ?? '', $mb[2] ?? '');
        });
        $array = array_values($array);
    };

    $sortUnits($equipment['CHILLER']);
    $sortUnits($equipment['CCP']);
    $sortUnits($equipment['CHWP']);
    $sortUnits($equipment['CT']);

    $payload = json_encode([
        'success'     => true,
        'server_time' => date('Y-m-d H:i:s'),
        'equipment'   => $equipment,
    ], JSON_UNESCAPED_UNICODE);

    header('Content-Length: ' . strlen($payload));
    echo $payload;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
