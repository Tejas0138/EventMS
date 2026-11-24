<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
$pdo = Database::getConnection();

echo "=== STEP 1: APPLYING DATABASE MIGRATIONS ===\n";

// Helper to check index existence
function indexExists(PDO $pdo, string $table, string $indexName): bool {
    $stmt = $pdo->query("SHOW INDEX FROM `$table`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (($row['Key_name'] ?? '') === $indexName) {
            return true;
        }
    }
    return false;
}

// Helper to check column existence
function columnExists(PDO $pdo, string $table, string $columnName): bool {
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (($row['Field'] ?? '') === $columnName) {
            return true;
        }
    }
    return false;
}

// 1. Drop old UNIQUE mobile constraints if present
foreach (['allowed_visitors', 'gift_distribution'] as $tbl) {
    $stmt = $pdo->query("SHOW INDEX FROM `$tbl`");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (($row['Column_name'] ?? '') === 'mobile' && (int)($row['Non_unique'] ?? 1) === 0) {
            $key = $row['Key_name'];
            $pdo->exec("ALTER TABLE `$tbl` DROP INDEX `$key`");
            echo "Dropped unique constraint '$key' on $tbl (column mobile).\n";
        }
    }
}

// 2. Add columns to allowed_visitors with explicit utf8mb4_unicode_ci collation
$columnsToAdd = [
    'user_id'     => 'VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
    'region'      => 'VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
    'fincert'     => 'VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
    'school_name' => 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
    'designation' => 'VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
    'email'       => 'VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL'
];

foreach ($columnsToAdd as $col => $definition) {
    if (!columnExists($pdo, 'allowed_visitors', $col)) {
        $pdo->exec("ALTER TABLE `allowed_visitors` ADD COLUMN `$col` $definition");
        echo "Added column '$col' to allowed_visitors.\n";
    }
    if (!columnExists($pdo, 'gift_distribution', $col)) {
        $pdo->exec("ALTER TABLE `gift_distribution` ADD COLUMN `$col` $definition");
        echo "Added column '$col' to gift_distribution.\n";
    }
}

// 3. Add UNIQUE user_id constraints
if (!indexExists($pdo, 'allowed_visitors', 'uniq_user_id')) {
    $pdo->exec("ALTER TABLE `allowed_visitors` ADD UNIQUE KEY `uniq_user_id` (`user_id`)");
    echo "Added UNIQUE KEY 'uniq_user_id' on allowed_visitors.\n";
}
if (!indexExists($pdo, 'gift_distribution', 'uniq_gift_user_id')) {
    $pdo->exec("ALTER TABLE `gift_distribution` ADD UNIQUE KEY `uniq_gift_user_id` (`user_id`)");
    echo "Added UNIQUE KEY 'uniq_gift_user_id' on gift_distribution.\n";
}

// 4. Add normal performance indexes
$indexes = [
    'allowed_visitors' => [
        'idx_vis_mobile'  => 'mobile',
        'idx_vis_region'  => 'region',
        'idx_vis_fincert' => 'fincert',
    ],
    'gift_distribution' => [
        'idx_gift_mob'     => 'mobile',
        'idx_gift_reg'     => 'region',
        'idx_gift_fincert' => 'fincert',
    ]
];

foreach ($indexes as $table => $idxList) {
    foreach ($idxList as $idxName => $colName) {
        if (!indexExists($pdo, $table, $idxName)) {
            $pdo->exec("ALTER TABLE `$table` ADD INDEX `$idxName` (`$colName`)");
            echo "Added index '$idxName' on $table ($colName).\n";
        }
    }
}

echo "Database schema migrated successfully!\n\n";

echo "=== STEP 2: REMOVING OLD 10 SEEDED TEST VISITORS ===\n";
// Delete visitors that have empty or null user_id (the old 10 test records)
$delGate = $pdo->exec("DELETE FROM `allowed_visitors` WHERE `user_id` IS NULL OR `user_id` = ''");
$delGift = $pdo->exec("DELETE FROM `gift_distribution` WHERE `user_id` IS NULL OR `user_id` = ''");
echo "Deleted test visitors: $delGate from allowed_visitors, $delGift from gift_distribution.\n\n";

