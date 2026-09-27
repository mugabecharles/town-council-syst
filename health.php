<?php
// TCMS Health Check endpoint — used by Render
// Returns 200 OK immediately without requiring DB
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'status'  => 'ok',
    'service' => 'TCMS',
    'time'    => date('Y-m-d H:i:s'),
    'php'     => PHP_VERSION,
]);
