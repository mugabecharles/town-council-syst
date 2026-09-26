<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_payer') {
        $ward_id    = (int)($_POST['ward_id'] ?? 0);
        $payerNum   = generatePayerNumber($ward_id);
        $stmt = $db->prepare("INSERT INTO payers 
            (payer_number,payer_type,full_name,business_name,national_id,tin_number,phone,phone2,email,address,ward_id,parish_id,village_id,registration_date,registered_by,status,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $payerNum,
            $_POST['payer_type'],
            trim($_POST['full_name']),
            trim($_POST['business_name'] ?? ''),
            trim($_POST['national_id'] ?? ''),
            trim($_POST['tin_number'] ?? ''),
            trim($_POST['phone'] ?? ''),
            trim($_POST['phone2'] ?? ''),
            trim($_POST['email'] ?? ''),
            trim($_POST['address'] ?? ''),
            $ward_id,
            !empty($_POST['parish_id'])  ? (int)$_POST['parish_id']  : null,
            !empty($_POST['village_id']) ? (int)$_POST['village_id'] : null,
            date('Y-m-d'),
            $user['id'],
            'active',
            trim($_POST['notes'] ?? '')
        ]);
        $newId = $db->lastInsertId();
        logAudit('CREATE', 'revenue', 'payer', $newId, $payerNum);
        setFlash('success', "Payer registered successfully. Payer ID: {$payerNum}");
    } elseif ($action === 'update_payer') {
        $id = (int)$_POST['payer_id'];
        $db->prepare("UPDATE payers SET payer_type=?,full_name=?,business_name=?,national_id=?,tin_number=?,phone=?,phone2=?,email=?,address=?,ward_id=?,parish_id=?,village_id=?,status=?,notes=? WHERE id=?")
           ->execute([
               $_POST['payer_type'], trim($_POST['full_name']), trim($_POST['business_name'] ?? ''),
               trim($_POST['national_id'] ?? ''), trim($_POST['tin_number'] ?? ''),
               trim($_POST['phone'] ?? ''), trim($_POST['phone2'] ?? ''),
               trim($_POST['email'] ?? ''), trim($_POST['address'] ?? ''),
               (int)$_POST['ward_id'],
               !empty($_POST['parish_id'])  ? (int)$_POST['parish_id']  : null,
               !empty($_POST['village_id']) ? (int)$_POST['village_id'] : null,
               $_POST['status'], trim($_POST['notes'] ?? ''), $id
           ]);
        logAudit('UPDATE', 'revenue', 'payer', $id, '');
        setFlash('success', 'Payer record updated.');
    }
    header('Location: payers.php'); exit;
}

// Filters
$search  = trim($_GET['search'] ?? '');
$wardFil = (int)($_GET['ward_id'] ?? 0);
$statusF = $_GET['status'] ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));

