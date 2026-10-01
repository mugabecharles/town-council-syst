<?php
/**
 * TCMS - Main Layout Helper
 * Renders page shell: header, sidebar, topbar, content wrapper, footer
 */

require_once __DIR__ . '/../includes/functions.php';

$__user = getCurrentUser();

// Build sidebar nav based on role
function getSidebarNav(array $user): array {
    $role = $user['role_slug'];
    $nav  = [];

    // Dashboard - all roles
    $nav[] = ['section' => 'Main'];
    $nav[] = ['label' => 'Dashboard', 'href' => 'dashboard.php', 'icon' => '⊞', 'module' => 'dashboard'];

    // Revenue
    if (in_array($role, ['admin','town_clerk','finance_officer','revenue_officer','auditor','management'])) {
        $nav[] = ['section' => 'Revenue'];
        $nav[] = ['label' => 'Revenue Dashboard',   'href' => 'modules/revenue/dashboard.php',    'icon' => '◈', 'module' => 'revenue'];
        $nav[] = ['label' => 'Revenue Payers',       'href' => 'modules/revenue/payers.php',       'icon' => '◉', 'module' => 'payers'];
        $nav[] = ['label' => 'Assessments',          'href' => 'modules/revenue/assessments.php',  'icon' => '◆', 'module' => 'assessments'];
        $nav[] = ['label' => 'Payments & Receipts',  'href' => 'modules/revenue/payments.php',     'icon' => '◇', 'module' => 'payments'];
        $nav[] = ['label' => 'Arrears & Defaulters', 'href' => 'modules/revenue/arrears.php',      'icon' => '▲', 'module' => 'arrears'];
        $nav[] = ['label' => 'Demand Notices',       'href' => 'modules/revenue/demand_notice.php', 'icon' => '◆', 'module' => 'demand_notice'];
        $nav[] = ['label' => 'Bulk Import',           'href' => 'modules/revenue/bulk_import.php',   'icon' => '◑', 'module' => 'bulk_import'];
    }

    // Finance & Budget
    if (in_array($role, ['admin','town_clerk','finance_officer','auditor','management'])) {
        $nav[] = ['section' => 'Finance'];
        $nav[] = ['label' => 'Government Funds',  'href' => 'modules/funding/index.php',         'icon' => '◎', 'module' => 'funding'];
        $nav[] = ['label' => 'Budgets',           'href' => 'modules/budget/index.php',          'icon' => '▣', 'module' => 'budget'];
    }

    // Expenditure - HODs also need access
    if (in_array($role, ['admin','town_clerk','finance_officer','hod','auditor'])) {
        if (!in_array(['section' => 'Finance'], $nav)) $nav[] = ['section' => 'Finance'];
        $nav[] = ['label' => 'Payment Vouchers',  'href' => 'modules/expenditure/vouchers.php',  'icon' => '▤', 'module' => 'vouchers'];
        $nav[] = ['label' => 'Requisitions',       'href' => 'modules/expenditure/requisitions.php','icon' => '◑', 'module' => 'requisitions'];
        $nav[] = ['label' => 'Pending Approvals', 'href' => 'modules/expenditure/approvals.php', 'icon' => '▥', 'module' => 'approvals'];
    }

    // Departments
    if (in_array($role, ['admin','town_clerk','finance_officer','hod','auditor','management'])) {
        $nav[] = ['section' => 'Departments'];
        $nav[] = ['label' => 'Departments',       'href' => 'modules/departments/index.php',     'icon' => '▦', 'module' => 'departments'];
    }

    // Projects & Procurement
    if (in_array($role, ['admin','town_clerk','finance_officer','hod','auditor','management'])) {
        $nav[] = ['section' => 'Projects'];
        $nav[] = ['label' => 'Projects',          'href' => 'modules/projects/index.php',        'icon' => '◐', 'module' => 'projects'];
        $nav[] = ['label' => 'Procurement',       'href' => 'modules/procurement/index.php',     'icon' => '◑', 'module' => 'procurement'];
        $nav[] = ['label' => 'Assets Register',   'href' => 'modules/assets_mgmt/index.php',     'icon' => '◒', 'module' => 'assets'];
    }

    // Documents
    $nav[] = ['section' => 'Documents'];
    $nav[] = ['label' => 'Document Library',  'href' => 'modules/documents/index.php',       'icon' => '▧', 'module' => 'documents'];

    // Reports
    $nav[] = ['section' => 'Reports'];
    $nav[] = ['label' => 'Revenue Reports',    'href' => 'modules/reports/revenue.php',   'icon' => '▨', 'module' => 'reports'];
    $nav[] = ['label' => 'Finance Reports',    'href' => 'modules/reports/finance.php',   'icon' => '▩', 'module' => 'reports'];
    $nav[] = ['label' => 'Cash Flow & Intel',  'href' => 'modules/reports/cashflow.php',  'icon' => '◈', 'module' => 'cashflow'];
    if (in_array($role, ['admin','town_clerk','auditor'])) {
        $nav[] = ['label' => 'Audit Reports',  'href' => 'modules/reports/audit.php',     'icon' => '◫', 'module' => 'reports'];
    }

    // Administration
    if (in_array($role, ['admin','town_clerk'])) {
        $nav[] = ['section' => 'Administration'];
        $nav[] = ['label' => 'Users & Roles',     'href' => 'modules/admin/users.php',          'icon' => '◭', 'module' => 'users'];
        $nav[] = ['label' => 'Audit Trail',       'href' => 'modules/audit/index.php',           'icon' => '◮', 'module' => 'audit'];
        $nav[] = ['label' => 'System Settings',   'href' => 'modules/admin/settings.php',        'icon' => '◬', 'module' => 'settings'];
    }

    return $nav;
}

