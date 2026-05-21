<?php
// ============================================================
// Hiney's Eggs and Live Chicken Business
// File: user/cart_action.php
// Purpose: AJAX handler for cart add / update / remove
// ============================================================

session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

// Must be logged in as customer
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    echo json_encode(['success' => false, 'message' => 'Please log in to manage your cart.']);
    exit;
}

$uid    = (int)$_SESSION['user_id'];
$action = trim($_POST['action'] ?? '');

// ── Helper: get cart count ────────────────────────────────────
function getCartCount(mysqli $conn, int $uid): int {
    $r = $conn->query("SELECT COALESCE(SUM(quantity),0) AS cnt FROM cart WHERE user_id = {$uid}");
    return $r ? (int)($r->fetch_assoc()['cnt'] ?? 0) : 0;
}

// ── ADD ───────────────────────────────────────────────────────
if ($action === 'add') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $quantity  = max(1, (int)($_POST['quantity'] ?? 1));

    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product.']);
        exit;
    }

    // Check product exists and is active
    $stmt = $conn->prepare("SELECT id, name FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found.']);
        exit;
    }

    // Check stock
    $sr = $conn->query("SELECT quantity FROM inventory WHERE product_id = {$productId} LIMIT 1");
    $stockRow = $sr ? $sr->fetch_assoc() : null;
    $stock = (int)($stockRow['quantity'] ?? 0);

    if ($stock <= 0) {
        echo json_encode(['success' => false, 'message' => 'Sorry, this product is out of stock.']);
        exit;
    }

    // Check existing cart qty
    $cr = $conn->query("SELECT id, quantity FROM cart WHERE user_id = {$uid} AND product_id = {$productId} LIMIT 1");
    $existing = $cr ? $cr->fetch_assoc() : null;

    if ($existing) {
        $newQty = min($stock, (int)$existing['quantity'] + $quantity);
        $conn->query("UPDATE cart SET quantity = {$newQty} WHERE id = {$existing['id']}");
    } else {
        $quantity = min($stock, $quantity);
        $conn->query("INSERT INTO cart (user_id, product_id, quantity) VALUES ({$uid}, {$productId}, {$quantity})");
    }

    echo json_encode([
        'success'    => true,
        'message'    => 'Added to cart!',
        'cart_count' => getCartCount($conn, $uid),
    ]);
    exit;
}

// ── UPDATE QUANTITY ───────────────────────────────────────────
if ($action === 'update') {
    $cartId   = (int)($_POST['cart_id']  ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 1);

    if ($cartId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid cart item.']);
        exit;
    }

    // Verify cart item belongs to this user
    $cr = $conn->query("SELECT c.id, c.product_id FROM cart c WHERE c.id = {$cartId} AND c.user_id = {$uid} LIMIT 1");
    $cartItem = $cr ? $cr->fetch_assoc() : null;

    if (!$cartItem) {
        echo json_encode(['success' => false, 'message' => 'Cart item not found.']);
        exit;
    }

    // Check stock
    $productId = (int)$cartItem['product_id'];
    $sr = $conn->query("SELECT quantity FROM inventory WHERE product_id = {$productId} LIMIT 1");
    $stock = (int)(($sr ? $sr->fetch_assoc() : [])['quantity'] ?? 0);

    $quantity = max(1, min($stock, $quantity));
    $conn->query("UPDATE cart SET quantity = {$quantity} WHERE id = {$cartId}");

    // Get item subtotal
    $pr = $conn->query("SELECT price FROM products WHERE id = {$productId} LIMIT 1");
    $price = (float)(($pr ? $pr->fetch_assoc() : [])['price'] ?? 0);
    $subtotal = $price * $quantity;

    // Get cart total
    $tr = $conn->query("
        SELECT COALESCE(SUM(p.price * c.quantity), 0) AS total
        FROM cart c
        JOIN products p ON p.id = c.product_id
        WHERE c.user_id = {$uid}
    ");
    $cartTotal = (float)(($tr ? $tr->fetch_assoc() : [])['total'] ?? 0);

    echo json_encode([
        'success'    => true,
        'quantity'   => $quantity,
        'subtotal'   => number_format($subtotal, 2),
        'cart_total' => number_format($cartTotal, 2),
        'cart_count' => getCartCount($conn, $uid),
    ]);
    exit;
}

// ── REMOVE ────────────────────────────────────────────────────
if ($action === 'remove') {
    $cartId = (int)($_POST['cart_id'] ?? 0);

    if ($cartId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid cart item.']);
        exit;
    }

    $conn->query("DELETE FROM cart WHERE id = {$cartId} AND user_id = {$uid}");

    // Recalculate total
    $tr = $conn->query("
        SELECT COALESCE(SUM(p.price * c.quantity), 0) AS total
        FROM cart c
        JOIN products p ON p.id = c.product_id
        WHERE c.user_id = {$uid}
    ");
    $cartTotal = (float)(($tr ? $tr->fetch_assoc() : [])['total'] ?? 0);

    echo json_encode([
        'success'    => true,
        'cart_total' => number_format($cartTotal, 2),
        'cart_count' => getCartCount($conn, $uid),
    ]);
    exit;
}

// ── CLEAR ALL ─────────────────────────────────────────────────
if ($action === 'clear') {
    $conn->query("DELETE FROM cart WHERE user_id = {$uid}");
    echo json_encode(['success' => true, 'cart_count' => 0]);
    exit;
}
// ── COUNT ─────────────────────────────────────────────────────
if ($action === 'count') {
    echo json_encode(['success' => true, 'cart_count' => getCartCount($conn, $uid)]);
    exit;
}
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
