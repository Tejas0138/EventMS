<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$pdo = Database::getConnection();

// =========================================================================
// Standalone Actions Handling: Reset Individual & Reset All (No Auth/Sessions)
// =========================================================================
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$msg = $_GET['msg'] ?? null;

if ($action === 'reset' && !empty($_GET['id'])) {
    $targetId = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE `allowed_visitors` SET is_checked_in = 0, checked_in_at = NULL WHERE id = :id");
    $stmt->execute([':id' => $targetId]);
    header('Location: dashboard.php?msg=reset_one');
    exit;
}

if ($action === 'reset_all') {
    $pdo->exec("UPDATE `allowed_visitors` SET is_checked_in = 0, checked_in_at = NULL");
    header('Location: dashboard.php?msg=reset_all');
    exit;
}

// =========================================================================
// Bulk Import Visitors via CSV (fgetcsv + ON DUPLICATE KEY UPDATE)
// =========================================================================
if ($action === 'import_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: dashboard.php?msg=invalid_file');
        exit;
    }

    $fileName = $_FILES['csv_file']['name'] ?? '';
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        header('Location: dashboard.php?msg=invalid_file');
        exit;
    }

    $tmpPath = $_FILES['csv_file']['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        header('Location: dashboard.php?msg=invalid_file');
        exit;
    }

    $handle = fopen($tmpPath, 'r');
    if ($handle === false) {
        header('Location: dashboard.php?msg=invalid_file');
        exit;
    }

    $importedCount = 0;
    $skippedCount = 0;
    $isFirstRow = true;

    $stmt = $pdo->prepare("
        INSERT INTO `allowed_visitors` (`name`, `mobile`, `is_checked_in`, `checked_in_at`, `created_at`)
        VALUES (:name, :mobile, 0, NULL, NOW())
        ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)
    ");

    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
        if (empty($row) || (count($row) === 1 && trim((string)$row[0]) === '')) {
            continue;
        }

        // Support semicolon delimiter if exported by spreadsheet applications
        if (count($row) === 1 && str_contains($row[0], ';')) {
            $row = str_getcsv($row[0], ';');
        }

        $col0 = trim((string)($row[0] ?? ''));
        $col1 = trim((string)($row[1] ?? ''));

        // Skip optional header row
        if ($isFirstRow) {
            $isFirstRow = false;
            $lower0 = strtolower($col0);
            $lower1 = strtolower($col1);
            if (
                str_contains($lower0, 'name') ||
                str_contains($lower1, 'mobile') ||
                str_contains($lower1, 'phone') ||
                str_contains($lower1, 'number') ||
                !preg_match('/\d/', $col1)
            ) {
                continue;
            }
        }

        $rawName = $col0;
        $rawMobile = $col1;

        // Auto-detect if mobile was placed in first column and name in second
        if (preg_match('/^[0-9+\s()-]+$/', $rawName) && !preg_match('/^[0-9+\s()-]+$/', $rawMobile)) {
            $temp = $rawName;
            $rawName = $rawMobile;
            $rawMobile = $temp;
        }

        // Sanitize mobile: digits only, normalize country codes
        $cleanMobile = preg_replace('/\D/', '', $rawMobile);
        if (strlen($cleanMobile) === 12 && str_starts_with($cleanMobile, '91')) {
            $cleanMobile = substr($cleanMobile, 2);
        }
        if (strlen($cleanMobile) === 11 && str_starts_with($cleanMobile, '0')) {
            $cleanMobile = substr($cleanMobile, 1);
        }

        if (strlen($cleanMobile) !== 10) {
            $skippedCount++;
            continue;
        }

        $cleanName = trim($rawName);
        if ($cleanName === '') {
            $cleanName = 'Visitor ' . substr($cleanMobile, -4);
        }

        try {
            $stmt->execute([
                ':name'   => mb_substr($cleanName, 0, 100),
                ':mobile' => $cleanMobile
            ]);
            $importedCount++;
        } catch (\Throwable $e) {
            $skippedCount++;
        }
    }

    fclose($handle);

    if ($importedCount === 0 && $skippedCount > 0) {
        header('Location: dashboard.php?msg=import_empty');
        exit;
    }

    header("Location: dashboard.php?msg=imported&count={$importedCount}&skipped={$skippedCount}");
    exit;
}

