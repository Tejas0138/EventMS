<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$pdo = Database::getConnection();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isSuperAdmin = !empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin';

// =========================================================================
// Standalone Actions: Single Reset & Bulk Reset (mirrors dashboard.php)
// =========================================================================
$action = $_GET['action'] ?? $_POST['action'] ?? null;
$msg    = $_GET['msg'] ?? null;

if ($action === 'reset' && !empty($_GET['id'])) { 
    if (!$isSuperAdmin) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Only the Super Admin can reset a kit.</p></body></html>';
        exit;
    }
    $targetId = (int)$_GET['id'];
    $visInfoStmt = $pdo->prepare("SELECT id, name, user_id, handed_over_by, counter_region FROM `gift_distribution` WHERE id = :id");
    $visInfoStmt->execute([':id' => $targetId]);
    $visInfo = $visInfoStmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        "UPDATE `gift_distribution`
         SET is_gift_collected = 0, gift_collected_at = NULL, handed_over_by = NULL, counter_region = NULL
         WHERE id = :id"
    );
    $stmt->execute([':id' => $targetId]);

    if ($visInfo) {
        try {
            $nowIST = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('d M Y, h:i:s A');
            $handedCounter = $visInfo['handed_over_by'] ?? 'Unknown';
            if (!empty($visInfo['counter_region'])) {
                $handedCounter .= ' (' . $visInfo['counter_region'] . ')';
            }
            $details = json_encode([
                'visitor_name'   => $visInfo['name'],
                'register_id'    => $visInfo['user_id'] ?: '—',
                'handed_over_by' => $visInfo['handed_over_by'] ?? 'Unknown',
                'counter_region' => $visInfo['counter_region'] ?? 'Unknown',
                'note'           => "COE Kit reset via gift_dashboard by Super Admin. Originally handed over by {$handedCounter}."
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $insAudit = $pdo->prepare("
                INSERT INTO `audit_log` (`action`, `performed_by`, `target_id`, `target_name`, `details`, `created_at_ist`, `created_at`)
                VALUES ('reset_kit', :by, :tid, :tname, :details, :ist, NOW())
            ");
            $insAudit->execute([
                ':by'         => $_SESSION['admin_username'] ?? 'admin',
                ':tid'        => $visInfo['user_id'] ?: (string)$targetId,
                ':tname'      => $visInfo['name'],
                ':details'    => $details,
                ':ist'        => $nowIST
            ]);
        } catch (\Throwable $e) {}
    }
    header('Location: gift_dashboard.php?msg=reset_one');
    exit;
}

if ($action === 'reset_all') {
    if (!$isSuperAdmin) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Only the Super Admin can reset a kit.</p></body></html>';
        exit;
    }
    $pdo->exec("UPDATE `gift_distribution` SET is_gift_collected = 0, gift_collected_at = NULL, handed_over_by = NULL, counter_region = NULL");
    try {
        $nowIST = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('d M Y, h:i:s A');
        $insAudit = $pdo->prepare("
            INSERT INTO `audit_log` (`action`, `performed_by`, `target_id`, `target_name`, `details`, `created_at_ist`, `created_at`)
            VALUES ('reset_all_kits', :by, NULL, NULL, :details, :ist, NOW())
        ");
        $insAudit->execute([
            ':by'      => $_SESSION['admin_username'] ?? 'admin',
            ':details' => 'Super Admin performed Reset All COE Kits via gift_dashboard',
            ':ist'     => $nowIST
        ]);
    } catch (\Throwable $e) {}
    header('Location: gift_dashboard.php?msg=reset_all');
    exit;
}

// Optional CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->query(
        "SELECT * FROM `gift_distribution` ORDER BY is_gift_collected DESC, gift_collected_at DESC, id ASC"
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=coe_kit_distribution_' . date('Y-m-d_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Full Name', 'Mobile Number', 'Kit Status', 'Collected At', 'Created At']);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['id'],
            $row['name'],
            $row['mobile'],
            $row['is_gift_collected'] == 1 ? 'Kit Collected' : 'Pending',
            $row['gift_collected_at'] ?? 'N/A',
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// =========================================================================
// Query Metrics: Total Eligible, Gifts Given, Pending
// =========================================================================
$mStmt = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_gift_collected = 1 THEN 1 ELSE 0 END) AS given,
        SUM(CASE WHEN is_gift_collected = 0 THEN 1 ELSE 0 END) AS pending
    FROM `gift_distribution`
");
$mRow          = $mStmt->fetch();
$totalEligible = (int)($mRow['total']   ?? 0);
$giftsGiven    = (int)($mRow['given']   ?? 0);
$pendingGifts  = (int)($mRow['pending'] ?? 0);
$progressRate  = $totalEligible > 0 ? round(($giftsGiven / $totalEligible) * 100) : 0;

// Search & Filter
$search = trim((string)($_GET['search'] ?? ''));
$filter = trim((string)($_GET['filter'] ?? 'all'));

$sql    = "SELECT * FROM `gift_distribution` WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (name LIKE :s OR mobile LIKE :sm)";
    $params[':s']  = "%$search%";
    $params[':sm'] = "%$search%";
}
if ($filter === 'collected') {
    $sql .= " AND is_gift_collected = 1";
} elseif ($filter === 'pending') {
    $sql .= " AND is_gift_collected = 0";
}
$sql .= " ORDER BY is_gift_collected DESC, gift_collected_at DESC, id ASC";

