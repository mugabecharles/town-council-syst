<?php
/**
 * TCMS Global Search
 * Searches across: payers, receipts, vouchers, projects,
 * documents, staff, meetings, requisitions, assessments.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user  = getCurrentUser();
$db    = getDB();
$q     = trim($_GET['q'] ?? '');
$cat   = $_GET['cat'] ?? 'all';

$results = [];
$total   = 0;

if (strlen($q) >= 2) {
    $like = "%$q%";

    // ── Revenue Payers ───────────────────────────────────────────
    if ($cat === 'all' || $cat === 'payers') {
        $s = $db->prepare("SELECT 'payer' AS type, p.id, p.payer_number AS ref,
            p.full_name AS title, p.business_name AS subtitle,
            p.phone AS meta1, w.name AS meta2, p.status
            FROM payers p LEFT JOIN wards w ON p.ward_id=w.id
            WHERE p.full_name LIKE ? OR p.business_name LIKE ?
               OR p.payer_number LIKE ? OR p.phone LIKE ? OR p.national_id LIKE ?
            ORDER BY p.full_name LIMIT 20");
        $s->execute([$like,$like,$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/revenue/payers.php?search='.urlencode($r['ref']);
            $r['icon'] = '◉';
            $r['cat']  = 'Revenue Payer';
            $results[] = $r; $total++;
        }
    }

    // ── Receipts / Payments ──────────────────────────────────────
    if ($cat === 'all' || $cat === 'receipts') {
        $s = $db->prepare("SELECT 'receipt' AS type, rp.id,
            rp.receipt_number AS ref,
            CONCAT(p.full_name, ' — ', rs.name) AS title,
            CONCAT('UGX ', FORMAT(rp.amount,0)) AS subtitle,
            rp.payment_date AS meta1, rp.payment_method AS meta2,
            rp.status
            FROM revenue_payments rp
            JOIN payers p ON rp.payer_id=p.id
            JOIN revenue_sources rs ON rp.revenue_source_id=rs.id
            WHERE rp.receipt_number LIKE ? OR p.full_name LIKE ?
               OR rp.transaction_reference LIKE ?
            ORDER BY rp.created_at DESC LIMIT 15");
        $s->execute([$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/revenue/receipt.php?ref='.urlencode($r['ref']);
            $r['icon'] = '◇';
            $r['cat']  = 'Receipt';
            $results[] = $r; $total++;
        }
    }

    // ── Payment Vouchers ─────────────────────────────────────────
    if ($cat === 'all' || $cat === 'vouchers') {
        $s = $db->prepare("SELECT 'voucher' AS type, pv.id,
            pv.voucher_number AS ref, pv.payee_name AS title,
            CONCAT('UGX ', FORMAT(pv.amount,0), ' — ', d.name) AS subtitle,
            pv.prepared_date AS meta1, pv.status AS meta2, pv.status
            FROM payment_vouchers pv JOIN departments d ON pv.department_id=d.id
            WHERE pv.voucher_number LIKE ? OR pv.payee_name LIKE ?
               OR pv.description LIKE ? OR pv.payment_reference LIKE ?
            ORDER BY pv.created_at DESC LIMIT 15");
        $s->execute([$like,$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/expenditure/voucher_view.php?id='.$r['id'];
            $r['icon'] = '▤';
            $r['cat']  = 'Payment Voucher';
            $results[] = $r; $total++;
        }
    }

    // ── Revenue Assessments ──────────────────────────────────────
    if ($cat === 'all' || $cat === 'assessments') {
        $s = $db->prepare("SELECT 'assessment' AS type, ra.id,
            ra.assessment_number AS ref,
            CONCAT(p.full_name, ' — ', rs.name) AS title,
            CONCAT('Due: UGX ', FORMAT(ra.balance,0)) AS subtitle,
            ra.financial_year AS meta1, ra.status AS meta2, ra.status
            FROM revenue_assessments ra
            JOIN payers p ON ra.payer_id=p.id
            JOIN revenue_sources rs ON ra.revenue_source_id=rs.id
            WHERE ra.assessment_number LIKE ? OR p.full_name LIKE ?
               OR p.payer_number LIKE ?
            ORDER BY ra.created_at DESC LIMIT 15");
        $s->execute([$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/revenue/assessments.php?search='.urlencode($r['ref']);
            $r['icon'] = '◆';
            $r['cat']  = 'Assessment';
            $results[] = $r; $total++;
        }
    }

    // ── Projects ─────────────────────────────────────────────────
    if ($cat === 'all' || $cat === 'projects') {
        $s = $db->prepare("SELECT 'project' AS type, pr.id,
            pr.project_code AS ref, pr.name AS title,
            CONCAT(d.name, ' — ', COALESCE(pr.location,'')) AS subtitle,
            pr.financial_year AS meta1, pr.status AS meta2, pr.status
            FROM projects pr LEFT JOIN departments d ON pr.department_id=d.id
            WHERE pr.name LIKE ? OR pr.project_code LIKE ?
               OR pr.description LIKE ? OR pr.contractor_name LIKE ?
            ORDER BY pr.created_at DESC LIMIT 10");
        $s->execute([$like,$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/projects/index.php';
            $r['icon'] = '◐';
            $r['cat']  = 'Project';
            $results[] = $r; $total++;
        }
    }

    // ── Documents ────────────────────────────────────────────────
    if ($cat === 'all' || $cat === 'documents') {
        $s = $db->prepare("SELECT 'document' AS type, d.id,
            d.doc_number AS ref, d.title AS title,
            CONCAT(UPPER(COALESCE(d.file_type,'?')), ' — ', COALESCE(dept.name,'General')) AS subtitle,
            d.financial_year AS meta1, d.doc_type AS meta2, 'active' AS status
            FROM documents d LEFT JOIN departments dept ON d.department_id=dept.id
            WHERE d.title LIKE ? OR d.doc_number LIKE ? OR d.description LIKE ?
            ORDER BY d.uploaded_at DESC LIMIT 10");
        $s->execute([$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/documents/index.php?search='.urlencode($r['ref']);
            $r['icon'] = '▧';
            $r['cat']  = 'Document';
            $results[] = $r; $total++;
        }
    }

    // ── Staff ────────────────────────────────────────────────────
    if ($cat === 'all' || $cat === 'staff') {
        $s = $db->prepare("SELECT 'staff' AS type, s.id,
            s.staff_number AS ref, s.full_name AS title,
            CONCAT(COALESCE(s.designation,''), ' — ', d.name) AS subtitle,
            s.phone AS meta1, s.employment_type AS meta2,
            IF(s.is_active,'active','inactive') AS status
            FROM staff s JOIN departments d ON s.department_id=d.id
            WHERE s.full_name LIKE ? OR s.staff_number LIKE ?
               OR s.nssf_number LIKE ? OR s.designation LIKE ?
            ORDER BY s.full_name LIMIT 10");
        $s->execute([$like,$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/admin/payroll.php?tab=staff';
            $r['icon'] = '◉';
            $r['cat']  = 'Staff';
            $results[] = $r; $total++;
        }
    }

    // ── Council Meetings ─────────────────────────────────────────
    if ($cat === 'all' || $cat === 'meetings') {
        $s = $db->prepare("SELECT 'meeting' AS type, m.id,
            m.meeting_number AS ref, m.title AS title,
            CONCAT(m.meeting_type, ' — ', COALESCE(m.venue,'')) AS subtitle,
            m.meeting_date AS meta1, m.status AS meta2, m.status
            FROM council_meetings m
            WHERE m.title LIKE ? OR m.meeting_number LIKE ?
               OR m.chairperson LIKE ? OR m.venue LIKE ?
            ORDER BY m.meeting_date DESC LIMIT 10");
        $s->execute([$like,$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/admin/meetings.php?view='.$r['id'];
            $r['icon'] = '◎';
            $r['cat']  = 'Meeting';
            $results[] = $r; $total++;
        }
    }

    // ── Resolutions ──────────────────────────────────────────────
    if ($cat === 'all' || $cat === 'meetings') {
        $s = $db->prepare("SELECT 'resolution' AS type, r.id,
            r.resolution_number AS ref, r.title AS title,
            SUBSTRING(r.resolution_text,1,80) AS subtitle,
            r.target_date AS meta1, r.status AS meta2, r.status
            FROM council_resolutions r
            WHERE r.title LIKE ? OR r.resolution_number LIKE ?
               OR r.resolution_text LIKE ?
            ORDER BY r.created_at DESC LIMIT 10");
        $s->execute([$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/admin/meetings.php?view='.$r['id'];
            $r['icon'] = '◆';
            $r['cat']  = 'Resolution';
            $results[] = $r; $total++;
        }
    }

    // ── Procurement Requisitions ─────────────────────────────────
    if ($cat === 'all' || $cat === 'procurement') {
        $s = $db->prepare("SELECT 'requisition' AS type, r.id,
            r.req_number AS ref, r.title AS title,
            CONCAT(d.name, ' — UGX ', FORMAT(r.estimated_amount,0)) AS subtitle,
            r.financial_year AS meta1, r.status AS meta2, r.status
            FROM expenditure_requisitions r JOIN departments d ON r.department_id=d.id
            WHERE r.title LIKE ? OR r.req_number LIKE ?
               OR r.supplier_name LIKE ?
            ORDER BY r.created_at DESC LIMIT 10");
        $s->execute([$like,$like,$like]);
        foreach ($s->fetchAll() as $r) {
            $r['url']  = APP_URL.'/modules/expenditure/requisitions.php?view='.$r['id'];
            $r['icon'] = '◑';
            $r['cat']  = 'Requisition';
            $results[] = $r; $total++;
        }
    }

    // Sort all results: exact ref matches first, then title matches
    usort($results, function($a, $b) use ($q) {
        $aExact = stripos($a['ref'], $q) === 0 ? 0 : 1;
        $bExact = stripos($b['ref'], $q) === 0 ? 0 : 1;
        return $aExact - $bExact;
    });
}

// ── JSON mode for live search ─────────────────────────────────────
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    $out = array_slice(array_map(fn($r) => [
        'ref'   => $r['ref'],
        'title' => $r['title'],
        'cat'   => $r['cat'],
        'url'   => $r['url'],
        'icon'  => $r['icon'],
    ], $results), 0, 12);
    echo json_encode($out);
    exit;
}

$cats = ['all'=>'All','payers'=>'Payers','receipts'=>'Receipts','vouchers'=>'Vouchers',
         'assessments'=>'Assessments','projects'=>'Projects','documents'=>'Documents',
         'staff'=>'Staff','meetings'=>'Meetings','procurement'=>'Procurement'];

renderHead('Search');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Global Search', $q ? "Results for: \"$q\"" : 'Search the entire system');
renderPageStart('Global Search','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Search'],
]);
renderPageActions('');
renderFlashMessages();
?>

<!-- Search box -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-body" style="padding:1.2rem;">
    <form method="GET" action="">
      <div style="display:flex;gap:.6rem;align-items:center;">
        <div style="flex:1;position:relative;">
          <input type="text" name="q" id="globalSearchInput" class="form-control"
                 value="<?= htmlspecialchars($q) ?>"
                 placeholder="Search payers, receipts, vouchers, projects, staff, meetings..."
                 autofocus autocomplete="off"
                 style="padding-right:3rem;font-size:1rem;height:44px;">
          <span style="position:absolute;right:1rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.85rem;">🔍</span>
        </div>
        <button type="submit" class="btn btn-primary" style="height:44px;padding:0 1.5rem;">Search</button>
        <?php if ($q): ?><a href="search.php" class="btn btn-outline-secondary" style="height:44px;padding:0 1rem;">Clear</a><?php endif; ?>
      </div>
    </form>

    <!-- Category filter pills -->
    <?php if ($q): ?>
    <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.8rem;">
      <?php foreach ($cats as $key => $label): ?>
      <a href="?q=<?= urlencode($q) ?>&cat=<?= $key ?>"
         style="padding:.25rem .8rem;border-radius:20px;font-size:.78rem;font-weight:600;text-decoration:none;
                background:<?= $cat===$key?'var(--primary)':'#fff' ?>;
                color:<?= $cat===$key?'#fff':'var(--text-muted)' ?>;
                border:1px solid <?= $cat===$key?'var(--primary)':'var(--border)' ?>;">
        <?= $label ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($q): ?>

<!-- Results -->
<?php if ($results): ?>
<div style="margin-bottom:.8rem;">
  <span class="fs-sm text-muted"><strong><?= $total ?></strong> result<?= $total!==1?'s':'' ?> for "<strong><?= htmlspecialchars($q) ?></strong>"</span>
</div>

<?php
// Group by category
$grouped = [];
foreach ($results as $r) { $grouped[$r['cat']][] = $r; }
foreach ($grouped as $catLabel => $catResults):
?>
<div class="card" style="margin-bottom:1rem;">
  <div class="card-header" style="padding:.7rem 1.2rem;">
    <h5 style="font-size:.88rem;margin:0;color:var(--primary);">
      <span style="margin-right:.4rem;"><?= $catResults[0]['icon'] ?></span>
      <?= htmlspecialchars($catLabel) ?>
      <span style="font-weight:400;color:var(--text-muted);margin-left:.3rem;">(<?= count($catResults) ?>)</span>
    </h5>
  </div>
  <div class="card-body p-0">
    <?php foreach ($catResults as $r): ?>
    <a href="<?= htmlspecialchars($r['url']) ?>"
       style="display:flex;align-items:center;gap:1rem;padding:.75rem 1.2rem;
              border-bottom:1px solid #f5f5f5;text-decoration:none;
              transition:background .15s;"
       onmouseover="this.style.background='#f8f9ff'"
       onmouseout="this.style.background=''">
      <div style="width:36px;height:36px;background:rgba(26,58,92,.08);border-radius:8px;
                  display:flex;align-items:center;justify-content:center;
                  font-size:.95rem;flex-shrink:0;color:var(--primary);">
        <?= $r['icon'] ?>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
          <span style="font-weight:700;color:var(--primary);font-size:.82rem;">
            <?= htmlspecialchars($r['ref']) ?>
          </span>
          <?php
          $highlightedTitle = preg_replace(
              '/('.preg_quote(htmlspecialchars($q),'/').')/i',
              '<mark style="background:#fff3cd;padding:0 2px;border-radius:2px;">$1</mark>',
              htmlspecialchars($r['title'])
          );
          ?>
          <span style="font-weight:600;color:var(--text-main);font-size:.9rem;"><?= $highlightedTitle ?></span>
          <?= getStatusBadge($r['status'] ?? 'active') ?>
        </div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-top:.15rem;">
          <?= htmlspecialchars($r['subtitle'] ?? '') ?>
          <?php if ($r['meta1'] ?? ''): ?>
          <span style="margin:0 .4rem;opacity:.4;">·</span><?= htmlspecialchars($r['meta1']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div style="color:var(--text-muted);font-size:1rem;flex-shrink:0;">›</div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php else: ?>
<div class="card">
  <div class="card-body" style="text-align:center;padding:3rem;">
    <div style="font-size:3rem;margin-bottom:1rem;opacity:.3;">🔍</div>
    <h4 style="color:var(--text-main);margin-bottom:.5rem;">No results found</h4>
    <p style="color:var(--text-muted);font-size:.9rem;">
      No records match "<strong><?= htmlspecialchars($q) ?></strong>".
      Try a different search term or select a different category.
    </p>
  </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- Empty state / tips -->
<div class="grid-2">
  <div class="card">
    <div class="card-header"><h5>What you can search</h5></div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
        <?php
        $tips = [
            ['◉','Payer names, Payer IDs, phone numbers'],
            ['◇','Receipt numbers, transaction references'],
            ['▤','Voucher numbers, payee names'],
            ['◆','Assessment references, source names'],
            ['◐','Project names, contractor names'],
            ['▧','Document titles, document numbers'],
            ['◉','Staff names, staff numbers, NSSF'],
            ['◎','Meeting numbers, resolutions'],
        ];
        foreach ($tips as [$icon,$text]): ?>
        <div style="display:flex;align-items:flex-start;gap:.6rem;padding:.5rem;background:#f8f9fa;border-radius:6px;">
          <span style="color:var(--primary);font-size:.9rem;"><?= $icon ?></span>
          <span style="font-size:.8rem;color:var(--text-muted);"><?= $text ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h5>Search Tips</h5></div>
    <div class="card-body" style="font-size:.85rem;line-height:1.9;">
      <div>Type at least <strong>2 characters</strong> to search.</div>
      <div>Search by <strong>ID</strong> for exact results: <code>RCP-2026</code>, <code>PV-2026</code></div>
      <div>Use <strong>phone number</strong> to find a payer quickly.</div>
      <div>Use <strong>Ctrl+K</strong> anywhere to open search.</div>
      <div>Filter by <strong>category</strong> to narrow results.</div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
