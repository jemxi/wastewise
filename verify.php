<?php
session_start();
require 'db_connection.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Debug mode - set to false in production
$debug_mode = true;

$error = '';
$success = '';
$verified_email = ''; // Store the email that was verified

// Check if token is provided
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];
    
    // Add debugging information
    if ($debug_mode) {
        echo "<div style='background-color: #f8f9fa; border: 1px solid #ddd; padding: 10px; margin-bottom: 20px;'>";
        echo "<strong>Debug Information:</strong><br>";
        echo "Token received: " . htmlspecialchars($token) . "<br>";
        echo "Token length: " . strlen($token) . "<br>";
        echo "Current time: " . date('Y-m-d H:i:s') . "<br>";
        echo "</div>";
    }
    
    try {
        // Get current timestamp
        $now = date('Y-m-d H:i:s');
        
        // Begin transaction
        $pdo->beginTransaction();
        
        // First, check if this is a pending registration verification
        $stmt = $pdo->prepare("
            SELECT * FROM pending_registrations 
            WHERE verification_token = ? OR verification_token LIKE CONCAT('%', ?) 
            AND token_expires_at > ?
        ");
        $stmt->execute([$token, $token, $now]);
        $pending_registration = $stmt->fetch();
        
        if ($pending_registration) {
            // This is a new registration verification
            $verified_email = $pending_registration['email']; // Store the email
            
            // Create the user account from pending registration
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    username,
                    email,
                    password,
                    created_at,
                    is_admin,
                    is_verified,
                    email_verified_at,
                    google_id,
                    name
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $pending_registration['username'],
                $pending_registration['email'],
                $pending_registration['password'],
                $now, // Use current time as created_at
                0, // is_admin
                1, // is_verified (verified immediately)
                $now, // email_verified_at
                null, // google_id
                $pending_registration['username'] // name (default to username)
            ]);
            
            if ($result) {
                // Get the new user ID
                $user_id = $pdo->lastInsertId();
                
                // Delete the pending registration
                $stmt = $pdo->prepare("DELETE FROM pending_registrations WHERE id = ?");
                $stmt->execute([$pending_registration['id']]);
                
                // Commit transaction
                $pdo->commit();
                
                // Set success message
                $success = "Your email has been verified and your account has been created successfully! You can now log in.";
                
                // Auto-login the user (optional)
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $pending_registration['username'];
                $_SESSION['is_admin'] = 0;
            } else {
                // Rollback transaction
                $pdo->rollBack();
                $error = "Failed to create user account. Please try again or contact support.";
            }
        } else {
            // Check if this is an existing user verification
            $stmt = $pdo->prepare("
                SELECT v.*, u.username, u.email 
                FROM email_verifications v
                JOIN users u ON v.user_id = u.id
                WHERE (v.token = ? OR v.token LIKE CONCAT('%', ?)) 
                AND v.expires_at > ? AND v.is_used = 0
            ");
            $stmt->execute([$token, $token, $now]);
            $verification = $stmt->fetch();
            
            if ($verification) {
                $verified_email = $verification['email']; // Store the email
                
                // Mark the token as used
                $stmt = $pdo->prepare("
                    UPDATE email_verifications 
                    SET is_used = 1, used_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$now, $verification['id']]);
                
                // Mark the user as verified
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET is_verified = 1, email_verified_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$now, $verification['user_id']]);
                
                // Commit transaction
                $pdo->commit();
                
                // Set success message
                $success = "Your email has been verified successfully! You can now log in.";
                
                // Auto-login the user (optional)
                $_SESSION['user_id'] = $verification['user_id'];
                $_SESSION['username'] = $verification['username'];
                $_SESSION['is_admin'] = 0;
            } else {
                // Check if token exists but is expired or used
                $stmt = $pdo->prepare("
                    SELECT * FROM pending_registrations 
                    WHERE verification_token = ? OR verification_token LIKE CONCAT('%', ?)
                ");
                $stmt->execute([$token, $token]);
                $expired_pending = $stmt->fetch();
                
                if ($expired_pending) {
                    if (strtotime($expired_pending['token_expires_at']) < strtotime($now)) {
                        $error = "This verification link has expired. Please request a new one.";
                    } else {
                        $error = "Invalid verification link.";
                    }
                } else {
                    // Check existing email verifications
                    $stmt = $pdo->prepare("
                        SELECT v.*, u.username, u.email 
                        FROM email_verifications v
                        JOIN users u ON v.user_id = u.id
                        WHERE v.token = ? OR v.token LIKE CONCAT('%', ?)
                    ");
                    $stmt->execute([$token, $token]);
                    $expired_verification = $stmt->fetch();
                    
                    if ($expired_verification) {
                        if ($expired_verification['is_used'] == 1) {
                            $error = "This verification link has already been used.";
                        } else {
                            $error = "This verification link has expired. Please request a new one.";
                        }
                    } else {
                        $error = "Invalid verification link.";
                    }
                }
                
                // Rollback transaction
                $pdo->rollBack();
            }
        }
    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Database error: " . $e->getMessage();
    }
} else {
    $error = "No verification token provided.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-wastewise {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-white shadow-sm">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center">
                <img src="/assets/images/logo.png" alt="Wastewise Logo" class="h-10 mr-3">
                <span class="text-2xl font-bold text-gray-800">Wastewise</span>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 py-12 max-w-md">
        <div class="bg-white p-8 rounded-lg shadow-lg">
            <div class="text-center mb-8">
                <h2 class="text-3xl font-bold text-gray-800">Email Verification</h2>
                <p class="text-gray-600 mt-2">Wastewise Account Verification</p>
            </div>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                    <p><?php echo $error; ?></p>
                    
                    <?php if (strpos($error, "expired") !== false): ?>
                        <div class="mt-4">
                            <a href="resend-verification.php" class="text-blue-600 hover:underline">
                                Click here to request a new verification link
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert">
                    <p><?php echo $success; ?></p>
                </div>
                
                <div class="mt-6 text-center">
                    <div class="mb-6">
                        <i class="fas fa-check-circle text-green-500 text-6xl"></i>
                        <h3 class="text-2xl font-bold text-gray-800 mt-4">Verification Successful!</h3>
                        <p class="text-gray-600 mt-2">Your account has been verified successfully.</p>
                    </div>
                    
                    <p>You will be redirected to the homepage in <span id="countdown" class="font-bold">5</span> seconds.</p>
                    <script>
                        // Countdown timer
                        let seconds = 5;
                        const countdownElement = document.getElementById('countdown');
                        
                        const interval = setInterval(function() {
                            seconds--;
                            countdownElement.textContent = seconds;
                            
                            if (seconds <= 0) {
                                clearInterval(interval);
                                window.location.href = 'home.php';
                            }
                        }, 1000);
                    </script>
                    
                    <div class="mt-6">
                        <a href="home.php" class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-md">
                            Go to Homepage
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white py-8 mt-12">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-4 md:mb-0">
                    <p>&copy; <?php echo date('Y'); ?> Wastewise. All rights reserved.</p>
                </div>
                <div class="flex space-x-4">
                    <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-facebook"></i></a>
                    <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-twitter"></i></a>
                    <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-instagram"></i></a>
                    <a href="#" class="text-gray-300 hover:text-white"><i class="fab fa-linkedin"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <?php if ($verified_email): ?>
    <!-- Broadcast verification status to other tabs/devices -->
    <script>
        // Use localStorage to broadcast the verification
        try {
            localStorage.setItem('wastewise_verified_email', '<?php echo $verified_email; ?>');
            localStorage.setItem('wastewise_verification_time', '<?php echo time(); ?>');
        } catch (e) {
            console.log('LocalStorage not available:', e);
        }
    </script>
    <?php endif; ?>
</body>
</html>