$where = ['1=1'];
$params = [];
if ($search)  { $where[] = "(p.full_name LIKE ? OR p.business_name LIKE ? OR p.payer_number LIKE ? OR p.phone LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]); }
if ($wardFil) { $where[] = "p.ward_id = ?"; $params[] = $wardFil; }
if ($statusF) { $where[] = "p.status = ?"; $params[] = $statusF; }
$whereSQL = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM payers p WHERE $whereSQL");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pg    = paginate($total, $page);

$dataStmt = $db->prepare("SELECT p.*, w.name AS ward_name, par.name AS parish_name, v.name AS village_name 
    FROM payers p LEFT JOIN wards w ON p.ward_id=w.id LEFT JOIN parishes par ON p.parish_id=par.id LEFT JOIN villages v ON p.village_id=v.id
    WHERE $whereSQL ORDER BY p.created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$dataStmt->execute($params);
$payers = $dataStmt->fetchAll();

$wards = $db->query("SELECT * FROM wards WHERE is_active=1 ORDER BY name")->fetchAll();

renderHead('Revenue Payers');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Revenue Payers', 'Manage registered revenue payers');
renderPageStart('Revenue Payers', '', [
    ['url' => APP_URL . '/dashboard.php', 'label' => 'Dashboard'],
    ['url' => '#', 'label' => 'Revenue Payers']
]);
renderPageActions('<button class="btn btn-primary" data-modal="addPayerModal">+ Register New Payer</button>
  <a href="' . APP_URL . '/modules/reports/revenue.php?report=payers" class="btn btn-outline-secondary">Export</a>');
renderFlashMessages();
?>

<!-- Filter Row -->
<form method="GET" id="filterForm">
<div class="filter-row">
  <div class="form-group">
    <label>Search</label>
    <input type="text" name="search" class="form-control" placeholder="Name, Payer ID, Phone..." value="<?= htmlspecialchars($search) ?>" style="min-width:220px;">
  </div>
  <div class="form-group">
    <label>Ward</label>
    <select name="ward_id" class="form-select">
      <option value="">All Wards</option>
      <?php foreach ($wards as $w): ?>
      <option value="<?= $w['id'] ?>" <?= $wardFil==$w['id']?'selected':'' ?>><?= htmlspecialchars($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Status</label>
    <select name="status" class="form-select">
      <option value="">All</option>
      <option value="active"    <?= $statusF==='active'?'selected':'' ?>>Active</option>
      <option value="inactive"  <?= $statusF==='inactive'?'selected':'' ?>>Inactive</option>
      <option value="suspended" <?= $statusF==='suspended'?'selected':'' ?>>Suspended</option>
    </select>
  </div>
  <div class="form-group">
    <label>&nbsp;</label>
    <div style="display:flex;gap:.4rem;">
      <button type="submit" class="btn btn-primary">Filter</button>
      <a href="payers.php" class="btn btn-outline-secondary">Reset</a>
    </div>
  </div>
</div>
</form>

<!-- Payers Table -->
<div class="card">
  <div class="card-header">
    <h5><span class="ch-icon">◉</span> Revenue Payers <span style="font-weight:400;font-size:.82rem;color:#6c757d;margin-left:.5rem;">(<?= number_format($total) ?> records)</span></h5>
  </div>
  <div class="card-body p-0">
    <?php if ($payers): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead>
          <tr>
            <th>#</th><th>Payer ID</th><th>Name / Business</th><th>Type</th>
            <th>Ward</th><th>Parish</th><th>Phone</th><th>Status</th><th>Registered</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($payers as $i => $p): ?>
        <tr>
          <td class="text-muted fs-xs"><?= $pg['offset'] + $i + 1 ?></td>
          <td><span class="fw-700 text-primary"><?= htmlspecialchars($p['payer_number']) ?></span></td>
          <td>
            <div class="fw-600"><?= htmlspecialchars($p['full_name']) ?></div>
            <?php if ($p['business_name']): ?><div class="fs-xs text-muted"><?= htmlspecialchars($p['business_name']) ?></div><?php endif; ?>
          </td>
          <td class="fs-sm"><?= ucfirst($p['payer_type']) ?></td>
          <td class="fs-sm"><?= htmlspecialchars($p['ward_name'] ?? '—') ?></td>
          <td class="fs-sm"><?= htmlspecialchars($p['parish_name'] ?? '—') ?></td>
          <td class="fs-sm"><?= htmlspecialchars($p['phone'] ?? '—') ?></td>
          <td><?= getStatusBadge($p['status']) ?></td>
          <td class="fs-xs"><?= formatDate($p['registration_date']) ?></td>
          <td>
            <div style="display:flex;gap:.3rem;">
              <button class="btn btn-sm btn-outline-primary" onclick="editPayer(<?= htmlspecialchars(json_encode($p)) ?>)">Edit</button>
              <a href="<?= APP_URL ?>/modules/revenue/assessments.php?payer_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary">Assess</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">◉</div>
      <h5>No payers found</h5>
      <p>Register a new payer using the button above.</p>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($pg['total_pages'] > 1): ?>
  <div class="card-footer"><?= renderPagination($pg, '?search=' . urlencode($search) . '&ward_id=' . $wardFil . '&status=' . $statusF) ?></div>
  <?php endif; ?>
</div>

<!-- ── Add Payer Modal ────────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="addPayerModal">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h5>Register New Revenue Payer</h5>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_payer">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Payer Type <span class="req">*</span></label>
            <select name="payer_type" class="form-select" required>
              <option value="individual">Individual</option>
              <option value="business">Business</option>
              <option value="organization">Organization</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Full Name <span class="req">*</span></label>
            <input type="text" name="full_name" class="form-control" required placeholder="Full name of individual or proprietor">
          </div>
          <div class="form-group">
            <label class="form-label">Business / Trading Name</label>
            <input type="text" name="business_name" class="form-control" placeholder="Business or trading name">
          </div>
          <div class="form-group">
            <label class="form-label">National ID / TIN</label>
            <input type="text" name="national_id" class="form-control" placeholder="National ID or TIN">
          </div>
          <div class="form-group">
            <label class="form-label">TIN Number</label>
            <input type="text" name="tin_number" class="form-control" placeholder="Tax Identification Number">
          </div>
          <div class="form-group">
            <label class="form-label">Primary Phone <span class="req">*</span></label>
            <input type="text" name="phone" class="form-control" required placeholder="+256 7XX XXX XXX">
          </div>
          <div class="form-group">
            <label class="form-label">Alternative Phone</label>
            <input type="text" name="phone2" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Ward <span class="req">*</span></label>
            <select name="ward_id" id="ward_id" class="form-select" required>
              <option value="">-- Select Ward --</option>
              <?php foreach ($wards as $w): ?>
              <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Parish</label>
            <select name="parish_id" id="parish_id" class="form-select">
              <option value="">-- Select Parish --</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Village</label>
            <select name="village_id" id="village_id" class="form-select">
              <option value="">-- Select Village --</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Physical Address</label>
          <textarea name="address" class="form-control" rows="2" placeholder="Physical address / plot number"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Register Payer</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Payer Modal ───────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="editPayerModal">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h5>Edit Payer Record</h5>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_payer">
      <input type="hidden" name="payer_id" id="ep_id">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Payer ID</label>
            <input type="text" id="ep_payer_number" class="form-control" disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Payer Type <span class="req">*</span></label>
            <select name="payer_type" id="ep_payer_type" class="form-select" required>
              <option value="individual">Individual</option>
              <option value="business">Business</option>
              <option value="organization">Organization</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Full Name <span class="req">*</span></label>
            <input type="text" name="full_name" id="ep_full_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">Business Name</label>
            <input type="text" name="business_name" id="ep_business_name" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">National ID</label>
            <input type="text" name="national_id" id="ep_national_id" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">TIN Number</label>
            <input type="text" name="tin_number" id="ep_tin_number" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input type="text" name="phone" id="ep_phone" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Alt Phone</label>
            <input type="text" name="phone2" id="ep_phone2" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="ep_email" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Ward <span class="req">*</span></label>
            <select name="ward_id" id="ep_ward_id" class="form-select" required>
              <option value="">-- Select Ward --</option>
              <?php foreach ($wards as $w): ?>
              <option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Parish</label>
            <input type="hidden" name="parish_id" id="ep_parish_id">
            <input type="text" id="ep_parish_name" class="form-control" disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Village</label>
            <input type="hidden" name="village_id" id="ep_village_id">
            <input type="text" id="ep_village_name" class="form-control" disabled>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" id="ep_status" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <textarea name="address" id="ep_address" class="form-control" rows="2"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea name="notes" id="ep_notes" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<?php
renderPageEnd();
echo '</div></div>';
?>
<script>
function editPayer(p) {
  document.getElementById('ep_id').value           = p.id;
  document.getElementById('ep_payer_number').value = p.payer_number;
  document.getElementById('ep_payer_type').value   = p.payer_type;
  document.getElementById('ep_full_name').value    = p.full_name;
  document.getElementById('ep_business_name').value= p.business_name || '';
  document.getElementById('ep_national_id').value  = p.national_id || '';
  document.getElementById('ep_tin_number').value   = p.tin_number || '';
  document.getElementById('ep_phone').value        = p.phone || '';
  document.getElementById('ep_phone2').value       = p.phone2 || '';
  document.getElementById('ep_email').value        = p.email || '';
  document.getElementById('ep_ward_id').value      = p.ward_id || '';
  document.getElementById('ep_parish_id').value    = p.parish_id || '';
  document.getElementById('ep_parish_name').value  = p.parish_name || '';
  document.getElementById('ep_village_id').value   = p.village_id || '';
  document.getElementById('ep_village_name').value = p.village_name || '';
  document.getElementById('ep_address').value      = p.address || '';
  document.getElementById('ep_status').value       = p.status;
  document.getElementById('ep_notes').value        = p.notes || '';
  openModal('editPayerModal');
}
</script>
<?php renderFooter(); ?>