// Optional CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->query("SELECT * FROM `allowed_visitors` ORDER BY is_checked_in DESC, checked_in_at DESC, id ASC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=event_gate_visitors_' . date('Y-m-d_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Full Name', 'Mobile Number', 'Gate Status', 'Checked In At', 'Created At']);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['id'],
            $row['name'],
            $row['mobile'],
            $row['is_checked_in'] == 1 ? 'Inside Gate' : 'Not Checked-in',
            $row['checked_in_at'] ?? 'N/A',
            $row['created_at']
        ]);
    }
    fclose($out);
    exit;
}

// =========================================================================
// Query Metrics: Total Invited (10), Inside Gate, Pending Entry
// =========================================================================
$mStmt = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_checked_in = 1 THEN 1 ELSE 0 END) as checked_in,
        SUM(CASE WHEN is_checked_in = 0 THEN 1 ELSE 0 END) as pending
    FROM `allowed_visitors`
");
$mRow = $mStmt->fetch();
$totalInvited = (int)($mRow['total'] ?? 0);
$insideGate   = (int)($mRow['checked_in'] ?? 0);
$pendingEntry = (int)($mRow['pending'] ?? 0);
$progressRate = $totalInvited > 0 ? round(($insideGate / $totalInvited) * 100) : 0;

// Search & Filter
$search = trim((string)($_GET['search'] ?? ''));
$filter = trim((string)($_GET['filter'] ?? 'all'));

$sql = "SELECT * FROM `allowed_visitors` WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= " AND (name LIKE :s OR mobile LIKE :sm)";
    $params[':s'] = "%$search%";
    $params[':sm'] = "%$search%";
}
if ($filter === 'checked_in') {
    $sql .= " AND is_checked_in = 1";
} elseif ($filter === 'pending') {
    $sql .= " AND is_checked_in = 0";
}
$sql .= " ORDER BY is_checked_in DESC, checked_in_at DESC, id ASC";

