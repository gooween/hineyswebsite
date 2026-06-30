<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/get_delivery_zones.php
// AJAX endpoint — returns delivery-zone data as JSON.
//
//   ?action=municipalities      -> list of active municipalities
//   ?action=barangays&m=Cortes  -> barangays + fee for that municipality
//   ?action=fee&m=Cortes&b=Loreto -> single fee for one zone
// ============================================================

session_start();
require_once '../config/db.php';
requireCustomer();

header('Content-Type: application/json');

$action = trim($_GET['action'] ?? '');

// Fallback flat fee from settings (used when a zone has no row / not found)
function fallbackFee(mysqli $conn): float
{
    $r = $conn->query("SELECT setting_value FROM settings WHERE setting_key='delivery_fee' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) return (float)$row['setting_value'];
    return 50.00;
}

if ($action === 'municipalities') {
    $rows = [];
    $res = $conn->query("SELECT DISTINCT municipality FROM delivery_zones WHERE active=1 ORDER BY municipality ASC");
    while ($r = $res->fetch_assoc()) $rows[] = $r['municipality'];
    echo json_encode(['ok' => true, 'municipalities' => $rows]);
    exit;
}

if ($action === 'barangays') {
    $m = trim($_GET['m'] ?? '');
    if ($m === '') {
        echo json_encode(['ok' => false, 'error' => 'No municipality given.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT barangay, fee FROM delivery_zones WHERE municipality=? AND active=1 ORDER BY barangay ASC");
    $stmt->bind_param('s', $m);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = ['barangay' => $r['barangay'], 'fee' => (float)$r['fee']];
    }
    $stmt->close();
    echo json_encode(['ok' => true, 'barangays' => $rows]);
    exit;
}

if ($action === 'fee') {
    $m = trim($_GET['m'] ?? '');
    $b = trim($_GET['b'] ?? '');
    if ($m === '' || $b === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing zone.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT fee FROM delivery_zones WHERE municipality=? AND barangay=? AND active=1 LIMIT 1");
    $stmt->bind_param('ss', $m, $b);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        echo json_encode(['ok' => true, 'fee' => (float)$row['fee'], 'fallback' => false]);
    } else {
        echo json_encode(['ok' => true, 'fee' => fallbackFee($conn), 'fallback' => true]);
    }
    $stmt->close();
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