echo "=== STEP 3: PARSING raw_data_AG.xlsx AND IMPORTING ===\n";

function parseXlsx(string $filePath): array {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new Exception("Unable to open XLSX file: $filePath");
    }
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $xml = simplexml_load_string($ssXml);
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string)$si->t;
            } elseif (isset($si->r)) {
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
                $sharedStrings[] = $text;
            } else {
                $sharedStrings[] = '';
            }
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        throw new Exception("Unable to find sheet1.xml in XLSX.");
    }
    $xml = simplexml_load_string($sheetXml);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $colAttr = (string)$cell['r'];
            preg_match('/([A-Z]+)(\d+)/', $colAttr, $matches);
            $colLetters = $matches[1] ?? '';
            $colIndex = 0;
            for ($k = 0; $k < strlen($colLetters); $k++) {
                $colIndex = $colIndex * 26 + (ord($colLetters[$k]) - ord('A') + 1);
            }
            $colIndex -= 1;
            $type = (string)$cell['t'];
            $val = isset($cell->v) ? (string)$cell->v : '';
            if ($type === 's') {
                $val = $sharedStrings[(int)$val] ?? '';
            } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                $val = (string)$cell->is->t;
            }
            $rowData[$colIndex] = $val;
        }
        if (!empty($rowData)) {
            $maxCol = max(array_keys($rowData));
            $denseRow = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $denseRow[$c] = $rowData[$c] ?? '';
            }
            $rows[] = $denseRow;
        }
    }
    $zip->close();
    return $rows;
}

function normalizeRegion(string $raw): string {
    $t = trim($raw);
    if ($t === '') return '';
    $t = preg_replace('/\s+/', ' ', $t);
    return mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
}

$xlsxFile = __DIR__ . '/raw_data_AG.xlsx';
$rows = parseXlsx($xlsxFile);
$header = array_shift($rows);

