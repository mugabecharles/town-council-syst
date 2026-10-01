<?php
/**
 * TCMS Receipt PDF Export
 * Generates a downloadable/printable PDF receipt.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../lib/pdf.php';
requireLogin();

$ref = trim($_GET['ref'] ?? '');
if (!$ref) { http_response_code(400); die('Invalid receipt reference.'); }

$db   = getDB();
$stmt = $db->prepare("
    SELECT rp.*, p.full_name, p.business_name, p.payer_number, p.address,
           rs.name AS source_name, w.name AS ward_name,
           u.full_name AS officer_name
    FROM revenue_payments rp
    JOIN payers p  ON rp.payer_id = p.id
    JOIN revenue_sources rs ON rp.revenue_source_id = rs.id
    LEFT JOIN wards w ON rp.ward_id = w.id
    LEFT JOIN users u ON rp.collected_by = u.id
    WHERE rp.receipt_number = ?
");
$stmt->execute([$ref]);
$pay = $stmt->fetch();

if (!$pay) { http_response_code(404); die('Receipt not found.'); }

$council  = getSystemSetting('council_name')    ?? 'Kijura Town Council';
$address  = getSystemSetting('council_address') ?? '';
$phone    = getSystemSetting('council_phone')   ?? '';
$baseUrl  = APP_URL;

logAudit('DOWNLOAD_RECEIPT', 'revenue', 'receipt', $pay['id'], $pay['receipt_number']);

// ── Build PDF ─────────────────────────────────────────────────────
$pdf = new TcmsPdf("Receipt {$pay['receipt_number']}");
$pdf->addPage();

$pw  = $pdf->getPageWidth();
$iw  = $pdf->getInnerWidth();
$lm  = 50;

// ── Header background ─────────────────────────────────────────────
$pdf->setFillColor(26, 58, 92);
$pdf->rect(0, 0, $pw, 80, 'F');

// Gold accent bar
$pdf->setFillColor(200, 168, 75);
$pdf->rect(0, 80, $pw, 4, 'F');

// Council logo box
$pdf->imageBox(($pw - 50) / 2, 12, 50, 50, 'TC');

// Council name
$pdf->setFont('', 'B', 14);
$pdf->setTextColor(255, 255, 255);
$pdf->text($lm, 70, $council);

$pdf->setFont('', '', 9);
$pdf->setTextColor(200, 200, 200);
if ($address) $pdf->text($lm, 82, $address);
if ($phone)   $pdf->text($lm, 92, "Tel: $phone");

// OFFICIAL RECEIPT title
$pdf->setFont('', 'B', 16);
$pdf->setTextColor(200, 168, 75);
$title = 'OFFICIAL RECEIPT';
$titleX = ($pw - strlen($title) * 16 * 0.52) / 2;
$pdf->text($titleX > $lm ? $titleX : $lm, 108, $title);

// Voided watermark
if ($pay['status'] === 'voided') {
    $pdf->setFont('', 'B', 48);
    $pdf->setTextColor(189, 33, 48);
    $pdf->text(100, 350, 'VOIDED');
}

$pdf->setY(125);

// ── Receipt number + date row ─────────────────────────────────────
$pdf->setFont('', 'B', 10);
$pdf->setTextColor(26, 58, 92);
$pdf->text($lm, 125, "Receipt No: {$pay['receipt_number']}");
$dateStr = "Date: " . date('d/m/Y', strtotime($pay['payment_date']));
$pdf->text($pw - $lm - strlen($dateStr) * 10 * 0.52, 125, $dateStr);

// Divider
$pdf->setDrawColor(26, 58, 92);
$pdf->setLineWidth(1);
$pdf->line($lm, 130, $pw - $lm, 130);

// ── Payer details table ───────────────────────────────────────────
$pdf->setY(140);
$rows = [
    ['Payer ID',        $pay['payer_number']],
    ['Payer Name',      $pay['full_name']],
];
if ($pay['business_name']) $rows[] = ['Business Name', $pay['business_name']];
if ($pay['ward_name'])     $rows[] = ['Ward',           $pay['ward_name']];
$rows[] = ['Revenue Source',  $pay['source_name']];
$rows[] = ['Financial Year',  $pay['financial_year']];
$rows[] = ['Payment Method',  ucwords(str_replace('_', ' ', $pay['payment_method']))];
if ($pay['transaction_reference']) $rows[] = ['Transaction Ref', $pay['transaction_reference']];
if ($pay['bank_name'])             $rows[] = ['Bank',            $pay['bank_name']];

foreach ($rows as [$label, $value]) {
    $y = $pdf->getCurrentY();
    $pdf->checkPageBreak(12);
    // Alternating row background
    static $rowIdx = 0;
    if ($rowIdx % 2 === 0) {
        $pdf->setFillColor(248, 249, 250);
        $pdf->rect($lm, $y, $iw, 12, 'F');
    }
    $rowIdx++;
    $pdf->setFont('', '', 9);
    $pdf->setTextColor(100, 100, 100);
    $pdf->text($lm + 4, $y + 9, $label . ':');
    $pdf->setFont('', 'B', 9);
    $pdf->setTextColor(30, 30, 30);
    $pdf->text($lm + 140, $y + 9, $value);
    $pdf->setY($y + 12);
}

// ── Amount box ────────────────────────────────────────────────────
$pdf->checkPageBreak(50);
$ay = $pdf->getCurrentY() + 10;
$pdf->setFillColor(26, 58, 92);
$pdf->rect($lm, $ay, $iw, 40, 'F');
$pdf->setFont('', '', 9);
$pdf->setTextColor(200, 200, 200);
$pdf->text(($pw - 30) / 2, $ay + 10, 'AMOUNT PAID');
$amountStr = 'UGX ' . number_format($pay['amount'], 0);
$pdf->setFont('', 'B', 22);
$pdf->setTextColor(255, 255, 255);
$ax = ($pw - strlen($amountStr) * 22 * 0.52) / 2;
$pdf->text($ax > $lm ? $ax : $lm, $ay + 30, $amountStr);
$pdf->setY($ay + 50);

// Amount in words
$pdf->setFont('', '', 8);
$pdf->setTextColor(80, 80, 80);
$words = numberToWords($pay['amount']);
$pdf->text($lm, $pdf->getCurrentY() + 6, $words);
$pdf->setY($pdf->getCurrentY() + 18);

// ── Divider ───────────────────────────────────────────────────────
$pdf->setDrawColor(200, 200, 200);
$pdf->setLineWidth(0.5);
$pdf->line($lm, $pdf->getCurrentY(), $pw - $lm, $pdf->getCurrentY());
$pdf->setY($pdf->getCurrentY() + 15);

// ── Signatures ────────────────────────────────────────────────────
$sigY = $pdf->getCurrentY() + 30;
$sigW = ($iw - 20) / 2;

// Left sig — Revenue Officer
$pdf->setDrawColor(50, 50, 50);
$pdf->setLineWidth(0.5);
$pdf->line($lm, $sigY, $lm + $sigW, $sigY);
$pdf->setFont('', 'B', 8);
$pdf->setTextColor(60, 60, 60);
$pdf->text($lm, $sigY + 8, 'Revenue Officer');
$pdf->setFont('', '', 8);
$pdf->setTextColor(100, 100, 100);
$pdf->text($lm, $sigY + 17, $pay['officer_name'] ?? '');

// Right sig — Authorized Signatory
$rx = $pw - $lm - $sigW;
$pdf->line($rx, $sigY, $rx + $sigW, $sigY);
$pdf->setFont('', 'B', 8);
$pdf->setTextColor(60, 60, 60);
$pdf->text($rx, $sigY + 8, 'Authorized Signatory');
$pdf->setFont('', '', 8);
$pdf->setTextColor(100, 100, 100);
$pdf->text($rx, $sigY + 17, $council);

// QR placeholder box
$qrY = $sigY - 5;
$qrX = ($pw - 50) / 2;
$pdf->setFillColor(240, 240, 240);
$pdf->setDrawColor(180, 180, 180);
$pdf->rect($qrX, $qrY, 50, 50, 'FD');
$pdf->setFont('', '', 7);
$pdf->setTextColor(120, 120, 120);
$pdf->text($qrX + 4, $qrY + 28, 'Scan to verify');
$pdf->text($qrX + 8, $qrY + 36, $pay['receipt_number']);

// Footer
$footerY = $pdf->getPageHeight() - 30;
$pdf->setFillColor(248, 249, 250);
$pdf->rect(0, $footerY - 5, $pw, 35, 'F');
$pdf->setFont('', '', 7);
$pdf->setTextColor(120, 120, 120);
$footerText = "This is an official receipt of $council. Verify at: " . APP_URL . "/verify.php?ref={$pay['receipt_number']}";
$pdf->text($lm, $footerY + 5, $footerText);
$pdf->text($lm, $footerY + 14, "Printed: " . date('d/m/Y H:i:s') . " | Receipt must not be altered.");

$pdf->output('D', "Receipt-{$pay['receipt_number']}.pdf");
