<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/email.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

/* ══════════════════════════════════════════════════════════════════
   POST HANDLERS
══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    /* ── Create new requisition ──────────────────────────────────── */
    if ($action === 'create_req') {
        $year = date('Y');
        $cnt  = (int)$db->query("SELECT COUNT(*)+1 FROM expenditure_requisitions WHERE YEAR(created_at)=$year")->fetchColumn();
        $rnum = 'REQ-' . $year . '-' . str_pad($cnt, 4, '0', STR_PAD_LEFT);

        $db->prepare("INSERT INTO expenditure_requisitions
            (req_number,financial_year,department_id,budget_id,funding_source_id,
             title,description,justification,estimated_amount,priority,required_date,
             supplier_name,status,prepared_by,prepared_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'draft',?,?)")
           ->execute([
               $rnum, $fy,
               (int)$_POST['department_id'],
               !empty($_POST['budget_id']) ? (int)$_POST['budget_id'] : null,
               !empty($_POST['funding_source_id']) ? (int)$_POST['funding_source_id'] : null,
               trim($_POST['title']),
               trim($_POST['description'] ?? ''),
               trim($_POST['justification'] ?? ''),
               (float)str_replace(',', '', $_POST['estimated_amount']),
               $_POST['priority'] ?? 'normal',
               !empty($_POST['required_date']) ? $_POST['required_date'] : null,
               trim($_POST['supplier_name'] ?? ''),
               $user['id'], date('Y-m-d')
           ]);
        $newId = (int)$db->lastInsertId();
        logReqLog($db, $newId, $user['id'], 'CREATED', '', '', 'draft');
        logAudit('CREATE', 'expenditure', 'requisition', $newId, $rnum);
        setFlash('success', "Requisition created: <strong>$rnum</strong>");
        header('Location: requisitions.php?view=' . $newId); exit;
    }

    /* ── Submit for approval ─────────────────────────────────────── */
    if ($action === 'submit_req') {
        $rid = (int)$_POST['req_id'];
        $req = getReq($db, $rid);
        if ($req && $req['status'] === 'draft'
            && ($req['prepared_by'] == $user['id'] || hasRole(['admin']))) {
            $db->prepare("UPDATE expenditure_requisitions SET status='submitted', updated_at=NOW() WHERE id=?")
               ->execute([$rid]);
            logReqLog($db, $rid, $user['id'], 'SUBMITTED', '', 'draft', 'submitted');
            // Email HOD
            $hods = $db->prepare("SELECT u.email,u.full_name FROM users u JOIN roles r ON u.role_id=r.id WHERE r.slug='hod' AND u.department_id=? AND u.is_active=1 AND u.email!=''");
            $hods->execute([$req['department_id']]);
            foreach ($hods->fetchAll() as $hod) {
                emailRequisitionStatusChange($req, $hod, 'submitted');
            }
            processEmailQueue(5);
            setFlash('success', "Requisition {$req['req_number']} submitted for HOD approval.");
        }
        header('Location: requisitions.php?view=' . $rid); exit;
    }

    /* ── HOD Approve ─────────────────────────────────────────────── */
    if ($action === 'hod_approve_req' && hasRole(['admin','hod'])) {
        $rid     = (int)$_POST['req_id'];
        $comment = trim($_POST['comment'] ?? '');
        $req     = getReq($db, $rid);
        if ($req && $req['status'] === 'submitted') {
            $db->prepare("UPDATE expenditure_requisitions SET status='hod_approved', hod_approved_by=?, hod_approved_at=NOW(), updated_at=NOW() WHERE id=?")
               ->execute([$user['id'], $rid]);
            logReqLog($db, $rid, $user['id'], 'HOD_APPROVED', $comment, 'submitted', 'hod_approved');
            // Email Town Clerk
            $tcs = $db->query("SELECT u.email,u.full_name FROM users u JOIN roles r ON u.role_id=r.id WHERE r.slug='town_clerk' AND u.is_active=1 AND u.email!=''")->fetchAll();
            foreach ($tcs as $tc) emailRequisitionStatusChange($req, $tc, 'hod_approved', $comment);
            // Notify preparer
            $preparer = getUserById($db, $req['prepared_by']);
            if ($preparer) sendNotification($preparer['id'], "Requisition {$req['req_number']} HOD Approved", "HOD approved your requisition. Awaiting Town Clerk.", 'success', 'requisition', $rid);
            processEmailQueue(5);
            setFlash('success', "Requisition approved.");
        }
        header('Location: requisitions.php?view=' . $rid); exit;
    }

    /* ── TC Approve ──────────────────────────────────────────────── */
    if ($action === 'tc_approve_req' && hasRole(['admin','town_clerk'])) {
        $rid     = (int)$_POST['req_id'];
        $comment = trim($_POST['comment'] ?? '');
        $req     = getReq($db, $rid);
        if ($req && $req['status'] === 'hod_approved') {
            $db->prepare("UPDATE expenditure_requisitions SET status='tc_approved', tc_approved_by=?, tc_approved_at=NOW(), updated_at=NOW() WHERE id=?")
               ->execute([$user['id'], $rid]);
            logReqLog($db, $rid, $user['id'], 'TC_APPROVED', $comment, 'hod_approved', 'tc_approved');
            // Email Finance
            $fos = $db->query("SELECT u.email,u.full_name FROM users u JOIN roles r ON u.role_id=r.id WHERE r.slug='finance_officer' AND u.is_active=1 AND u.email!=''")->fetchAll();
            foreach ($fos as $fo) emailRequisitionStatusChange($req, $fo, 'tc_approved', $comment);
            processEmailQueue(5);
            setFlash('success', "Requisition approved by Town Clerk. Procurement can now issue LPO.");
        }
        header('Location: requisitions.php?view=' . $rid); exit;
    }

    /* ── Reject ──────────────────────────────────────────────────── */
    if ($action === 'reject_req' && hasRole(['admin','hod','town_clerk','finance_officer'])) {
        $rid    = (int)$_POST['req_id'];
        $reason = trim($_POST['reject_reason'] ?? '');
        if (!$reason) { setFlash('danger', 'Rejection reason is required.'); header('Location: requisitions.php?view='.$rid); exit; }
        $req = getReq($db, $rid);
        if ($req) {
            $old = $req['status'];
            $db->prepare("UPDATE expenditure_requisitions SET status='rejected', rejection_reason=?, updated_at=NOW() WHERE id=?")
               ->execute([$reason, $rid]);
            logReqLog($db, $rid, $user['id'], 'REJECTED', $reason, $old, 'rejected');
            // Email preparer
            $preparer = getUserById($db, $req['prepared_by']);
            if ($preparer) emailRequisitionStatusChange($req, $preparer, 'rejected', $reason);
            processEmailQueue(5);
            setFlash('danger', "Requisition {$req['req_number']} rejected.");
        }
        header('Location: requisitions.php?view=' . $rid); exit;
    }

    /* ── Issue LPO ───────────────────────────────────────────────── */
    if ($action === 'issue_lpo' && hasRole(['admin','finance_officer','hod'])) {
        $rid        = (int)$_POST['req_id'];
        $lpoNum     = trim($_POST['lpo_number']);
        $lpoDate    = $_POST['lpo_date'];
        $supplierName = trim($_POST['supplier_name'] ?? '');
        $finalAmount  = (float)str_replace(',', '', $_POST['final_amount'] ?? 0);
        $supplierId   = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;

        $db->prepare("UPDATE expenditure_requisitions SET
                status='lpo_issued', lpo_number=?, lpo_date=?,
                supplier_name=?, supplier_id=?,
                final_amount=IF(?=0,estimated_amount,?),
                updated_at=NOW() WHERE id=?")
           ->execute([$lpoNum, $lpoDate, $supplierName, $supplierId,
                      $finalAmount, $finalAmount, $rid]);
        $req = getReq($db, $rid);
        logReqLog($db, $rid, $user['id'], 'LPO_ISSUED', "LPO: $lpoNum", 'tc_approved', 'lpo_issued');
        // Notify preparer
        $preparer = getUserById($db, $req['prepared_by']);
        if ($preparer) sendNotification($preparer['id'], "LPO Issued: {$req['req_number']}", "Local Purchase Order $lpoNum has been issued.", 'info', 'requisition', $rid);
        setFlash('success', "LPO $lpoNum issued successfully.");
        header('Location: requisitions.php?view=' . $rid); exit;
    }

    /* ── Confirm Delivery ────────────────────────────────────────── */
    if ($action === 'confirm_delivery' && hasRole(['admin','finance_officer','hod'])) {
        $rid          = (int)$_POST['req_id'];
        $deliveryDate = $_POST['delivery_date'];
        $deliveryNote = trim($_POST['delivery_note'] ?? '');
        $db->prepare("UPDATE expenditure_requisitions SET status='delivered', delivery_date=?, delivery_note=?, updated_at=NOW() WHERE id=?")
           ->execute([$deliveryDate, $deliveryNote, $rid]);
        logReqLog($db, $rid, $user['id'], 'DELIVERED', "DN: $deliveryNote", 'lpo_issued', 'delivered');
        setFlash('success', "Delivery confirmed.");
        header('Location: requisitions.php?view=' . $rid); exit;
    }

    /* ── Record Invoice ──────────────────────────────────────────── */
    if ($action === 'record_invoice' && hasRole(['admin','finance_officer'])) {
        $rid           = (int)$_POST['req_id'];
        $invoiceNum    = trim($_POST['invoice_number']);
        $invoiceDate   = $_POST['invoice_date'];
        $invoiceAmount = (float)str_replace(',', '', $_POST['invoice_amount']);
        $db->prepare("UPDATE expenditure_requisitions SET status='invoiced', invoice_number=?, invoice_date=?, invoice_amount=?, updated_at=NOW() WHERE id=?")
           ->execute([$invoiceNum, $invoiceDate, $invoiceAmount, $rid]);
        logReqLog($db, $rid, $user['id'], 'INVOICED', "Inv: $invoiceNum UGX $invoiceAmount", 'delivered', 'invoiced');
        setFlash('success', "Invoice $invoiceNum recorded. Ready to create payment voucher.");
        header('Location: requisitions.php?view=' . $rid); exit;
    }

    /* ── Create Voucher from Requisition ─────────────────────────── */
    if ($action === 'create_voucher_from_req' && hasRole(['admin','finance_officer','hod'])) {
        $rid = (int)$_POST['req_id'];
        $req = getReq($db, $rid);
        if ($req && in_array($req['status'], ['invoiced','delivered'])) {
            $vnum   = generateVoucherNumber();
            $amount = $req['invoice_amount'] ?? $req['final_amount'] ?? $req['estimated_amount'];
            $desc   = "Payment for: {$req['title']}" .
                      ($req['lpo_number']   ? " | LPO: {$req['lpo_number']}"    : '') .
                      ($req['invoice_number'] ? " | INV: {$req['invoice_number']}" : '');
            $db->prepare("INSERT INTO payment_vouchers
                (voucher_number,financial_year,department_id,budget_id,funding_source_id,
                 payee_name,description,amount,account_code,requisition_id,status,prepared_by,prepared_date)
                VALUES (?,?,?,?,?,?,?,?,?,?,'draft',?,?)")
               ->execute([$vnum, $req['financial_year'], $req['department_id'],
                          $req['budget_id'], $req['funding_source_id'],
                          $req['supplier_name'] ?: $req['title'],
                          $desc, $amount,
                          '', $rid, $user['id'], date('Y-m-d')]);
            $vid = (int)$db->lastInsertId();
            $db->prepare("UPDATE expenditure_requisitions SET status='voucher_created', voucher_id=?, updated_at=NOW() WHERE id=?")
               ->execute([$vid, $rid]);
            logReqLog($db, $rid, $user['id'], 'VOUCHER_CREATED', "Voucher: $vnum", $req['status'], 'voucher_created');
            logAudit('CREATE', 'expenditure', 'voucher', $vid, $vnum);
            setFlash('success', "Payment voucher <strong>$vnum</strong> created from requisition. <a href='" . APP_URL . "/modules/expenditure/voucher_view.php?id=$vid'>Open Voucher →</a>");
        }
        header('Location: requisitions.php?view=' . $rid); exit;
    }

    /* ── Upload document ─────────────────────────────────────────── */
    if ($action === 'upload_req_doc') {
        $rid     = (int)$_POST['req_id'];
        $docType = $_POST['doc_type'] ?? 'other';
        $title   = trim($_POST['doc_title'] ?? 'Document');
        if (!empty($_FILES['req_doc']) && $_FILES['req_doc']['error'] === UPLOAD_ERR_OK) {
            $result = handleFileUpload($_FILES['req_doc'], 'documents/' . date('Y/m'));
            if ($result['success']) {
                $db->prepare("INSERT INTO requisition_documents (requisition_id,doc_type,title,file_name,file_path,file_size,file_type,uploaded_by) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$rid, $docType, $title, $result['file_name'], $result['file_path'], $result['file_size'], $result['file_type'], $user['id']]);
                setFlash('success', 'Document uploaded.');
            } else {
                setFlash('danger', $result['error']);
            }
        }
        header('Location: requisitions.php?view=' . $rid); exit;
    }
}

/* ══════════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════════ */
function getReq(PDO $db, int $id) {
    $stmt = $db->prepare("SELECT r.*, d.name AS dept_name, u.full_name AS preparer_name,
        u.email AS preparer_email, b.budget_category, fs.name AS source_name
        FROM expenditure_requisitions r
        JOIN departments d ON r.department_id=d.id
        JOIN users u ON r.prepared_by=u.id
        LEFT JOIN budgets b ON r.budget_id=b.id
        LEFT JOIN funding_sources fs ON r.funding_source_id=fs.id
        WHERE r.id=?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}
function logReqLog(PDO $db, int $rid, int $uid, string $action, string $comment, string $old, string $new): void {
    try {
        $db->prepare("INSERT INTO requisition_log (requisition_id,user_id,action,comment,old_status,new_status,ip_address) VALUES (?,?,?,?,?,?,?)")
           ->execute([$rid, $uid, $action, $comment, $old, $new, getClientIP()]);
    } catch (Exception $e) { error_log("ReqLog: " . $e->getMessage()); }
}
function getUserById(PDO $db, int $id) {
    $s = $db->prepare("SELECT id,full_name,email FROM users WHERE id=?"); $s->execute([$id]); return $s->fetch();
}

/* ══════════════════════════════════════════════════════════════════
   VIEW — single requisition detail
══════════════════════════════════════════════════════════════════ */
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId) {
    $req = getReq($db, $viewId);
    if (!$req) { setFlash('danger','Requisition not found.'); header('Location: requisitions.php'); exit; }
    if (hasRole(['hod']) && !hasRole(['admin']) && $req['department_id'] != $user['department_id']) {
        setFlash('danger','Access denied.'); header('Location: requisitions.php'); exit;
    }

    // Load activity log
    $log = $db->prepare("SELECT rl.*, u.full_name FROM requisition_log rl JOIN users u ON rl.user_id=u.id WHERE rl.requisition_id=? ORDER BY rl.created_at DESC");
    $log->execute([$viewId]); $log = $log->fetchAll();

    // Load documents
    $rdocs = $db->prepare("SELECT rd.*, u.full_name AS uploader FROM requisition_documents rd JOIN users u ON rd.uploaded_by=u.id WHERE rd.requisition_id=? ORDER BY rd.uploaded_at DESC");
    $rdocs->execute([$viewId]); $rdocs = $rdocs->fetchAll();

    $suppliers = $db->query("SELECT * FROM suppliers WHERE is_active=1 ORDER BY name")->fetchAll();
    $budgets   = $db->query("SELECT b.*,d.name AS dept_name FROM budgets b JOIN departments d ON b.department_id=d.id WHERE b.status IN ('approved','active') ORDER BY d.name")->fetchAll();
    $fSources  = $db->query("SELECT * FROM funding_sources WHERE is_active=1 ORDER BY name")->fetchAll();

    // Linked voucher
    $linkedVoucher = null;
    if ($req['voucher_id']) {
        $vs = $db->prepare("SELECT voucher_number,status,amount FROM payment_vouchers WHERE id=?");
        $vs->execute([$req['voucher_id']]); $linkedVoucher = $vs->fetch();
    }

    // Stage config
    $stages = [
        'draft'           => ['label'=>'Draft',              'icon'=>'○', 'color'=>'var(--text-muted)'],
        'submitted'       => ['label'=>'Submitted',          'icon'=>'●', 'color'=>'var(--primary)'],
        'hod_approved'    => ['label'=>'HOD Approved',       'icon'=>'✓', 'color'=>'var(--info)'],
        'tc_approved'     => ['label'=>'TC Approved',        'icon'=>'✓', 'color'=>'var(--info)'],
        'lpo_issued'      => ['label'=>'LPO Issued',         'icon'=>'📋','color'=>'var(--primary)'],
        'delivered'       => ['label'=>'Delivered',          'icon'=>'📦','color'=>'var(--primary)'],
        'invoiced'        => ['label'=>'Invoice Received',   'icon'=>'🧾','color'=>'var(--warning)'],
        'voucher_created' => ['label'=>'Voucher Created',    'icon'=>'▤', 'color'=>'var(--success)'],
        'completed'       => ['label'=>'Completed',          'icon'=>'✅','color'=>'var(--success)'],
        'rejected'        => ['label'=>'Rejected',           'icon'=>'✕', 'color'=>'var(--danger)'],
    ];
    $stageOrder = array_keys($stages);
    $curIdx     = (int)array_search($req['status'], $stageOrder);

    renderHead('Requisition ' . $req['req_number']);
    echo '<body><div class="app-wrapper">';
    renderSidebar($user);
    echo '<div class="main-content">';
    renderTopbar('Expenditure Requisition', $req['req_number']);
    renderPageStart('Requisition: ' . htmlspecialchars($req['req_number']), '', [
        ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
        ['url'=>APP_URL.'/modules/expenditure/requisitions.php','label'=>'Requisitions'],
        ['url'=>'#','label'=>$req['req_number']],
    ]);
    renderPageActions('<a href="requisitions.php" class="btn btn-outline-secondary">← All Requisitions</a>');
    renderFlashMessages();
    ?>

<!-- Progress pipeline -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-body" style="padding:.9rem 1.2rem;">
    <div style="display:flex;align-items:center;gap:0;overflow-x:auto;">
      <?php
      $pipelineStages = ['draft','submitted','hod_approved','tc_approved','lpo_issued','delivered','invoiced','voucher_created','completed'];
      foreach ($pipelineStages as $i => $s):
        $done    = $curIdx > $i;
        $current = $curIdx === $i;
        $rejected= $req['status'] === 'rejected';
        $bgColor  = $done ? 'var(--primary)' : ($current && !$rejected ? 'var(--accent)' : '#e9ecef');
        $txtColor = ($done || $current) && !$rejected ? '#fff' : 'var(--text-muted)';
        if ($rejected && $i >= $curIdx) { $bgColor='#e9ecef'; $txtColor='#ccc'; }
      ?>
      <div style="display:flex;align-items:center;flex:1;min-width:0;">
        <div style="flex:1;background:<?= $bgColor ?>;color:<?= $txtColor ?>;padding:.45rem .6rem;font-size:.72rem;font-weight:700;text-align:center;white-space:nowrap;border-radius:<?= $i===0?'4px 0 0 4px':($i===count($pipelineStages)-1?'0 4px 4px 0':'0') ?>;">
          <?= $stages[$s]['label'] ?>
        </div>
        <?php if ($i < count($pipelineStages)-1): ?>
        <div style="width:0;height:0;border-top:18px solid transparent;border-bottom:18px solid transparent;border-left:10px solid <?= $bgColor ?>;flex-shrink:0;"></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($req['status'] === 'rejected'): ?>
    <div class="alert alert-danger" style="margin-top:.7rem;margin-bottom:0;">
      <span>✕</span><span><strong>Rejected:</strong> <?= htmlspecialchars($req['rejection_reason'] ?? 'No reason given') ?></span>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="grid-3" style="align-items:start;">

  <!-- ── Requisition details ─────────────────────────────────────── -->
  <div style="grid-column:span 2;">

    <div class="card" style="margin-bottom:1rem;">
      <div class="card-header">
        <h5><span class="ch-icon">◑</span> Requisition Details</h5>
        <div style="display:flex;gap:.4rem;align-items:center;">
          <?= getStatusBadge($req['status']) ?>
          <span class="badge badge-<?= $req['priority']==='urgent'?'danger':($req['priority']==='high'?'warning':($req['priority']==='normal'?'info':'secondary')) ?>">
            <?= ucfirst($req['priority']) ?> Priority
          </span>
        </div>
      </div>
      <div class="card-body">
        <div class="grid-2" style="gap:.5rem;margin-bottom:.8rem;">
          <div class="receipt-row"><span class="text-muted fs-sm">Req. Number:</span><strong class="text-primary"><?= htmlspecialchars($req['req_number']) ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Financial Year:</span><strong><?= $req['financial_year'] ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Department:</span><strong><?= htmlspecialchars($req['dept_name']) ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Required By:</span><strong><?= $req['required_date'] ? formatDate($req['required_date']) : '—' ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Budget Line:</span><strong><?= htmlspecialchars($req['budget_category'] ?? '—') ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Funding Source:</span><strong><?= htmlspecialchars($req['source_name'] ?? '—') ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Prepared By:</span><strong><?= htmlspecialchars($req['preparer_name']) ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Date Prepared:</span><strong><?= formatDate($req['prepared_date']) ?></strong></div>
        </div>

        <div style="margin-bottom:.8rem;">
          <div class="fs-sm fw-600" style="margin-bottom:.2rem;">Title</div>
          <div style="font-size:.95rem;font-weight:700;color:var(--primary);"><?= htmlspecialchars($req['title']) ?></div>
        </div>
        <?php if ($req['description']): ?>
        <div style="margin-bottom:.8rem;">
          <div class="fs-sm fw-600 text-muted">Description</div>
          <p style="background:#f8f9fa;padding:.7rem;border-radius:4px;font-size:.87rem;margin:.3rem 0;"><?= htmlspecialchars($req['description']) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($req['justification']): ?>
        <div style="margin-bottom:.8rem;">
          <div class="fs-sm fw-600 text-muted">Justification</div>
          <p style="background:#f8f9fa;padding:.7rem;border-radius:4px;font-size:.87rem;margin:.3rem 0;"><?= htmlspecialchars($req['justification']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Amount table -->
        <div style="background:var(--primary);color:#fff;border-radius:8px;padding:1rem;margin-top:.8rem;">
          <div class="grid-3" style="gap:.5rem;text-align:center;">
            <div>
              <div style="font-size:.7rem;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">Estimated</div>
              <div style="font-size:1.2rem;font-weight:800;">UGX <?= number_format($req['estimated_amount']) ?></div>
            </div>
            <div>
              <div style="font-size:.7rem;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">Final/LPO</div>
              <div style="font-size:1.2rem;font-weight:800;"><?= $req['final_amount'] ? 'UGX ' . number_format($req['final_amount']) : '—' ?></div>
            </div>
            <div>
              <div style="font-size:.7rem;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">Invoice</div>
              <div style="font-size:1.2rem;font-weight:800;"><?= $req['invoice_amount'] ? 'UGX ' . number_format($req['invoice_amount']) : '—' ?></div>
            </div>
          </div>
        </div>

        <!-- LPO / Delivery / Invoice fields -->
        <?php if ($req['lpo_number']): ?>
        <div style="margin-top:1rem;padding:1rem;background:#f0f7ff;border-radius:8px;border-left:4px solid var(--info);">
          <div class="fs-sm fw-700" style="margin-bottom:.5rem;">📋 Local Purchase Order</div>
          <div class="grid-2" style="gap:.3rem;">
            <div class="receipt-row fs-sm"><span class="text-muted">LPO No:</span><strong><?= htmlspecialchars($req['lpo_number']) ?></strong></div>
            <div class="receipt-row fs-sm"><span class="text-muted">LPO Date:</span><strong><?= $req['lpo_date'] ? formatDate($req['lpo_date']) : '—' ?></strong></div>
            <div class="receipt-row fs-sm"><span class="text-muted">Supplier:</span><strong><?= htmlspecialchars($req['supplier_name'] ?? '—') ?></strong></div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($req['delivery_date']): ?>
        <div style="margin-top:.8rem;padding:1rem;background:#f0fff4;border-radius:8px;border-left:4px solid var(--success);">
          <div class="fs-sm fw-700" style="margin-bottom:.5rem;">📦 Delivery</div>
          <div class="grid-2" style="gap:.3rem;">
            <div class="receipt-row fs-sm"><span class="text-muted">Delivery Date:</span><strong><?= formatDate($req['delivery_date']) ?></strong></div>
            <div class="receipt-row fs-sm"><span class="text-muted">Delivery Note:</span><strong><?= htmlspecialchars($req['delivery_note'] ?? '—') ?></strong></div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($req['invoice_number']): ?>
        <div style="margin-top:.8rem;padding:1rem;background:#fffbf0;border-radius:8px;border-left:4px solid var(--warning);">
          <div class="fs-sm fw-700" style="margin-bottom:.5rem;">🧾 Invoice</div>
          <div class="grid-2" style="gap:.3rem;">
            <div class="receipt-row fs-sm"><span class="text-muted">Invoice No:</span><strong><?= htmlspecialchars($req['invoice_number']) ?></strong></div>
            <div class="receipt-row fs-sm"><span class="text-muted">Invoice Date:</span><strong><?= $req['invoice_date'] ? formatDate($req['invoice_date']) : '—' ?></strong></div>
            <div class="receipt-row fs-sm"><span class="text-muted">Invoice Amount:</span><strong>UGX <?= number_format($req['invoice_amount']) ?></strong></div>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($linkedVoucher): ?>
        <div style="margin-top:.8rem;padding:1rem;background:#f0fff4;border-radius:8px;border-left:4px solid var(--success);">
          <div class="fs-sm fw-700" style="margin-bottom:.4rem;">▤ Payment Voucher Created</div>
          <a href="<?= APP_URL ?>/modules/expenditure/voucher_view.php?id=<?= $req['voucher_id'] ?>" class="btn btn-sm btn-success">
            Open Voucher <?= htmlspecialchars($linkedVoucher['voucher_number']) ?> — <?= getStatusBadge($linkedVoucher['status']) ?>
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Documents -->
    <div class="card">
      <div class="card-header">
        <h5><span class="ch-icon">▧</span> Documents (<?= count($rdocs) ?>)</h5>
        <button class="btn btn-sm btn-outline-primary" data-modal="uploadReqDocModal">+ Upload</button>
      </div>
      <div class="card-body p-0">
        <?php if ($rdocs): ?>
        <div class="table-wrapper">
          <table class="tcms-table">
            <thead><tr><th>Title</th><th>Type</th><th>Format</th><th>By</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($rdocs as $d): ?>
            <tr>
              <td class="fw-600"><?= htmlspecialchars($d['title']) ?></td>
              <td><span class="badge badge-info"><?= ucwords(str_replace('_',' ',$d['doc_type'])) ?></span></td>
              <td><span class="badge badge-secondary"><?= strtoupper($d['file_type']??'—') ?></span></td>
              <td class="fs-sm"><?= htmlspecialchars($d['uploader']) ?></td>
              <td class="fs-sm"><?= formatDate($d['uploaded_at']) ?></td>
              <td>
                <a href="<?= APP_URL ?>/uploads/<?= htmlspecialchars($d['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
                <a href="<?= APP_URL ?>/uploads/<?= htmlspecialchars($d['file_path']) ?>" download class="btn btn-sm btn-outline-secondary">↓</a>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No documents uploaded yet.</p></div><?php endif; ?>
      </div>
    </div>

  </div>

  <!-- ── Right: Actions + Activity log ────────────────────────────── -->
  <div>

    <!-- Action Panel -->
    <div class="card" style="margin-bottom:1rem;">
      <div class="card-header"><h5><span class="ch-icon">◉</span> Actions</h5></div>
      <div class="card-body">
        <?php
        $s = $req['status'];
        $preparedByMe = $req['prepared_by'] == $user['id'];
        ?>

        <?php if ($s === 'draft' && ($preparedByMe || hasRole(['admin']))): ?>
        <p class="fs-sm text-muted mb-2">Submit this requisition to start the approval process.</p>
        <form method="POST">
          <?= csrfField() ?><input type="hidden" name="action" value="submit_req"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
          <button type="submit" class="btn btn-primary btn-block" data-confirm="Submit for HOD approval?">↑ Submit for Approval</button>
        </form>

        <?php elseif ($s === 'submitted' && hasRole(['admin','hod'])): ?>
        <form method="POST" style="margin-bottom:.5rem;">
          <?= csrfField() ?><input type="hidden" name="action" value="hod_approve_req"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
          <div class="form-group"><label class="form-label">Comment</label><textarea name="comment" class="form-control" rows="2"></textarea></div>
          <button type="submit" class="btn btn-success btn-block">✓ HOD Approve</button>
        </form>
        <form method="POST">
          <?= csrfField() ?><input type="hidden" name="action" value="reject_req"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
          <div class="form-group"><label class="form-label">Rejection reason <span class="req">*</span></label><textarea name="reject_reason" class="form-control" rows="2" required></textarea></div>
          <button type="submit" class="btn btn-danger btn-block">✕ Reject</button>
        </form>

        <?php elseif ($s === 'hod_approved' && hasRole(['admin','town_clerk'])): ?>
        <form method="POST" style="margin-bottom:.5rem;">
          <?= csrfField() ?><input type="hidden" name="action" value="tc_approve_req"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
          <div class="form-group"><label class="form-label">Comment</label><textarea name="comment" class="form-control" rows="2"></textarea></div>
          <button type="submit" class="btn btn-success btn-block">✓ Town Clerk Approve</button>
        </form>
        <form method="POST">
          <?= csrfField() ?><input type="hidden" name="action" value="reject_req"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
          <div class="form-group"><label class="form-label">Rejection reason <span class="req">*</span></label><textarea name="reject_reason" class="form-control" rows="2" required></textarea></div>
          <button type="submit" class="btn btn-danger btn-block">✕ Reject</button>
        </form>

        <?php elseif ($s === 'tc_approved' && hasRole(['admin','finance_officer','hod'])): ?>
        <p class="fs-sm text-muted mb-2">Issue a Local Purchase Order to the supplier.</p>
        <button class="btn btn-primary btn-block" data-modal="issueLpoModal">📋 Issue LPO</button>

        <?php elseif ($s === 'lpo_issued' && hasRole(['admin','finance_officer','hod'])): ?>
        <p class="fs-sm text-muted mb-2">Confirm goods/services have been received.</p>
        <button class="btn btn-primary btn-block" data-modal="confirmDeliveryModal">📦 Confirm Delivery</button>

        <?php elseif ($s === 'delivered' && hasRole(['admin','finance_officer'])): ?>
        <p class="fs-sm text-muted mb-2">Record the supplier invoice.</p>
        <button class="btn btn-primary btn-block" data-modal="recordInvoiceModal">🧾 Record Invoice</button>

        <?php elseif ($s === 'invoiced' && hasRole(['admin','finance_officer','hod'])): ?>
        <p class="fs-sm text-muted mb-2">Create a payment voucher to process this payment.</p>
        <form method="POST">
          <?= csrfField() ?><input type="hidden" name="action" value="create_voucher_from_req"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
          <button type="submit" class="btn btn-success btn-block" data-confirm="Create payment voucher from this requisition?">▤ Create Payment Voucher</button>
        </form>

        <?php elseif ($s === 'voucher_created' && $linkedVoucher): ?>
        <p class="fs-sm text-success" style="margin-bottom:.8rem;">✅ Payment voucher has been created.</p>
        <a href="<?= APP_URL ?>/modules/expenditure/voucher_view.php?id=<?= $req['voucher_id'] ?>" class="btn btn-primary btn-block">Open Voucher →</a>

        <?php else: ?>
        <p class="text-muted fs-sm">No action required at this stage.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Activity Log -->
    <div class="card">
      <div class="card-header"><h5><span class="ch-icon">◮</span> Activity Log</h5></div>
      <div class="card-body" style="padding:.8rem;">
        <?php if ($log): ?>
        <div class="approval-timeline">
          <?php foreach ($log as $l): ?>
          <div class="timeline-item">
            <div class="timeline-dot done" style="background:var(--primary);">●</div>
            <div class="timeline-content">
              <div class="stage"><?= ucwords(str_replace('_',' ',$l['action'])) ?></div>
              <div class="meta"><?= htmlspecialchars($l['full_name']) ?> · <?= formatDateTime($l['created_at']) ?></div>
              <?php if ($l['comment']): ?><div class="comment"><?= htmlspecialchars($l['comment']) ?></div><?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?><p class="fs-sm text-muted">No activity yet.</p><?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- Issue LPO Modal -->
<div class="modal-backdrop" id="issueLpoModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Issue Local Purchase Order</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="issue_lpo"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">LPO Number <span class="req">*</span></label><input type="text" name="lpo_number" class="form-control" required></div>
        <div class="form-group"><label class="form-label">LPO Date <span class="req">*</span></label><input type="date" name="lpo_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label class="form-label">Supplier Name <span class="req">*</span></label>
          <input type="text" name="supplier_name" class="form-control" required value="<?= htmlspecialchars($req['supplier_name'] ?? '') ?>">
        </div>
        <div class="form-group"><label class="form-label">Select Registered Supplier (optional)</label>
          <select name="supplier_id" class="form-select"><option value="">— None —</option>
            <?php foreach ($suppliers as $sup): ?><option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Final Amount (UGX) <span class="req">*</span></label><input type="number" name="final_amount" class="form-control" min="1" step="1" value="<?= $req['estimated_amount'] ?>" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Issue LPO</button>
      </div>
    </form>
  </div>
</div>

<!-- Confirm Delivery Modal -->
<div class="modal-backdrop" id="confirmDeliveryModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Confirm Delivery</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="confirm_delivery"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Delivery Date <span class="req">*</span></label><input type="date" name="delivery_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label class="form-label">Delivery Note Number</label><input type="text" name="delivery_note" class="form-control" placeholder="GRN / Delivery Note No."></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-success">Confirm Delivery</button>
      </div>
    </form>
  </div>
</div>

<!-- Record Invoice Modal -->
<div class="modal-backdrop" id="recordInvoiceModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Record Supplier Invoice</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="record_invoice"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Invoice Number <span class="req">*</span></label><input type="text" name="invoice_number" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Invoice Date <span class="req">*</span></label><input type="date" name="invoice_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label class="form-label">Invoice Amount (UGX) <span class="req">*</span></label><input type="number" name="invoice_amount" class="form-control" required min="1" step="1" value="<?= $req['final_amount'] ?? $req['estimated_amount'] ?>"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Record Invoice</button>
      </div>
    </form>
  </div>
</div>

<!-- Upload Doc Modal -->
<div class="modal-backdrop" id="uploadReqDocModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Upload Document</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?><input type="hidden" name="action" value="upload_req_doc"><input type="hidden" name="req_id" value="<?= $req['id'] ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Document Type <span class="req">*</span></label>
          <select name="doc_type" class="form-select" required>
            <?php foreach (['quotation'=>'Quotation','specification'=>'Specification','lpo'=>'LPO','delivery_note'=>'Delivery Note','invoice'=>'Invoice','other'=>'Other'] as $k=>$l): ?>
            <option value="<?= $k ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Document Title <span class="req">*</span></label><input type="text" name="doc_title" class="form-control" required></div>
        <div class="form-group"><label class="form-label">File <span class="req">*</span></label>
          <input type="file" name="req_doc" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx">
          <div class="form-text">PDF, JPG, PNG, DOCX, XLSX. Max 10MB.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Upload</button>
      </div>
    </form>
  </div>
</div>

    <?php
    renderPageEnd(); echo '</div></div>'; renderFooter();
    exit;
}

/* ══════════════════════════════════════════════════════════════════
   LIST VIEW
══════════════════════════════════════════════════════════════════ */
$statusFil = $_GET['status'] ?? '';
$deptFil   = (int)($_GET['dept_id'] ?? 0);
$fyFil     = $_GET['fy'] ?? $fy;
$page      = max(1, (int)($_GET['page'] ?? 1));

$where  = ['1=1']; $params = [];
if (hasRole(['hod']) && !hasRole(['admin'])) { $where[] = "r.department_id=?"; $params[] = $user['department_id']; }
elseif ($deptFil) { $where[] = "r.department_id=?"; $params[] = $deptFil; }
if ($statusFil) { $where[] = "r.status=?"; $params[] = $statusFil; }
if ($fyFil)     { $where[] = "r.financial_year=?"; $params[] = $fyFil; }
$wSQL = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM expenditure_requisitions r WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total, $page);
$rows = $db->prepare("SELECT r.*, d.name AS dept_name, u.full_name AS preparer
    FROM expenditure_requisitions r
    JOIN departments d ON r.department_id=d.id
    JOIN users u ON r.prepared_by=u.id
    WHERE $wSQL ORDER BY r.created_at DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $requisitions = $rows->fetchAll();

// Summary counts
$sumStmt = $db->query("SELECT status, COUNT(*) cnt FROM expenditure_requisitions GROUP BY status");
$sumMap  = array_column($sumStmt->fetchAll(), 'cnt', 'status');

$depts   = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$budgets = $db->query("SELECT b.*,d.name AS dept_name FROM budgets b JOIN departments d ON b.department_id=d.id WHERE b.status IN ('approved','active') ORDER BY d.name")->fetchAll();
$fSources= $db->query("SELECT * FROM funding_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

$statuses = ['draft','submitted','hod_approved','tc_approved','lpo_issued','delivered','invoiced','voucher_created','completed','rejected','cancelled'];

renderHead('Expenditure Requisitions');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Expenditure Requisitions', 'Request → LPO → Delivery → Invoice → Voucher');
renderPageStart('Expenditure Requisitions', '', [
    ['url' => APP_URL . '/dashboard.php', 'label' => 'Dashboard'],
    ['url' => '#', 'label' => 'Requisitions'],
]);
renderPageActions('<button class="btn btn-primary" data-modal="createReqModal">+ New Requisition</button>
  <a href="' . APP_URL . '/modules/expenditure/vouchers.php" class="btn btn-outline-secondary">Payment Vouchers</a>');
renderFlashMessages();
?>

<!-- Summary pills -->
<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.2rem;">
  <?php
  $pillStatus = [
    '' => 'All',
    'draft' => 'Draft', 'submitted' => 'Pending', 'hod_approved' => 'HOD Approved',
    'tc_approved' => 'TC Approved', 'lpo_issued' => 'LPO Issued',
    'delivered' => 'Delivered', 'invoiced' => 'Invoiced',
    'voucher_created' => 'Voucher Created', 'completed' => 'Completed',
    'rejected' => 'Rejected',
  ];
  foreach ($pillStatus as $key => $lbl):
    $isActive  = $statusFil === $key;
    $count     = $key === '' ? $total : ($sumMap[$key] ?? 0);
    $pillStyle = $isActive
      ? 'background:var(--primary);color:#fff;border-color:var(--primary);'
      : 'background:#fff;color:var(--text-main);border:1px solid var(--border);';
  ?>
  <a href="?status=<?= $key ?>&fy=<?= urlencode($fyFil) ?>"
     style="<?= $pillStyle ?> padding:.3rem .9rem;border-radius:20px;font-size:.8rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;transition:all .15s;">
    <?= $lbl ?>
    <?php if ($count > 0): ?>
    <span style="background:<?= $isActive ? 'rgba(255,255,255,.25)' : 'var(--primary)' ?>;color:<?= $isActive?'#fff':'#fff' ?>;border-radius:10px;padding:1px 7px;font-size:.7rem;"><?= $count ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="GET">
<input type="hidden" name="status" value="<?= htmlspecialchars($statusFil) ?>">
<div class="filter-row">
  <?php if (!hasRole(['hod'])): ?>
  <div class="form-group"><label>Department</label>
    <select name="dept_id" class="form-select"><option value="">All</option>
      <?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>" <?= $deptFil==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="form-group"><label>Financial Year</label>
    <select name="fy" class="form-select"><?php foreach ($fyears as $y): ?><option value="<?= $y ?>" <?= $y===$fyFil?'selected':'' ?>><?= $y ?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="requisitions.php" class="btn btn-outline-secondary">Reset</a></div></div>
</div>
</form>

<!-- Table -->
<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◑</span> Requisitions (<?= number_format($total) ?>)</h5></div>
  <div class="card-body p-0">
    <?php if ($requisitions): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>#</th><th>Req. No.</th><th>Title</th><th>Department</th><th>Priority</th><th class="text-right">Est. Amount</th><th>Required By</th><th>Status</th><th>Prepared</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($requisitions as $i => $r): ?>
        <tr>
          <td class="fs-xs text-muted"><?= $pg['offset']+$i+1 ?></td>
          <td><a href="?view=<?= $r['id'] ?>" class="fw-700 text-primary"><?= htmlspecialchars($r['req_number']) ?></a></td>
          <td>
            <div class="fw-600"><?= htmlspecialchars(substr($r['title'], 0, 40)) ?><?= strlen($r['title'])>40?'…':'' ?></div>
            <?php if ($r['supplier_name']): ?><div class="fs-xs text-muted"><?= htmlspecialchars($r['supplier_name']) ?></div><?php endif; ?>
          </td>
          <td class="fs-sm"><?= htmlspecialchars($r['dept_name']) ?></td>
          <td>
            <span class="badge badge-<?= $r['priority']==='urgent'?'danger':($r['priority']==='high'?'warning':($r['priority']==='normal'?'info':'secondary')) ?>">
              <?= ucfirst($r['priority']) ?>
            </span>
          </td>
          <td class="text-right fw-700"><?= number_format($r['estimated_amount']) ?></td>
          <td class="fs-sm"><?= $r['required_date'] ? formatDate($r['required_date']) : '—' ?></td>
          <td><?= getStatusBadge($r['status']) ?></td>
          <td class="fs-sm"><?= formatDate($r['prepared_date']) ?><br><span class="fs-xs text-muted"><?= htmlspecialchars($r['preparer']) ?></span></td>
          <td><a href="?view=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">◑</div><h5>No requisitions found</h5><p>Create a new requisition using the button above.</p></div>
    <?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?= renderPagination($pg,'?status='.urlencode($statusFil).'&dept_id='.$deptFil.'&fy='.urlencode($fyFil)) ?></div><?php endif; ?>
</div>

<!-- Create Requisition Modal -->
<div class="modal-backdrop" id="createReqModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>New Expenditure Requisition</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="create_req">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Title / Description of Item(s) <span class="req">*</span></label>
            <input type="text" name="title" class="form-control" required placeholder="e.g. Purchase of office stationery — Q4 2026">
          </div>
          <div class="form-group">
            <label class="form-label">Department <span class="req">*</span></label>
            <select name="department_id" class="form-select" required>
              <option value="">— Select —</option>
              <?php foreach ($depts as $d): ?>
              <option value="<?= $d['id'] ?>" <?= $user['department_id']==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Priority</label>
            <select name="priority" class="form-select">
              <option value="low">Low</option>
              <option value="normal" selected>Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Estimated Amount (UGX) <span class="req">*</span></label>
            <input type="number" name="estimated_amount" class="form-control" required min="1" step="1">
          </div>
          <div class="form-group">
            <label class="form-label">Required By Date</label>
            <input type="date" name="required_date" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Budget Line</label>
            <select name="budget_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($budgets as $b): ?>
              <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['dept_name'] . ' — ' . $b['budget_category'] . ' (UGX ' . number_format($b['approved_amount']) . ')') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Funding Source</label>
            <select name="funding_source_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($fSources as $fs): ?><option value="<?= $fs['id'] ?>"><?= htmlspecialchars($fs['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Preferred Supplier (if known)</label>
            <input type="text" name="supplier_name" class="form-control" placeholder="Name of supplier">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Detailed Description</label>
          <textarea name="description" class="form-control" rows="2" placeholder="Detailed specifications, quantities, etc."></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Justification <span class="req">*</span></label>
          <textarea name="justification" class="form-control" rows="2" required placeholder="Why is this purchase necessary?"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Create Requisition</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