$vStmt = $pdo->prepare($sql);
$vStmt->execute($params);
$visitors = $vStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Live Tracking & Reset Dashboard | EventMS</title>
    
    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Unified EventMS Design System (Navy Header + White Card Body) -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="desktop-admin-wrap">

    <!-- Hybrid Card: Solid Dark Navy #0f1c30 Header + Pure White #ffffff Body -->
    <div class="hybrid-card">

        <!-- 1. Header Block: Solid Dark Navy #0f1c30 with Glowing Shield Icon & Direct Links -->
        <div class="hybrid-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                
                <!-- Left: Title, Shield Icon & Subtitle -->
                <div class="d-flex align-items-center gap-3">
                    <div class="hybrid-header-icon flex-shrink-0">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h4 class="fw-bold mb-0 text-white">Live Tracking &amp; Reset Dashboard</h4>
                            <span class="hybrid-header-pill d-none d-sm-inline-flex" style="font-size: 0.72rem; padding: 0.2rem 0.65rem;">
                                Public Access
                            </span>
                        </div>
                        <p class="text-white-50 small mb-0">Real-time gate attendance monitoring and visitor state resets</p>
                    </div>
                </div>

                <!-- Right: Action Pills with Direct Link back to index.php -->
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="index.php" class="hybrid-header-pill" title="Return to Check-in Terminal">
                        <i class="bi bi-door-open me-1 text-info"></i> Gate Terminal
                    </a>
                    <a href="?export=csv&search=<?= urlencode($search) ?>&filter=<?= urlencode($filter) ?>" class="hybrid-header-pill" title="Download CSV Report">
                        <i class="bi bi-download me-1"></i> Export CSV
                    </a>
                    <button type="button" class="btn hybrid-header-pill" data-bs-toggle="modal" data-bs-target="#importCsvModal" title="Bulk Import Visitors via CSV">
                        <i class="bi bi-cloud-arrow-up me-1 text-info"></i> Import CSV
                    </button>
                    <a 
                        href="dashboard.php?action=reset_all" 
                        class="btn btn-reset-bulk-danger btn-sm px-3 py-1"
                        onclick="return confirm('Are you sure you want to reset ALL <?= $totalInvited ?> visitors to Not Checked-in status?');"
                        title="Clear all gate entries"
                    >
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset All Check-ins
                    </a>
                </div>

            </div>
        </div>

        <!-- 2. Card Body: Clean Pure White #ffffff with High-Contrast Text -->
        <div class="hybrid-body p-3 p-md-4">

            <!-- Flash Notices -->
            <?php if ($msg === 'imported'): ?>
                <?php $count = (int)($_GET['count'] ?? 0); $skipped = (int)($_GET['skipped'] ?? 0); ?>
                <div class="alert alert-success bg-success-subtle border-success-subtle text-success-emphasis d-flex align-items-center py-2 px-3 rounded-3 small mb-4 shadow-sm" role="alert">
                    <i class="bi bi-check-circle-fill me-2 fs-5 text-success"></i>
                    <div>
                        Successfully imported <strong><?= $count ?></strong> visitor<?= $count === 1 ? '' : 's' ?> from CSV!
                        <?php if ($skipped > 0): ?>
                            <span class="text-muted ms-1">(<?= $skipped ?> invalid or empty row<?= $skipped === 1 ? '' : 's' ?> skipped)</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($msg === 'invalid_file'): ?>
                <div class="alert alert-danger bg-danger-subtle border-danger-subtle text-danger-emphasis d-flex align-items-center py-2 px-3 rounded-3 small mb-4 shadow-sm" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-5 text-danger"></i>
                    <div>Upload failed: Please select a valid <strong>.csv</strong> file.</div>
                </div>
            <?php elseif ($msg === 'import_empty'): ?>
                <div class="alert alert-warning border-warning-subtle text-warning-emphasis d-flex align-items-center py-2 px-3 rounded-3 small mb-4 shadow-sm" role="alert">
                    <i class="bi bi-exclamation-circle-fill me-2 fs-5 text-warning"></i>
                    <div>The uploaded CSV did not contain any valid visitor rows (Name, 10-digit Mobile Number).</div>
                </div>
            <?php elseif ($msg === 'reset_one'): ?>
                <div class="alert alert-success bg-success-subtle border-success-subtle text-success-emphasis d-flex align-items-center py-2 px-3 rounded-3 small mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                    <div>Visitor entry status successfully reset to <strong>Not Checked-in</strong> (Pending).</div>
                </div>
            <?php elseif ($msg === 'reset_all'): ?>
                <div class="alert alert-warning border-warning-subtle text-warning-emphasis d-flex align-items-center py-2 px-3 rounded-3 small mb-4" role="alert">
                    <i class="bi bi-arrow-repeat me-2 fs-5"></i>
                    <div>All visitors have been reset back to <strong>Not Checked-in</strong>. Gate is clear for new entries.</div>
                </div>
            <?php endif; ?>

            <!-- Real-Time Metrics Cards: Total Invited, Inside Gate, Pending Entry, Gate Flow Rate -->
            <div class="row g-3 mb-4">
                
                <!-- 1. Total Invited -->
                <div class="col-6 col-md-3">
                    <div class="metric-box-white">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Total Invited</span>
                            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-3" style="width: 38px; height: 38px; font-size: 1.15rem;">
                                <i class="bi bi-people-fill"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold text-dark mb-0"><?= $totalInvited ?></h2>
                        <small class="text-muted">Whitelisted Attendees</small>
                    </div>
                </div>

                <!-- 2. Inside Gate -->
                <div class="col-6 col-md-3">
                    <div class="metric-box-white" style="border-left: 4px solid var(--status-success);">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-success small fw-bold text-uppercase d-flex align-items-center gap-1" style="letter-spacing: 0.5px;">
                                <span class="pulse-dot-green"></span> Inside Gate
                            </span>
                            <div class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success rounded-3" style="width: 38px; height: 38px; font-size: 1.15rem;">
                                <i class="bi bi-check2-circle"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold text-success mb-0"><?= $insideGate ?></h2>
                        <small class="text-muted">Checked in at gate</small>
                    </div>
                </div>

                <!-- 3. Pending Entry -->
                <div class="col-6 col-md-3">
                    <div class="metric-box-white" style="border-left: 4px solid var(--status-warning);">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-warning-emphasis small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Pending Entry</span>
                            <div class="d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning-emphasis rounded-3" style="width: 38px; height: 38px; font-size: 1.15rem;">
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold text-warning-emphasis mb-0"><?= $pendingEntry ?></h2>
                        <small class="text-muted">Awaiting gate arrival</small>
                    </div>
                </div>

                <!-- 4. Gate Flow Rate -->
                <div class="col-6 col-md-3">
                    <div class="metric-box-white">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-primary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Gate Progress</span>
                            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-3" style="width: 38px; height: 38px; font-size: 1.15rem;">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold text-dark mb-0"><?= $progressRate ?>%</h2>
                        <div class="progress mt-2" style="height: 6px; background-color: #f1f5f9;">
                            <div class="progress-bar" role="progressbar" style="width: <?= $progressRate ?>%; background-color: var(--accent-blue);" aria-valuenow="<?= $progressRate ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Search & Filter Bar -->
            <div class="p-3 bg-light rounded-4 border mb-4">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-12 col-md-5">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted rounded-start-pill ps-3">
                                <i class="bi bi-search"></i>
                            </span>
                            <input 
                                type="text" 
                                name="search" 
                                class="form-control search-box-light border-start-0 rounded-end-pill ps-1" 
                                placeholder="Search visitor by name or mobile..." 
                                value="<?= htmlspecialchars($search) ?>"
                            >
                        </div>
                    </div>
                    
                    <div class="col-12 col-md-4">
                        <div class="btn-group w-100 shadow-sm" role="group">
                            <a href="?filter=all&search=<?= urlencode($search) ?>" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary bg-white' ?>" style="<?= $filter === 'all' ? 'background-color: var(--accent-blue);' : '' ?>">All (<?= $totalInvited ?>)</a>
                            <a href="?filter=checked_in&search=<?= urlencode($search) ?>" class="btn btn-sm <?= $filter === 'checked_in' ? 'btn-success' : 'btn-outline-secondary bg-white' ?>">Inside Gate (<?= $insideGate ?>)</a>
                            <a href="?filter=pending&search=<?= urlencode($search) ?>" class="btn btn-sm <?= $filter === 'pending' ? 'btn-warning' : 'btn-outline-secondary bg-white' ?>">Pending (<?= $pendingEntry ?>)</a>
                        </div>
                    </div>

                    <div class="col-12 col-md-3 text-md-end d-flex gap-2 justify-content-md-end">
                        <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" style="background-color: var(--accent-blue);">
                            <i class="bi bi-funnel me-1"></i> Filter
                        </button>
                        <?php if ($search !== '' || $filter !== 'all'): ?>
                            <a href="dashboard.php" class="btn btn-pill-white btn-sm px-3" style="width: auto;">
                                <i class="bi bi-x me-1"></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Tabular Log: Visitor Name, Mobile, Check-In Timestamp, Status, Action -->
            <div class="border rounded-4 overflow-hidden shadow-sm">
                <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark d-flex align-items-center gap-2">
                        <i class="bi bi-table text-primary"></i>
                        <span>Visitor Check-In Log</span>
                    </span>
                    <span class="badge bg-white text-secondary border px-3 py-1 rounded-pill">
                        <?= count($visitors) ?> Visitors &bull; Prioritizing Active Entries
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-light-crisp align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width: 50px;">#</th>
                                <th>Full Name</th>
                                <th>Mobile Number</th>
                                <th>Current Status</th>
                                <th>Gate Entry Timestamp</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($visitors)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                                        No matching visitors found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($visitors as $v): ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-bold"><?= (int)$v['id'] ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($v['name']) ?></div>
                                            <small class="text-muted">Pre-Seeded Guest</small>
                                        </td>
                                        <td>
                                            <span class="px-2 py-1 bg-light text-dark rounded-2 border fw-semibold small">
                                                +91 <?= htmlspecialchars($v['mobile']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ((int)$v['is_checked_in'] === 1): ?>
                                                <span class="badge badge-inside-light px-3 py-1 rounded-pill d-inline-flex align-items-center gap-1">
                                                    <span class="pulse-dot-green"></span>
                                                    <span>Inside Gate</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-pending-light px-3 py-1 rounded-pill d-inline-flex align-items-center gap-1">
                                                    <i class="bi bi-clock me-1 text-muted"></i>
                                                    <span>Not Checked-in</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($v['checked_in_at'])): ?>
                                                <span class="text-dark fw-semibold small">
                                                    <i class="bi bi-clock-history text-success me-1"></i>
                                                    <?= htmlspecialchars(date('d M Y, h:i:s A', strtotime($v['checked_in_at']))) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">&mdash; Awaiting Entry &mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <?php if ((int)$v['is_checked_in'] === 1): ?>
                                                <!-- Individual Reset Link -->
                                                <a 
                                                    href="dashboard.php?action=reset&id=<?= (int)$v['id'] ?>" 
                                                    class="btn btn-outline-warning btn-sm rounded-pill px-3 fw-bold"
                                                    onclick="return confirm('Reset entry state for <?= htmlspecialchars(addslashes($v['name'])) ?>?');"
                                                    title="Reset to Pending"
                                                >
                                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small pe-2">
                                                    <i class="bi bi-check-circle text-secondary"></i> Ready
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- Bulk Import CSV Modal (Hybrid Navy Header + Pure White Body) -->
<div class="modal fade" id="importCsvModal" tabindex="-1" aria-labelledby="importCsvModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered px-3" style="max-width: 480px; margin: auto;">
        <div class="modal-content hybrid-modal overflow-hidden">
            <form method="POST" action="dashboard.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_csv">
                
                <div class="modal-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <div class="hybrid-header-icon" style="width: 34px; height: 34px; font-size: 1.05rem;">
                            <i class="bi bi-cloud-arrow-up"></i>
                        </div>
                        <h6 class="modal-title fw-bold text-white mb-0" id="importCsvModalLabel">Bulk Import Visitors (CSV)</h6>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body p-4">
                    <!-- Instruction Card -->
                    <div class="p-3 bg-light rounded-3 border mb-3 small text-secondary">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-info-circle-fill text-primary fs-6"></i>
                            <strong class="text-dark">Required CSV Format</strong>
                        </div>
                        <p class="mb-2">Your CSV file must include columns for <strong>Name</strong> and <strong>10-digit Mobile Number</strong>.</p>
                        
                        <div class="p-2 bg-white rounded-2 border font-monospace small text-dark user-select-all mb-2">
                            Name, Mobile Number<br>
                            Gopal Sir, 7720877204<br>
                            Tejas K, 9022492233
                        </div>
                        <ul class="list-unstyled mb-0 text-muted" style="font-size: 0.78rem; line-height: 1.5;">
                            <li><i class="bi bi-check2 text-success me-1"></i> Optional header row is automatically detected &amp; skipped.</li>
                            <li><i class="bi bi-check2 text-success me-1"></i> Mobile numbers are sanitized to 10 digits (e.g. +91 prefix trimmed).</li>
                            <li><i class="bi bi-check2 text-success me-1"></i> Duplicate numbers update visitor names without resetting check-in state.</li>
                        </ul>
                    </div>

                    <!-- File Input -->
                    <div class="mb-2">
                        <label for="csvFileInput" class="form-label fw-bold small text-secondary text-uppercase" style="letter-spacing: 0.5px;">
                            <i class="bi bi-filetype-csv me-1 text-primary"></i> Select CSV File
                        </label>
                        <input 
                            type="file" 
                            class="form-control" 
                            id="csvFileInput" 
                            name="csv_file" 
                            accept=".csv,text/csv" 
                            required
                        >
                        <div class="form-text small text-muted">
                            Accepts standard <code>.csv</code> spreadsheet files.
                        </div>
                    </div>
                </div>

                <div class="modal-footer d-flex justify-content-end gap-2 p-3 bg-light border-top">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3 py-1 btn-sm fw-semibold" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 py-1 btn-sm fw-bold" style="background-color: var(--accent-blue);">
                        <i class="bi bi-cloud-arrow-up me-1"></i> Upload &amp; Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bootstrap 5.3.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.hybrid-body .alert');
    if (alerts.length) {
        alerts.forEach(function(alert) {
            const isError = alert.classList.contains('alert-danger');
            const duration = isError ? 3000 : 1000;
            setTimeout(function() {
                alert.style.transition = 'opacity 0.2s ease, max-height 0.25s ease, margin 0.25s ease, padding 0.25s ease';
                alert.style.overflow = 'hidden';
                alert.style.maxHeight = alert.offsetHeight + 'px';
                requestAnimationFrame(function() {
                    alert.style.opacity = '0';
                    alert.style.maxHeight = '0';
                    alert.style.marginTop = '0';
                    alert.style.marginBottom = '0';
                    alert.style.paddingTop = '0';
                    alert.style.paddingBottom = '0';
                });
                setTimeout(function() {
                    if (alert.parentNode) alert.parentNode.removeChild(alert);
                }, 260);
            }, duration);
        });

        try {
            const url = new URL(window.location.href);
            let changed = false;
            ['msg', 'count', 'skipped'].forEach(function(p) {
                if (url.searchParams.has(p)) {
                    url.searchParams.delete(p);
                    changed = true;
                }
            });
            if (changed) {
                const query = url.searchParams.toString();
                window.history.replaceState(null, '', url.pathname + (query ? '?' + query : '') + url.hash);
            }
        } catch (e) {}
    }
});
</script>
</body>
</html>
