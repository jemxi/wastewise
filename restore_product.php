<?php
session_start();
require 'db_connection.php';

// Check if user is logged in and is a seller
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Get the product ID
$product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
$seller_id = isset($_POST['seller_id']) ? intval($_POST['seller_id']) : 0;

if ($product_id <= 0 || $seller_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or seller ID']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Verify product belongs to this seller
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND seller_id = ?");
    $stmt->execute([$product_id, $seller_id]);
    $product = $stmt->fetch();
    
    if (!$product) {
        throw new Exception("Product not found or you don't have permission to restore it.");
    }
    
    // Restore the product
    $stmt = $pdo->prepare("
        UPDATE products 
        SET archived = 0,
            updated_at = NOW()
        WHERE id = ? AND seller_id = ?
    ");
    $stmt->execute([$product_id, $seller_id]);
    
    // Commit transaction
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Product has been restored successfully. It will now appear in the store again.'
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>
