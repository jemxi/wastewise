<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
header('Content-Type: application/json');

// ✅ Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not logged in']);
    exit;
}

// ✅ Database connection
$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');
if ($db->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// ✅ Sanitize and get POST data
$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
$reason = isset($_POST['reason']) ? $db->real_escape_string($_POST['reason']) : '';
$user_id = $_SESSION['user_id'];

// ✅ Check if order exists and belongs to user
$check = $db->prepare("SELECT id, status FROM orders WHERE id = ? AND user_id = ?");
$check->bind_param("ii", $order_id, $user_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Order not found or unauthorized']);
    exit;
}

$order = $result->fetch_assoc();

if ($order['status'] === 'cancelled') {
    echo json_encode(['success' => false, 'error' => 'Order is already cancelled']);
    exit;
}

$db->begin_transaction();

try {
    $items_query = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
    $items_query->bind_param("i", $order_id);
    $items_query->execute();
    $items_result = $items_query->get_result();
    
    while ($item = $items_result->fetch_assoc()) {
        $product_id = $item['product_id'];
        $quantity = $item['quantity'];
        
        $update_stock = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        $update_stock->bind_param("ii", $quantity, $product_id);
        
        if (!$update_stock->execute()) {
            throw new Exception("Failed to restore stock for product ID: $product_id");
        }
        $update_stock->close();
    }
    $items_query->close();

    // ✅ Insert into cancelled_orders table (no comments field)
    $insert = $db->prepare("INSERT INTO cancelled_orders (order_id, user_id, reason, cancelled_at) VALUES (?, ?, ?, NOW())");
    $insert->bind_param("iis", $order_id, $user_id, $reason);

    if (!$insert->execute()) {
        throw new Exception("Failed to save cancellation: " . $insert->error);
    }
    $insert->close();

    // ✅ Update order status to 'Cancelled'
    $update = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
    $update->bind_param("i", $order_id);

    if (!$update->execute()) {
        throw new Exception("Failed to update order status");
    }
    $update->close();

    $db->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $db->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$db->close();
?>
