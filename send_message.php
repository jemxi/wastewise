<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Database connection
    $db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');
if ($db->connect_error) {
    die("DB connection failed: " . $db->connect_error);
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die("Unauthorized");
}

$sender_id = $_SESSION['user_id'];
$seller_id = $_POST['seller_id'] ?? null;
$message = trim($_POST['message'] ?? '');

if ($seller_id && !empty($message)) {
    $stmt = $db->prepare("INSERT INTO messages (sender_id, receiver_id, message, sent_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $sender_id, $seller_id, $message);
    $stmt->execute();
    $stmt->close();

    header("Location: seller-shop.php?id=" . $seller_id . "&msg=sent");
    exit();
} else {
    http_response_code(400); // Bad request
    echo "Missing seller ID or message content.";
}
?>
