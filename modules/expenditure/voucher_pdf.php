<?php
/**
 * TCMS Professional Printable Payment Voucher
 * Print-optimised HTML that opens a clean A4 payment voucher.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('Invalid voucher.'); }

$stmt = $db->prepare("SELECT pv.*, d.name AS dept_name, fs.name AS source_name,
    b.budget_category, b.approved_amount AS budget_approved,
    u.full_name AS preparer, u.designation AS preparer_title,
    a.full_name AS approver_name
    FROM payment_vouchers pv
    JOIN departments d ON pv.department_id=d.id
    LEFT JOIN funding_sources fs ON pv.funding_source_id=fs.id
    LEFT JOIN budgets b ON pv.budget_id=b.id
    JOIN users u ON pv.prepared_by=u.id
    LEFT JOIN users a ON pv.approved_by=a.id
    WHERE pv.id=?");
$stmt->execute([$id]); $v=$stmt->fetch();
if (!$v) { http_response_code(404); die('Voucher not found.'); }

// Approvals chain
$approvals=$db->prepare("SELECT va.*,u.full_name,u.designation FROM voucher_approvals va JOIN users u ON va.approver_id=u.id WHERE va.voucher_id=? AND va.action='approved' ORDER BY va.approved_at");
$approvals->execute([$id]); $approvals=$approvals->fetchAll();

// Documents attached
$docs=$db->prepare("SELECT title,file_type FROM documents WHERE related_module='voucher' AND related_id=? AND is_latest=1 ORDER BY uploaded_at DESC");
$docs->execute([$id]); $docs=$docs->fetchAll();

$council     = getSystemSetting('council_name')    ?? 'Kijura Town Council';
$councilAddr = getSystemSetting('council_address') ?? '';
$councilTel  = getSystemSetting('council_phone')   ?? '';

logAudit('PRINT_VOUCHER','expenditure','voucher',$id,$v['voucher_number']);
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8">
<title>Payment Voucher <?=htmlspecialchars($v['voucher_number'])?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:#f4f6f9;padding:20px;color:#1c2b3a;}
.voucher{max-width:740px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,.12);}
.v-header{background:#1a3a5c;padding:18px 28px;border-bottom:5px solid #c8a84b;display:flex;align-items:center;justify-content:space-between;}
.v-logo{width:52px;height:52px;background:#c8a84b;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:900;color:#1a3a5c;}
.v-council{color:#fff;}
.v-council h2{font-size:15px;font-weight:800;line-height:1.2;}
.v-council p{font-size:10px;color:rgba(255,255,255,.65);margin-top:2px;}
.v-doc-title{text-align:right;}
.v-doc-title h3{color:#c8a84b;font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:2px;}
.v-doc-title p{color:rgba(255,255,255,.65);font-size:11px;}
.v-ref-bar{background:#f8f9fb;padding:10px 28px;border-bottom:1px solid #e9ecef;display:flex;justify-content:space-between;align-items:center;}
.v-ref-bar .ref{font-size:18px;font-weight:900;color:#1a3a5c;letter-spacing:.5px;}
.v-ref-bar .status{padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;}
.status-paid{background:#d4edda;color:#155724;} .status-draft{background:#e2e3e5;color:#383d41;}
.status-approved{background:#cce5ff;color:#004085;} .status-pending{background:#fff3cd;color:#856404;}
.v-body{padding:20px 28px;}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:16px;font-size:12px;}
.d-row{display:flex;border-bottom:1px solid #f0f0f0;} .d-row:last-child{border:none;}
.d-lbl{width:40%;padding:7px 12px;background:#f8f9fa;font-weight:600;color:#6c757d;flex-shrink:0;}
.d-val{padding:7px 12px;font-weight:600;word-break:break-word;}
.desc-box{border:1px solid #dee2e6;border-radius:6px;padding:12px 14px;font-size:12px;margin-bottom:16px;background:#fafbfc;}
.desc-box .lbl{font-size:10px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.amount-box{background:#1a3a5c;border-radius:8px;padding:16px 20px;text-align:center;margin-bottom:16px;}
.amount-box .albl{font-size:10px;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:.5px;}
.amount-box .aval{font-size:28px;font-weight:900;color:#fff;margin:4px 0;}
.amount-box .awords{font-size:10px;color:rgba(255,255,255,.6);font-style:italic;}
.docs-list{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;}
.doc-chip{background:#f0f4ff;border:1px solid #c5d0e6;border-radius:4px;padding:3px 8px;font-size:10px;color:#1a3a5c;font-weight:600;}
.sig-section{margin-top:20px;border-top:1px solid #e9ecef;padding-top:16px;}
.sig-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:12px;}
.sig-box{text-align:center;}
.sig-name{font-size:10px;font-weight:700;color:#1a3a5c;margin-bottom:2px;}
.sig-line{border-top:1px solid #333;margin-top:40px;padding-top:4px;}
.sig-role{font-size:9px;color:#888;}
.sig-date{font-size:9px;color:#aaa;margin-top:2px;}
.v-footer{background:#f8f9fb;padding:10px 28px;border-top:1px solid #e9ecef;display:flex;justify-content:space-between;font-size:9px;color:#aaa;}
.no-print{margin-bottom:12px;}
@media print{
  body{background:#fff;padding:0;}
  .no-print{display:none!important;}
  .voucher{box-shadow:none;border-radius:0;max-width:100%;}
  @page{size:A4;margin:12mm 15mm;}
}
</style></head>
<body>
<div class="no-print" style="display:flex;gap:10px;align-items:center;max-width:740px;margin:0 auto 12px;">
  <button onclick="window.print()" style="background:#1a3a5c;color:#fff;border:none;padding:9px 24px;border-radius:6px;font-weight:700;cursor:pointer;font-size:13px;">🖨 Print Voucher</button>
  <a href="voucher_view.php?id=<?=$id?>" style="color:#6c757d;font-size:12px;text-decoration:none;">← Back to Voucher</a>
  <?php if($v['status']==='voided'): ?>
  <span style="background:#f8d7da;color:#721c24;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;">VOIDED — NOT VALID FOR PAYMENT</span>
  <?php endif; ?>
</div>

<div class="voucher">
  <!-- Header -->
  <div class="v-header">
    <div style="display:flex;align-items:center;gap:14px;">
      <div class="v-logo">TC</div>
      <div class="v-council">
        <h2><?=htmlspecialchars($council)?></h2>
        <?php if($councilAddr): ?><p><?=htmlspecialchars($councilAddr)?></p><?php endif; ?>
        <?php if($councilTel):  ?><p>Tel: <?=htmlspecialchars($councilTel)?></p><?php endif; ?>
      </div>
    </div>
    <div class="v-doc-title">
      <h3>Payment Voucher</h3>
      <p>Official Financial Document</p>
    </div>
  </div>

  <!-- Reference bar -->
  <div class="v-ref-bar">
    <div><span style="font-size:11px;color:#6c757d;">Voucher No:</span> <span class="ref"><?=htmlspecialchars($v['voucher_number'])?></span></div>
    <div style="display:flex;align-items:center;gap:10px;">
      <?php
      $statClass=['paid'=>'status-paid','draft'=>'status-draft','completed'=>'status-paid',
                  'finance_cleared'=>'status-approved','tc_approved'=>'status-approved',
                  'hod_approved'=>'status-approved','submitted'=>'status-pending','returned'=>'status-pending'];
      ?>
      <span class="status <?=$statClass[$v['status']]??'status-draft'?>"><?=ucwords(str_replace('_',' ',$v['status']))?></span>
      <span style="font-size:11px;color:#6c757d;">Date: <strong><?=formatDate($v['prepared_date'])?></strong></span>
    </div>
  </div>

  <div class="v-body">

    <!-- Details grid -->
    <div class="detail-grid">
      <div class="d-row"><div class="d-lbl">Department</div><div class="d-val"><?=htmlspecialchars($v['dept_name'])?></div></div>
      <div class="d-row"><div class="d-lbl">Financial Year</div><div class="d-val"><?=$v['financial_year']?></div></div>
      <div class="d-row"><div class="d-lbl">Payee Name</div><div class="d-val fw-800"><?=htmlspecialchars($v['payee_name'])?></div></div>
      <div class="d-row"><div class="d-lbl">Payee Contact</div><div class="d-val"><?=htmlspecialchars($v['payee_contact']??'—')?></div></div>
      <div class="d-row"><div class="d-lbl">Budget Line</div><div class="d-val"><?=htmlspecialchars($v['budget_category']??'—')?></div></div>
      <div class="d-row"><div class="d-lbl">Funding Source</div><div class="d-val"><?=htmlspecialchars($v['source_name']??'—')?></div></div>
      <div class="d-row"><div class="d-lbl">Account Code</div><div class="d-val"><?=htmlspecialchars($v['account_code']??'—')?></div></div>
      <div class="d-row"><div class="d-lbl">Prepared By</div><div class="d-val"><?=htmlspecialchars($v['preparer'])?><?=$v['preparer_title']?' — '.htmlspecialchars($v['preparer_title']):''?></div></div>
      <?php if($v['payment_date']): ?>
      <div class="d-row"><div class="d-lbl">Payment Date</div><div class="d-val"><?=formatDate($v['payment_date'])?></div></div>
      <div class="d-row"><div class="d-lbl">Payment Reference</div><div class="d-val"><?=htmlspecialchars($v['payment_reference']??'—')?></div></div>
      <?php endif; ?>
      <?php if($v['payment_method']): ?>
      <div class="d-row"><div class="d-lbl">Payment Method</div><div class="d-val"><?=ucwords(str_replace('_',' ',$v['payment_method']))?></div></div>
      <?php endif; ?>
    </div>

    <!-- Description -->
    <div class="desc-box">
      <div class="lbl">Purpose / Description</div>
      <div style="font-size:12px;line-height:1.6;"><?=htmlspecialchars($v['description'])?></div>
    </div>

    <!-- Amount -->
    <div class="amount-box">
      <div class="albl">Amount</div>
      <div class="aval">UGX <?=number_format($v['amount'],0)?></div>
      <div class="awords"><?=numberToWords($v['amount'])?></div>
    </div>

    <!-- Supporting documents -->
    <?php if($docs): ?>
    <div style="font-size:10px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Attached Documents</div>
    <div class="docs-list">
      <?php foreach($docs as $doc): ?>
      <span class="doc-chip"><?=htmlspecialchars($doc['title'])?> [<?=strtoupper($doc['file_type']??'?')?>]</span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Approval signatures -->
    <div class="sig-section">
      <div style="font-size:10px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px;">Approval Signatures</div>
      <div class="sig-grid">

        <!-- Preparer -->
        <div class="sig-box">
          <div class="sig-name"><?=htmlspecialchars($v['preparer'])?></div>
          <div class="sig-role">Prepared by</div>
          <div class="sig-line"></div>
          <div class="sig-date"><?=formatDate($v['prepared_date'])?></div>
        </div>

        <!-- HOD -->
        <?php
        $hodApproval   = array_filter($approvals, fn($a)=>$a['approval_stage']==='hod');
        $tcApproval    = array_filter($approvals, fn($a)=>$a['approval_stage']==='town_clerk');
        $finApproval   = array_filter($approvals, fn($a)=>in_array($a['approval_stage'],['finance_verify','finance_clear']));
        $hodA=reset($hodApproval);$tcA=reset($tcApproval);$finA=reset($finApproval);
        ?>
        <div class="sig-box">
          <?php if($hodA): ?><div class="sig-name"><?=htmlspecialchars($hodA['full_name'])?></div><div class="sig-role">Head of Department</div><?php else: ?><div class="sig-name" style="color:#ccc;">Pending</div><div class="sig-role">Head of Department</div><?php endif; ?>
          <div class="sig-line"></div>
          <div class="sig-date"><?=$hodA?formatDate($hodA['approved_at']):'Date: ___________'?></div>
        </div>

        <!-- Town Clerk -->
        <div class="sig-box">
          <?php if($tcA): ?><div class="sig-name"><?=htmlspecialchars($tcA['full_name'])?></div><div class="sig-role">Town Clerk</div><?php else: ?><div class="sig-name" style="color:#ccc;">Pending</div><div class="sig-role">Town Clerk</div><?php endif; ?>
          <div class="sig-line"></div>
          <div class="sig-date"><?=$tcA?formatDate($tcA['approved_at']):'Date: ___________'?></div>
        </div>

        <!-- Finance -->
        <div class="sig-box">
          <?php if($finA): ?><div class="sig-name"><?=htmlspecialchars($finA['full_name'])?></div><div class="sig-role">Finance Officer</div><?php else: ?><div class="sig-name" style="color:#ccc;">Pending</div><div class="sig-role">Finance Officer</div><?php endif; ?>
          <div class="sig-line"></div>
          <div class="sig-date"><?=$finA?formatDate($finA['approved_at']):'Date: ___________'?></div>
        </div>

      </div>
    </div>

  </div>

  <!-- Footer -->
  <div class="v-footer">
    <span><?=htmlspecialchars($council)?> | <?=htmlspecialchars($councilAddr)?></span>
    <span>Voucher: <?=htmlspecialchars($v['voucher_number'])?> | Printed: <?=date('d/m/Y H:i')?></span>
  </div>
</div>
</body></html>
