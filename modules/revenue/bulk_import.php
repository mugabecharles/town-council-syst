<?php
/**
 * TCMS Bulk Payer & Assessment Import
 * Supports CSV upload for:
 *   1. Bulk Payer Registration
 *   2. Bulk Revenue Assessment
 * With preview, validation, and error reporting.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();

if (!hasRole(['admin','finance_officer','revenue_officer','town_clerk'])) {
    setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit;
}

$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

// ══════════════════════════════════════════════════════════════════
// POST HANDLERS
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* ── STEP 1: Upload & Preview ──────────────────────────────── */
    if ($action === 'preview_import') {
        $type = $_POST['import_type'] ?? 'assessments';
        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            setFlash('danger','Please select a CSV file to upload.');
            header('Location: bulk_import.php'); exit;
        }

        $file    = $_FILES['csv_file']['tmp_name'];
        $origName= $_FILES['csv_file']['name'];
        if (!in_array(strtolower(pathinfo($origName, PATHINFO_EXTENSION)), ['csv','txt'])) {
            setFlash('danger','Only CSV files are supported.');
            header('Location: bulk_import.php'); exit;
        }

        $rows = parseCsv($file);
        if (count($rows) < 2) {
            setFlash('danger','CSV file is empty or has no data rows.');
            header('Location: bulk_import.php'); exit;
        }

        // Store parsed data in session for confirmation step
        startSecureSession();
        $_SESSION['bulk_preview'] = [
            'type'    => $type,
            'headers' => $rows[0],
            'rows'    => array_slice($rows, 1, 500), // max 500 rows preview
            'total'   => count($rows) - 1,
            'file'    => $origName,
        ];
        header('Location: bulk_import.php?step=preview'); exit;
    }

    /* ── STEP 2: Confirm & Process ─────────────────────────────── */
    if ($action === 'process_import') {
        startSecureSession();
        $preview = $_SESSION['bulk_preview'] ?? null;
        if (!$preview) {
            setFlash('danger','Session expired. Please re-upload the file.');
            header('Location: bulk_import.php'); exit;
        }

        $type = $preview['type'];
        $rows = $preview['rows'];
        $ok = 0; $fail = 0; $errors = [];

        if ($type === 'assessments') {
            list($ok, $fail, $errors) = importAssessments($db, $rows, $fy, $user['id']);
        } elseif ($type === 'payers') {
            list($ok, $fail, $errors) = importPayers($db, $rows, $user['id']);
        }

        // Log the import
        $db->prepare("INSERT INTO bulk_import_logs
            (import_type, file_name, total_rows, success_rows, failed_rows, errors, imported_by, financial_year)
            VALUES (?,?,?,?,?,?,?,?)")
           ->execute([
               $type, $preview['file'], $preview['total'],
               $ok, $fail,
               $errors ? json_encode(array_slice($errors, 0, 50)) : null,
               $user['id'], $fy
           ]);

        logAudit('BULK_IMPORT', 'revenue', $type, 0, $preview['file'],
            [], ['ok' => $ok, 'fail' => $fail]);

        unset($_SESSION['bulk_preview']);

        if ($ok > 0) {
            setFlash('success', "<strong>Import complete:</strong> $ok record(s) imported successfully." .
                ($fail > 0 ? " $fail row(s) had errors." : ''));
        } else {
            setFlash('danger', "Import failed — 0 records imported. $fail error(s). Check the file and try again.");
        }
        header('Location: bulk_import.php?tab=history'); exit;
    }

    /* ── Cancel preview ─────────────────────────────────────────── */
    if ($action === 'cancel_preview') {
        startSecureSession();
        unset($_SESSION['bulk_preview']);
        header('Location: bulk_import.php'); exit;
    }
}

// ══════════════════════════════════════════════════════════════════
// IMPORT FUNCTIONS
// ══════════════════════════════════════════════════════════════════

function parseCsv(string $file): array {
    $rows = []; $handle = fopen($file, 'r');
    if (!$handle) return $rows;
    while (($row = fgetcsv($handle, 2000, ',')) !== false) {
        // Skip totally empty rows
        if (array_filter($row)) $rows[] = array_map('trim', $row);
    }
    fclose($handle);
    return $rows;
}