$stmtGate = $pdo->prepare("
    INSERT INTO `allowed_visitors` 
        (`name`, `mobile`, `user_id`, `region`, `fincert`, `school_name`, `designation`, `email`, 
         `is_checked_in`, `checked_in_at`, `is_day1_checked_in`, `day1_checked_in_at`, `is_day2_checked_in`, `day2_checked_in_at`, `created_at`)
    VALUES 
        (:name, :mobile, :user_id, :region, :fincert, :school_name, :designation, :email, 
         0, NULL, 0, NULL, 0, NULL, NOW())
    ON DUPLICATE KEY UPDATE
        `name` = VALUES(`name`),
        `mobile` = VALUES(`mobile`),
        `region` = VALUES(`region`),
        `fincert` = VALUES(`fincert`),
        `school_name` = VALUES(`school_name`),
        `designation` = VALUES(`designation`),
        `email` = VALUES(`email`)
");

$stmtGift = $pdo->prepare("
    INSERT INTO `gift_distribution`
        (`name`, `mobile`, `user_id`, `region`, `fincert`, `school_name`, `designation`, `email`,
         `is_gift_collected`, `gift_collected_at`, `created_at`)
    VALUES
        (:name, :mobile, :user_id, :region, :fincert, :school_name, :designation, :email,
         0, NULL, NOW())
    ON DUPLICATE KEY UPDATE
        `name` = VALUES(`name`),
        `mobile` = VALUES(`mobile`),
        `region` = VALUES(`region`),
        `fincert` = VALUES(`fincert`),
        `school_name` = VALUES(`school_name`),
        `designation` = VALUES(`designation`),
        `email` = VALUES(`email`)
");

$totalImported = 0;
$missingContact = [];
$invalidContactLen = [];
$contactGroups = [];
$fincertGroups = [];
$missingRegion = [];

$pdo->beginTransaction();

try {
    foreach ($rows as $idx => $r) {
        $rowNum = $idx + 2;
        $regionRaw  = trim($r[1] ?? '');
        $region     = normalizeRegion($regionRaw);
        $userid     = trim($r[2] ?? '');
        $schname    = trim($r[3] ?? '');
        $umname     = trim($r[4] ?? '');
        $desig      = trim($r[5] ?? '');
        $rawContact = trim($r[6] ?? '');
        $contact    = preg_replace('/\D/', '', $rawContact);
        $emaild     = trim($r[7] ?? '');
        $fincert    = strtoupper(trim($r[8] ?? ''));

        if ($umname === '') {
            $umname = 'Visitor ' . $userid;
        }

        // Stats tracking
        if ($region === '') {
            $missingRegion[] = ['row' => $rowNum, 'userid' => $userid, 'name' => $umname];
        }

        if ($contact === '') {
            $missingContact[] = ['row' => $rowNum, 'userid' => $userid, 'name' => $umname];
        } else {
            if (strlen($contact) !== 10) {
                $invalidContactLen[] = ['row' => $rowNum, 'userid' => $userid, 'name' => $umname, 'contact' => $contact, 'raw' => $rawContact];
            }
            $contactGroups[$contact][] = ['row' => $rowNum, 'userid' => $userid, 'name' => $umname];
        }

        if ($fincert !== '') {
            $fincertGroups[$fincert][] = ['row' => $rowNum, 'userid' => $userid, 'name' => $umname];
        }

        // Execute Upsert for both tables
        $params = [
            ':name'        => $umname,
            ':mobile'      => $contact,
            ':user_id'     => $userid,
            ':region'      => $region,
            ':fincert'     => $fincert,
            ':school_name' => $schname,
            ':designation' => $desig,
            ':email'       => $emaild
        ];

        $stmtGate->execute($params);
        $stmtGift->execute($params);
        $totalImported++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("FATAL ERROR DURING IMPORT: " . $e->getMessage() . "\n");
}

$dupContacts = array_filter($contactGroups, fn($list) => count($list) > 1);
$dupFincerts = array_filter($fincertGroups, fn($list) => count($list) > 1);

$finalGateCount = $pdo->query("SELECT COUNT(*) FROM `allowed_visitors`")->fetchColumn();
$finalGiftCount = $pdo->query("SELECT COUNT(*) FROM `gift_distribution`")->fetchColumn();

echo "================ DATA IMPORT AUDIT REPORT ================\n";
echo "Total Rows Processed & Imported: $totalImported\n";
echo "Final Count in allowed_visitors: $finalGateCount\n";
echo "Final Count in gift_distribution: $finalGiftCount\n\n";

echo "1. Rows with Missing Contact (Count: " . count($missingContact) . "):\n";
foreach ($missingContact as $item) {
    echo "   - Row {$item['row']}: User ID '{$item['userid']}' | Name: {$item['name']}\n";
}
echo "\n";

echo "2. Rows with Invalid Contact Length (not 10 digits) (Count: " . count($invalidContactLen) . "):\n";
foreach ($invalidContactLen as $item) {
    echo "   - Row {$item['row']}: User ID '{$item['userid']}' | Name: {$item['name']} | Clean Digits: '{$item['contact']}' (Length: " . strlen($item['contact']) . ") | Raw: '{$item['raw']}'\n";
}
echo "\n";

echo "3. Duplicate Contact Numbers (Count: " . count($dupContacts) . "):\n";
foreach ($dupContacts as $phone => $visitors) {
    echo "   - Mobile '$phone' shared by " . count($visitors) . " visitors:\n";
    foreach ($visitors as $v) {
        echo "       * Row {$v['row']}: {$v['name']} (User ID: {$v['userid']})\n";
    }
}
echo "\n";

echo "4. Duplicate FinCert IDs (Count: " . count($dupFincerts) . "):\n";
foreach ($dupFincerts as $fc => $visitors) {
    echo "   - FinCert '$fc' shared by " . count($visitors) . " visitors:\n";
    foreach ($visitors as $v) {
        echo "       * Row {$v['row']}: {$v['name']} (User ID: {$v['userid']})\n";
    }
}
echo "\n";

echo "5. Rows with Missing Region (Count: " . count($missingRegion) . "):\n";
foreach ($missingRegion as $item) {
    echo "   - Row {$item['row']}: User ID '{$item['userid']}' | Name: {$item['name']}\n";
}
echo "\n==========================================================\n";
