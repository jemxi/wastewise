<?php
session_start();
$db = new mysqli('localhost', 'root', '', 'wastewise');

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Admin not logged in']);
    exit();
}

$order_id = $_POST['order_id'] ?? null;

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Order ID is required']);
    exit();
}

$stmt = $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'cancellation_pending'");
$stmt->bind_param("i", $order_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Cancellation approved successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to approve cancellation']);
}

$db->close();