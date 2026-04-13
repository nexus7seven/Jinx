<?php
// Deckard callback_action.php — with SKIP + screen-pop verification + direct_dial (Jinx click-to-call)
// Deploy to: /var/www/html/api/callback_action.php

header('Content-Type: application/json');

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$WORKABLE_STATUS = 'NA';

/**
 * Must stay in sync with Jinx App\Support\VicidialDialPhone::nationalDigits().
 * UK national digits for external_dial "value" with phone_code 44.
 */
function deckard_normalize_uk_national(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '44') && strlen($digits) > 10) {
        return substr($digits, 2);
    }
    if (str_starts_with($digits, '0')) {
        return substr($digits, 1);
    }

    return $digits;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$action = $payload['action'] ?? null;

// ============================================================================
// DIRECT DIAL (Jinx — no vicidial_callbacks row; same pause / external_dial / popup as action=dial)
// ============================================================================
if ($action === 'direct_dial') {
    $req_lead_id = isset($payload['lead_id']) ? (int) $payload['lead_id'] : 0;
    $phone = deckard_normalize_uk_national(trim($payload['phone_number'] ?? ''));

    if ($phone === '') {
        echo json_encode(['ok' => false, 'error' => 'invalid_phone_number']);
        exit;
    }

    $db_host = 'localhost';
    $db_name = 'asterisk';
    $db_user = 'cron';
    $db_pass = '1234';

    $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_errno) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'db_connect_fail',
            'details' => $mysqli->connect_error,
        ]);
        exit;
    }

    $resolved_lead_id = 0;
    $campaign = '';

    /*
     * Campaign resolution for direct_dial (needs campaign_id like action=dial):
     *
     * 1) If request includes lead_id > 0: look up that vicidial_list.lead_id (unique row).
     *    If the row has no usable campaign_id, fall through to step 2 using the dial phone.
     *
     * 2) Phone fallback: match vicidial_list.phone_number to the normalized national digits
     *    we are about to dial. If multiple rows share the same phone, pick ONE row
     *    deterministically: ORDER BY vl.lead_id DESC — highest lead_id is treated as the
     *    most recent / best-defined list row in typical VICIdial usage (auto-increment PK).
     */
    $sqlLookupByLead = '
        SELECT vl.lead_id, vls.campaign_id
        FROM vicidial_list vl
        LEFT JOIN vicidial_lists vls ON vls.list_id = vl.list_id
        WHERE vl.lead_id = ?
        LIMIT 1
    ';
    $sqlLookupByPhone = '
        SELECT vl.lead_id, vls.campaign_id
        FROM vicidial_list vl
        LEFT JOIN vicidial_lists vls ON vls.list_id = vl.list_id
        WHERE vl.phone_number = ?
        ORDER BY vl.lead_id DESC
        LIMIT 1
    ';

    if ($req_lead_id > 0) {
        $stmt = $mysqli->prepare($sqlLookupByLead);
        if (!$stmt) {
            echo json_encode(['ok' => false, 'error' => 'prepare_failed_direct', 'details' => $mysqli->error]);
            exit;
        }
        $stmt->bind_param('i', $req_lead_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row && ! empty($row['campaign_id'])) {
            $resolved_lead_id = (int) $row['lead_id'];
            $campaign = (string) $row['campaign_id'];
        }
    }

    if ($campaign === '') {
        $stmt = $mysqli->prepare($sqlLookupByPhone);
        if (!$stmt) {
            echo json_encode(['ok' => false, 'error' => 'prepare_failed_direct_phone', 'details' => $mysqli->error]);
            exit;
        }
        $stmt->bind_param('s', $phone);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row && ! empty($row['campaign_id'])) {
            $resolved_lead_id = (int) $row['lead_id'];
            $campaign = (string) $row['campaign_id'];
        }
    }

    if ($campaign === '') {
        echo json_encode(['ok' => false, 'error' => 'no_campaign_for_direct_dial']);
        exit;
    }

    $agentUser = '6666';
    $agentPass = 'Redr00k2025';
    $apiUrl = 'https://dialer.hextech.lol/agc/api.php';

    $pauseUrl = $apiUrl . '?' . http_build_query([
        'source' => 'deckard',
        'user' => $agentUser,
        'pass' => $agentPass,
        'agent_user' => $agentUser,
        'function' => 'external_pause',
        'value' => 'PAUSE',
        'pause_code' => 'CALLBACK',
    ]);

    @file_get_contents($pauseUrl);

    $dialUrl = $apiUrl . '?' . http_build_query([
        'source' => 'deckard',
        'user' => $agentUser,
        'pass' => $agentPass,
        'agent_user' => $agentUser,
        'function' => 'external_dial',
        'value' => $phone,
        'phone_code' => '44',
        'search' => 'YES',
        'preview' => 'NO',
        'focus' => 'YES',
        'vendor_lead_code' => '',
        'crm_popup_login' => 'YES',
        'lead_id' => $resolved_lead_id,
    ]);

    $dialResponse = @file_get_contents($dialUrl);

    if (! $dialResponse || stripos($dialResponse, 'SUCCESS') === false) {
        echo json_encode([
            'ok' => false,
            'error' => 'external_dial_failed',
            'details' => $dialResponse,
        ]);
        exit;
    }

    $popupConfirmed = false;
    if ($resolved_lead_id > 0) {
        for ($i = 0; $i < 15; $i++) {
            usleep(200000);

            $q = $mysqli->query("
                SELECT lead_id
                FROM vicidial_live_agents
                WHERE user = '" . $mysqli->real_escape_string($agentUser) . "'
                LIMIT 1
            ");

            if ($q && $row = $q->fetch_assoc()) {
                if ((int) $row['lead_id'] === $resolved_lead_id) {
                    $popupConfirmed = true;
                    break;
                }
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'dialled' => true,
        'popup_confirmed' => $popupConfirmed,
        'agent' => $agentUser,
        'phone' => $phone,
        'lead_id' => $resolved_lead_id,
    ]);
    exit;
}

// ============================================================================
// Original: require callback_id for all other actions
// ============================================================================
$callback_id = isset($payload['callback_id']) ? (int) $payload['callback_id'] : 0;

if (! $action || ! $callback_id) {
    echo json_encode(['ok' => false, 'error' => 'missing_action_or_callback_id']);
    exit;
}

$db_host = 'localhost';
$db_name = 'asterisk';
$db_user = 'cron';
$db_pass = '1234';

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'db_connect_fail',
        'details' => $mysqli->connect_error,
    ]);
    exit;
}

