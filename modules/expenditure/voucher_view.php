<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/email.php';
require_once __DIR__ . '/../../includes/sms.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('danger', 'Invalid voucher.'); header('Location: vouchers.php'); exit; }

// ── Load voucher with all related data ───────────────────────────
function loadVoucher(PDO $db, int $id) {
    $stmt = $db->prepare("
        SELECT pv.*, d.name AS dept_name, fs.name AS source_name,
               b.budget_category, b.approved_amount AS budget_approved,
               u.full_name AS preparer, u.email AS preparer_email,
               u.id AS preparer_id
        FROM payment_vouchers pv
        JOIN departments d     ON pv.department_id = d.id
        LEFT JOIN funding_sources fs ON pv.funding_source_id = fs.id
        LEFT JOIN budgets b    ON pv.budget_id = b.id
        JOIN users u           ON pv.prepared_by = u.id
        WHERE pv.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

$v = loadVoucher($db, $id);
if (!$v) { setFlash('danger', 'Voucher not found.'); header('Location: vouchers.php'); exit; }

// Permission check
if (hasRole(['hod']) && !hasRole(['admin']) && $v['department_id'] != $user['department_id']) {
    setFlash('danger', 'Access denied.'); header('Location: vouchers.php'); exit;
}

// ── POST — handle all actions ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    // ── SUBMIT (draft → submitted) ────────────────────────────────
    if ($action === 'submit_voucher' && $v['status'] === 'draft'
        && ($v['prepared_by'] == $user['id'] || hasRole(['admin']))) {

        $db->prepare("UPDATE payment_vouchers SET status='submitted', updated_at=NOW() WHERE id=?")
           ->execute([$id]);
        $db->prepare("INSERT INTO voucher_approvals (voucher_id,approver_id,approver_role,approval_stage,action,comments,ip_address) VALUES (?,?,?,'hod','submitted',?,?)")
           ->execute([$id, $user['id'], $user['role_name'], 'Submitted for approval', getClientIP()]);
        logAudit('SUBMIT', 'expenditure', 'voucher', $id, $v['voucher_number']);
        sendNotification($user['id'], 'Voucher Submitted', "Voucher {$v['voucher_number']} submitted.", 'info', 'expenditure', $id);

        // Email HOD
        $vFresh = loadVoucher($db, $id);
        emailVoucherSubmitted($vFresh, ['full_name' => $user['full_name'], 'email' => $user['email']]);
        processEmailQueue(5);

        setFlash('success', "Voucher {$v['voucher_number']} submitted for HOD approval.");
        header('Location: voucher_view.php?id=' . $id); exit;
    }

    // ── SAVE CORRECTIONS (returned → draft-ready to resubmit) ─────
    if ($action === 'save_corrections' && $v['status'] === 'returned'
        && ($v['prepared_by'] == $user['id'] || hasRole(['admin','hod']))) {

        $newAmount      = (float)str_replace(',', '', $_POST['amount'] ?? $v['amount']);
        $newDesc        = trim($_POST['description'] ?? $v['description']);
        $newPayee       = trim($_POST['payee_name'] ?? $v['payee_name']);
        $newPayeeContact= trim($_POST['payee_contact'] ?? $v['payee_contact']);
        $newBudgetId    = !empty($_POST['budget_id']) ? (int)$_POST['budget_id'] : $v['budget_id'];
        $newFundId      = !empty($_POST['funding_source_id']) ? (int)$_POST['funding_source_id'] : $v['funding_source_id'];
        $newAccountCode = trim($_POST['account_code'] ?? $v['account_code']);
        $corrNote       = trim($_POST['correction_note'] ?? '');

        $db->prepare("UPDATE payment_vouchers SET
                amount=?, description=?, payee_name=?, payee_contact=?,
                budget_id=?, funding_source_id=?, account_code=?,
                correction_note=?,
                correction_count = correction_count + 1,
                original_amount = COALESCE(original_amount, ?),
                status='draft',
                updated_at=NOW()
            WHERE id=?")
           ->execute([$newAmount, $newDesc, $newPayee, $newPayeeContact,
                      $newBudgetId, $newFundId, $newAccountCode,
                      $corrNote, $v['amount'], $id]);

        $db->prepare("INSERT INTO voucher_approvals (voucher_id,approver_id,approver_role,approval_stage,action,comments,ip_address) VALUES (?,?,?,'correction','noted',?,?)")
           ->execute([$id, $user['id'], $user['role_name'], "Corrections applied: $corrNote", getClientIP()]);

        logAudit('CORRECT', 'expenditure', 'voucher', $id, $v['voucher_number'],
            ['amount' => $v['amount']], ['amount' => $newAmount, 'note' => $corrNote]);
        setFlash('success', 'Corrections saved. You can now resubmit the voucher.');
        header('Location: voucher_view.php?id=' . $id); exit;
    }

    // ── APPROVAL WORKFLOW ─────────────────────────────────────────
    $stageMap = [
        'hod_approve'    => ['stage' => 'hod',          'next' => 'hod_approved'],
        'tc_approve'     => ['stage' => 'town_clerk',    'next' => 'tc_approved'],
        'finance_verify' => ['stage' => 'finance_verify','next' => 'finance_verified'],
        'finance_clear'  => ['stage' => 'finance_clear', 'next' => 'finance_cleared'],
        'mark_paid'      => ['stage' => 'payment',       'next' => 'paid'],
        'reject'         => ['stage' => 'reject',        'next' => 'rejected'],
        'return_voucher' => ['stage' => 'return',        'next' => 'returned'],
    ];
    $roleAllowed = [
        'hod_approve'    => ['admin', 'hod'],
        'tc_approve'     => ['admin', 'town_clerk'],
        'finance_verify' => ['admin', 'finance_officer'],
        'finance_clear'  => ['admin', 'finance_officer'],
        'mark_paid'      => ['admin', 'finance_officer'],
        'reject'         => ['admin', 'town_clerk', 'finance_officer', 'hod'],
        'return_voucher' => ['admin', 'town_clerk', 'finance_officer'],
    ];

    if (isset($stageMap[$action]) && hasRole($roleAllowed[$action] ?? [])) {
        $map           = $stageMap[$action];
        $approvalAction = in_array($action, ['reject']) ? 'rejected'
                        : (in_array($action, ['return_voucher']) ? 'returned' : 'approved');

        // Extra fields for specific actions
        $returnNote = '';
        if ($action === 'return_voucher') {
            $returnNote = trim($_POST['return_reason'] ?? $comment);
            if (empty($returnNote)) { setFlash('danger', 'A reason is required when returning a voucher.'); header('Location: voucher_view.php?id=' . $id); exit; }
            $db->prepare("UPDATE payment_vouchers SET returned_by=?, returned_at=NOW(), correction_note=?, status='returned', updated_at=NOW() WHERE id=?")
               ->execute([$user['id'], $returnNote, $id]);
        } else {
            $db->prepare("UPDATE payment_vouchers SET status=?, updated_at=NOW() WHERE id=?")
               ->execute([$map['next'], $id]);
        }

        // If paid — record payment details + update budget
        if ($action === 'mark_paid') {
            $payRef = trim($_POST['payment_reference'] ?? '');
            $payMethod = $_POST['payment_method'] ?? 'bank_transfer';
            $db->prepare("UPDATE payment_vouchers SET payment_date=CURDATE(), payment_reference=?, payment_method=?, status='paid', updated_at=NOW() WHERE id=?")
               ->execute([$payRef, $payMethod, $id]);
            if ($v['budget_id']) {
                $db->prepare("UPDATE budgets SET spent_amount=spent_amount+? WHERE id=?")
                   ->execute([$v['amount'], $v['budget_id']]);
            }
        }

        // Log approval
        $db->prepare("INSERT INTO voucher_approvals (voucher_id,approver_id,approver_role,approval_stage,action,comments,ip_address) VALUES (?,?,?,?,?,?,?)")
           ->execute([$id, $user['id'], $user['role_name'], $map['stage'], $approvalAction,
                      $action === 'return_voucher' ? $returnNote : $comment, getClientIP()]);

        logAudit(strtoupper($action), 'expenditure', 'voucher', $id, $v['voucher_number']);

        // In-app notification to preparer
        $notifMsg   = "Voucher {$v['voucher_number']} has been {$approvalAction}.";
        $notifType  = $approvalAction === 'approved' ? 'success' : ($approvalAction === 'returned' ? 'warning' : 'danger');
        sendNotification($v['prepared_by'], "Voucher {$approvalAction}: {$v['voucher_number']}", $notifMsg, $notifType, 'expenditure', $id);

        // ── EMAIL triggers ────────────────────────────────────────
        $vFresh    = loadVoucher($db, $id);
        $preparer  = ['full_name' => $vFresh['preparer'], 'email' => $vFresh['preparer_email']];

        if ($action === 'hod_approve') {
            emailVoucherHodApproved($vFresh, $user);
            // Notify TC via in-app too
            $tcs = $db->query("SELECT id FROM users u JOIN roles r ON u.role_id=r.id WHERE r.slug='town_clerk' AND u.is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tcs as $tcId) sendNotification($tcId, "Voucher Awaiting TC Approval", "Voucher {$v['voucher_number']} needs Town Clerk approval.", 'approval', 'expenditure', $id);
        } elseif ($action === 'tc_approve') {
            emailVoucherTcApproved($vFresh, $user);
            $fos = $db->query("SELECT id FROM users u JOIN roles r ON u.role_id=r.id WHERE r.slug='finance_officer' AND u.is_active=1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($fos as $foId) sendNotification($foId, "Voucher Awaiting Finance Verification", "Voucher {$v['voucher_number']} needs finance verification.", 'approval', 'expenditure', $id);
        } elseif ($action === 'mark_paid') {
            emailVoucherPaid($vFresh, $preparer);
        } elseif ($action === 'return_voucher') {
            emailVoucherReturned($vFresh, $preparer, $returnNote);
        }

        // Process the email queue non-blocking (max 5 per request)
        processEmailQueue(5);

        // ── SMS triggers ──────────────────────────────────────────
        $preparerPhone = $db->prepare("SELECT phone FROM users WHERE id=?");
        $preparerPhone->execute([$vFresh['prepared_by']]); $prepPhone = $preparerPhone->fetchColumn();
        if ($prepPhone) {
            if ($action === 'mark_paid') {
                smsVoucherApproval($prepPhone, $vFresh['preparer'], $vFresh['voucher_number'], 'PAID');
            } elseif ($action === 'return_voucher') {
                smsVoucherApproval($prepPhone, $vFresh['preparer'], $vFresh['voucher_number'], 'returned for correction', $returnNote);
            } elseif ($action === 'reject') {
                smsVoucherApproval($prepPhone, $vFresh['preparer'], $vFresh['voucher_number'], 'rejected', $comment);
            }
        }
        processSmsQueue(3);

        setFlash('success', "Voucher {$approvalAction} successfully.");
        header('Location: voucher_view.php?id=' . $id); exit;
    }
}

// Reload fresh
$v = loadVoucher($db, $id);
if (!$v) { setFlash('danger', 'Voucher not found.'); header('Location: vouchers.php'); exit; }

// Load approvals & docs
$appStmt = $db->prepare("SELECT va.*, u.full_name AS approver_name, u.designation
    FROM voucher_approvals va JOIN users u ON va.approver_id=u.id
    WHERE va.voucher_id=? ORDER BY va.approved_at");
$appStmt->execute([$id]); $approvals = $appStmt->fetchAll();

$docs = $db->prepare("SELECT d.*, u.full_name AS uploader_name FROM documents d LEFT JOIN users u ON d.uploaded_by=u.id WHERE d.related_module='voucher' AND d.related_id=? ORDER BY d.uploaded_at DESC");
$docs->execute([$id]); $docs = $docs->fetchAll();

// Supporting data for correction form
$budgets   = $db->query("SELECT b.*, d.name AS dept_name FROM budgets b JOIN departments d ON b.department_id=d.id WHERE b.status IN ('approved','active') ORDER BY d.name")->fetchAll();
$fSources  = $db->query("SELECT * FROM funding_sources WHERE is_active=1 ORDER BY name")->fetchAll();

$councilName = getSystemSetting('council_name') ?? 'Town Council';
$isReturned  = $v['status'] === 'returned';
$canCorrect  = $isReturned && ($v['prepared_by'] == $user['id'] || hasRole(['admin', 'hod']));

renderHead('Voucher ' . $v['voucher_number']);
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Payment Voucher', $v['voucher_number']);
renderPageStart('Payment Voucher ' . $v['voucher_number'], '', [
    ['url' => APP_URL . '/dashboard.php',                  'label' => 'Dashboard'],
    ['url' => APP_URL . '/modules/expenditure/vouchers.php','label' => 'Vouchers'],
    ['url' => '#',                                          'label' => $v['voucher_number']],
]);
$vId = $v['id'];
renderPageActions('<button class="btn btn-primary no-print" data-print>🖨 Print</button>
  <a href="voucher_pdf.php?id='.$vId.'" target="_blank" class="btn btn-outline-primary no-print">📄 PDF Voucher</a>
  <a href="vouchers.php" class="btn btn-outline-secondary no-print">← Back</a>');
renderFlashMessages();
?>

<?php if ($isReturned): ?>
<div class="alert alert-warning">
  <span>↩</span>
  <div>
    <strong>This voucher was returned for corrections.</strong>
    <?php if ($v['correction_note']): ?>
      Reason: <?= htmlspecialchars($v['correction_note']) ?>
    <?php endif; ?>
    <?php if ($v['correction_count'] > 0): ?>
      <span class="badge badge-secondary" style="margin-left:.5rem;">Correction #<?= $v['correction_count'] + 1 ?></span>
    <?php endif; ?>
  </div>
  <?php if ($canCorrect): ?>
  <button class="btn btn-sm btn-warning" data-modal="correctVoucherModal" style="margin-left:auto;">Edit & Correct</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid-3" style="align-items:start;">

  <!-- ── Left: Voucher details + documents ── -->
  <div style="grid-column:span 2;">

    <div class="card" style="margin-bottom:1rem;">
      <div class="card-header">
        <h5><span class="ch-icon">▤</span> Voucher Details</h5>
        <?= getStatusBadge($v['status']) ?>
      </div>
      <div class="card-body">
        <!-- Print header -->
        <div style="text-align:center;border-bottom:2px solid var(--primary);padding-bottom:1rem;margin-bottom:1.2rem;">
          <div style="width:54px;height:54px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .6rem;color:var(--accent);font-weight:800;font-size:1.1rem;">TC</div>
          <h3 style="font-size:1.1rem;font-weight:800;color:var(--primary);"><?= htmlspecialchars($councilName) ?></h3>
          <p style="font-size:.9rem;font-weight:700;color:var(--accent);letter-spacing:2px;text-transform:uppercase;margin-top:.3rem;">PAYMENT VOUCHER</p>
        </div>

        <div class="grid-2" style="gap:.5rem;">
          <div class="receipt-row"><span class="text-muted fs-sm">Voucher No:</span><strong class="text-primary"><?= htmlspecialchars($v['voucher_number']) ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Date:</span><strong><?= formatDate($v['prepared_date']) ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Financial Year:</span><strong><?= $v['financial_year'] ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Department:</span><strong><?= htmlspecialchars($v['dept_name']) ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Payee Name:</span><strong><?= htmlspecialchars($v['payee_name']) ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Payee Contact:</span><strong><?= htmlspecialchars($v['payee_contact'] ?? '—') ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Budget Line:</span><strong><?= htmlspecialchars($v['budget_category'] ?? '—') ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Funding Source:</span><strong><?= htmlspecialchars($v['source_name'] ?? '—') ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Account Code:</span><strong><?= htmlspecialchars($v['account_code'] ?? '—') ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Prepared By:</span><strong><?= htmlspecialchars($v['preparer']) ?></strong></div>
          <?php if ($v['payment_reference']): ?>
          <div class="receipt-row"><span class="text-muted fs-sm">Payment Reference:</span><strong><?= htmlspecialchars($v['payment_reference']) ?></strong></div>
          <div class="receipt-row"><span class="text-muted fs-sm">Payment Date:</span><strong><?= formatDate($v['payment_date']) ?></strong></div>
          <?php endif; ?>
          <?php if ($v['correction_count'] > 0): ?>
          <div class="receipt-row"><span class="text-muted fs-sm">Corrections:</span>
            <strong class="text-warning"><?= $v['correction_count'] ?> time(s)
              <?php if ($v['original_amount'] && $v['original_amount'] != $v['amount']): ?>
              (Original: UGX <?= number_format($v['original_amount']) ?>)
              <?php endif; ?>
            </strong>
          </div>
          <?php endif; ?>
        </div>

        <div class="receipt-row" style="margin-top:.5rem;"><span class="text-muted fs-sm">Description:</span></div>
        <p style="background:#f8f9fa;padding:.8rem;border-radius:4px;font-size:.88rem;margin:.3rem 0 1rem;"><?= htmlspecialchars($v['description']) ?></p>

        <div style="text-align:center;background:var(--primary);color:#fff;padding:1rem;border-radius:6px;margin:1rem 0;">
          <div style="font-size:.78rem;opacity:.8;margin-bottom:.2rem;">AMOUNT</div>
          <div style="font-size:1.8rem;font-weight:800;"><?= CURRENCY ?> <?= number_format($v['amount'], 0) ?></div>
          <div style="font-size:.78rem;opacity:.8;margin-top:.2rem;font-style:italic;"><?= numberToWords($v['amount']) ?></div>
        </div>

        <!-- Signature blocks (print only) -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-top:2rem;">
          <?php foreach (['HOD / Preparer', 'Town Clerk', 'Finance Officer'] as $sig): ?>
          <div style="text-align:center;">
            <div style="height:45px;border-bottom:1px solid #333;margin-bottom:.4rem;"></div>
            <div style="font-size:.75rem;color:#6c757d;"><?= $sig ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Documents -->
    <div class="card">
      <div class="card-header">
        <h5><span class="ch-icon">▧</span> Supporting Documents (<?= count($docs) ?>)</h5>
        <button class="btn btn-sm btn-outline-primary no-print" data-modal="uploadDocModal">+ Attach</button>
      </div>
      <div class="card-body p-0">
        <?php if ($docs): ?>
        <div class="table-wrapper">
          <table class="tcms-table">
            <thead><tr><th>Title</th><th>Type</th><th>Size</th><th>Uploaded By</th><th>Date</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($docs as $doc): ?>
            <tr>
              <td class="fw-600"><?= htmlspecialchars($doc['title']) ?></td>
              <td><span class="badge badge-info"><?= strtoupper($doc['file_type'] ?? '—') ?></span></td>
              <td class="fs-sm"><?= $doc['file_size'] ? round($doc['file_size'] / 1024) . ' KB' : '—' ?></td>
              <td class="fs-sm"><?= htmlspecialchars($doc['uploader_name'] ?? '—') ?></td>
              <td class="fs-sm"><?= formatDateTime($doc['uploaded_at']) ?></td>
              <td>
                <a href="<?= APP_URL ?>/uploads/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
                <a href="<?= APP_URL ?>/uploads/<?= htmlspecialchars($doc['file_path']) ?>" download class="btn btn-sm btn-outline-secondary">↓</a>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state" style="padding:1.5rem;"><p>No documents attached yet.</p></div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- ── Right: Workflow + Actions ── -->
  <div>

    <!-- Approval Timeline -->
    <div class="card" style="margin-bottom:1rem;">
      <div class="card-header"><h5><span class="ch-icon">▥</span> Approval Workflow</h5></div>
      <div class="card-body">
        <?php
        $stages = [
            ['key' => 'draft',            'label' => 'Draft'],
            ['key' => 'submitted',        'label' => 'Submitted'],
            ['key' => 'hod_approved',     'label' => 'HOD Approved'],
            ['key' => 'tc_approved',      'label' => 'Town Clerk Approved'],
            ['key' => 'finance_verified', 'label' => 'Finance Verified'],
            ['key' => 'finance_cleared',  'label' => 'Finance Cleared'],
            ['key' => 'paid',             'label' => 'Payment Made'],
            ['key' => 'completed',        'label' => 'Completed'],
        ];
        $statusOrder = array_column($stages, 'key');
        $currentIdx  = (int)array_search($v['status'], $statusOrder);
        $stageApprovals = [];
        foreach ($approvals as $ap) {
            $stageApprovals[$ap['approval_stage']][] = $ap;
        }
        $stageToLabel = [
            'hod'           => 'HOD Approved',
            'town_clerk'    => 'Town Clerk Approved',
            'finance_verify'=> 'Finance Verified',
            'finance_clear' => 'Finance Cleared',
            'payment'       => 'Payment Made',
        ];
        ?>
        <div class="approval-timeline">
          <?php foreach ($stages as $i => $st):
            $dot = $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'pending' : 'waiting');
            if (in_array($v['status'], ['rejected','returned','cancelled'])) $dot = $i < $currentIdx ? 'done' : 'waiting';
          ?>
          <div class="timeline-item">
            <div class="timeline-dot <?= $dot ?>"><?= $dot === 'done' ? '✓' : ($dot === 'pending' ? '●' : '○') ?></div>
            <div class="timeline-content">
              <div class="stage"><?= htmlspecialchars($st['label']) ?></div>
              <?php foreach ($approvals as $ap):
                if (($stageToLabel[$ap['approval_stage']] ?? '') === $st['label']): ?>
                <div class="meta"><?= htmlspecialchars($ap['approver_name']) ?> &mdash; <?= formatDateTime($ap['approved_at']) ?></div>
                <?php if ($ap['comments']): ?><div class="comment"><?= htmlspecialchars($ap['comments']) ?></div><?php endif; ?>
              <?php endif; endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>

          <?php if (in_array($v['status'], ['rejected', 'returned', 'cancelled'])): ?>
          <div class="timeline-item">
            <div class="timeline-dot rejected">✕</div>
            <div class="timeline-content">
              <div class="stage" style="color:var(--danger);"><?= ucfirst($v['status']) ?></div>
              <?php if ($v['rejection_reason']): ?><div class="comment"><?= htmlspecialchars($v['rejection_reason']) ?></div><?php endif; ?>
              <?php if ($v['correction_note'] && $v['status'] === 'returned'): ?><div class="comment text-warning"><?= htmlspecialchars($v['correction_note']) ?></div><?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Action Panel -->
    <?php if (!in_array($v['status'], ['completed', 'cancelled'])): ?>
    <div class="card no-print">
      <div class="card-header"><h5><span class="ch-icon">◉</span> Take Action</h5></div>
      <div class="card-body">
        <?php

        // ── Submit (draft) ────────────────────────────────────────
        if ($v['status'] === 'draft' && ($v['prepared_by'] == $user['id'] || hasRole(['admin']))): ?>
          <p class="fs-sm text-muted" style="margin-bottom:.8rem;">Submit this voucher to start the approval process.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="submit_voucher">
            <button type="submit" class="btn btn-primary btn-block" data-confirm="Submit voucher for HOD approval?">Submit for Approval</button>
          </form>

        <?php // ── HOD Approve ───────────────────────────────────
        elseif ($v['status'] === 'submitted' && hasRole(['admin', 'hod'])): ?>
          <p class="fs-sm text-muted" style="margin-bottom:.8rem;">Review and approve this voucher as Head of Department.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="hod_approve">
            <div class="form-group"><label class="form-label">Comment (optional)</label><textarea name="comment" class="form-control" rows="2" placeholder="Add a comment..."></textarea></div>
            <div style="display:flex;gap:.5rem;">
              <button type="submit" class="btn btn-success" style="flex:1;">✓ Approve</button>
            </div>
          </form>
          <form method="POST" style="margin-top:.5rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <div class="form-group" style="margin-bottom:.5rem;"><label class="form-label">Reason for rejection <span class="req">*</span></label><textarea name="comment" class="form-control" rows="2" required></textarea></div>
            <button type="submit" class="btn btn-danger btn-block" data-confirm="Reject this voucher?">✕ Reject</button>
          </form>

        <?php // ── Town Clerk Approve ────────────────────────────
        elseif ($v['status'] === 'hod_approved' && hasRole(['admin', 'town_clerk'])): ?>
          <p class="fs-sm text-muted" style="margin-bottom:.8rem;">Town Clerk review and approval.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="tc_approve">
            <div class="form-group"><label class="form-label">Comment (optional)</label><textarea name="comment" class="form-control" rows="2"></textarea></div>
            <div style="display:flex;gap:.5rem;">
              <button type="submit" class="btn btn-success" style="flex:1;">✓ Approve</button>
            </div>
          </form>
          <form method="POST" style="margin-top:.5rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="return_voucher">
            <div class="form-group" style="margin-bottom:.5rem;"><label class="form-label">Reason for return <span class="req">*</span></label><textarea name="return_reason" class="form-control" rows="2" required placeholder="State what needs to be corrected..."></textarea></div>
            <button type="submit" class="btn btn-warning btn-block">↩ Return for Correction</button>
          </form>

        <?php // ── Finance Verify ────────────────────────────────
        elseif ($v['status'] === 'tc_approved' && hasRole(['admin', 'finance_officer'])): ?>
          <p class="fs-sm text-muted" style="margin-bottom:.8rem;">Finance verification — confirm budget availability, documents and amount.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="finance_verify">
            <div class="form-group"><label class="form-label">Verification notes</label><textarea name="comment" class="form-control" rows="2"></textarea></div>
            <button type="submit" class="btn btn-info btn-block">✓ Verify</button>
          </form>
          <form method="POST" style="margin-top:.5rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="return_voucher">
            <div class="form-group" style="margin-bottom:.5rem;"><label class="form-label">Reason for return <span class="req">*</span></label><textarea name="return_reason" class="form-control" rows="2" required></textarea></div>
            <button type="submit" class="btn btn-warning btn-block">↩ Return for Correction</button>
          </form>

        <?php // ── Finance Clear ─────────────────────────────────
        elseif ($v['status'] === 'finance_verified' && hasRole(['admin', 'finance_officer'])): ?>
          <p class="fs-sm text-muted" style="margin-bottom:.8rem;">Authorize voucher for payment.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="finance_clear">
            <div class="form-group"><label class="form-label">Comment</label><textarea name="comment" class="form-control" rows="2"></textarea></div>
            <button type="submit" class="btn btn-primary btn-block">✓ Clear for Payment</button>
          </form>

        <?php // ── Mark Paid ─────────────────────────────────────
        elseif ($v['status'] === 'finance_cleared' && hasRole(['admin', 'finance_officer'])): ?>
          <p class="fs-sm text-muted" style="margin-bottom:.8rem;">Record payment and mark as paid.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_paid">
            <div class="form-group"><label class="form-label">Payment Method</label>
              <select name="payment_method" class="form-select">
                <option value="bank_transfer">Bank Transfer</option>
                <option value="cheque">Cheque</option>
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile Money</option>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Payment Reference</label><input type="text" name="payment_reference" class="form-control" placeholder="Bank ref / cheque number..."></div>
            <button type="submit" class="btn btn-success btn-block" data-confirm="Mark this voucher as PAID? This will update the budget.">✓ Mark as Paid</button>
          </form>

        <?php // ── Returned — resubmit after correction ──────────
        elseif ($v['status'] === 'returned' && ($v['prepared_by'] == $user['id'] || hasRole(['admin', 'hod']))): ?>
          <p class="fs-sm" style="color:var(--warning);margin-bottom:.8rem;"><strong>↩ Returned for corrections.</strong></p>
          <?php if ($v['correction_note']): ?>
          <div style="background:#fff3cd;border-left:3px solid var(--warning);padding:.7rem .9rem;border-radius:0 4px 4px 0;margin-bottom:.8rem;font-size:.83rem;">
            <strong>Required correction:</strong><br><?= htmlspecialchars($v['correction_note']) ?>
          </div>
          <?php endif; ?>
          <button class="btn btn-warning btn-block" data-modal="correctVoucherModal" style="margin-bottom:.5rem;">✎ Edit &amp; Correct Voucher</button>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="submit_voucher">
            <button type="submit" class="btn btn-primary btn-block" data-confirm="Resubmit this voucher for approval?">↑ Resubmit for Approval</button>
          </form>

        <?php else: ?>
          <p class="text-muted fs-sm">No action available at this stage for your role.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- ── Voucher Correction Modal ───────────────────────────────── -->
<?php if ($canCorrect): ?>
<div class="modal-backdrop" id="correctVoucherModal">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h5>✎ Correct Voucher — <?= htmlspecialchars($v['voucher_number']) ?></h5>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_corrections">
      <div class="modal-body">
        <?php if ($v['correction_note']): ?>
        <div class="alert alert-warning">
          <span>⚠</span><span><strong>Required correction:</strong> <?= htmlspecialchars($v['correction_note']) ?></span>
        </div>
        <?php endif; ?>
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Payee Name <span class="req">*</span></label>
            <input type="text" name="payee_name" class="form-control" required value="<?= htmlspecialchars($v['payee_name']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Payee Contact</label>
            <input type="text" name="payee_contact" class="form-control" value="<?= htmlspecialchars($v['payee_contact'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Amount (UGX) <span class="req">*</span></label>
            <input type="number" name="amount" class="form-control" required min="1" step="1" value="<?= $v['amount'] ?>">
            <?php if ($v['original_amount'] && $v['original_amount'] != $v['amount']): ?>
            <div class="form-text">Original amount: UGX <?= number_format($v['original_amount']) ?></div>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label">Budget Line</label>
            <select name="budget_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($budgets as $b): ?>
              <option value="<?= $b['id'] ?>" <?= $b['id'] == $v['budget_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($b['dept_name'] . ' — ' . $b['budget_category'] . ' (UGX ' . number_format($b['approved_amount']) . ')') ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Funding Source</label>
            <select name="funding_source_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($fSources as $fs): ?>
              <option value="<?= $fs['id'] ?>" <?= $fs['id'] == $v['funding_source_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($fs['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Account Code</label>
            <input type="text" name="account_code" class="form-control" value="<?= htmlspecialchars($v['account_code'] ?? '') ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description / Purpose <span class="req">*</span></label>
          <textarea name="description" class="form-control" rows="3" required><?= htmlspecialchars($v['description']) ?></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Correction Summary <span class="req">*</span></label>
          <textarea name="correction_note" class="form-control" rows="2" required placeholder="Briefly describe what you changed and why..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-warning">Save Corrections</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Upload Document Modal ─────────────────────────────────── -->
<div class="modal-backdrop" id="uploadDocModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Attach Supporting Document</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST" action="<?= APP_URL ?>/modules/documents/upload.php" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="related_module" value="voucher">
      <input type="hidden" name="related_id" value="<?= $v['id'] ?>">
      <input type="hidden" name="redirect" value="<?= APP_URL ?>/modules/expenditure/voucher_view.php?id=<?= $v['id'] ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Document Title <span class="req">*</span></label><input type="text" name="title" class="form-control" required></div>
        <div class="form-group">
          <label class="form-label">Document Type <span class="req">*</span></label>
          <select name="doc_type" class="form-select" required>
            <?php foreach (['invoice'=>'Invoice','voucher'=>'Voucher','receipt'=>'Receipt','contract'=>'Contract','procurement'=>'LPO / Delivery Note','other'=>'Other'] as $v2 => $l): ?>
            <option value="<?= $v2 ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">File <span class="req">*</span></label>
          <input type="file" name="document" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx">
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

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
