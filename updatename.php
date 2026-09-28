<?php
// One-time name update — DELETE after use
require_once __DIR__ . '/config/database.php';
try {
    $db = getDB();
    $updates = [
        ['council_name',    'Kijura Town Council'],
        ['council_address', 'P.O. Box 100, Kijura'],
        ['council_email',   'info@kijuratc.go.ug'],
    ];
    foreach ($updates as [$key, $val]) {
        $db->prepare("UPDATE system_settings SET setting_value=? WHERE setting_key=?")->execute([$val, $key]);
        echo "Updated $key = $val<br>\n";
    }
    echo "<strong>Done. Council name is now Kijura Town Council.</strong><br>\n";
    echo '<a href="/modules/auth/login.php">Go to Login</a>';
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
