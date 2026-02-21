<?php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set JSON header immediately
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// For testing purposes, set a default user_id if not logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $user_id = 1;
} else {
    $user_id = $_SESSION['user_id'];
}

// Database connection
$servername = "localhost";
$username = "u255729624_wastewise";
$password = "/l5Dv04*K";
$dbname = "u255729624_wastewise";

try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Only process POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Check if ajax_action is set
if (!isset($_POST['ajax_action'])) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit();
}

try {
    $action = $_POST['ajax_action'];
    
    if ($action === 'update_quantity') {
        $cart_id = intval($_POST['cart_id']);
        $quantity = max(1, intval($_POST['quantity']));
        
        // Check if cart item exists and belongs to user
        $checkStmt = $pdo->prepare("SELECT id FROM cart_items WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$cart_id, $user_id]);
        
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Cart item not found']);
            exit();
        }
        
        $stmt = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$quantity, $cart_id, $user_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Quantity updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update quantity']);
        }
        exit();
    }
    
    if ($action === 'remove_item') {
        $cart_id = intval($_POST['cart_id']);
        
        // Check if cart item exists and belongs to user
        $checkStmt = $pdo->prepare("SELECT id FROM cart_items WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$cart_id, $user_id]);
        
        if (!$checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Cart item not found']);
            exit();
        }
        
        $stmt = $pdo->prepare("DELETE FROM cart_items WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$cart_id, $user_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Item removed from cart']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to remove item']);
        }
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
