<?php
session_start();
$db = new mysqli('localhost', 'root', '', 'wastewise');

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

    if ($order_id !== 0) {
        // Archive order
        $stmt = $db->prepare("UPDATE orders SET archived = 1 WHERE id = ? AND status = 'cancelled'");
        $stmt->bind_param("i", $order_id);
        
        if ($stmt->execute()) {
            // Remove the order from user notifications
            $stmt = $db->prepare("DELETE FROM notifications WHERE order_id = ?");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            
            echo json_encode(['success' => true, 'message' => 'Order archived successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to archive the order']);
        }
    } elseif ($product_id !== 0) {
        // Archive product
        $stmt = $db->prepare("UPDATE products SET archived = 1 WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Product archived successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to archive the product']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request: No order_id or product_id provided']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
