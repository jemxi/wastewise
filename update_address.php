<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get data from form
    $fullName = filter_input(INPUT_POST, 'fullName', FILTER_SANITIZE_SPECIAL_CHARS);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS);
    $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_SPECIAL_CHARS);
    $barangay = filter_input(INPUT_POST, 'barangay', FILTER_SANITIZE_SPECIAL_CHARS);
    
    $user_id = $_SESSION['user_id'];
    
    // Validation
    if (empty($fullName) || empty($phone) || empty($address) || empty($barangay)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        exit();
    }
    
    // Validate phone format
    if (strlen($phone) !== 11 || !preg_match('/^09\d{9}$/', $phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid phone number format']);
        exit();
    }
    
    // Validate address length
    if (strlen($address) < 5) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Address must be at least 5 characters']);
        exit();
    }
    
    // Check if PDO connection exists
    if (!isset($pdo)) {
        error_log("Database connection failed. PDO object not found.");
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection error']);
        exit();
    }
    
    $stmt = $pdo->prepare("
        UPDATE users 
        SET name = :full_name, 
            default_phone = :phone, 
            default_address = :address, 
            default_barangay = :barangay
        WHERE id = :user_id
    ");
    
    $result = $stmt->execute([
        ':full_name' => $fullName,
        ':phone' => $phone,
        ':address' => $address,
        ':barangay' => $barangay,
        ':user_id' => $user_id
    ]);
    
    if ($result) {
        http_response_code(200);
        echo json_encode([
            'success' => true, 
            'message' => 'Address updated successfully!'
        ]);
    } else {
        error_log("Failed to update address for user_id: $user_id. Error: " . implode(' ', $stmt->errorInfo()));
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update address in database']);
    }
    
} catch (PDOException $e) {
    error_log("Database error in update_address.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Unexpected error in update_address.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
}
?>
