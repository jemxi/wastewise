<?php
session_start();
require 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if required parameters are provided
if (!isset($_POST['user_id']) || !isset($_POST['subject']) || !isset($_POST['message']) || 
    !isset($_POST['seller_id']) || !isset($_POST['seller_name'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$user_id = intval($_POST['user_id']);
$subject = $_POST['subject'];
$message = $_POST['message'];
$seller_id = intval($_POST['seller_id']);
$seller_name = $_POST['seller_name'];
$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : null;

try {
    // Verify the seller
    $stmt = $pdo->prepare("SELECT * FROM sellers WHERE id = ? AND user_id = ?");
    $stmt->execute([$seller_id, $_SESSION['user_id']]);
    $seller = $stmt->fetch();
    
    if (!$seller) {
        throw new Exception("Unauthorized access");
    }
    
    // Check if messages table exists, create if not
    $stmt = $pdo->prepare("
        CREATE TABLE IF NOT EXISTS messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            sender_type ENUM('user', 'seller', 'admin') NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            order_id INT NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (sender_id),
            INDEX (receiver_id),
            INDEX (order_id)
        )
    ");
    $stmt->execute();
    
    // Send message
    $stmt = $pdo->prepare("
        INSERT INTO messages (sender_id, receiver_id, sender_type, subject, message, order_id, created_at)
        VALUES (?, ?, 'seller', ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $seller_id,
        $user_id,
        $subject,
        $message,
        $order_id
    ]);
    
    // Send notification to user
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, related_id, created_at)
        VALUES (?, 'new_message', ?, ?, ?, NOW())
    ");
    
    $notification_title = "New message from $seller_name";
    $notification_message = "You have received a new message regarding: $subject";
    
    $stmt->execute([
        $user_id,
        $notification_title,
        $notification_message,
        $order_id ?? $seller_id
    ]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
