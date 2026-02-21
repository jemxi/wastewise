<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit();
}

$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');

// Check DB connection
if ($db->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

try {
    // Verify the order belongs to the user and is cancelled
    $verify_query = "SELECT id FROM orders WHERE id = ? AND user_id = ? AND status = 'cancelled'";
    $verify_stmt = $db->prepare($verify_query);
    $verify_stmt->bind_param("ii", $order_id, $user_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();

    if ($verify_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Order not found or cannot be repurchased']);
        exit();
    }

    $items_query = "SELECT product_id, quantity FROM order_items WHERE order_id = ?";
    $items_stmt = $db->prepare($items_query);
    $items_stmt->bind_param("i", $order_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();

    if ($items_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No items found in this order']);
        exit();
    }

    $items_added = 0;
    while ($item = $items_result->fetch_assoc()) {
        $product_id = $item['product_id'];
        $quantity = $item['quantity'];

        // Check if item already exists in cart
        $check_cart = "SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?";
        $check_stmt = $db->prepare($check_cart);
        $check_stmt->bind_param("ii", $user_id, $product_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            // Update existing cart item quantity
            $cart_item = $check_result->fetch_assoc();
            $new_quantity = $cart_item['quantity'] + $quantity;
            $update_cart = "UPDATE cart_items SET quantity = ? WHERE id = ?";
            $update_stmt = $db->prepare($update_cart);
            $update_stmt->bind_param("ii", $new_quantity, $cart_item['id']);
            $update_stmt->execute();
        } else {
            // Add new item to cart
            $insert_cart = "INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)";
            $insert_stmt = $db->prepare($insert_cart);
            $insert_stmt->bind_param("iii", $user_id, $product_id, $quantity);
            $insert_stmt->execute();
        }

        $items_added++;
    }

    echo json_encode([
        'success' => true,
        'message' => "Added $items_added item(s) to your cart. Please review before checkout."
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
} finally {
    $db->close();
}
?>
