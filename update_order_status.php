<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// This file handles order status updates from seller side
// Stock should ONLY be deducted when order moves to "to_receive" status (shipped/out_for_delivery)

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Database connection
$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');

if ($db->connect_error) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

header('Content-Type: application/json');

$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
$new_status = isset($_POST['status']) ? trim($_POST['status']) : '';

if (!$order_id || !$new_status) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID or status']);
    exit();
}

try {
    $stmt = $db->prepare("SELECT o.status FROM orders o WHERE o.id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }

    $old_status = $order['status'];

    // This ensures stock is only reduced when order is actually being sent to customer
    if (($new_status === 'shipped' || $new_status === 'out_for_delivery') && 
        ($old_status === 'pending' || $old_status === 'processing')) {
        
        $db->begin_transaction();
        
        // Get all order items and deduct stock
        $stmt = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $items_result = $stmt->get_result();
        
        while ($item = $items_result->fetch_assoc()) {
            $product_id = $item['product_id'];
            $quantity = $item['quantity'];
            
            $update_stmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $update_stmt->bind_param("ii", $quantity, $product_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
        
        // Update order status
        $stmt = $db->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $order_id);
        $stmt->execute();
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Order status updated and stock deducted']);
        
    } else {
        $stmt = $db->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $order_id);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Order status updated']);
    }
    
} catch (Exception $e) {
    // Rollback on error
    if ($db->inTransaction()) {
        $db->rollback();
    }
    echo json_encode(['success' => false, 'message' => 'Error updating order: ' . $e->getMessage()]);
}

$db->close();
?>