function renderHead(string $title, array $extraCss = []): void {
    $appName = defined('APP_NAME') ? APP_NAME : 'TCMS';
    $base    = defined('APP_URL')  ? APP_URL  : '';
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>{$title} — {$appName}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="{$base}/assets/css/tcms.css">

HTML;
    foreach ($extraCss as $css) echo "  <link rel=\"stylesheet\" href=\"{$css}\">\n";
    echo "</head>\n";
}

function renderSidebar(array $user): void {
    $base    = defined('APP_URL') ? APP_URL : '';
    $current = basename($_SERVER['PHP_SELF']);
    $currentDir = basename(dirname($_SERVER['PHP_SELF']));
    $nav     = getSidebarNav($user);
    $initial = strtoupper(substr($user['full_name'], 0, 1));
    $councilName = getSystemSetting('council_name') ?? 'Town Council';

    echo <<<HTML
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-emblem">TC</div>
    <div class="brand-text">
      <h2>{$councilName}</h2>
      <span>Management System</span>
    </div>
  </div>
  <div class="sidebar-user">
    <div class="user-avatar">{$initial}</div>
    <div class="user-info">
      <div class="name">{$user['full_name']}</div>
      <div class="role">{$user['role_name']}</div>
    </div>
  </div>
  <nav>
HTML;

    foreach ($nav as $item) {
        if (isset($item['section'])) {
            echo "    <div class=\"nav-section\"><div class=\"nav-section-label\">{$item['section']}</div>\n";
            echo "    </div>\n";
            // reopen for links
            echo "    <div>\n";
            continue;
        }
        $href    = $base . '/' . $item['href'];
        $active  = (str_contains($_SERVER['PHP_SELF'], $item['module'])) ? 'active' : '';
        $icon    = $item['icon'];
        $label   = $item['label'];
        echo "      <a href=\"{$href}\" class=\"nav-item-link {$active}\"><span class=\"nav-icon\">{$icon}</span>{$label}</a>\n";
    }

    echo <<<HTML
    </div>
  </nav>
  <div class="sidebar-footer">
    <a href="{$base}/modules/auth/profile.php">⚙ My Profile</a>
    <a href="{$base}/modules/auth/logout.php">⏻ Sign Out</a>
  </div>
</aside>
<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none';"></div>
HTML;
}

