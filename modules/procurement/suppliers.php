<?php
/**
 * TCMS Supplier Register
 * Full supplier management with procurement history.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_supplier') {
        $cnt  = (int)$db->query("SELECT COUNT(*)+1 FROM suppliers")->fetchColumn();
        $code = 'SUP-' . str_pad($cnt, 4, '0', STR_PAD_LEFT);
        try {
            $db->prepare("INSERT INTO suppliers (supplier_code,name,contact_person,phone,email,address,tin_number,category,is_active)
                VALUES (?,?,?,?,?,?,?,?,1)")
               ->execute([$code, trim($_POST['name']), trim($_POST['contact_person']??''),
                          trim($_POST['phone']??''), trim($_POST['email']??''),
                          trim($_POST['address']??''), trim($_POST['tin_number']??''),
                          trim($_POST['category']??'')]);
            $sid = (int)$db->lastInsertId();
            logAudit('CREATE','procurement','supplier',$sid,$code);
            setFlash('success',"Supplier registered: $code");
        } catch (PDOException $e) {
            setFlash('danger','Failed: '.$e->getMessage());
        }
    } elseif ($action === 'update_supplier') {
        $sid = (int)$_POST['supplier_id'];
        $db->prepare("UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,tin_number=?,category=?,is_active=? WHERE id=?")
           ->execute([trim($_POST['name']),trim($_POST['contact_person']??''),trim($_POST['phone']??''),
                      trim($_POST['email']??''),trim($_POST['address']??''),trim($_POST['tin_number']??''),
                      trim($_POST['category']??''),isset($_POST['is_active'])?1:0,$sid]);
        setFlash('success','Supplier updated.');
    } elseif ($action === 'toggle_supplier') {
        $sid = (int)$_POST['supplier_id'];
        $db->prepare("UPDATE suppliers SET is_active=NOT is_active WHERE id=?")->execute([$sid]);
        setFlash('success','Supplier status updated.');
    }
    header('Location: suppliers.php'); exit;
}

$search = trim($_GET['search'] ?? '');
$catFil = trim($_GET['cat'] ?? '');
$page   = max(1,(int)($_GET['page']??1));

$where  = ['1=1']; $params = [];
if ($search)  { $where[] = "(name LIKE ? OR supplier_code LIKE ? OR phone LIKE ?)"; $params=array_merge($params,["%$search%","%$search%","%$search%"]); }
if ($catFil)  { $where[] = "category=?"; $params[]=$catFil; }
$wSQL = implode(' AND ',$where);

$cnt = $db->prepare("SELECT COUNT(*) FROM suppliers WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);
$rows = $db->prepare("SELECT * FROM suppliers WHERE $wSQL ORDER BY name LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $suppliers=$rows->fetchAll();

// Procurement history per supplier
$supHistory = [];
foreach ($suppliers as $s) {
    $h = $db->prepare("SELECT COUNT(*) cnt,COALESCE(SUM(r.estimated_amount),0) total_value,MAX(r.created_at) last_order FROM expenditure_requisitions r WHERE r.supplier_id=?");
    $h->execute([$s['id']]); $supHistory[$s['id']]=$h->fetch();
}

$categories = $db->query("SELECT DISTINCT category FROM suppliers WHERE category IS NOT NULL AND category!='' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$activeCount=(int)$db->query("SELECT COUNT(*) FROM suppliers WHERE is_active=1")->fetchColumn();
$totalValue =(float)$db->query("SELECT COALESCE(SUM(r.estimated_amount),0) FROM expenditure_requisitions r JOIN suppliers s ON r.supplier_id=s.id")->fetchColumn();

renderHead('Supplier Register');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Supplier Register','Manage approved suppliers and procurement history');
renderPageStart('Supplier Register','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>APP_URL.'/modules/procurement/index.php','label'=>'Procurement'],
    ['url'=>'#','label'=>'Suppliers'],
]);
renderPageActions('<button class="btn btn-primary" data-modal="addSupplierModal">+ Register Supplier</button>');
renderFlashMessages();
?>

<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">◑</div><div class="stat-info"><div class="label">Total Suppliers</div><div class="value"><?=number_format($total)?></div></div></div>
  <div class="stat-card green"><div class="stat-icon">✓</div><div class="stat-info"><div class="label">Active</div><div class="value"><?=number_format($activeCount)?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Total Procurement Value</div><div class="value"><?=number_format($totalValue/1000000,1)?>M</div></div></div>
  <div class="stat-card"><div class="stat-icon">◎</div><div class="stat-info"><div class="label">Categories</div><div class="value"><?=count($categories)?></div></div></div>
</div>

<form method="GET">
<div class="filter-row">
  <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" value="<?=htmlspecialchars($search)?>" placeholder="Name, code, phone..."></div>
  <div class="form-group"><label>Category</label>
    <select name="cat" class="form-select"><option value="">All</option><?php foreach($categories as $c): ?><option value="<?=$c?>" <?=$catFil===$c?'selected':''?>><?=htmlspecialchars($c)?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="suppliers.php" class="btn btn-outline-secondary">Reset</a></div></div>
</div>
</form>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◑</span> Suppliers (<?=number_format($total)?>)</h5></div>
  <div class="card-body p-0">
    <?php if($suppliers): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>#</th><th>Code</th><th>Supplier Name</th><th>Contact</th><th>Phone</th><th>Category</th><th>TIN</th><th class="text-right">Orders</th><th class="text-right">Total Value</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($suppliers as $i=>$s): $h=$supHistory[$s['id']]??[]; ?>
        <tr>
          <td class="fs-xs text-muted"><?=$pg['offset']+$i+1?></td>
          <td class="fw-700 text-primary"><?=htmlspecialchars($s['supplier_code'])?></td>
          <td><div class="fw-600"><?=htmlspecialchars($s['name'])?></div><?php if($s['address']): ?><div class="fs-xs text-muted"><?=htmlspecialchars(substr($s['address'],0,30))?></div><?php endif; ?></td>
          <td class="fs-sm"><?=htmlspecialchars($s['contact_person']??'—')?></td>
          <td class="fs-sm"><?=htmlspecialchars($s['phone']??'—')?></td>
          <td class="fs-sm"><?=htmlspecialchars($s['category']??'—')?></td>
          <td class="fs-sm"><?=htmlspecialchars($s['tin_number']??'—')?></td>
          <td class="text-right"><?=number_format((int)($h['cnt']??0))?></td>
          <td class="text-right fw-700"><?=$h['total_value']>0?number_format($h['total_value']):'—'?></td>
          <td><?=getStatusBadge($s['is_active']?'active':'inactive')?></td>
          <td>
            <div style="display:flex;gap:.3rem;">
              <button class="btn btn-sm btn-outline-primary" onclick="editSupplier(<?=htmlspecialchars(json_encode($s))?>)">Edit</button>
              <form method="POST" style="display:inline;">
                <?=csrfField()?><input type="hidden" name="action" value="toggle_supplier"><input type="hidden" name="supplier_id" value="<?=$s['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-<?=$s['is_active']?'danger':'success'?>"><?=$s['is_active']?'Suspend':'Activate'?></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">◑</div><h5>No suppliers found</h5></div><?php endif; ?>
  </div>
  <?php if($pg['total_pages']>1): ?><div class="card-footer"><?=renderPagination($pg,'?search='.urlencode($search).'&cat='.urlencode($catFil))?></div><?php endif; ?>
</div>

<!-- Add Supplier Modal -->
<div class="modal-backdrop" id="addSupplierModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Register Supplier</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?><input type="hidden" name="action" value="add_supplier">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Supplier / Company Name <span class="req">*</span></label><input type="text" name="name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Contact Person</label><input type="text" name="contact_person" class="form-control"></div>
          <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
          <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
          <div class="form-group"><label class="form-label">TIN Number</label><input type="text" name="tin_number" class="form-control"></div>
          <div class="form-group"><label class="form-label">Category</label><input type="text" name="category" class="form-control" placeholder="e.g. Stationery, Construction, IT, Food"></div>
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Physical Address</label><textarea name="address" class="form-control" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Register Supplier</button></div>
    </form>
  </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal-backdrop" id="editSupplierModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Edit Supplier</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?><input type="hidden" name="action" value="update_supplier"><input type="hidden" name="supplier_id" id="es_id">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Name <span class="req">*</span></label><input type="text" name="name" id="es_name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Contact Person</label><input type="text" name="contact_person" id="es_contact" class="form-control"></div>
          <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" id="es_phone" class="form-control"></div>
          <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="es_email" class="form-control"></div>
          <div class="form-group"><label class="form-label">TIN</label><input type="text" name="tin_number" id="es_tin" class="form-control"></div>
          <div class="form-group"><label class="form-label">Category</label><input type="text" name="category" id="es_cat" class="form-control"></div>
          <div class="form-group"><label class="form-label">Active</label>
            <select name="is_active" id="es_active" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select>
            <input type="hidden" name="is_active" id="es_active_h">
          </div>
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Address</label><textarea name="address" id="es_address" class="form-control" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
function editSupplier(s) {
  document.getElementById('es_id').value      = s.id;
  document.getElementById('es_name').value    = s.name;
  document.getElementById('es_contact').value = s.contact_person||'';
  document.getElementById('es_phone').value   = s.phone||'';
  document.getElementById('es_email').value   = s.email||'';
  document.getElementById('es_tin').value     = s.tin_number||'';
  document.getElementById('es_cat').value     = s.category||'';
  document.getElementById('es_address').value = s.address||'';
  document.getElementById('es_active').value  = s.is_active;
  openModal('editSupplierModal');
}
</script>
<?php renderFooter(); ?>
