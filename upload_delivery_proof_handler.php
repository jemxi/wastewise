<?php
session_start();
require 'db_connection.php';

// API endpoint for uploading proof of delivery
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Verify user is a rider
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated');
    }
    
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM riders WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $rider = $stmt->fetch();
    
    if (!$rider) {
        throw new Exception('User is not a registered rider');
    }
    
    $order_id = intval($_POST['order_id'] ?? 0);
    $delivery_notes = $_POST['delivery_notes'] ?? '';
    
    if (!$order_id) {
        throw new Exception('Invalid order ID');
    }
    
    // Verify delivery exists and belongs to this rider
    $stmt = $pdo->prepare("
        SELECT * FROM deliveries 
        WHERE order_id = ? AND rider_id = ? AND status IN ('pending', 'in_transit')
    ");
    $stmt->execute([$order_id, $rider['id']]);
    $delivery = $stmt->fetch();
    
    if (!$delivery) {
        throw new Exception('No active delivery found for this order');
    }
    
    // Handle file upload
    if (!isset($_FILES['proof_image'])) {
        throw new Exception('No image file provided');
    }
    
    $file = $_FILES['proof_image'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $file['error']);
    }
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        throw new Exception('Invalid file type. Only JPG, PNG, and GIF are allowed.');
    }
    
    // Create unique filename
    $filename = 'proof_' . $order_id . '_' . time() . '.' . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $upload_dir = 'uploads/proof_of_delivery';
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $filepath = $upload_dir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save uploaded file');
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Update delivery with proof
        $stmt = $pdo->prepare("
            UPDATE deliveries 
            SET proof_image = ?, delivery_notes = ?, status = 'delivered', delivery_date = NOW()
            WHERE order_id = ? AND rider_id = ?
        ");
        $stmt->execute([$filename, $delivery_notes, $order_id, $rider['id']]);
        
        // Update order status
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET status = 'delivered', status_description = 'Delivered with proof of delivery.'
            WHERE id = ?
        ");
        $stmt->execute([$order_id]);
        
        // Add status history
        $stmt = $pdo->prepare("
            INSERT INTO order_status_history (order_id, status, notes, updated_by, created_at)
            VALUES (?, 'delivered', ?, ?, NOW())
        ");
        $stmt->execute([
            $order_id,
            'Proof of delivery submitted - ' . $delivery_notes,
            $rider['first_name'] . ' ' . $rider['last_name']
        ]);
        
        // Notify customer
        $stmt = $pdo->prepare("
            SELECT user_id FROM orders WHERE id = ?
        ");
        $stmt->execute([$order_id]);
        $customer_id = $stmt->fetchColumn();
        
        if ($customer_id) {
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, related_id, is_read, created_at)
                VALUES (?, 'delivery', 'Delivery Confirmed', ?, ?, 0, NOW())
            ");
            $stmt->execute([
                $customer_id,
                'Your order #' . $order_id . ' has been delivered successfully.',
                $order_id
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Proof of delivery submitted successfully',
            'order_id' => $order_id,
            'proof_image' => $filename
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
