<?php
/**
 * EventMS - Region-wise COE Kit Counter Seeder & Credentials Generator
 * Creates dedicated accounts for all regions + Unassigned/Help Desk
 * Idempotent: skips already existing counter accounts without modifying passwords
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$pdo = Database::getConnection();

function generateSafePassword(int $length = 12): string {
    // Avoid confusing characters: 0, O, o, 1, l, I
    $chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ@#$%*';
    $max = strlen($chars) - 1;
    $pwd = '';
    for ($i = 0; $i < $length; $i++) {
        $pwd .= $chars[random_int(0, $max)];
    }
    return $pwd;
}

function regionToUsername(string $region): string {
    $clean = trim($region);
    if ($clean === '' || strcasecmp($clean, 'Help Desk') === 0 || strcasecmp($clean, 'Unassigned / Help Desk') === 0) {
        return 'counter_helpdesk';
    }
    $slug = strtolower($clean);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return 'counter_' . $slug;
}

echo "=== 1. FETCHING DISTINCT REGIONS FROM DATABASE ===\n";

// Fetch all distinct normalized regions from allowed_visitors
$stmt = $pdo->query("
    SELECT region, COUNT(*) as cnt 
    FROM `allowed_visitors` 
    GROUP BY region 
    ORDER BY region ASC
");

$regionsMap = [];
$emptyRegionCount = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rawRegion = trim((string)($row['region'] ?? ''));
    $count = (int)$row['cnt'];
    if ($rawRegion === '' || $rawRegion === '—' || $rawRegion === '-') {
        $emptyRegionCount += $count;
    } else {
        // Normalized title case key
        $regionsMap[$rawRegion] = ($regionsMap[$rawRegion] ?? 0) + $count;
    }
}

// Add the Unassigned / Help Desk counter
$countersToCreate = [];
$countersToCreate[] = [
    'region'   => 'Unassigned / Help Desk',
    'db_region'=> 'Help Desk',
    'username' => 'counter_helpdesk',
    'visitors' => $emptyRegionCount
];

foreach ($regionsMap as $reg => $cnt) {
    $countersToCreate[] = [
        'region'   => $reg,
        'db_region'=> $reg,
        'username' => regionToUsername($reg),
        'visitors' => $cnt
    ];
}

echo "Found " . count($regionsMap) . " active regions + 1 Help Desk counter.\n";
echo "Total counters to manage: " . count($countersToCreate) . "\n\n";

echo "=== 2. SEEDING / VERIFYING COUNTER ACCOUNTS IN `admins` ===\n";

$insertStmt = $pdo->prepare("
    INSERT INTO `admins` (`username`, `password`, `role`, `region`, `status`, `created_at`)
    VALUES (:username, :password, 'counter', :region, 'active', NOW())
");

$existingStmt = $pdo->prepare("SELECT id, username, role, region, status FROM `admins` WHERE username = :u LIMIT 1");

$csvFile = __DIR__ . '/counter_credentials.csv';
$existingCsvData = [];
if (file_exists($csvFile)) {
    if (($handle = fopen($csvFile, 'r')) !== false) {
        $hdr = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (!empty($row[1])) {
                $existingCsvData[$row[1]] = $row;
            }
        }
        fclose($handle);
    }
}

$csvRows = [];
$createdCount = 0;
$skippedCount = 0;

$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$loginUrl = "http://{$host}/EventMS/login.php";

foreach ($countersToCreate as $c) {
    $existingStmt->execute([':u' => $c['username']]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $skippedCount++;
        echo " - [EXISTS] {$c['username']} ({$c['region']}) => Skipped (password preserved)\n";
        // Preserve password in CSV if previously recorded
        $prevPassword = $existingCsvData[$c['username']][2] ?? '[PRESERVED - ALREADY ACTIVE]';
        $csvRows[] = [
            $c['region'],
            $c['username'],
            $prevPassword,
            $loginUrl,
            $c['visitors']
        ];
    } else {
        $plainPassword = generateSafePassword(12);
        $hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);

        $insertStmt->execute([
            ':username' => $c['username'],
            ':password' => $hashedPassword,
            ':region'   => $c['db_region'],
        ]);
        $createdCount++;
        echo " + [CREATED] {$c['username']} ({$c['region']}) => {$c['visitors']} visitors\n";

        $csvRows[] = [
            $c['region'],
            $c['username'],
            $plainPassword,
            $loginUrl,
            $c['visitors']
        ];
    }
}

echo "\nSummary: {$createdCount} created, {$skippedCount} preserved.\n\n";

echo "=== 3. WRITING counter_credentials.csv ===\n";

$fp = fopen($csvFile, 'w');
fputcsv($fp, ['region', 'username', 'password', 'login_url', 'number_of_visitors_in_region']);
foreach ($csvRows as $row) {
    fputcsv($fp, $row);
}
fclose($fp);

echo "Successfully written " . count($csvRows) . " credential records to:\n{$csvFile}\n";
echo "NOTE: This file contains one-time plain passwords for distribution to counter teams.\n";
