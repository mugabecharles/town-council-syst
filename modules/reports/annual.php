<?php
/**
 * TCMS Consolidated Annual Financial Report
 * One-page comprehensive report for the Town Clerk / management.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user   = getCurrentUser();
$db     = getDB();
$fy     = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);
$fyears = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
$council = getSystemSetting('council_name') ?? 'Kijura Town Council';
$councilAddr = getSystemSetting('council_address') ?? '';

// ── FY dates ───────────────────────────────────────────────────────
$fyRow = $db->prepare("SELECT start_date,end_date,status FROM financial_years WHERE year_code=?");
$fyRow->execute([$fy]); $fyRow=$fyRow->fetch();
$fyStart = $fyRow['start_date'] ?? date('Y-07-01');
$fyEnd   = $fyRow['end_date']   ?? date('Y-06-30',strtotime('+1 year'));

// ════════════════════════════════════════════════════════════════════
// ALL DATA IN ONE PASS
// ════════════════════════════════════════════════════════════════════

// ── Revenue Summary ────────────────────────────────────────────────
$revTarget = (float)$db->prepare("SELECT COALESCE(SUM(target_amount),0) FROM revenue_targets WHERE financial_year=?")->execute([$fy])?0:0;
$rtS=$db->prepare("SELECT COALESCE(SUM(target_amount),0) FROM revenue_targets WHERE financial_year=?");$rtS->execute([$fy]);$revTarget=(float)$rtS->fetchColumn();
if(!$revTarget)$revTarget=850000000;
$revCollected=(float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'")->execute([$fy])?0:0;
$rcS=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'");$rcS->execute([$fy]);$revCollected=(float)$rcS->fetchColumn();
$revVoided=(float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='voided'")->execute([$fy])?0:0;
$rvS=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='voided'");$rvS->execute([$fy]);$revVoided=(float)$rvS->fetchColumn();
$revArrears=(float)$db->prepare("SELECT COALESCE(SUM(balance),0) FROM revenue_assessments WHERE financial_year=? AND status IN ('active','partial','overdue')")->execute([$fy])?0:0;
$raS=$db->prepare("SELECT COALESCE(SUM(balance),0) FROM revenue_assessments WHERE financial_year=? AND status IN ('active','partial','overdue')");$raS->execute([$fy]);$revArrears=(float)$raS->fetchColumn();
$collRate=$revTarget>0?round($revCollected/$revTarget*100,1):0;

// Revenue by source
$bySrc=$db->prepare("SELECT rs.name,COALESCE(SUM(rp.amount),0) total,COUNT(rp.id) txns FROM revenue_sources rs LEFT JOIN revenue_payments rp ON rp.revenue_source_id=rs.id AND rp.status='active' AND rp.financial_year=? GROUP BY rs.id ORDER BY total DESC");
$bySrc->execute([$fy]);$bySrc=$bySrc->fetchAll();

// Revenue by ward
$byWard=$db->prepare("SELECT w.name,COALESCE(SUM(rp.amount),0) total,COALESCE(rt.target_amount,0) target FROM wards w LEFT JOIN revenue_payments rp ON rp.ward_id=w.id AND rp.status='active' AND rp.financial_year=? LEFT JOIN revenue_targets rt ON rt.ward_id=w.id AND rt.financial_year=? GROUP BY w.id ORDER BY total DESC");
$byWard->execute([$fy,$fy]);$byWard=$byWard->fetchAll();

// Revenue by month
$byMonth=$db->prepare("SELECT DATE_FORMAT(payment_date,'%b') mon,MONTH(payment_date) mnum,SUM(amount) rev FROM revenue_payments WHERE financial_year=? AND status='active' GROUP BY MONTH(payment_date) ORDER BY MONTH(payment_date)");
$byMonth->execute([$fy]);$byMonth=$byMonth->fetchAll();

// ── Expenditure Summary ────────────────────────────────────────────
$totalBudget=(float)$db->prepare("SELECT COALESCE(SUM(COALESCE(revised_amount,approved_amount)),0) FROM budgets WHERE financial_year=?")->execute([$fy])?0:0;
$tbS=$db->prepare("SELECT COALESCE(SUM(COALESCE(revised_amount,approved_amount)),0) FROM budgets WHERE financial_year=?");$tbS->execute([$fy]);$totalBudget=(float)$tbS->fetchColumn();
$totalSpent=(float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')")->execute([$fy])?0:0;
$tsS=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')");$tsS->execute([$fy]);$totalSpent=(float)$tsS->fetchColumn();
$budgetUtil=$totalBudget>0?round($totalSpent/$totalBudget*100,1):0;

// Expenditure by department
$byDept=$db->prepare("SELECT d.name,COALESCE(SUM(CASE WHEN pv.status IN ('paid','completed') THEN pv.amount ELSE 0 END),0) spent,COALESCE(SUM(COALESCE(b.revised_amount,b.approved_amount)),0) budget FROM departments d LEFT JOIN payment_vouchers pv ON pv.department_id=d.id AND pv.financial_year=? LEFT JOIN budgets b ON b.department_id=d.id AND b.financial_year=? GROUP BY d.id ORDER BY spent DESC");
$byDept->execute([$fy,$fy]);$byDept=$byDept->fetchAll();

// ── Government Funds ───────────────────────────────────────────────
$govtTotal=(float)$db->prepare("SELECT COALESCE(SUM(amount_received),0) FROM government_funds WHERE financial_year=?")->execute([$fy])?0:0;
$gtS=$db->prepare("SELECT COALESCE(SUM(amount_received),0) FROM government_funds WHERE financial_year=?");$gtS->execute([$fy]);$govtTotal=(float)$gtS->fetchColumn();
$govtUtil=(float)$db->prepare("SELECT COALESCE(SUM(amount_utilized),0) FROM government_funds WHERE financial_year=?")->execute([$fy])?0:0;
$guS=$db->prepare("SELECT COALESCE(SUM(amount_utilized),0) FROM government_funds WHERE financial_year=?");$guS->execute([$fy]);$govtUtil=(float)$guS->fetchColumn();
$govtFunds=$db->prepare("SELECT gf.funding_id,gf.programme_name,gf.fund_type,gf.amount_received,gf.amount_utilized,fs.name src FROM government_funds gf JOIN funding_sources fs ON gf.funding_source_id=fs.id WHERE gf.financial_year=? ORDER BY gf.amount_received DESC");
$govtFunds->execute([$fy]);$govtFunds=$govtFunds->fetchAll();

// ── Financial Position ─────────────────────────────────────────────
$totalInflow  = $revCollected + $govtTotal;
$netBalance   = $totalInflow - $totalSpent;

// ── Arrears ────────────────────────────────────────────────────────
$arrearsList=$db->prepare("SELECT p.full_name,p.payer_number,p.phone,w.name ward,rs.name source,ra.balance FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id JOIN revenue_sources rs ON ra.revenue_source_id=rs.id LEFT JOIN wards w ON p.ward_id=w.id WHERE ra.financial_year=? AND ra.balance>0 AND ra.status IN ('active','partial','overdue') ORDER BY ra.balance DESC LIMIT 20");
$arrearsList->execute([$fy]);$arrearsList=$arrearsList->fetchAll();

// ── Projects ───────────────────────────────────────────────────────
$projects=$db->prepare("SELECT p.*,d.name dept FROM projects p LEFT JOIN departments d ON p.department_id=d.id WHERE p.financial_year=? ORDER BY p.budget_amount DESC");
$projects->execute([$fy]);$projects=$projects->fetchAll();
$totalProjBudget=array_sum(array_column($projects,'budget_amount'));
$totalProjSpent =array_sum(array_column($projects,'amount_spent'));

// ── Stats ──────────────────────────────────────────────────────────
$activePayers =(int)$db->query("SELECT COUNT(*) FROM payers WHERE status='active'")->fetchColumn();
$totalReceipts=(int)$db->prepare("SELECT COUNT(*) FROM revenue_payments WHERE financial_year=? AND status='active'")->execute([$fy])?0:0;
$trS=$db->prepare("SELECT COUNT(*) FROM revenue_payments WHERE financial_year=? AND status='active'");$trS->execute([$fy]);$totalReceipts=(int)$trS->fetchColumn();
$totalVouchers=(int)$db->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE financial_year=?")->execute([$fy])?0:0;
$tvS=$db->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE financial_year=?");$tvS->execute([$fy]);$totalVouchers=(int)$tvS->fetchColumn();
$defaulters   =(int)$db->prepare("SELECT COUNT(DISTINCT payer_id) FROM revenue_assessments WHERE financial_year=? AND balance>0")->execute([$fy])?0:0;
$dfS=$db->prepare("SELECT COUNT(DISTINCT payer_id) FROM revenue_assessments WHERE financial_year=? AND balance>0");$dfS->execute([$fy]);$defaulters=(int)$dfS->fetchColumn();

// Chart data
$mLabels=json_encode(array_column($byMonth,'mon'));
$mValues=json_encode(array_column($byMonth,'rev'));
$srcLabels=json_encode(array_column($bySrc,'name'));
$srcValues=json_encode(array_column($bySrc,'total'));
$deptLabels=json_encode(array_column($byDept,'name'));
$deptSpent =json_encode(array_column($byDept,'spent'));
$deptBudget=json_encode(array_column($byDept,'budget'));

$print = isset($_GET['print']);
if ($print) {
    // Strip layout for clean print
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width">
<title>Annual Report <?= $fy ?> — <?= htmlspecialchars($council) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:#fff;color:#1c2b3a;font-size:10.5pt;}
.no-print{display:none!important;}
.page{width:210mm;max-width:100%;margin:0 auto;padding:10mm 15mm;}
.cover{text-align:center;padding:30mm 0 20mm;border-bottom:4px solid #1a3a5c;margin-bottom:10mm;}
.cover .logo{width:72px;height:72px;background:#1a3a5c;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 8mm;font-size:22px;font-weight:900;color:#c8a84b;}
.cover h1{font-size:20pt;font-weight:900;color:#1a3a5c;text-transform:uppercase;letter-spacing:1.5px;}
.cover h2{font-size:13pt;color:#c8a84b;font-weight:700;margin:4px 0;}
.cover p{font-size:9pt;color:#666;margin-top:4px;}
.section-title{font-size:12pt;font-weight:800;color:#1a3a5c;text-transform:uppercase;letter-spacing:1px;border-bottom:2px solid #c8a84b;padding-bottom:4px;margin:8mm 0 4mm;}
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:4mm;margin-bottom:6mm;}
.kpi{background:#f8f9fa;border:1px solid #e0e0e0;border-left:4px solid #1a3a5c;border-radius:4px;padding:4mm;}
.kpi .lbl{font-size:7.5pt;color:#888;text-transform:uppercase;letter-spacing:.5px;}
.kpi .val{font-size:14pt;font-weight:800;color:#1a3a5c;margin:2px 0;}
.kpi .sub{font-size:7.5pt;color:#aaa;}
.kpi.green{border-left-color:#1e7e34;} .kpi.green .val{color:#1e7e34;}
.kpi.red{border-left-color:#bd2130;} .kpi.red .val{color:#bd2130;}
.kpi.gold{border-left-color:#c8a84b;} .kpi.gold .val{color:#c8a84b;}
table{width:100%;border-collapse:collapse;font-size:9pt;margin-bottom:5mm;}
thead th{background:#1a3a5c;color:#fff;padding:4px 8px;text-align:left;font-weight:700;}
thead th.r{text-align:right;}
tbody td{padding:3.5px 8px;border-bottom:1px solid #f0f0f0;}
tbody tr:nth-child(even) td{background:#f9f9f9;}
tfoot th,tfoot td{padding:4px 8px;background:#f0f0f0;font-weight:700;border-top:2px solid #1a3a5c;}
.r{text-align:right;}
.summary-box{background:#1a3a5c;color:#fff;border-radius:6px;padding:6mm;margin:5mm 0;display:grid;grid-template-columns:1fr 1fr 1fr;gap:4mm;text-align:center;}
.summary-box .lbl{font-size:7.5pt;opacity:.7;text-transform:uppercase;letter-spacing:.5px;}
.summary-box .val{font-size:14pt;font-weight:800;}
.summary-box .gold{color:#c8a84b;}
.progress-bar-row{display:flex;align-items:center;gap:4mm;margin-bottom:2mm;font-size:8.5pt;}
.progress-bg{flex:1;height:6px;background:#e9ecef;border-radius:3px;overflow:hidden;}
.progress-fill{height:100%;background:#1a3a5c;border-radius:3px;}
.badge{display:inline-block;padding:1px 6px;border-radius:10px;font-size:7pt;font-weight:700;text-transform:uppercase;}
.badge-ok{background:#d4edda;color:#155724;} .badge-warn{background:#fff3cd;color:#856404;}
.badge-danger{background:#f8d7da;color:#721c24;} .badge-info{background:#d1ecf1;color:#0c5460;}
.sig-row{display:flex;justify-content:space-between;margin-top:15mm;padding-top:6mm;border-top:1px solid #ccc;}
.sig-block{text-align:center;width:42%;}
.sig-line{border-top:1px solid #333;padding-top:4px;font-size:8pt;color:#666;margin-top:20mm;}
.footer-note{font-size:7.5pt;color:#999;text-align:center;margin-top:6mm;border-top:1px dotted #ddd;padding-top:3mm;}
@media print{@page{size:A4;margin:0;} body{padding:0;} .page{padding:8mm 12mm;}}
</style></head><body>
<div class="no-print" style="background:#1a3a5c;padding:10px 16px;display:flex;gap:10px;align-items:center;">
  <span style="color:#fff;font-weight:700;font-family:sans-serif;">Annual Report — <?= htmlspecialchars($fy) ?></span>
  <button onclick="window.print()" style="background:#c8a84b;color:#1a3a5c;border:none;padding:7px 20px;border-radius:4px;font-weight:700;cursor:pointer;margin-left:auto;">🖨 Print / Save PDF</button>
  <a href="annual.php?fy=<?= urlencode($fy) ?>" style="color:rgba(255,255,255,.7);font-size:12px;text-decoration:none;">← Back</a>
</div>
<div class="page">

  <!-- Cover -->
  <div class="cover">
    <div class="logo">TC</div>
    <h1><?= htmlspecialchars($council) ?></h1>
    <h2>Annual Financial Performance Report</h2>
    <p>Financial Year: <strong><?= $fy ?></strong> (<?= formatDate($fyStart) ?> — <?= formatDate($fyEnd) ?>)</p>
    <p>Generated: <?= date('d F Y H:i') ?> by <?= htmlspecialchars($user['full_name']) ?></p>
    <?php if($councilAddr): ?><p><?= htmlspecialchars($councilAddr) ?></p><?php endif; ?>
  </div>

  <!-- Financial Position Summary -->
  <div class="section-title">1. Financial Position Summary</div>
  <div class="summary-box">
    <div><div class="lbl">Total Income</div><div class="val gold">UGX <?= number_format($totalInflow) ?></div></div>
    <div><div class="lbl">Total Expenditure</div><div class="val" style="color:rgba(255,255,255,.85);">UGX <?= number_format($totalSpent) ?></div></div>
    <div><div class="lbl">Net Balance</div><div class="val <?= $netBalance>=0?'gold':'' ?>"><?= $netBalance>=0?'SURPLUS':'DEFICIT' ?> UGX <?= number_format(abs($netBalance)) ?></div></div>
  </div>
  <div class="kpi-row">
    <div class="kpi green"><div class="lbl">Local Revenue</div><div class="val">UGX <?= number_format($revCollected/1000000,1) ?>M</div><div class="sub"><?= $collRate ?>% of target</div></div>
    <div class="kpi gold"><div class="lbl">Govt Funds</div><div class="val">UGX <?= number_format($govtTotal/1000000,1) ?>M</div><div class="sub"><?= $govtTotal>0?round($govtUtil/$govtTotal*100,1):0 ?>% utilized</div></div>
    <div class="kpi red"><div class="lbl">Expenditure</div><div class="val">UGX <?= number_format($totalSpent/1000000,1) ?>M</div><div class="sub"><?= $budgetUtil ?>% of budget</div></div>
    <div class="kpi"><div class="lbl">Revenue Arrears</div><div class="val">UGX <?= number_format($revArrears/1000000,1) ?>M</div><div class="sub"><?= $defaulters ?> defaulters</div></div>
  </div>

  <!-- Revenue Performance -->
  <div class="section-title">2. Revenue Performance</div>
  <table>
    <thead><tr><th>Revenue Source</th><th class="r">Transactions</th><th class="r">Amount Collected (UGX)</th><th class="r">% of Total</th></tr></thead>
    <tbody>
    <?php foreach($bySrc as $s): $pct=$revCollected>0?round($s['total']/$revCollected*100,1):0; ?>
    <tr><td><?=htmlspecialchars($s['name'])?></td><td class="r"><?=number_format($s['txns'])?></td><td class="r"><?=number_format($s['total'])?></td><td class="r"><?=$pct?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>TOTAL</td><td class="r"><?=number_format($totalReceipts)?></td><td class="r"><?=number_format($revCollected)?></td><td class="r">100%</td></tr></tfoot>
  </table>

  <!-- Revenue by Ward -->
  <div class="section-title">3. Revenue by Ward</div>
  <table>
    <thead><tr><th>Ward</th><th class="r">Target (UGX)</th><th class="r">Collected (UGX)</th><th class="r">Balance</th><th class="r">Collection %</th></tr></thead>
    <tbody>
    <?php foreach($byWard as $w): $wpct=$w['target']>0?round($w['total']/$w['target']*100,1):0; ?>
    <tr><td><?=htmlspecialchars($w['name'])?></td><td class="r"><?=$w['target']>0?number_format($w['target']):'—'?></td><td class="r"><?=number_format($w['total'])?></td><td class="r"><?=$w['target']>0?number_format($w['target']-$w['total']):'—'?></td><td class="r"><?=$w['target']>0?"$wpct%":'—'?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>TOTAL</td><td class="r"><?=number_format($revTarget)?></td><td class="r"><?=number_format($revCollected)?></td><td class="r"><?=number_format($revTarget-$revCollected)?></td><td class="r"><?=$collRate?>%</td></tr></tfoot>
  </table>

  <!-- Expenditure by Department -->
  <div class="section-title">4. Expenditure by Department</div>
  <table>
    <thead><tr><th>Department</th><th class="r">Approved Budget</th><th class="r">Expenditure</th><th class="r">Balance</th><th class="r">Utilization</th></tr></thead>
    <tbody>
    <?php foreach($byDept as $d): $upct=$d['budget']>0?round($d['spent']/$d['budget']*100,1):0; ?>
    <tr><td><?=htmlspecialchars($d['name'])?></td><td class="r"><?=$d['budget']>0?number_format($d['budget']):'—'?></td><td class="r"><?=number_format($d['spent'])?></td><td class="r"><?=$d['budget']>0?number_format($d['budget']-$d['spent']):'—'?></td><td class="r"><?=$d['budget']>0?"$upct%":'—'?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>TOTAL</td><td class="r"><?=number_format($totalBudget)?></td><td class="r"><?=number_format($totalSpent)?></td><td class="r"><?=number_format($totalBudget-$totalSpent)?></td><td class="r"><?=$budgetUtil?>%</td></tr></tfoot>
  </table>

  <!-- Government Funds -->
  <?php if($govtFunds): ?>
  <div class="section-title">5. Government Funds</div>
  <table>
    <thead><tr><th>Fund ID</th><th>Source/Programme</th><th>Type</th><th class="r">Received</th><th class="r">Utilized</th><th class="r">Balance</th></tr></thead>
    <tbody>
    <?php foreach($govtFunds as $gf): ?>
    <tr><td><?=htmlspecialchars($gf['funding_id'])?></td><td><?=htmlspecialchars($gf['programme_name']??$gf['src'])?></td><td><span class="badge badge-info"><?=ucwords(str_replace('_',' ',$gf['fund_type']))?></span></td><td class="r"><?=number_format($gf['amount_received'])?></td><td class="r"><?=number_format($gf['amount_utilized'])?></td><td class="r"><?=number_format($gf['amount_received']-$gf['amount_utilized'])?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="3">TOTAL</td><td class="r"><?=number_format($govtTotal)?></td><td class="r"><?=number_format($govtUtil)?></td><td class="r"><?=number_format($govtTotal-$govtUtil)?></td></tr></tfoot>
  </table>
  <?php endif; ?>

  <!-- Revenue Arrears -->
  <?php if($arrearsList): ?>
  <div class="section-title">6. Top Revenue Arrears</div>
  <table>
    <thead><tr><th>Payer</th><th>ID</th><th>Ward</th><th>Revenue Source</th><th class="r">Outstanding (UGX)</th></tr></thead>
    <tbody>
    <?php foreach($arrearsList as $a): ?>
    <tr><td><?=htmlspecialchars($a['full_name'])?></td><td><?=htmlspecialchars($a['payer_number'])?></td><td><?=htmlspecialchars($a['ward']??'—')?></td><td><?=htmlspecialchars($a['source'])?></td><td class="r"><?=number_format($a['balance'])?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4">TOTAL ARREARS</td><td class="r"><?=number_format($revArrears)?></td></tr></tfoot>
  </table>
  <?php endif; ?>

  <!-- Projects -->
  <?php if($projects): ?>
  <div class="section-title">7. Projects</div>
  <table>
    <thead><tr><th>Project</th><th>Department</th><th>Status</th><th class="r">Budget</th><th class="r">Spent</th><th class="r">Progress</th></tr></thead>
    <tbody>
    <?php foreach($projects as $p): ?>
    <tr><td><?=htmlspecialchars($p['name'])?></td><td class="r"><?=htmlspecialchars($p['dept']??'—')?></td><td><span class="badge badge-<?=$p['status']==='completed'?'ok':($p['status']==='active'?'info':'warn')?>"><?=ucfirst($p['status'])?></span></td><td class="r"><?=number_format($p['budget_amount'])?></td><td class="r"><?=number_format($p['amount_spent'])?></td><td class="r"><?=$p['progress_percent']?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="3">TOTAL</td><td class="r"><?=number_format($totalProjBudget)?></td><td class="r"><?=number_format($totalProjSpent)?></td><td class="r">—</td></tr></tfoot>
  </table>
  <?php endif; ?>

  <!-- Signatures -->
  <div class="sig-row">
    <div class="sig-block"><div class="sig-line">Finance Officer / CFO<br><?= htmlspecialchars($council) ?></div></div>
    <div class="sig-block" style="width:20%"><div style="margin-top:20mm;text-align:center;font-size:8pt;color:#aaa;">Official Stamp</div></div>
    <div class="sig-block"><div class="sig-line">Town Clerk<br><?= htmlspecialchars($council) ?></div></div>
  </div>

  <div class="footer-note">
    <?= htmlspecialchars($council) ?> | Annual Financial Performance Report | FY <?= $fy ?><br>
    This report was generated electronically by the TCMS on <?= date('d F Y') ?> and is subject to audit verification.
  </div>
</div>
</body></html>
    <?php exit; }

// ── Normal page (preview + actions) ──────────────────────────────
renderHead('Annual Report');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Consolidated Annual Report','Full financial performance report for the financial year');
renderPageStart('Annual Financial Report','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>APP_URL.'/modules/reports/finance.php','label'=>'Finance Reports'],
    ['url'=>'#','label'=>'Annual Report'],
]);
$fyOpts='';foreach($fyears as $y)$fyOpts.="<option value='$y'".($y===$fy?' selected':'').">$y</option>";
renderPageActions("
  <form method='GET' style='display:flex;gap:.5rem;align-items:center;'>
    <select name='fy' class='form-select' onchange='this.form.submit()' style='width:130px;'>$fyOpts</select>
  </form>
  <a href='annual.php?fy=".urlencode($fy)."&print=1' target='_blank' class='btn btn-primary'>🖨 Open Print Report</a>
  <a href='".APP_URL."/api/export.php?type=dept_expenditure&fy=".urlencode($fy)."' class='btn btn-outline-secondary'>📊 Export Excel</a>
");
renderFlashMessages();
?>

<!-- Summary cards -->
<div class="grid-5" style="margin-bottom:1.2rem;">
  <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Revenue Collected</div><div class="value"><?=number_format($revCollected/1000000,1)?>M</div><div class="sub up"><?=$collRate?>% of target</div></div></div>
  <div class="stat-card gold"><div class="stat-icon">🏛</div><div class="stat-info"><div class="label">Govt Funds</div><div class="value"><?=number_format($govtTotal/1000000,1)?>M</div><div class="sub"><?=$govtTotal>0?round($govtUtil/$govtTotal*100,1):0?>% utilized</div></div></div>
  <div class="stat-card red"><div class="stat-icon">📤</div><div class="stat-info"><div class="label">Expenditure</div><div class="value"><?=number_format($totalSpent/1000000,1)?>M</div><div class="sub"><?=$budgetUtil?>% of budget</div></div></div>
  <div class="stat-card <?=$netBalance>=0?'green':'red'?>"><div class="stat-icon">⚖</div><div class="stat-info"><div class="label">Net Balance</div><div class="value"><?=number_format(abs($netBalance)/1000000,1)?>M</div><div class="sub <?=$netBalance>=0?'up':'down'?>"><?=$netBalance>=0?'▲ Surplus':'▼ Deficit'?></div></div></div>
  <div class="stat-card amber"><div class="stat-icon">⏳</div><div class="stat-info"><div class="label">Arrears</div><div class="value"><?=number_format($revArrears/1000000,1)?>M</div><div class="sub"><?=$defaulters?> defaulters</div></div></div>
</div>

<!-- Charts preview -->
<div class="grid-3" style="margin-bottom:1.2rem;">
  <div class="card" style="grid-column:span 2;"><div class="card-header"><h5><span class="ch-icon">📈</span> Monthly Revenue — FY <?=$fy?></h5></div><div class="card-body"><div class="chart-container" style="height:200px;"><canvas id="annualRevChart"></canvas></div></div></div>
  <div class="card"><div class="card-header"><h5><span class="ch-icon">◎</span> Revenue by Source</h5></div><div class="card-body"><div class="chart-container" style="height:200px;"><canvas id="annualSrcChart"></canvas></div></div></div>
</div>

<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header"><h5><span class="ch-icon">▦</span> Budget vs Expenditure by Department</h5></div>
  <div class="card-body"><div class="chart-container" style="height:200px;"><canvas id="annualDeptChart"></canvas></div></div>
</div>

<div class="alert alert-info">
  <span>ℹ</span>
  <span>Click <strong>Open Print Report</strong> to view the full formatted annual report. Use your browser's <strong>Print → Save as PDF</strong> to export a PDF copy.</span>
</div>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  makeLineChart('annualRevChart',<?=$mLabels?>,[{label:'Revenue (UGX)',data:<?=$mValues?>,borderColor:'#1a3a5c',backgroundColor:'rgba(26,58,92,.07)',fill:true,tension:.4,borderWidth:2.5}]);
  makeDoughnutChart('annualSrcChart',<?=$srcLabels?>,<?=$srcValues?>);
  makeBarChart('annualDeptChart',<?=$deptLabels?>,[
    {label:'Budget',data:<?=$deptBudget?>,backgroundColor:'rgba(26,58,92,.6)',borderRadius:3},
    {label:'Spent', data:<?=$deptSpent?>, backgroundColor:'rgba(200,168,75,.8)',borderRadius:3}
  ]);
});
</script>
<?php renderFooter(); ?>
