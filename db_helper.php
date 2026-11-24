<?php
/**
 * EventMS Load Test Database Helper
 */
declare(strict_types=1);

$action = $argv[1] ?? 'get_counts';

require_once 'c:/xampp/htdocs/EventMS_loadtest/config/db.php';
$pdo = Database::getConnection();

// Double check database is eventms_loadtest
$currentDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
if ($currentDb !== 'eventms_loadtest' && $action !== 'get_real_db_counts') {
    echo json_encode(['error' => "FATAL: Not connected to eventms_loadtest. Connected to: $currentDb"]);
    exit(1);
}

if ($action === 'get_counts') {
    $d1 = (int)$pdo->query("SELECT COUNT(*) FROM allowed_visitors WHERE added_by = 'loadtest' AND is_day1_checked_in = 1")->fetchColumn();
    $d2 = (int)$pdo->query("SELECT COUNT(*) FROM allowed_visitors WHERE added_by = 'loadtest' AND is_day2_checked_in = 1")->fetchColumn();
    $kits = (int)$pdo->query("SELECT COUNT(*) FROM gift_distribution WHERE added_by = 'loadtest' AND is_gift_collected = 1")->fetchColumn();
    $distinctD1 = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM allowed_visitors WHERE added_by = 'loadtest' AND is_day1_checked_in = 1")->fetchColumn();
    $distinctKits = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM gift_distribution WHERE added_by = 'loadtest' AND is_gift_collected = 1")->fetchColumn();
    $totalLoadtestVisitors = (int)$pdo->query("SELECT COUNT(*) FROM allowed_visitors WHERE added_by = 'loadtest'")->fetchColumn();

    echo json_encode([
        'd1_checkins' => $d1,
        'd2_checkins' => $d2,
        'kits_collected' => $kits,
        'distinct_d1_visitors' => $distinctD1,
        'distinct_kits_visitors' => $distinctKits,
        'total_loadtest_visitors' => $totalLoadtestVisitors
    ]);
    exit(0);
}

if ($action === 'reset_all') {
    $pdo->exec("UPDATE allowed_visitors SET is_checked_in = 0, checked_in_at = NULL, is_day1_checked_in = 0, day1_checked_in_at = NULL, is_day2_checked_in = 0, day2_checked_in_at = NULL WHERE added_by = 'loadtest'");
    $pdo->exec("UPDATE gift_distribution SET is_gift_collected = 0, gift_collected_at = NULL, handed_over_by = NULL, counter_region = NULL WHERE added_by = 'loadtest'");
    $pdo->exec("DELETE FROM rate_limits WHERE action LIKE 'gate_%'");
    echo json_encode(['status' => 'reset_complete']);
    exit(0);
}

if ($action === 'set_event_dates') {
    $d1 = $argv[2] ?? date('Y-m-d');
    $d2 = $argv[3] ?? '2026-11-20';
    $open = $argv[4] ?? '1';
    $pdo->prepare("REPLACE INTO event_settings (setting_key, setting_value, updated_at, updated_by) VALUES ('day1_date', ?, NOW(), 'loadtest')")->execute([$d1]);
    $pdo->prepare("REPLACE INTO event_settings (setting_key, setting_value, updated_at, updated_by) VALUES ('day2_date', ?, NOW(), 'loadtest')")->execute([$d2]);
    $pdo->prepare("REPLACE INTO event_settings (setting_key, setting_value, updated_at, updated_by) VALUES ('self_checkin_open', ?, NOW(), 'loadtest')")->execute([$open]);
    echo json_encode(['status' => 'settings_updated', 'day1' => $d1, 'day2' => $d2, 'open' => $open]);
    exit(0);
}

if ($action === 'check_duplicates') {
    // Check if any visitor has multiple records or duplicate checkin counts
    $stmt1 = $pdo->query("SELECT user_id, COUNT(*) as cnt FROM allowed_visitors WHERE added_by = 'loadtest' GROUP BY user_id HAVING cnt > 1");
    $dupUsers = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->query("SELECT user_id, COUNT(*) as cnt FROM gift_distribution WHERE added_by = 'loadtest' GROUP BY user_id HAVING cnt > 1");
    $dupGifts = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'duplicate_visitor_rows' => $dupUsers,
        'duplicate_gift_rows' => $dupGifts
    ]);
    exit(0);
}

if ($action === 'get_visitors') {
    $limit = isset($argv[2]) ? (int)$argv[2] : 300;
    $stmt = $pdo->prepare("
        SELECT v.id as visitor_id, g.id as gift_id, v.user_id, v.name, v.mobile, v.fincert, v.region, v.counter_id,
               c.counter_no, c.username as counter_username, c.name as counter_name
        FROM allowed_visitors v
        LEFT JOIN gift_distribution g ON g.user_id = v.user_id
        LEFT JOIN counters c ON c.id = v.counter_id
        WHERE v.added_by = 'loadtest'
        ORDER BY v.id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit(0);
}

if ($action === 'get_real_db_counts') {
    $realPdo = new PDO("mysql:host=localhost;dbname=eventms_db;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $visCount = (int)$realPdo->query("SELECT COUNT(*) FROM allowed_visitors")->fetchColumn();
    $giftCount = (int)$realPdo->query("SELECT COUNT(*) FROM gift_distribution")->fetchColumn();
    $d1 = (int)$realPdo->query("SELECT COUNT(*) FROM allowed_visitors WHERE is_day1_checked_in = 1")->fetchColumn();
    $d2 = (int)$realPdo->query("SELECT COUNT(*) FROM allowed_visitors WHERE is_day2_checked_in = 1")->fetchColumn();
    $kits = (int)$realPdo->query("SELECT COUNT(*) FROM gift_distribution WHERE is_gift_collected = 1")->fetchColumn();

    echo json_encode([
        'database' => 'eventms_db',
        'allowed_visitors' => $visCount,
        'gift_distribution' => $giftCount,
        'day1_checkins' => $d1,
        'day2_checkins' => $d2,
        'kits_collected' => $kits
    ]);
    exit(0);
}

echo json_encode(['error' => "Unknown action: $action"]);
