<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('danger','Invalid voucher.'); header('Location: vouchers.php'); exit; }

$stmt = $db->prepare("SELECT pv.*, d.name AS dept_name, fs.name AS source_name, b.budget_category,
    u.full_name AS preparer, b.approved_amount AS budget_approved
    FROM payment_vouchers pv JOIN departments d ON pv.department_id=d.id
    LEFT JOIN funding_sources fs ON pv.funding_source_id=fs.id
    LEFT JOIN budgets b ON pv.budget_id=b.id
    JOIN users u ON pv.prepared_by=u.id
    WHERE pv.id=?");
$stmt->execute([$id]); $v = $stmt->fetch();
if (!$v) { setFlash('danger','Voucher not found.'); header('Location: vouchers.php'); exit; }

// Permission: HODs only see own dept
if (hasRole(['hod']) && $v['department_id'] != $user['department_id']) {
    setFlash('danger','Access denied.'); header('Location: vouchers.php'); exit;
}

// Load approvals
$approvals = $db->prepare("SELECT va.*, u.full_name AS approver_name, u.designation FROM voucher_approvals va JOIN users u ON va.approver_id=u.id WHERE va.voucher_id=? ORDER BY va.approved_at")->execute([$id])?
    $db->query("SELECT va.*, u.full_name AS approver_name, u.designation FROM voucher_approvals va JOIN users u ON va.approver_id=u.id WHERE va.voucher_id=$id ORDER BY va.approved_at")->fetchAll() : [];
$appStmt = $db->prepare("SELECT va.*, u.full_name AS approver_name, u.designation FROM voucher_approvals va JOIN users u ON va.approver_id=u.id WHERE va.voucher_id=? ORDER BY va.approved_at");
$appStmt->execute([$id]); $approvals = $appStmt->fetchAll();

// Documents
$docs = $db->prepare("SELECT * FROM documents WHERE related_module='voucher' AND related_id=? ORDER BY uploaded_at DESC"); $docs->execute([$id]); $docs = $docs->fetchAll();

// Handle approval action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    $stageMap = [
        'hod_approve'     => ['stage'=>'hod',         'next'=>'hod_approved'],
        'tc_approve'      => ['stage'=>'town_clerk',   'next'=>'tc_approved'],
        'finance_verify'  => ['stage'=>'finance_verify','next'=>'finance_verified'],
        'finance_clear'   => ['stage'=>'finance_clear','next'=>'finance_cleared'],
        'mark_paid'       => ['stage'=>'payment',      'next'=>'paid'],
        'reject'          => ['stage'=>'general',      'next'=>'rejected'],
        'return_voucher'  => ['stage'=>'general',      'next'=>'returned'],
    ];

    $roleAllowed = [
        'hod_approve'    => ['admin','hod'],
        'tc_approve'     => ['admin','town_clerk'],
        'finance_verify' => ['admin','finance_officer'],
        'finance_clear'  => ['admin','finance_officer'],
        'mark_paid'      => ['admin','finance_officer'],
        'reject'         => ['admin','town_clerk','finance_officer','hod'],
        'return_voucher' => ['admin','town_clerk','finance_officer'],
    ];

    if (isset($stageMap[$action]) && hasRole($roleAllowed[$action] ?? [])) {
        $map = $stageMap[$action];
        $approvalAction = in_array($action,['reject'])  ? 'rejected'  :
                          (in_array($action,['return_voucher']) ? 'returned' : 'approved');

        $db->prepare("INSERT INTO voucher_approvals (voucher_id,approver_id,approver_role,approval_stage,action,comments,ip_address) VALUES (?,?,?,?,?,?,?)")
           ->execute([$id, $user['id'], $user['role_name'], $map['stage'], $approvalAction, $comment, getClientIP()]);

        $db->prepare("UPDATE payment_vouchers SET status=?, updated_at=NOW() WHERE id=?")->execute([$map['next'], $id]);

        // If paid, update budget spent
        if ($action === 'mark_paid') {
            $payRef = trim($_POST['payment_reference'] ?? '');
            $db->prepare("UPDATE payment_vouchers SET payment_date=CURDATE(), payment_reference=?, status='paid' WHERE id=?")->execute([$payRef, $id]);
            if ($v['budget_id']) {
                $db->prepare("UPDATE budgets SET spent_amount=spent_amount+? WHERE id=?")->execute([$v['amount'],$v['budget_id']]);
            }
        }

        logAudit(strtoupper($action),'expenditure','voucher',$id,$v['voucher_number']);

        // Notify preparer
        sendNotification($v['prepared_by'], 'Voucher '.$v['voucher_number'].' '.$approvalAction, "Your voucher has been {$approvalAction}. ".($comment?"Comment: $comment":""), $approvalAction==='approved'?'success':'danger','expenditure',$id);

        setFlash('success', 'Voucher ' . ucfirst($approvalAction) . ' successfully.');
        header('Location: voucher_view.php?id='.$id); exit;
    }
}