// ============================================================================
// RESCHEDULE CALLBACK
// ============================================================================
if ($action === 'reschedule') {
    $new_time = trim($payload['new_time'] ?? '');
    $comments = $payload['comments'] ?? null;

    if ($new_time === '') {
        echo json_encode(['ok' => false, 'error' => 'missing_new_time']);
        exit;
    }

    $ts = strtotime($new_time);
    if ($ts === false) {
        echo json_encode(['ok' => false, 'error' => 'bad_time_format']);
        exit;
    }

    $new_dt = date('Y-m-d H:i:s', $ts);

    if ($comments !== null) {
        $stmt = $mysqli->prepare('
          UPDATE vicidial_callbacks
          SET callback_time = ?, comments = ?
          WHERE callback_id = ?
        ');
        if (! $stmt) {
            echo json_encode(['ok' => false, 'error' => 'prepare_failed', 'details' => $mysqli->error]);
            exit;
        }
        $stmt->bind_param('ssi', $new_dt, $comments, $callback_id);
    } else {
        $stmt = $mysqli->prepare('
          UPDATE vicidial_callbacks
          SET callback_time = ?
          WHERE callback_id = ?
        ');
        if (! $stmt) {
            echo json_encode(['ok' => false, 'error' => 'prepare_failed', 'details' => $mysqli->error]);
            exit;
        }
        $stmt->bind_param('si', $new_dt, $callback_id);
    }

    if (! $stmt->execute()) {
        echo json_encode(['ok' => false, 'error' => 'update_failed', 'details' => $stmt->error]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'callback_id' => $callback_id,
        'new_time' => $new_dt,
    ]);
    exit;
}

// ============================================================================
// SKIP CALLBACK
// ============================================================================
if ($action === 'skip') {
    $stmt = $mysqli->prepare('
      SELECT lead_id
      FROM vicidial_callbacks
      WHERE callback_id = ?
      LIMIT 1
    ');
    if (! $stmt) {
        echo json_encode(['ok' => false, 'error' => 'prepare_failed_skip', 'details' => $mysqli->error]);
        exit;
    }
    $stmt->bind_param('i', $callback_id);
    if (! $stmt->execute()) {
        echo json_encode(['ok' => false, 'error' => 'execute_failed_skip', 'details' => $stmt->error]);
        exit;
    }
    $stmt->bind_result($lead_id);
    if (! $stmt->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'callback_not_found']);
        exit;
    }
    $stmt->close();

    $lead_id = (int) $lead_id;

    $stmtU = $mysqli->prepare('
      UPDATE vicidial_callbacks
      SET status = \'INACTIVE\'
      WHERE callback_id = ?
    ');
    if (! $stmtU) {
        echo json_encode(['ok' => false, 'error' => 'prepare_failed_skip_update', 'details' => $mysqli->error]);
        exit;
    }
    $stmtU->bind_param('i', $callback_id);
    if (! $stmtU->execute()) {
        echo json_encode(['ok' => false, 'error' => 'skip_update_failed', 'details' => $stmtU->error]);
        exit;
    }
    $stmtU->close();

    $listStatusUpdated = false;

    if ($lead_id > 0) {
        $stmtF = $mysqli->prepare('
          SELECT callback_id
          FROM vicidial_callbacks
          WHERE lead_id = ?
            AND status IN (\'ACTIVE\',\'LIVE\',\'USERONLY\')
            AND callback_time > NOW()
          LIMIT 1
        ');
        if ($stmtF) {
            $stmtF->bind_param('i', $lead_id);
            if ($stmtF->execute()) {
                $stmtF->store_result();
                $hasFuture = $stmtF->num_rows > 0;
                $stmtF->close();

                if (! $hasFuture) {
                    $stmtL = $mysqli->prepare('
                      UPDATE vicidial_list
                      SET status = ?
                      WHERE lead_id = ?
                    ');
                    if ($stmtL) {
                        $stmtL->bind_param('si', $WORKABLE_STATUS, $lead_id);
                        if ($stmtL->execute()) {
                            $listStatusUpdated = $stmtL->affected_rows > 0;
                        }
                        $stmtL->close();
                    }
                }
            } else {
                $stmtF->close();
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'skipped' => true,
        'callback_id' => $callback_id,
        'lead_id' => $lead_id,
        'list_status_updated' => $listStatusUpdated,
        'workable_status' => $WORKABLE_STATUS,
    ]);
    exit;
}

// ============================================================================
// DIAL CALLBACK
// ============================================================================
if ($action === 'dial') {
    $sql = '
      SELECT cb.callback_id,
             cb.lead_id,
             cb.callback_time,
             cb.status,
             cb.comments,
             vl.phone_number,
             vl.list_id,
             vls.campaign_id
      FROM vicidial_callbacks cb
      LEFT JOIN vicidial_list   vl  ON vl.lead_id  = cb.lead_id
      LEFT JOIN vicidial_lists  vls ON vls.list_id = vl.list_id
      WHERE cb.callback_id = ?
      LIMIT 1
    ';

    $stmt = $mysqli->prepare($sql);
    if (! $stmt) {
        echo json_encode(['ok' => false, 'error' => 'prepare_failed_cb', 'details' => $mysqli->error]);
        exit;
    }

    $stmt->bind_param('i', $callback_id);
    if (! $stmt->execute()) {
        echo json_encode(['ok' => false, 'error' => 'execute_failed_cb', 'details' => $stmt->error]);
        exit;
    }

    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        echo json_encode(['ok' => false, 'error' => 'callback_not_found']);
        exit;
    }

    $stmt->bind_result(
        $cb_id,
        $lead_id,
        $cb_time,
        $cb_status,
        $cb_comments,
        $phone_number,
        $list_id,
        $campaign_id
    );
    $stmt->fetch();
    $stmt->close();

    $phone = $phone_number ?? '';
    $campaign = $campaign_id ?? '';
    $lead_id = (int) $lead_id;

    if ($phone === '') {
        echo json_encode(['ok' => false, 'error' => 'no_phone_number']);
        exit;
    }
    if ($campaign === '') {
        echo json_encode(['ok' => false, 'error' => 'no_campaign_for_callback']);
        exit;
    }

    $agentUser = '6666';
    $agentPass = 'Redr00k2025';
    $apiUrl = 'https://dialer.hextech.lol/agc/api.php';

    $pauseUrl = $apiUrl . '?' . http_build_query([
        'source' => 'deckard',
        'user' => $agentUser,
        'pass' => $agentPass,
        'agent_user' => $agentUser,
        'function' => 'external_pause',
        'value' => 'PAUSE',
        'pause_code' => 'CALLBACK',
    ]);

    $pauseResponse = @file_get_contents($pauseUrl);

    $dialUrl = $apiUrl . '?' . http_build_query([
        'source' => 'deckard',
        'user' => $agentUser,
        'pass' => $agentPass,
        'agent_user' => $agentUser,
        'function' => 'external_dial',
        'value' => $phone,
        'phone_code' => '44',
        'search' => 'YES',
        'preview' => 'NO',
        'focus' => 'YES',
        'vendor_lead_code' => '',
        'crm_popup_login' => 'YES',
        'lead_id' => $lead_id,
    ]);

    $dialResponse = @file_get_contents($dialUrl);

    if (! $dialResponse || stripos($dialResponse, 'SUCCESS') === false) {
        echo json_encode([
            'ok' => false,
            'error' => 'external_dial_failed',
            'details' => $dialResponse,
        ]);
        exit;
    }

    $popupConfirmed = false;

    for ($i = 0; $i < 15; $i++) {
        usleep(200000);

        $q = $mysqli->query("
            SELECT lead_id
            FROM vicidial_live_agents
            WHERE user = '" . $mysqli->real_escape_string($agentUser) . "'
            LIMIT 1
        ");

        if ($q && $row = $q->fetch_assoc()) {
            if ((int) $row['lead_id'] === $lead_id) {
                $popupConfirmed = true;
                break;
            }
        }
    }

    if ($popupConfirmed) {
        $mysqli->query('
            UPDATE vicidial_callbacks
            SET status = \'INACTIVE\'
            WHERE callback_id = ' . (int) $callback_id
        );
    }

    echo json_encode([
        'ok' => true,
        'dialled' => true,
        'popup_confirmed' => $popupConfirmed,
        'agent' => $agentUser,
        'phone' => $phone,
        'lead_id' => $lead_id,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_action']);
