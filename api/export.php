<?php
/**
 * TCMS Universal Export API
 * Handles CSV/Excel export for all report types.
 * Usage: /api/export.php?type=revenue_payments&format=xlsx&fy=2026/2027
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../lib/excel.php';
requireLogin();

$type   = $_GET['type']   ?? '';
$format = $_GET['format'] ?? 'xlsx';  // xlsx or csv
$fy     = $_GET['fy']     ?? (getSystemSetting('current_financial_year') ?? '2026/2027');
$from   = $_GET['date_from'] ?? '';
$to     = $_GET['date_to']   ?? '';
$deptId = (int)($_GET['dept_id'] ?? 0);
$wardId = (int)($_GET['ward_id'] ?? 0);
$srcId  = (int)($_GET['source_id'] ?? 0);

$db  = getDB();
$xl  = new TcmsExcel('TCMS Export');
$council = getSystemSetting('council_name') ?? 'Kijura Town Council';

logAudit('EXPORT', 'reports', $type, 0, $format);

switch ($type) {

    /* ── Revenue Payments ────────────────────────────────────────── */
    case 'revenue_payments': {
        $where  = ["rp.status='active'"]; $params = [];
        if ($fy)     { $where[] = "rp.financial_year=?"; $params[] = $fy; }
        if ($from)   { $where[] = "rp.payment_date>=?";  $params[] = $from; }
        if ($to)     { $where[] = "rp.payment_date<=?";  $params[] = $to; }
        if ($wardId) { $where[] = "rp.ward_id=?";        $params[] = $wardId; }
        if ($srcId)  { $where[] = "rp.revenue_source_id=?"; $params[] = $srcId; }
        $wSQL = implode(' AND ', $where);

        $rows = $db->prepare("SELECT rp.receipt_number, p.full_name, p.payer_number,
                rs.name AS source, w.name AS ward, rp.amount,
                rp.payment_date, rp.payment_method, rp.transaction_reference,
                u.full_name AS officer
            FROM revenue_payments rp
            JOIN payers p ON rp.payer_id=p.id
            JOIN revenue_sources rs ON rp.revenue_source_id=rs.id
            LEFT JOIN wards w ON rp.ward_id=w.id
            LEFT JOIN users u ON rp.collected_by=u.id
            WHERE $wSQL ORDER BY rp.payment_date DESC");
        $rows->execute($params); $data = $rows->fetchAll();

        $total = array_sum(array_column($data, 'amount'));

        $xl->addSheet('Revenue Payments')
           ->setHeaders(['Receipt No', 'Payer Name', 'Payer ID', 'Revenue Source',
                         'Ward', 'Amount (UGX)', 'Date', 'Method', 'Reference', 'Officer'])
           ->setColStyles(['text','text','text','text','text','currency','text','text','text','text']);

        foreach ($data as $r) {
            $xl->addRow([$r['receipt_number'], $r['full_name'], $r['payer_number'],
                         $r['source'], $r['ward'] ?? '', $r['amount'],
                         $r['payment_date'], ucwords(str_replace('_',' ',$r['payment_method'])),
                         $r['transaction_reference'] ?? '', $r['officer'] ?? '']);
        }
        $xl->addSummaryRow(['', '', '', '', 'TOTAL', $total, '', '', '', '']);

        $filename = "revenue-payments-$fy";
        break;
    }

    /* ── Revenue by Ward ─────────────────────────────────────────── */
    case 'revenue_by_ward': {
        $rows = $db->prepare("SELECT w.name AS ward,
            COALESCE(SUM(rp.amount),0) AS collected,
            COUNT(rp.id) AS transactions
            FROM wards w
            LEFT JOIN revenue_payments rp ON rp.ward_id=w.id AND rp.status='active' AND rp.financial_year=?
            GROUP BY w.id ORDER BY collected DESC");
        $rows->execute([$fy]); $data = $rows->fetchAll();
        $total = array_sum(array_column($data,'collected'));

        $xl->addSheet("Revenue by Ward ($fy)")
           ->setHeaders(['Ward', 'Amount Collected (UGX)', 'Transactions'])
           ->setColStyles(['text','currency','number']);
        foreach ($data as $r) $xl->addRow([$r['ward'], $r['collected'], $r['transactions']]);
        $xl->addSummaryRow(['TOTAL', $total, array_sum(array_column($data,'transactions'))]);
        $filename = "revenue-by-ward-$fy";
        break;
    }

    /* ── Revenue by Source ───────────────────────────────────────── */
    case 'revenue_by_source': {
        $rows = $db->prepare("SELECT rs.name AS source, rs.category,
            COALESCE(SUM(rp.amount),0) AS collected, COUNT(rp.id) AS transactions
            FROM revenue_sources rs
            LEFT JOIN revenue_payments rp ON rp.revenue_source_id=rs.id AND rp.status='active' AND rp.financial_year=?
            GROUP BY rs.id ORDER BY collected DESC");
        $rows->execute([$fy]); $data = $rows->fetchAll();

        $xl->addSheet("Revenue by Source ($fy)")
           ->setHeaders(['Revenue Source', 'Category', 'Amount Collected (UGX)', 'Transactions'])
           ->setColStyles(['text','text','currency','number']);
        foreach ($data as $r) $xl->addRow([$r['source'], $r['category'] ?? '', $r['collected'], $r['transactions']]);
        $xl->addSummaryRow(['TOTAL', '', array_sum(array_column($data,'collected')), array_sum(array_column($data,'transactions'))]);
        $filename = "revenue-by-source-$fy";
        break;
    }

    /* ── Revenue Arrears ─────────────────────────────────────────── */
    case 'revenue_arrears': {
        $rows = $db->prepare("SELECT p.payer_number, p.full_name, p.business_name, p.phone,
            w.name AS ward, rs.name AS source,
            ra.assessment_number, ra.total_due, ra.amount_paid, ra.balance,
            ra.due_date, DATEDIFF(CURDATE(),ra.due_date) AS days_overdue
            FROM revenue_assessments ra
            JOIN payers p ON ra.payer_id=p.id
            JOIN revenue_sources rs ON ra.revenue_source_id=rs.id
            LEFT JOIN wards w ON p.ward_id=w.id
            WHERE ra.financial_year=? AND ra.status IN ('active','partial','overdue') AND ra.balance>0
            ORDER BY ra.balance DESC");
        $rows->execute([$fy]); $data = $rows->fetchAll();

        $xl->addSheet("Revenue Arrears ($fy)")
           ->setHeaders(['Payer ID','Name','Business','Phone','Ward','Revenue Source',
                         'Assessment','Total Due','Paid','Balance','Due Date','Days Overdue'])
           ->setColStyles(['text','text','text','text','text','text','text','currency','currency','currency','text','number']);
        foreach ($data as $r) {
            $xl->addRow([$r['payer_number'],$r['full_name'],$r['business_name']??'',$r['phone']??'',
                         $r['ward']??'',$r['source'],$r['assessment_number'],
                         $r['total_due'],$r['amount_paid'],$r['balance'],
                         $r['due_date']??'',$r['days_overdue']??0]);
        }
        $xl->addSummaryRow(['','','','','','','TOTAL',
            array_sum(array_column($data,'total_due')),
            array_sum(array_column($data,'amount_paid')),
            array_sum(array_column($data,'balance')),'','']);
        $filename = "revenue-arrears-$fy";
        break;
    }

    /* ── Expenditure Vouchers ────────────────────────────────────── */
    case 'vouchers': {
        $where  = ['1=1']; $params = [];
        $where[] = "pv.financial_year=?"; $params[] = $fy;
        if ($deptId) { $where[] = "pv.department_id=?"; $params[] = $deptId; }
        $wSQL = implode(' AND ', $where);

        $rows = $db->prepare("SELECT pv.voucher_number, d.name AS dept, pv.payee_name,
            pv.description, pv.amount, pv.status, pv.prepared_date,
            pv.payment_date, pv.payment_reference, u.full_name AS preparer,
            b.budget_category, fs.name AS funding_source
            FROM payment_vouchers pv
            JOIN departments d ON pv.department_id=d.id
            JOIN users u ON pv.prepared_by=u.id
            LEFT JOIN budgets b ON pv.budget_id=b.id
            LEFT JOIN funding_sources fs ON pv.funding_source_id=fs.id
            WHERE $wSQL ORDER BY pv.created_at DESC");
        $rows->execute($params); $data = $rows->fetchAll();
        $paid = array_filter($data, fn($r) => in_array($r['status'],['paid','completed']));
        $total = array_sum(array_column(iterator_to_array((function() use($paid) { yield from $paid; })()), 'amount'));

        $xl->addSheet("Vouchers ($fy)")
           ->setHeaders(['Voucher No','Department','Payee','Description','Amount','Status',
                         'Prepared','Payment Date','Reference','Prepared By','Budget Line','Funding'])
           ->setColStyles(['text','text','text','text','currency','text','text','text','text','text','text','text']);
        foreach ($data as $r) {
            $xl->addRow([$r['voucher_number'],$r['dept'],$r['payee_name'],
                         substr($r['description'],0,80),$r['amount'],
                         ucwords(str_replace('_',' ',$r['status'])),
                         $r['prepared_date'],$r['payment_date']??'',$r['payment_reference']??'',
                         $r['preparer'],$r['budget_category']??'',$r['funding_source']??'']);
        }
        $xl->addSummaryRow(['','','','TOTAL PAID',$total,'','','','','','','']);
        $filename = "vouchers-$fy";
        break;
    }

    /* ── Department Expenditure ──────────────────────────────────── */
    case 'dept_expenditure': {
        $rows = $db->prepare("SELECT d.name AS dept,
            COALESCE(SUM(CASE WHEN pv.status IN ('paid','completed') THEN pv.amount ELSE 0 END),0) AS spent,
            COUNT(CASE WHEN pv.status IN ('paid','completed') THEN 1 END) AS vouchers,
            COALESCE(SUM(COALESCE(b.revised_amount,b.approved_amount)),0) AS budget
            FROM departments d
            LEFT JOIN payment_vouchers pv ON pv.department_id=d.id AND pv.financial_year=?
            LEFT JOIN budgets b ON b.department_id=d.id AND b.financial_year=?
            GROUP BY d.id ORDER BY spent DESC");
        $rows->execute([$fy,$fy]); $data = $rows->fetchAll();

        $xl->addSheet("Dept Expenditure ($fy)")
           ->setHeaders(['Department','Budget (UGX)','Spent (UGX)','Balance (UGX)','Util %','Vouchers'])
           ->setColStyles(['text','currency','currency','currency','number','number']);
        foreach ($data as $r) {
            $bal  = $r['budget'] - $r['spent'];
            $pct  = $r['budget']>0 ? round($r['spent']/$r['budget']*100,1) : 0;
            $xl->addRow([$r['dept'],$r['budget'],$r['spent'],$bal,$pct,$r['vouchers']]);
        }
        $xl->addSummaryRow(['TOTAL',
            array_sum(array_column($data,'budget')),
            array_sum(array_column($data,'spent')),
            array_sum(array_column($data,'budget'))-array_sum(array_column($data,'spent')),
            '','']);
        $filename = "dept-expenditure-$fy";
        break;
    }

    /* ── Payers Registry ─────────────────────────────────────────── */
    case 'payers': {
        $rows = $db->query("SELECT p.payer_number, p.payer_type, p.full_name, p.business_name,
            p.phone, p.email, w.name AS ward, par.name AS parish, v.name AS village,
            p.status, p.registration_date
            FROM payers p
            LEFT JOIN wards w ON p.ward_id=w.id
            LEFT JOIN parishes par ON p.parish_id=par.id
            LEFT JOIN villages v ON p.village_id=v.id
            ORDER BY p.full_name");
        $data = $rows->fetchAll();

        $xl->addSheet('Revenue Payers')
           ->setHeaders(['Payer ID','Type','Full Name','Business Name','Phone','Email',
                         'Ward','Parish','Village','Status','Registered'])
           ->setColStyles(['text','text','text','text','text','text','text','text','text','text','text']);
        foreach ($data as $r) {
            $xl->addRow([$r['payer_number'],$r['payer_type'],$r['full_name'],$r['business_name']??'',
                         $r['phone']??'',$r['email']??'',$r['ward']??'',$r['parish']??'',
                         $r['village']??'',$r['status'],$r['registration_date']]);
        }
        $filename = 'payers-registry';
        break;
    }

    /* ── Government Funds ────────────────────────────────────────── */
    case 'govt_funds': {
        $rows = $db->prepare("SELECT gf.funding_id, fs.name AS source, gf.ministry_agency,
            gf.programme_name, gf.fund_type, gf.amount_received, gf.amount_utilized,
            (gf.amount_received-gf.amount_utilized) AS balance,
            gf.date_received, gf.reference_number, d.name AS dept, gf.status
            FROM government_funds gf
            JOIN funding_sources fs ON gf.funding_source_id=fs.id
            LEFT JOIN departments d ON gf.department_id=d.id
            WHERE gf.financial_year=? ORDER BY gf.date_received DESC");
        $rows->execute([$fy]); $data = $rows->fetchAll();

        $xl->addSheet("Govt Funds ($fy)")
           ->setHeaders(['Fund ID','Source','Ministry/Agency','Programme','Type',
                         'Received (UGX)','Utilized (UGX)','Balance (UGX)',
                         'Date Received','Reference','Department','Status'])
           ->setColStyles(['text','text','text','text','text','currency','currency','currency','text','text','text','text']);
        foreach ($data as $r) {
            $xl->addRow([$r['funding_id'],$r['source'],$r['ministry_agency']??'',
                         $r['programme_name']??'',ucwords(str_replace('_',' ',$r['fund_type'])),
                         $r['amount_received'],$r['amount_utilized'],$r['balance'],
                         $r['date_received'],$r['reference_number']??'',$r['dept']??'',$r['status']]);
        }
        $xl->addSummaryRow(['','','','','TOTAL',
            array_sum(array_column($data,'amount_received')),
            array_sum(array_column($data,'amount_utilized')),
            array_sum(array_column($data,'balance')),'','','','']);
        $filename = "govt-funds-$fy";
        break;
    }

    default:
        http_response_code(400);
        die("Unknown export type: $type");
}

// Append date to filename
$filename .= '-' . date('Ymd');

if ($format === 'csv') {
    $xl->downloadCsv($filename . '.csv');
} else {
    $xl->download($filename . '.xlsx');
}
