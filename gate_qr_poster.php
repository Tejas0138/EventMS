<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$pdo = Database::getConnection();
$settings = getEventSettings($pdo);
$eventName = trim((string)($settings['event_name'] ?? 'COE Event 2026'));
$gateCheckinUrl = getBaseUrl() . '/gate_checkin.php';
$autoPrint = !empty($_GET['auto_print']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gate Check-In Poster | <?= htmlspecialchars($eventName) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🛡️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
    @page {
        size: A4 portrait;
        margin: 10mm 15mm;
    }

    html, body {
        margin: 0;
        padding: 0;
        background: #f8fafc;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        color: #0f172a;
        -webkit-font-smoothing: antialiased;
    }

    /* Top Floating Toolbar (Hidden on Print) */
    .no-print-bar {
        position: sticky;
        top: 0;
        left: 0;
        right: 0;
        background: #0f1c30;
        color: #ffffff;
        padding: 10px 18px;
        z-index: 999;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    /* A4 Poster Container (Screen Preview & Print Target) */
    .poster-sheet {
        width: 210mm;
        min-height: 297mm;
        max-width: 100%;
        margin: 24px auto;
        background: #ffffff;
        padding: 24mm 20mm;
        box-sizing: border-box;
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
    }

    .poster-inner {
        width: 100%;
        max-width: 170mm;
        border: 3px solid #0f1c30;
        border-radius: 24px;
        padding: 32px 24px 28px;
        box-sizing: border-box;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: #ffffff;
    }

    .poster-event-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f1f5f9;
        color: #0f1c30;
        font-weight: 800;
        font-size: 15px;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        padding: 6px 18px;
        border-radius: 999px;
        border: 1.5px solid #cbd5e1;
        margin-bottom: 20px;
    }

    .poster-title {
        font-size: 42px;
        font-weight: 900;
        color: #0f1c30;
        margin: 0 0 10px;
        line-height: 1.15;
        letter-spacing: -0.5px;
        text-transform: uppercase;
    }

    .poster-qr-frame {
        width: 290px;
        height: 290px;
        padding: 16px;
        background: #ffffff;
        border: 2px solid #e2e8f0;
        border-radius: 20px;
        box-shadow: 0 8px 24px rgba(15, 28, 48, 0.08);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 20px auto 24px;
        box-sizing: border-box;
    }

    .poster-qr-frame canvas,
    .poster-qr-frame img {
        width: 256px !important;
        height: 256px !important;
        display: block;
    }

    .poster-instructions {
        font-size: 21px;
        font-weight: 800;
        color: #0f172a;
        margin: 0 0 14px;
        line-height: 1.3;
        letter-spacing: -0.2px;
    }

    .poster-subline {
        font-size: 15px;
        font-weight: 600;
        color: #64748b;
        margin: 0 0 20px;
        padding: 6px 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        display: inline-block;
    }

    .poster-url-text {
        font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
        font-size: 13px;
        font-weight: 600;
        color: #1d4ed8;
        background: #eff6ff;
        padding: 6px 16px;
        border-radius: 8px;
        border: 1px solid #bfdbfe;
        word-break: break-all;
    }

    /* Print Styles: Fit One Single A4 Page Exactly */
    @media print {
        html, body {
            background: #ffffff !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .no-print-bar {
            display: none !important;
        }

        .poster-sheet {
            width: 100% !important;
            min-height: 100vh !important;
            height: 100vh !important;
            margin: 0 !important;
            padding: 12mm 15mm !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            page-break-after: avoid !important;
            page-break-inside: avoid !important;
        }

        .poster-inner {
            border: 3.5px solid #000000 !important;
            height: 100% !important;
            max-height: 270mm !important;
        }

        .poster-title {
            color: #000000 !important;
        }

        .poster-qr-frame {
            border-color: #000000 !important;
            box-shadow: none !important;
        }
    }
    </style>
</head>
<body>

<div class="no-print-bar">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-printer-fill fs-5 text-warning"></i>
        <span class="fw-bold">Gate Check-In Poster (A4 Printable)</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button type="button" onclick="window.print()" class="btn btn-primary btn-sm px-3 fw-bold">
            <i class="bi bi-printer me-1"></i> Print Poster
        </button>
        <button type="button" onclick="window.close()" class="btn btn-outline-light btn-sm px-3">
            <i class="bi bi-x me-1"></i> Close
        </button>
    </div>
</div>

<div class="poster-sheet">
    <div class="poster-inner">
        
        <?php if (!empty($eventName)): ?>
            <div class="poster-event-badge">
                <i class="bi bi-shield-check"></i>
                <?= htmlspecialchars($eventName) ?>
            </div>
        <?php endif; ?>

        <h1 class="poster-title">Scan to Check In</h1>

        <!-- High-Resolution Local QR Canvas/Image -->
        <div class="poster-qr-frame" id="posterQrFrame">
            <!-- Rendered by QRCode.js locally -->
        </div>

        <div class="poster-instructions">
            Enter your mobile number or Register Id
        </div>

        <div class="poster-subline">
            <i class="bi bi-calendar-check me-1 text-primary"></i> Day 1 and Day 2 use the same QR code.
        </div>

        <div class="poster-url-text">
            <?= htmlspecialchars($gateCheckinUrl) ?>
        </div>

    </div>
</div>

<script src="assets/js/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const targetUrl = <?= json_encode($gateCheckinUrl) ?>;
    const container = document.getElementById('posterQrFrame');
    
    if (typeof QRCode !== 'undefined' && container) {
        new QRCode(container, {
            text: targetUrl,
            width: 256,
            height: 256,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
    }

    <?php if ($autoPrint): ?>
    setTimeout(() => {
        window.print();
    }, 400);
    <?php endif; ?>
});
</script>

</body>
</html>