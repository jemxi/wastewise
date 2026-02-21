<?php
session_start();
require 'db_connection.php'; // Assume this file connects to your database and provides $pdo

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to submit a rating.']);
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_item_id = isset($_POST['order_item_id']) ? (int)$_POST['order_item_id'] : 0;
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
    $review_text = isset($_POST['review_text']) ? trim($_POST['review_text']) : '';

    // Basic validation
    if ($order_item_id <= 0 || $product_id <= 0 || $rating < 1 || $rating > 5 || empty($review_text)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input. Please provide a rating (1-5) and a review.']);
        exit();
    }

    try {
        // Check if the user actually owns this order_item and it's delivered
        $stmt = $pdo->prepare("
            SELECT oi.id, o.status, oi.is_rated
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE oi.id = ? AND o.user_id = ?
        ");
        $stmt->execute([$order_item_id, $user_id]);
        $order_item_info = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order_item_info) {
            echo json_encode(['success' => false, 'message' => 'Order item not found or you do not have permission.']);
            exit();
        }

        if ($order_item_info['status'] !== 'delivered') {
            echo json_encode(['success' => false, 'message' => 'You can only rate delivered items.']);
            exit();
        }

        if ($order_item_info['is_rated'] == 1) {
            echo json_encode(['success' => false, 'message' => 'This item has already been rated.']);
            exit();
        }

        // Insert review into product_reviews table
        $stmt = $pdo->prepare("
            INSERT INTO product_reviews (product_id, user_id, rating, review_text, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$product_id, $user_id, $rating, $review_text]);

        // Update order_items to mark as rated
        $stmt = $pdo->prepare("UPDATE order_items SET is_rated = 1 WHERE id = ?");
        $stmt->execute([$order_item_id]);

        echo json_encode(['success' => true, 'message' => 'Rating submitted successfully!']);

    } catch (PDOException $e) {
        error_log("Database error in submit_rating.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
