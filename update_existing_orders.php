<?php
// This script updates existing order items with the correct seller_id values
// Run this once after adding the seller_id column to the order_items table

// Include database connection
$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Check if user is logged in as admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo "Access denied. Admin privileges required.";
    exit;
}

echo "<h1>Updating Order Items with Seller IDs</h1>";

// Start transaction
$db->begin_transaction();

try {
    // First, make sure the seller_id column exists in the order_items table
    $db->query("ALTER TABLE order_items ADD COLUMN IF NOT EXISTS seller_id INT NULL");
    
    // Get all order items without seller_id
    $result = $db->query("
        SELECT oi.id, oi.product_id, oi.order_id
        FROM order_items oi
        WHERE oi.seller_id IS NULL
    ");
    
    $items = $result->fetch_all(MYSQLI_ASSOC);
    
    echo "<p>Found " . count($items) . " order items to update.</p>";
    
    $updated = 0;
    $errors = 0;
    
    foreach ($items as $item) {
        // Get the seller_id for this product
        $stmt = $db->prepare("
            SELECT seller_id 
            FROM products 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $item['product_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $product = $result->fetch_assoc();
        
        if ($product && isset($product['seller_id'])) {
            // Update the order item with the seller_id
            $stmt = $db->prepare("
                UPDATE order_items 
                SET seller_id = ? 
                WHERE id = ?
            ");
            $stmt->bind_param("ii", $product['seller_id'], $item['id']);
            
            if ($stmt->execute()) {
                $updated++;
                echo "<p>Updated order item #" . $item['id'] . " for order #" . $item['order_id'] . " with seller ID " . $product['seller_id'] . "</p>";
            } else {
                $errors++;
                echo "<p style='color:red'>Failed to update order item #" . $item['id'] . ": " . $db->error . "</p>";
            }
        } else {
            $errors++;
            echo "<p style='color:red'>Could not find seller for product #" . $item['product_id'] . " in order item #" . $item['id'] . "</p>";
        }
    }
    
    // Commit transaction
    $db->commit();
    
    echo "<h2>Summary</h2>";
    echo "<p>Total items processed: " . count($items) . "</p>";
    echo "<p>Successfully updated: " . $updated . "</p>";
    echo "<p>Errors: " . $errors . "</p>";
    
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollback();
    echo "<h2>Error</h2>";
    echo "<p style='color:red'>Database error: " . $e->getMessage() . "</p>";
}
?>

<p><a href="admin_dashboard.php">Return to Dashboard</a></p>
