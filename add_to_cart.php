<?php
session_start();
$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Initialize the response array
$response = array('success' => false, 'message' => '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0;
    
    // For testing/development, create a default user ID if not set
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['user_id'] = 1; // Default test user
    }
    $user_id = $_SESSION['user_id'];

    if ($product_id > 0 && $quantity > 0) {
        try {
            // Check if the product exists and has enough stock
            $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND stock >= ?");
            $stmt->bind_param("ii", $product_id, $quantity);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $product = $result->fetch_assoc();
                
                // Begin transaction
                $db->begin_transaction();

                try {
                    // Check if the item is already in the cart
                    $stmt = $db->prepare("SELECT * FROM cart_items WHERE user_id = ? AND product_id = ?");
                    $stmt->bind_param("ii", $user_id, $product_id);
                    $stmt->execute();
                    $cart_result = $stmt->get_result();

                    if ($cart_result->num_rows > 0) {
                        // Update existing cart item
                        $cart_item = $cart_result->fetch_assoc();
                        $new_quantity = $cart_item['quantity'] + $quantity;
                        
                        // Check if new total quantity is available in stock
                        if ($new_quantity <= $product['stock']) {
                            $stmt = $db->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
                            $stmt->bind_param("ii", $new_quantity, $cart_item['id']);
                            $stmt->execute();
                        } else {
                            throw new Exception("Not enough stock available.");
                        }
                    } else {
                        // Add new cart item
                        if ($quantity <= $product['stock']) {
                            $stmt = $db->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)");
                            $stmt->bind_param("iii", $user_id, $product_id, $quantity);
                            $stmt->execute();
                        } else {
                            throw new Exception("Not enough stock available.");
                        }
                    }

                    // Commit transaction
                    $db->commit();
                    
                    $response['success'] = true;
                    $response['message'] = 'Item added to cart successfully.';
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $db->rollback();
                    $response['message'] = $e->getMessage();
                }
            } else {
                $response['message'] = 'Product not found or insufficient stock.';
            }
        } catch (Exception $e) {
            $response['message'] = 'Database error occurred.';
        }
    } else {
        $response['message'] = 'Invalid product ID or quantity.';
    }
} else {
    $response['message'] = 'Invalid request method.';
}

// Set proper content type header
header('Content-Type: application/json');

// Send response
echo json_encode($response);
?>

