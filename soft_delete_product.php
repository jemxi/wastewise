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
        throw new Exception("Product not found or you don't have permission to delete it.");
    }
    
    // Check if product is referenced in order_items
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $has_orders = ($stmt->fetchColumn() > 0);
    
    if ($has_orders) {
        // Soft delete - mark as archived instead of deleting
        $stmt = $pdo->prepare("
            UPDATE products 
            SET archived = 1, 
                stock = 0, 
                updated_at = NOW()
            WHERE id = ? AND seller_id = ?
        ");
        $stmt->execute([$product_id, $seller_id]);
        
        // Commit transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'soft_delete' => true,
            'message' => 'Product has been archived because it has existing orders. It will no longer appear in the store.'
        ]);
    } else {
        // Hard delete - product has no orders
        // Delete product image if it exists
        if ($product['image_path'] && file_exists($product['image_path'])) {
            unlink($product['image_path']);
        }
        
        // Delete product from database
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND seller_id = ?");
        $stmt->execute([$product_id, $seller_id]);
        
        // Commit transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'soft_delete' => false,
            'message' => 'Product deleted successfully!'
        ]);
    }
    
} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>
s