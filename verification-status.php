<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Get email from request
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

if (empty($email)) {
    echo json_encode(['error' => 'Email is required']);
    exit;
}

try {
    // First check if user exists and is verified
    $stmt = $pdo->prepare("SELECT id, username, is_verified, email_verified_at FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        // User exists, check verification status
        echo json_encode([
            'exists' => true,
            'verified' => (bool)$user['is_verified'],
            'verified_at' => $user['email_verified_at'],
            'username' => $user['username']
        ]);
    } else {
        // Check if there's a pending registration
        $stmt = $pdo->prepare("SELECT id, username FROM pending_registrations WHERE email = ?");
        $stmt->execute([$email]);
        $pending = $stmt->fetch();
        
        if ($pending) {
            echo json_encode([
                'exists' => true,
                'verified' => false,
                'pending' => true,
                'username' => $pending['username']
            ]);
        } else {
            echo json_encode([
                'exists' => false,
                'verified' => false
            ]);
        }
    }
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