function renderTopbar(string $title, string $subtitle = ''): void {
    $base          = defined('APP_URL') ? APP_URL : '';
    $unread        = getUnreadNotificationCount();
    $dotHtml       = $unread > 0 ? '<span class="notif-dot"></span>' : '';
    $notifications = getRecentNotifications(6);
    $notifHtml     = '';
    foreach ($notifications as $n) {
        $typeMap = ['info'=>'ℹ','warning'=>'⚠','success'=>'✓','danger'=>'✕','approval'=>'✔','reminder'=>'◷'];
        $ic = $typeMap[$n['type']] ?? 'ℹ';
        $time = date('d M H:i', strtotime($n['created_at']));
        $notifHtml .= "<div style=\"padding:.6rem .9rem;border-bottom:1px solid #f0f0f0;font-size:.8rem;\"><strong style=\"display:block;\">{$n['title']}</strong><span style=\"color:#6c757d;\">{$n['message']}</span><div style=\"font-size:.72rem;color:#aaa;margin-top:.2rem;\">{$time}</div></div>";
    }
    if (!$notifHtml) $notifHtml = '<div style="padding:1.2rem;text-align:center;color:#aaa;font-size:.84rem;">No notifications</div>';

    echo <<<HTML
<header class="topbar">
  <div class="topbar-left">
    <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">☰</button>
    <div>
      <div class="topbar-title">{$title}</div>
      <div class="topbar-subtitle">{$subtitle}</div>
    </div>
  </div>
  <div class="topbar-right">
    <div style="position:relative;">
      <button class="topbar-btn" id="notifBtn" aria-label="Notifications" onclick="document.getElementById('notifDrop').classList.toggle('show')">
        🔔{$dotHtml}
      </button>
      <div id="notifDrop" style="display:none;position:absolute;right:0;top:44px;width:320px;background:#fff;border:1px solid #dee2e6;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.15);z-index:2000;" class="">
        <div style="padding:.7rem .9rem;border-bottom:1px solid #dee2e6;font-weight:700;font-size:.84rem;display:flex;justify-content:space-between;">
          Notifications <a href="{$base}/api/notifications.php?action=mark_read" style="font-size:.75rem;font-weight:400;">Mark all read</a>
        </div>
        {$notifHtml}
        <div style="padding:.5rem .9rem;text-align:center;"><a href="{$base}/modules/notifications/index.php" style="font-size:.8rem;">View all</a></div>
      </div>
    </div>
  </div>
</header>
<script>document.addEventListener('click',function(e){var d=document.getElementById('notifDrop');if(d&&!e.target.closest('#notifBtn')&&!e.target.closest('#notifDrop'))d.style.display='none';});</script>
HTML;
}

function renderPageStart(string $title, string $subtitle = '', array $breadcrumb = []): void {
    echo '<div class="page-content">' . "\n";
    if ($title) {
        $bcHtml = '';
        if ($breadcrumb) {
            $bcHtml = '<div class="breadcrumb">';
            foreach ($breadcrumb as $i => $crumb) {
                if ($i < count($breadcrumb) - 1) {
                    $bcHtml .= "<a href=\"{$crumb['url']}\">{$crumb['label']}</a> &rsaquo; ";
                } else {
                    $bcHtml .= "<span>{$crumb['label']}</span>";
                }
            }
            $bcHtml .= '</div>';
        }
        echo <<<HTML
<div class="page-header">
  <div>
    <h1>{$title}</h1>
    {$bcHtml}
  </div>
HTML;
        // actions div is left open — caller should close with renderPageActions()
    }
}

function renderPageActions(string $html = ''): void {
    if ($html) {
        echo "<div class=\"page-actions\">{$html}</div>";
    }
    echo "</div>\n"; // close page-header
}

function renderPageEnd(): void {
    echo '</div>' . "\n"; // close page-content
}

function renderFooter(array $scripts = []): void {
    $base = defined('APP_URL') ? APP_URL : '';
    echo <<<HTML
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="{$base}/assets/js/tcms.js"></script>
HTML;
    foreach ($scripts as $s) echo "  <script src=\"{$s}\"></script>\n";
    echo "</body>\n</html>\n";
}

function renderFlashMessages(): void {
    foreach (getFlash() as $f) {
        $type = htmlspecialchars($f['type'], ENT_QUOTES);
        $msg  = htmlspecialchars($f['message'], ENT_QUOTES);
        echo "<div class=\"alert alert-{$type}\" data-auto-dismiss=\"6000\"><span>{$msg}</span><button class=\"alert-close\">✕</button></div>\n";
    }
}