function importAssessments(PDO $db, array $rows, string $fy, int $userId): array {
    // Expected CSV columns:
    // payer_number, revenue_source_code, assessed_amount, penalty_amount, period_from, period_to, due_date, notes
    $ok = 0; $fail = 0; $errors = [];
    $headers = array_map('strtolower', array_map('trim', $rows[0] ?? []));

    // Detect if first row is a header
    $startRow = 0;
    if (in_array('payer_number', $headers) || in_array('payer_id', $headers)) {
        $startRow = 1; // skip header row
    }

    for ($i = $startRow; $i < count($rows); $i++) {
        $r    = $rows[$i];
        $line = $i + 1;

        // Map by position or header
        if ($startRow === 1) {
            $row = array_combine($headers, array_pad($r, count($headers), ''));
        } else {
            $keys = ['payer_number','revenue_source_code','assessed_amount','penalty_amount','period_from','period_to','due_date','notes'];
            $row  = array_combine($keys, array_pad($r, count($keys), ''));
        }

        $payerNum  = trim($row['payer_number']  ?? $row['payer_id'] ?? '');
        $srcCode   = strtoupper(trim($row['revenue_source_code'] ?? $row['source_code'] ?? ''));
        $amount    = (float)str_replace([',','UGX',' '], '', $row['assessed_amount'] ?? '0');
        $penalty   = (float)str_replace([',','UGX',' '], '', $row['penalty_amount']  ?? '0');

        if (empty($payerNum) || empty($srcCode) || $amount <= 0) {
            $fail++; $errors[] = "Row $line: payer_number, source_code, and assessed_amount are required (got: $payerNum / $srcCode / $amount)"; continue;
        }

        // Look up payer
        $ps = $db->prepare("SELECT id FROM payers WHERE payer_number=?");
        $ps->execute([$payerNum]); $payer = $ps->fetch();
        if (!$payer) {
            $fail++; $errors[] = "Row $line: Payer not found — $payerNum"; continue;
        }

        // Look up revenue source
        $ss = $db->prepare("SELECT id FROM revenue_sources WHERE source_code=?");
        $ss->execute([$srcCode]); $src = $ss->fetch();
        if (!$src) {
            $fail++; $errors[] = "Row $line: Revenue source not found — $srcCode"; continue;
        }

        // Check for duplicate assessment this FY
        $dup = $db->prepare("SELECT id FROM revenue_assessments WHERE payer_id=? AND revenue_source_id=? AND financial_year=?");
        $dup->execute([$payer['id'], $src['id'], $fy]);
        if ($dup->fetch()) {
            $fail++; $errors[] = "Row $line: Assessment already exists for $payerNum / $srcCode / $fy"; continue;
        }

        try {
            $total   = $amount + $penalty;
            $asmNum  = generateAssessmentNumber();
            $db->prepare("INSERT INTO revenue_assessments
                (assessment_number,payer_id,revenue_source_id,financial_year,
                 assessed_amount,penalty_amount,total_due,assessed_by,assessed_date,
                 period_from,period_to,due_date,notes,status)
                VALUES (?,?,?,?,?,?,?,?,CURDATE(),?,?,?,?,'active')")
               ->execute([
                   $asmNum, $payer['id'], $src['id'], $fy,
                   $amount, $penalty, $total, $userId,
                   !empty($row['period_from']) ? $row['period_from'] : null,
                   !empty($row['period_to'])   ? $row['period_to']   : null,
                   !empty($row['due_date'])     ? $row['due_date']    : null,
                   trim($row['notes'] ?? ''),
               ]);
            $ok++;
        } catch (PDOException $e) {
            $fail++; $errors[] = "Row $line: DB error — " . $e->getMessage();
        }
    }
    return [$ok, $fail, $errors];
}

function importPayers(PDO $db, array $rows, int $userId): array {
    // Expected CSV columns:
    // full_name, business_name, phone, national_id, ward_code, address, payer_type, notes
    $ok = 0; $fail = 0; $errors = [];
    $headers  = array_map('strtolower', array_map('trim', $rows[0] ?? []));
    $startRow = in_array('full_name', $headers) ? 1 : 0;

    for ($i = $startRow; $i < count($rows); $i++) {
        $r    = $rows[$i];
        $line = $i + 1;
        if ($startRow === 1) {
            $row = array_combine($headers, array_pad($r, count($headers), ''));
        } else {
            $keys = ['full_name','business_name','phone','national_id','ward_code','address','payer_type','notes'];
            $row  = array_combine($keys, array_pad($r, count($keys), ''));
        }

        $fullName = trim($row['full_name'] ?? '');
        $phone    = trim($row['phone']     ?? '');
        $wardCode = strtoupper(trim($row['ward_code'] ?? ''));

        if (empty($fullName)) {
            $fail++; $errors[] = "Row $line: full_name is required"; continue;
        }

        // Look up ward
        $wardId = null;
        if ($wardCode) {
            $ws = $db->prepare("SELECT id FROM wards WHERE ward_code=?");
            $ws->execute([$wardCode]); $ward = $ws->fetch();
            if (!$ward) {
                $fail++; $errors[] = "Row $line: Ward not found — $wardCode"; continue;
            }
            $wardId = $ward['id'];
        }

        // Check duplicate phone
        if ($phone) {
            $dp = $db->prepare("SELECT id FROM payers WHERE phone=?");
            $dp->execute([$phone]);
            if ($dp->fetch()) {
                $fail++; $errors[] = "Row $line: Phone already registered — $phone"; continue;
            }
        }

        try {
            $payerNum = generatePayerNumber($wardId ?? 0);
            $type     = in_array($row['payer_type'] ?? '', ['individual','business','organization'])
                      ? $row['payer_type'] : 'individual';
            $db->prepare("INSERT INTO payers
                (payer_number,payer_type,full_name,business_name,phone,national_id,address,ward_id,registration_date,registered_by,status)
                VALUES (?,?,?,?,?,?,?,?,CURDATE(),?,'active')")
               ->execute([
                   $payerNum, $type, $fullName,
                   trim($row['business_name'] ?? ''),
                   $phone,
                   trim($row['national_id']   ?? ''),
                   trim($row['address']       ?? ''),
                   $wardId, $userId,
               ]);
            $ok++;
        } catch (PDOException $e) {
            $fail++; $errors[] = "Row $line: DB error — " . $e->getMessage();
        }
    }
    return [$ok, $fail, $errors];
}

// ══════════════════════════════════════════════════════════════════
// VIEW
// ══════════════════════════════════════════════════════════════════
startSecureSession();
$step    = $_GET['step'] ?? 'upload';
$tab     = $_GET['tab']  ?? 'upload';
$preview = $_SESSION['bulk_preview'] ?? null;
if ($preview) $step = 'preview';

// Import history
$history = $db->query("SELECT bl.*, u.full_name AS importer FROM bulk_import_logs bl
    JOIN users u ON bl.imported_by=u.id ORDER BY bl.created_at DESC LIMIT 30")->fetchAll();

// Supporting data for templates
$wards   = $db->query("SELECT * FROM wards WHERE is_active=1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM revenue_sources WHERE is_active=1 ORDER BY source_code")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

renderHead('Bulk Import');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Bulk Import', 'Upload CSV to register payers or create assessments in bulk');
renderPageStart('Bulk Import', '', [
    ['url' => APP_URL.'/dashboard.php','label' => 'Dashboard'],
    ['url' => APP_URL.'/modules/revenue/payers.php','label' => 'Revenue'],
    ['url' => '#','label' => 'Bulk Import'],
]);
renderPageActions('');
renderFlashMessages();
?>

<?php if ($step === 'preview' && $preview): ?>
<!-- ══ PREVIEW STEP ════════════════════════════════════════════════ -->
<div class="alert alert-info">
  <span>ℹ</span>
  <div>
    <strong>Preview: <?= htmlspecialchars($preview['file']) ?></strong> —
    <?= $preview['total'] ?> data row(s) detected.
    Type: <strong><?= ucfirst($preview['type']) ?></strong>.
    Review below then click <strong>Confirm Import</strong>.
  </div>
</div>

<div class="card" style="margin-bottom:1rem;">
  <div class="card-header">
    <h5><span class="ch-icon">◑</span> Data Preview (first 20 rows)</h5>
    <div style="display:flex;gap:.5rem;">
      <form method="POST" style="display:inline;">
        <?= csrfField() ?><input type="hidden" name="action" value="cancel_preview">
        <button type="submit" class="btn btn-sm btn-outline-secondary">✕ Cancel</button>
      </form>
      <form method="POST" style="display:inline;">
        <?= csrfField() ?><input type="hidden" name="action" value="process_import">
        <button type="submit" class="btn btn-sm btn-primary"
                data-confirm="Import <?= $preview['total'] ?> record(s) into the system?">
          ✓ Confirm Import (<?= $preview['total'] ?> records)
        </button>
      </form>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table table-sm">
        <thead>
          <tr>
            <th>#</th>
            <?php foreach ($preview['headers'] as $h): ?>
            <th><?= htmlspecialchars($h) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($preview['rows'], 0, 20) as $i => $row): ?>
        <tr>
          <td class="fs-xs text-muted"><?= $i + 1 ?></td>
          <?php foreach ($row as $cell): ?>
          <td class="fs-sm"><?= htmlspecialchars($cell) ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($preview['total'] > 20): ?>
    <div style="padding:.6rem 1rem;background:#f8f9fa;font-size:.8rem;color:#6c757d;">
      Showing 20 of <?= $preview['total'] ?> rows. All rows will be imported.
    </div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- ══ UPLOAD STEP ══════════════════════════════════════════════ -->
<div class="grid-2" style="align-items:start;">

  <!-- Upload Form -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◑</span> Upload CSV File</h5></div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="preview_import">

        <div class="form-group">
          <label class="form-label">Import Type <span class="req">*</span></label>
          <select name="import_type" id="importType" class="form-select" required
                  onchange="showTemplate(this.value)">
            <option value="assessments">Revenue Assessments (bulk assess existing payers)</option>
            <option value="payers">New Payer Registration (register new payers in bulk)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">CSV File <span class="req">*</span></label>
          <input type="file" name="csv_file" class="form-control" required
                 accept=".csv,.txt">
          <div class="form-text">Max 500 rows per upload. UTF-8 CSV with headers recommended.</div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Upload & Preview →</button>
      </form>
    </div>
  </div>

  <!-- Template + Instructions -->
  <div>
    <!-- Assessments Template -->
    <div id="tmpl_assessments" class="card" style="margin-bottom:1rem;">
      <div class="card-header"><h5><span class="ch-icon">◆</span> CSV Template: Assessments</h5>
        <a href="data:text/csv;charset=utf-8,payer_number%2Crevenue_source_code%2Cassessed_amount%2Cpenalty_amount%2Cperiod_from%2Cperiod_to%2Cdue_date%2Cnotes%0ATC-W001-000001%2CTL001%2C500000%2C0%2C2026-07-01%2C2027-06-30%2C2026-09-30%2C%0A"
           download="assessment_template.csv" class="btn btn-sm btn-outline-secondary">⬇ Download Template</a>
      </div>
      <div class="card-body p-0">
        <div class="table-wrapper">
          <table class="tcms-table table-sm">
            <thead><tr><th>payer_number</th><th>revenue_source_code</th><th>assessed_amount</th><th>penalty_amount</th><th>period_from</th><th>period_to</th><th>due_date</th><th>notes</th></tr></thead>
            <tbody>
              <tr><td class="fs-xs text-primary">TC-W001-000001</td><td class="fs-xs">TL001</td><td class="fs-xs">500000</td><td class="fs-xs">0</td><td class="fs-xs">2026-07-01</td><td class="fs-xs">2027-06-30</td><td class="fs-xs">2026-09-30</td><td class="fs-xs"></td></tr>
              <tr><td class="fs-xs text-primary">TC-W002-000045</td><td class="fs-xs">MD001</td><td class="fs-xs">120000</td><td class="fs-xs">10000</td><td class="fs-xs">2026-07-01</td><td class="fs-xs">2026-12-31</td><td class="fs-xs">2026-08-31</td><td class="fs-xs">Penalty applied</td></tr>
            </tbody>
          </table>
        </div>
        <div style="padding:.8rem;font-size:.78rem;color:#6c757d;background:#f8f9fa;">
          <strong>Notes:</strong> payer_number and revenue_source_code must already exist in the system.
          Use <code>SHOW revenue_sources</code> codes from Settings → Revenue Sources.
        </div>
      </div>
    </div>

    <!-- Payers Template -->
    <div id="tmpl_payers" class="card" style="margin-bottom:1rem;display:none;">
      <div class="card-header"><h5><span class="ch-icon">◉</span> CSV Template: Payer Registration</h5>
        <a href="data:text/csv;charset=utf-8,full_name%2Cbusiness_name%2Cphone%2Cnational_id%2Cward_code%2Caddress%2Cpayer_type%2Cnotes%0AJohn%20Doe%2CABC%20Shop%2C0700000001%2CCM001234%2CW001%2CPlot%2010%2Cbusiness%2C%0A"
           download="payers_template.csv" class="btn btn-sm btn-outline-secondary">⬇ Download Template</a>
      </div>
      <div class="card-body p-0">
        <div class="table-wrapper">
          <table class="tcms-table table-sm">
            <thead><tr><th>full_name</th><th>business_name</th><th>phone</th><th>national_id</th><th>ward_code</th><th>address</th><th>payer_type</th><th>notes</th></tr></thead>
            <tbody>
              <tr><td class="fs-xs">John Doe</td><td class="fs-xs">ABC Shop</td><td class="fs-xs">0700000001</td><td class="fs-xs">CM001234</td><td class="fs-xs text-primary">W001</td><td class="fs-xs">Plot 10</td><td class="fs-xs">business</td><td class="fs-xs"></td></tr>
            </tbody>
          </table>
        </div>
        <div style="padding:.8rem;font-size:.78rem;color:#6c757d;background:#f8f9fa;">
          <strong>ward_code</strong> must match codes in Settings → Wards.
          payer_type: <code>individual</code>, <code>business</code>, or <code>organization</code>.
        </div>
      </div>
    </div>

    <!-- Available codes reference -->
    <div class="card">
      <div class="card-header"><h5><span class="ch-icon">◈</span> Quick Reference</h5></div>
      <div class="card-body">
        <div class="grid-2">
          <div>
            <div class="fs-sm fw-600 mb-1">Ward Codes</div>
            <?php foreach ($wards as $w): ?>
            <div class="fs-xs" style="padding:2px 0;"><?= htmlspecialchars($w['ward_code']) ?> — <?= htmlspecialchars($w['name']) ?></div>
            <?php endforeach; ?>
          </div>
          <div>
            <div class="fs-sm fw-600 mb-1">Revenue Source Codes</div>
            <?php foreach (array_slice($sources, 0, 12) as $s): ?>
            <div class="fs-xs" style="padding:2px 0;"><?= htmlspecialchars($s['source_code']) ?> — <?= htmlspecialchars($s['name']) ?></div>
            <?php endforeach; ?>
            <?php if (count($sources) > 12): ?><div class="fs-xs text-muted">+<?= count($sources)-12 ?> more in Settings</div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>
<?php endif; ?>

<!-- ══ IMPORT HISTORY ════════════════════════════════════════════ -->
<?php if ($tab === 'history' || !$preview): ?>
<div class="card" style="margin-top:1.2rem;">
  <div class="card-header"><h5><span class="ch-icon">◮</span> Import History</h5></div>
  <div class="card-body p-0">
    <?php if ($history): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead>
          <tr><th>Date</th><th>Type</th><th>File</th><th>FY</th><th class="text-right">Total</th><th class="text-right">Imported</th><th class="text-right">Failed</th><th>By</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h): ?>
        <tr>
          <td class="fs-sm"><?= formatDateTime($h['created_at']) ?></td>
          <td><span class="badge badge-info"><?= ucfirst($h['import_type']) ?></span></td>
          <td class="fs-sm"><?= htmlspecialchars($h['file_name'] ?? '—') ?></td>
          <td class="fs-sm"><?= $h['financial_year'] ?? '—' ?></td>
          <td class="text-right"><?= $h['total_rows'] ?></td>
          <td class="text-right fw-700 text-success"><?= $h['success_rows'] ?></td>
          <td class="text-right <?= $h['failed_rows']>0?'text-danger':'' ?>"><?= $h['failed_rows'] ?></td>
          <td class="fs-sm"><?= htmlspecialchars($h['importer']) ?></td>
          <td>
            <?php if ($h['failed_rows'] === 0): ?>
              <span class="badge badge-success">Clean</span>
            <?php elseif ($h['success_rows'] === 0): ?>
              <span class="badge badge-danger">Failed</span>
            <?php else: ?>
              <span class="badge badge-warning">Partial</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($h['errors'] && $h['failed_rows'] > 0): ?>
        <tr style="background:#fff5f5;">
          <td colspan="9" style="padding:.4rem 1rem;">
            <div style="font-size:.75rem;color:#721c24;">
              <?php $errs = json_decode($h['errors'], true) ?? []; ?>
              <?php foreach (array_slice($errs, 0, 5) as $e): ?>
              <div>⚠ <?= htmlspecialchars($e) ?></div>
              <?php endforeach; ?>
              <?php if (count($errs) > 5): ?><div>... and <?= count($errs)-5 ?> more errors</div><?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state" style="padding:2rem;"><div class="empty-icon">◑</div><p>No imports yet.</p></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
function showTemplate(type) {
    document.getElementById('tmpl_assessments').style.display = type === 'assessments' ? '' : 'none';
    document.getElementById('tmpl_payers').style.display      = type === 'payers'      ? '' : 'none';
}
</script>
<?php renderFooter(); ?>
