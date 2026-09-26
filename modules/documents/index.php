<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

$search   = trim($_GET['search'] ?? '');
$typeFil  = $_GET['type'] ?? '';
$deptFil  = (int)($_GET['dept_id'] ?? 0);
$page     = max(1,(int)($_GET['page'] ?? 1));

$where  = ['1=1']; $params = [];
if ($search)  { $where[] = "(d.title LIKE ? OR d.doc_number LIKE ?)"; $params=array_merge($params,["%$search%","%$search%"]); }
if ($typeFil) { $where[] = "d.doc_type=?";      $params[] = $typeFil; }
if ($deptFil) { $where[] = "d.department_id=?"; $params[] = $deptFil; }
// Confidential: only admin/finance see all
if (!hasRole(['admin','town_clerk','finance_officer','auditor'])) { $where[] = "(d.is_confidential=0 OR d.uploaded_by=?)"; $params[] = $user['id']; }
$wSQL = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM documents d WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);

$rows = $db->prepare("SELECT d.*, dept.name AS dept_name, u.full_name AS uploader
    FROM documents d
    LEFT JOIN departments dept ON d.department_id=dept.id
    LEFT JOIN users u ON d.uploaded_by=u.id
    WHERE $wSQL ORDER BY d.uploaded_at DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $docs = $rows->fetchAll();

$depts = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$types = ['voucher','receipt','invoice','report','contract','minutes','procurement','bank_statement','accountability','photo','other'];

renderHead('Document Library');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Document Library','Manage council documents and files');
renderPageStart('Document Library','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Documents']
]);
renderPageActions('<button class="btn btn-primary" data-modal="uploadModal">+ Upload Document</button>');
renderFlashMessages();
?>

<form method="GET">
<div class="filter-row">
  <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" value="<?=htmlspecialchars($search)?>" placeholder="Title or Doc number..."></div>
  <div class="form-group"><label>Type</label>
    <select name="type" class="form-select"><option value="">All Types</option>
      <?php foreach ($types as $t): ?><option value="<?=$t?>" <?=$typeFil===$t?'selected':''?>><?=ucwords(str_replace('_',' ',$t))?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label>Department</label>
    <select name="dept_id" class="form-select"><option value="">All</option>
      <?php foreach ($depts as $d): ?><option value="<?=$d['id']?>" <?=$deptFil==$d['id']?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="index.php" class="btn btn-outline-secondary">Reset</a></div></div>
</div>
</form>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">▧</span> Documents (<?=number_format($total)?>)</h5></div>
  <div class="card-body p-0">
    <?php if ($docs): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Doc No.</th><th>Title</th><th>Type</th><th>Department</th><th>Version</th><th>Format</th><th>Uploaded By</th><th>Date</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($docs as $doc): ?>
        <tr>
          <td class="fw-700 text-primary fs-sm"><?=htmlspecialchars($doc['doc_number'])?></td>
          <td>
            <div class="fw-600"><?=htmlspecialchars($doc['title'])?></div>
            <?php if ($doc['is_confidential']): ?><span class="badge badge-danger">Confidential</span><?php endif; ?>
          </td>
          <td><span class="badge badge-info"><?=ucwords(str_replace('_',' ',$doc['doc_type']))?></span></td>
          <td class="fs-sm"><?=htmlspecialchars($doc['dept_name'] ?? '—')?></td>
          <td class="text-center">v<?=$doc['version']?> <?=$doc['is_latest']?'<span class="badge badge-success">Latest</span>':''?></td>
          <td><span class="badge badge-secondary"><?=strtoupper($doc['file_type']??'—')?></span></td>
          <td class="fs-sm"><?=htmlspecialchars($doc['uploader']??'—')?></td>
          <td class="fs-sm"><?=formatDateTime($doc['uploaded_at'])?></td>
          <td>
            <div style="display:flex;gap:.3rem;">
              <a href="<?=APP_URL?>/uploads/<?=htmlspecialchars($doc['file_path'])?>" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
              <a href="<?=APP_URL?>/uploads/<?=htmlspecialchars($doc['file_path'])?>" download class="btn btn-sm btn-outline-secondary">Download</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">▧</div><h5>No documents found</h5><p>Upload a document using the button above.</p></div><?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?=renderPagination($pg,'?search='.urlencode($search).'&type='.urlencode($typeFil).'&dept_id='.$deptFil)?></div><?php endif; ?>
</div>

<!-- Upload Modal -->
<div class="modal-backdrop" id="uploadModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Upload Document</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST" action="upload.php" enctype="multipart/form-data">
      <?=csrfField()?>
      <input type="hidden" name="redirect" value="<?=APP_URL?>/modules/documents/index.php">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Document Title <span class="req">*</span></label><input type="text" name="title" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Document Type <span class="req">*</span></label>
          <select name="doc_type" class="form-select" required>
            <?php foreach ($types as $t): ?><option value="<?=$t?>"><?=ucwords(str_replace('_',' ',$t))?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Department</label>
          <select name="department_id" class="form-select">
            <option value="">-- General / All --</option>
            <?php foreach ($depts as $d): ?><option value="<?=$d['id']?>" <?=$user['department_id']==$d['id']?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Financial Year</label>
          <input type="text" name="financial_year" class="form-control" value="<?=getSystemSetting('current_financial_year')?>">
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="form-group">
          <label class="form-label">File <span class="req">*</span></label>
          <input type="file" name="document" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx">
          <div class="form-text">Allowed: PDF, JPG, PNG, DOCX, XLSX. Max 10MB.</div>
        </div>
        <div class="form-group">
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
            <input type="checkbox" name="is_confidential" value="1"> Mark as Confidential
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Upload Document</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