// Reload voucher
$stmt->execute([$id]); $v = $stmt->fetch();
$councilName = getSystemSetting('council_name') ?? 'Town Council';

renderHead('Voucher '.$v['voucher_number']);
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Payment Voucher', $v['voucher_number']);
renderPageStart('Payment Voucher '.$v['voucher_number'],'', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>APP_URL.'/modules/expenditure/vouchers.php','label'=>'Vouchers'],
    ['url'=>'#','label'=>$v['voucher_number']]
]);
renderPageActions('<button class="btn btn-primary no-print" data-print>🖨 Print</button>
  <a href="vouchers.php" class="btn btn-outline-secondary no-print">Back</a>');
renderFlashMessages();
?>

<div class="grid-3" style="align-items:start;">
  <!-- Voucher Details -->
  <div style="grid-column:span 2;">
    <div class="card" style="margin-bottom:1rem;">
      <div class="card-header">
        <h5><span class="ch-icon">▤</span> Voucher Details</h5>
        <?= getStatusBadge($v['status']) ?>
      </div>
      <div class="card-body">
        <!-- Voucher Print Header -->
        <div style="text-align:center;border-bottom:2px solid var(--primary);padding-bottom:1rem;margin-bottom:1.2rem;">
          <div style="width:54px;height:54px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .6rem;color:var(--accent);font-weight:800;font-size:1.1rem;">TC</div>
          <h3 style="font-size:1.1rem;font-weight:800;color:var(--primary);"><?=htmlspecialchars($councilName)?></h3>
          <p style="font-size:.9rem;font-weight:700;color:var(--accent);letter-spacing:2px;text-transform:uppercase;margin-top:.3rem;">PAYMENT VOUCHER</p>
        </div>

        <div class="grid-2" style="gap:.5rem;">
          <div class="receipt-row"><span class="text-muted fs-sm">Voucher No:</span><strong class="text-primary"><?=htmlspecialchars($v['voucher_number'])?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Date:</span><strong><?=formatDate($v['prepared_date'])?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Financial Year:</span><strong><?=$v['financial_year']?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Department:</span><strong><?=htmlspecialchars($v['dept_name'])?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Payee Name:</span><strong><?=htmlspecialchars($v['payee_name'])?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Payee Contact:</span><strong><?=htmlspecialchars($v['payee_contact'] ?? '—')?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Budget Line:</span><strong><?=htmlspecialchars($v['budget_category'] ?? '—')?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Funding Source:</span><strong><?=htmlspecialchars($v['source_name'] ?? '—')?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Account Code:</span><strong><?=htmlspecialchars($v['account_code'] ?? '—')?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Prepared By:</span><strong><?=htmlspecialchars($v['preparer'])?></strong></div>
          <?php if ($v['payment_reference']): ?>
          <div class="receipt-row"><span class="text-muted fs-sm">Payment Reference:</span><strong><?=htmlspecialchars($v['payment_reference'])?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Payment Date:</span><strong><?=formatDate($v['payment_date'])?></strong></div>
          <?php endif; ?>
        </div>

        <div class="receipt-row" style="margin-top:.5rem;"><span class="text-muted fs-sm">Description:</span></div>
        <p style="background:#f8f9fa;padding:.8rem;border-radius:4px;font-size:.88rem;margin:.3rem 0 1rem;"><?=htmlspecialchars($v['description'])?></p>

        <div style="text-align:center;background:var(--primary);color:#fff;padding:1rem;border-radius:6px;margin:1rem 0;">
          <div style="font-size:.78rem;opacity:.8;margin-bottom:.2rem;">AMOUNT</div>
          <div style="font-size:1.8rem;font-weight:800;"><?=CURRENCY?> <?=number_format($v['amount'],0)?></div>
          <div style="font-size:.78rem;opacity:.8;margin-top:.2rem;font-style:italic;"><?=numberToWords($v['amount'])?></div>
        </div>

        <!-- Signature blocks -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-top:2rem;" class="no-print">
          <?php
          $sigStages = ['HOD / Preparer','Town Clerk','Finance Officer'];
          foreach ($sigStages as $stage): ?>
          <div style="text-align:center;">
            <div style="height:45px;border-bottom:1px solid #333;margin-bottom:.4rem;"></div>
            <div style="font-size:.75rem;color:#6c757d;"><?=$stage?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Documents -->
    <div class="card">
      <div class="card-header">
        <h5><span class="ch-icon">▧</span> Supporting Documents</h5>
        <button class="btn btn-sm btn-outline-primary no-print" data-modal="uploadDocModal">+ Attach</button>
      </div>
      <div class="card-body p-0">
        <?php if ($docs): ?>
        <div class="table-wrapper">
          <table class="tcms-table">
            <thead><tr><th>Document</th><th>Type</th><th>Uploaded By</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($docs as $doc): ?>
            <tr>
              <td class="fw-600"><?=htmlspecialchars($doc['title'])?></td>
              <td><span class="badge badge-info"><?=strtoupper($doc['file_type']??'—')?></span></td>
              <td class="fs-sm"><?=htmlspecialchars($doc['uploaded_by']??'—')?></td>
              <td class="fs-sm"><?=formatDate($doc['uploaded_at'])?></td>
              <td><a href="<?=APP_URL?>/uploads/<?=htmlspecialchars($doc['file_path'])?>" target="_blank" class="btn btn-sm btn-outline-primary">View</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No documents attached yet.</p></div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Approval Workflow Sidebar -->
  <div>
    <div class="card" style="margin-bottom:1rem;">
      <div class="card-header"><h5><span class="ch-icon">▥</span> Approval Workflow</h5></div>
      <div class="card-body">
        <?php
        $stages = [
            ['key'=>'draft',           'label'=>'Draft'],
            ['key'=>'submitted',       'label'=>'Submitted'],
            ['key'=>'hod_approved',    'label'=>'HOD Approved'],
            ['key'=>'tc_approved',     'label'=>'Town Clerk Approved'],
            ['key'=>'finance_verified','label'=>'Finance Verified'],
            ['key'=>'finance_cleared', 'label'=>'Finance Cleared'],
            ['key'=>'paid',            'label'=>'Payment Made'],
            ['key'=>'completed',       'label'=>'Completed'],
        ];
        $statusOrder = array_column($stages,'key');
        $currentIdx  = array_search($v['status'], $statusOrder);
        if ($v['status']==='rejected') $currentIdx=-1;
        if ($v['status']==='returned') $currentIdx=-2;
        ?>
        <div class="approval-timeline">
          <?php foreach ($stages as $i => $st): ?>
          <?php $dot = $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'pending' : 'waiting'); ?>
          <div class="timeline-item">
            <div class="timeline-dot <?=$dot?>">
              <?= $dot==='done'?'✓':($dot==='pending'?'●':'○') ?>
            </div>
            <div class="timeline-content">
              <div class="stage"><?=htmlspecialchars($st['label'])?></div>
              <?php
              foreach ($approvals as $ap) {
                  $stageLabel = ['hod'=>'HOD Approved','town_clerk'=>'Town Clerk Approved','finance_verify'=>'Finance Verified','finance_clear'=>'Finance Cleared','payment'=>'Payment Made'];
                  if (($stageLabel[$ap['approval_stage']] ?? '') === $st['label']) {
                      echo '<div class="meta">'.$ap['approver_name'].' &mdash; '.formatDateTime($ap['approved_at']).'</div>';
                      if ($ap['comments']) echo '<div class="comment">'.htmlspecialchars($ap['comments']).'</div>';
                  }
              }
              ?>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (in_array($v['status'],['rejected','returned'])): ?>
          <div class="timeline-item">
            <div class="timeline-dot rejected">✕</div>
            <div class="timeline-content"><div class="stage"><?=ucfirst($v['status'])?></div></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Action Panel -->
    <?php if (!in_array($v['status'],['completed','cancelled'])): ?>
    <div class="card no-print">
      <div class="card-header"><h5><span class="ch-icon">◉</span> Take Action</h5></div>
      <div class="card-body">
        <?php
        $canAct = false;
        $actionForm = '';

        if ($v['status']==='submitted' && hasRole(['admin','hod'])):
            $canAct = true;
            $actionForm = '<p class="fs-sm text-muted mb-2">As Head of Department, review and approve this voucher.</p>
                <form method="POST"><input type="hidden" name="action" value="hod_approve">'.csrfField().'
                <div class="form-group"><label class="form-label">Comment</label><textarea name="comment" class="form-control" rows="2"></textarea></div>
                <div style="display:flex;gap:.5rem;">
                  <button type="submit" class="btn btn-success btn-block">Approve</button>
                </form>
                <form method="POST" style="flex:1;"><input type="hidden" name="action" value="reject">'.csrfField().'
                <button type="submit" class="btn btn-danger btn-block" data-confirm="Reject this voucher?">Reject</button></form></div>';
        elseif ($v['status']==='hod_approved' && hasRole(['admin','town_clerk'])):
            $canAct = true;
            $actionForm = '<p class="fs-sm text-muted mb-2">Town Clerk review and approval.</p>
                <form method="POST"><input type="hidden" name="action" value="tc_approve">'.csrfField().'
                <div class="form-group"><label class="form-label">Comment</label><textarea name="comment" class="form-control" rows="2"></textarea></div>
                <div style="display:flex;gap:.5rem;">
                  <button type="submit" class="btn btn-success btn-block">Approve</button>
                </form>
                <form method="POST" style="flex:1;"><input type="hidden" name="action" value="return_voucher">'.csrfField().'
                <input type="hidden" name="comment" value="Returned by Town Clerk">
                <button type="submit" class="btn btn-warning btn-block">Return</button></form></div>';
        elseif ($v['status']==='tc_approved' && hasRole(['admin','finance_officer'])):
            $canAct = true;
            $actionForm = '<p class="fs-sm text-muted mb-2">Finance verification — check budget, documents, amount.</p>
                <form method="POST"><input type="hidden" name="action" value="finance_verify">'.csrfField().'
                <div class="form-group"><label class="form-label">Comment</label><textarea name="comment" class="form-control" rows="2"></textarea></div>
                <button type="submit" class="btn btn-info btn-block">Verify</button></form>';
        elseif ($v['status']==='finance_verified' && hasRole(['admin','finance_officer'])):
            $canAct = true;
            $actionForm = '<p class="fs-sm text-muted mb-2">Finance clearance — authorize for payment.</p>
                <form method="POST"><input type="hidden" name="action" value="finance_clear">'.csrfField().'
                <div class="form-group"><label class="form-label">Comment</label><textarea name="comment" class="form-control" rows="2"></textarea></div>
                <button type="submit" class="btn btn-primary btn-block">Clear for Payment</button></form>';
        elseif ($v['status']==='finance_cleared' && hasRole(['admin','finance_officer'])):
            $canAct = true;
            $actionForm = '<p class="fs-sm text-muted mb-2">Record payment details and mark as paid.</p>
                <form method="POST"><input type="hidden" name="action" value="mark_paid">'.csrfField().'
                <div class="form-group"><label class="form-label">Payment Reference</label><input type="text" name="payment_reference" class="form-control" placeholder="Bank ref / cheque no..."></div>
                <button type="submit" class="btn btn-success btn-block" data-confirm="Mark this voucher as PAID?">Mark as Paid</button></form>';
        endif;

        if ($canAct) echo $actionForm;
        else echo '<p class="text-muted fs-sm">No action available at this stage for your role.</p>';
        ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Upload Document Modal -->
<div class="modal-backdrop" id="uploadDocModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Attach Supporting Document</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST" action="<?=APP_URL?>/modules/documents/upload.php" enctype="multipart/form-data">
      <?=csrfField()?>
      <input type="hidden" name="related_module" value="voucher">
      <input type="hidden" name="related_id" value="<?=$v['id']?>">
      <input type="hidden" name="redirect" value="<?=APP_URL?>/modules/expenditure/voucher_view.php?id=<?=$v['id']?>">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Document Title <span class="req">*</span></label>
          <input type="text" name="title" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">Document Type <span class="req">*</span></label>
          <select name="doc_type" class="form-select" required>
            <option value="invoice">Invoice</option>
            <option value="voucher">Voucher</option>
            <option value="receipt">Receipt</option>
            <option value="contract">Contract</option>
            <option value="procurement">LPO / Delivery Note</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">File <span class="req">*</span></label>
          <input type="file" name="document" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx">
          <div class="form-text">Allowed: PDF, JPG, PNG, DOCX, XLSX. Max 10MB.</div>
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