$vStmt = $pdo->prepare($sql);
$vStmt->execute($params);
$visitors = $vStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>COE Kit Tracking Dashboard | EventMS</title>

    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Unified EventMS Design System -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="desktop-admin-wrap">

    <!-- Hybrid Card: Solid Dark Navy #0f1c30 Header + Pure White #ffffff Body -->
    <div class="hybrid-card">

        <!-- 1. Header Block -->
        <div class="hybrid-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">

                <!-- Left: Title & Icon -->
                <div class="d-flex align-items-center gap-3">
                    <div class="hybrid-header-icon flex-shrink-0">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h4 class="fw-bold mb-0 text-white">COE Kit Tracking &amp; Reset Dashboard</h4>
                            <span class="hybrid-header-pill d-none d-sm-inline-flex" style="font-size: 0.72rem; padding: 0.2rem 0.65rem;">
                                Public Access
                            </span>
                        </div>
                        <p class="text-white-50 small mb-0">Real-time COE Kit handover monitoring and state resets</p>
                    </div>
                </div>

                <!-- Right: Actions -->
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="gift.php" class="hybrid-header-pill" title="Return to COE Kit Terminal">
                        <i class="bi bi-box-seam me-1 text-info"></i> COE Kit Terminal
                    </a>
                    <a href="index.php" class="hybrid-header-pill" title="Return to Gate Terminal">
                        <i class="bi bi-door-open me-1 text-info"></i> Gate Terminal
                    </a>
                    <a href="?export=csv&search=<?= urlencode($search) ?>&filter=<?= urlencode($filter) ?>" class="hybrid-header-pill" title="Download CSV Report">
                        <i class="bi bi-download me-1"></i> Export CSV
                    </a>
                    <?php if ($isSuperAdmin): ?>
                    <a
                        href="gift_dashboard.php?action=reset_all"
                        class="btn btn-reset-bulk-danger btn-sm px-3 py-1"
                        onclick="return confirm('Are you sure you want to reset ALL <?= $totalEligible ?> COE Kit records back to Pending?');"
                        title="Reset all COE Kit handover records"
                    >
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset All COE Kits
                    </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- 2. Card Body: Clean Pure White #ffffff -->
        <div class="hybrid-body p-3 p-md-4">

            <!-- Flash Notices -->
            <?php if ($msg === 'reset_one'): ?>
                <div class="alert alert-success bg-success-subtle border-success-subtle text-success-emphasis d-flex align-items-center py-2 px-3 rounded-3 small mb-4" role="alert">
                    <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                    <div>Visitor COE Kit status successfully reset to <strong>Pending</strong>.</div>
                </div>
            <?php elseif ($msg === 'reset_all'): ?>
                <div class="alert alert-warning border-warning-subtle text-warning-emphasis d-flex align-items-center py-2 px-3 rounded-3 small mb-4" role="alert">
                    <i class="bi bi-arrow-repeat me-2 fs-5"></i>
                    <div>All COE Kit records have been reset to <strong>Pending</strong>. Distribution counter cleared.</div>
                </div>
            <?php endif; ?>

            <!-- Metric Cards: Total Eligible, COE Kits Given, Pending, Progress Rate -->
            <div class="row g-3 mb-4">

                <!-- 1. Total Eligible -->
                <div class="col-6 col-md-3">
                    <div class="metric-box-white">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-secondary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Total Eligible</span>
                            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-3" style="width: 38px; height: 38px; font-size: 1.15rem;">
                                <i class="bi bi-people-fill"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold text-dark mb-0"><?= $totalEligible ?></h2>
                        <small class="text-muted">Registered Attendees</small>
                    </div>
                </div>

                <!-- 2. COE Kits Given -->
                <div class="col-6 col-md-3">
                    <div class="metric-box-white" style="border-left: 4px solid var(--status-success);">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-success small fw-bold text-uppercase d-flex align-items-center gap-1" style="letter-spacing: 0.5px;">
                                <span class="pulse-dot-green"></span> COE Kits Given
                            </span>
                            <div class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success rounded-3" style="width: 38px; height: 38px; font-size: 1.15rem;">
                                <i class="bi bi-box-seam"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold text-success mb-0"><?= $giftsGiven ?></h2>
                        <small class="text-muted">Collected at counter</small>
                    </div>
                </div>

                <!-- 3. Pending Kits -->
                <div class="col-6 col-md-3">
                    <div class="metric-box-white" style="border-left: 4px solid var(--status-warning);">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-warning-emphasis small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Pending Kits</span>
                            <div class="d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning-emphasis rounded-3" style="width: 38px; height: 38px; font-size: 1.15rem;">
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                        </div>
                        <h2 class="fw-bold text-warning-emphasis mb-0"><?= $pendingGifts ?></h2>
                        <small class="text-muted">Awaiting distribution</small>
                    </div>
                </div>

                <!-- 4. Distribution Progress -->
                <div class="col-6 col-md-3">
                    <div class="metric-box-white">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="text-primary small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Kit Progress</span>
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
                                placeholder="Search by name or mobile..."
                                value="<?= htmlspecialchars($search) ?>"
                            >
                        </div>
                    </div>

                    <div class="col-12 col-md-4">
                        <div class="btn-group w-100 shadow-sm" role="group">
                            <a href="?filter=all&search=<?= urlencode($search) ?>" class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary bg-white' ?>" style="<?= $filter === 'all' ? 'background-color: var(--accent-blue);' : '' ?>">All (<?= $totalEligible ?>)</a>
                            <a href="?filter=collected&search=<?= urlencode($search) ?>" class="btn btn-sm <?= $filter === 'collected' ? 'btn-success' : 'btn-outline-secondary bg-white' ?>">Given (<?= $giftsGiven ?>)</a>
                            <a href="?filter=pending&search=<?= urlencode($search) ?>" class="btn btn-sm <?= $filter === 'pending' ? 'btn-warning' : 'btn-outline-secondary bg-white' ?>">Pending (<?= $pendingGifts ?>)</a>
                        </div>
                    </div>

                    <div class="col-12 col-md-3 text-md-end d-flex gap-2 justify-content-md-end">
                        <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" style="background-color: var(--accent-blue);">
                            <i class="bi bi-funnel me-1"></i> Filter
                        </button>
                        <?php if ($search !== '' || $filter !== 'all'): ?>
                            <a href="gift_dashboard.php" class="btn btn-pill-white btn-sm px-3" style="width: auto;">
                                <i class="bi bi-x me-1"></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- COE Kit Distribution Log Table -->
            <div class="border rounded-4 overflow-hidden shadow-sm">
                <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                    <span class="fw-bold text-dark d-flex align-items-center gap-2">
                        <i class="bi bi-table text-primary"></i>
                        <span>COE Kit Distribution Log</span>
                    </span>
                    <span class="badge bg-white text-secondary border px-3 py-1 rounded-pill">
                        <?= count($visitors) ?> Attendees &bull; Prioritizing Collected Entries
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-light-crisp align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width: 50px;">#</th>
                                <th>Full Name</th>
                                <th>Mobile Number</th>
                                <th>Kit Status</th>
                                <th>Collection Timestamp</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($visitors)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                                        No matching attendees found.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($visitors as $v): ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-bold"><?= (int)$v['id'] ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($v['name']) ?></div>
                                            <small class="text-muted">Pre-Registered Guest</small>
                                        </td>
                                        <td>
                                            <span class="px-2 py-1 bg-light text-dark rounded-2 border fw-semibold small">
                                                +91 <?= htmlspecialchars($v['mobile']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ((int)$v['is_gift_collected'] === 1): ?>
                                                <span class="badge badge-inside-light px-3 py-1 rounded-pill d-inline-flex align-items-center gap-1">
                                                    <span class="pulse-dot-green"></span>
                                                    <span>Kit Collected</span>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-pending-light px-3 py-1 rounded-pill d-inline-flex align-items-center gap-1">
                                                    <i class="bi bi-clock me-1 text-muted"></i>
                                                    <span>Pending</span>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($v['gift_collected_at'])): ?>
                                                <span class="text-dark fw-semibold small">
                                                    <i class="bi bi-clock-history text-success me-1"></i>
                                                    <?= htmlspecialchars(date('d M Y, h:i:s A', strtotime($v['gift_collected_at']))) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">&mdash; Not Yet Handed Over &mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <?php if ((int)$v['is_gift_collected'] === 1): ?>
                                                <?php if ($isSuperAdmin): ?>
                                                    <!-- Individual Reset Link -->
                                                    <a
                                                        href="gift_dashboard.php?action=reset&id=<?= (int)$v['id'] ?>"
                                                        class="btn btn-outline-warning btn-sm rounded-pill px-3 fw-bold"
                                                        onclick="return confirm('Reset kit for <?= htmlspecialchars(addslashes($v['name'])) ?>? This marks the kit as not handed over.');"
                                                        title="Reset to Pending"
                                                    >
                                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                                                    </a>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary px-3 py-1 rounded-pill">
                                                        Collected
                                                    </span>
                                                <?php endif; ?>
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

