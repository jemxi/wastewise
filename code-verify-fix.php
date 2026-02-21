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

// Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['code']) && !empty($_POST['code']) && isset($_POST['email']) && !empty($_POST['email'])) {
        $code = trim($_POST['code']);
        $email = trim($_POST['email']);
        
        // Add debugging information
        if ($debug_mode) {
            echo "<div style='background-color: #f8f9fa; border: 1px solid #ddd; padding: 10px; margin-bottom: 20px;'>";
            echo "<strong>Debug Information:</strong><br>";
            echo "Code received: " . htmlspecialchars($code) . "<br>";
            echo "Email received: " . htmlspecialchars($email) . "<br>";
            echo "Current time: " . date('Y-m-d H:i:s') . "<br>";
            echo "</div>";
        }
        
        try {
            // Get current timestamp
            $now = date('Y-m-d H:i:s');
            
            // First check if this is a pending registration
            $stmt = $pdo->prepare("SELECT * FROM pending_registrations WHERE email = ?");
            $stmt->execute([$email]);
            $pending_registration = $stmt->fetch();
            
            if ($pending_registration) {
                // Begin transaction
                $pdo->beginTransaction();
                
                // Check if the token matches or is part of the verification token
                $verification_token = $pending_registration['verification_token'];
                
                // Check if token is valid (either full token or just the code part after the dash)
                $is_valid = false;
                
                // Check exact match
                if ($verification_token === $code) {
                    $is_valid = true;
                }
                // Check if token is the code part (after the dash)
                else if (strpos($verification_token, '-') !== false) {
                    $parts = explode('-', $verification_token);
                    $code_part = end($parts);
                    if ($code_part === $code) {
                        $is_valid = true;
                    }
                }
                // Check if token is contained in the verification token
                else if (strpos($verification_token, $code) !== false) {
                    $is_valid = true;
                }
                
                if ($is_valid && strtotime($pending_registration['token_expires_at']) > strtotime($now)) {
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
                    if (strtotime($pending_registration['token_expires_at']) <= strtotime($now)) {
                        $error = "This verification code has expired. Please request a new one.";
                    } else {
                        $error = "Invalid verification code. Please check and try again.";
                        
                        if ($debug_mode) {
                            echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;'>";
                            echo "<h3>Debug: Token Comparison</h3>";
                            echo "<p>Entered code: " . htmlspecialchars($code) . "</p>";
                            echo "<p>Full token: " . htmlspecialchars($verification_token) . "</p>";
                            if (strpos($verification_token, '-') !== false) {
                                $parts = explode('-', $verification_token);
                                $code_part = end($parts);
                                echo "<p>Code part: " . htmlspecialchars($code_part) . "</p>";
                            }
                            echo "</div>";
                        }
                    }
                    $pdo->rollBack();
                }
            } else {
                // Find the user by email
                $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Begin transaction
                    $pdo->beginTransaction();
                    
                    // Find verification token that ends with the provided code
                    $stmt = $pdo->prepare("
                        SELECT v.* 
                        FROM email_verifications v
                        WHERE v.user_id = ? 
                        AND (v.token = ? OR v.token LIKE ? OR v.token LIKE ?)
                        AND v.expires_at > ? AND v.is_used = 0
                    ");
                    $stmt->execute([
                        $user['id'], 
                        $code,                // Exact match
                        '%-' . $code,         // Format with dash
                        '%' . $code . '%',    // Code anywhere in token
                        $now
                    ]);
                    $verification = $stmt->fetch();
                    
                    if ($verification) {
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
                        $stmt->execute([$now, $user['id']]);
                        
                        // Commit transaction
                        $pdo->commit();
                        
                        // Set success message
                        $success = "Your email has been verified successfully! You can now log in.";
                        
                        // Auto-login the user (optional)
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['is_admin'] = 0;
                    } else {
                        // If debug mode is on, show more detailed error
                        if ($debug_mode) {
                            // Check if there are any tokens for this user
                            $stmt = $pdo->prepare("
                                SELECT token, expires_at, is_used 
                                FROM email_verifications 
                                WHERE user_id = ?
                                ORDER BY created_at DESC
                            ");
                            $stmt->execute([$user['id']]);
                            $tokens = $stmt->fetchAll();
                            
                            echo "<div style='background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;'>";
                            echo "<h3>Debug: Tokens for this user</h3>";
                            
                            if (count($tokens) > 0) {
                                foreach ($tokens as $t) {
                                    echo "<p>Token: " . $t['token'] . "</p>";
                                    echo "<p>Expires: " . $t['expires_at'] . "</p>";
                                    echo "<p>Used: " . ($t['is_used'] ? 'Yes' : 'No') . "</p>";
                                    echo "<hr>";
                                }
                            } else {
                                echo "<p>No tokens found for this user.</p>";
                            }
                            
                            echo "</div>";
                        }
                        
                        $error = "Invalid or expired verification code.";
                        $pdo->rollBack();
                    }
                } else {
                    $error = "No account found with this email address.";
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
        $error = "Please enter both your email and verification code.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify with Code - Wastewise</title>
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
                <h2 class="text-3xl font-bold text-gray-800">Verify Your Account</h2>
                <p class="text-gray-600 mt-2">Enter the 6-digit code sent to your email</p>
            </div>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                    <p><?php echo $error; ?></p>
                    
                    <?php if (strpos($error, "expired") !== false): ?>
                        <div class="mt-4">
                            <a href="resend-verification.php" class="text-blue-600 hover:underline">
                                Click here to request a new verification code
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
            <?php else: ?>
                <form action="" method="POST" class="space-y-6">
                    <div>
                        <label for="email" class="block text-gray-700 text-sm font-bold mb-2">Email Address</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="fas fa-envelope text-gray-400"></i>
                            </span>
                            <input type="email" id="email" name="email" 
                                   class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                   required>
                        </div>
                    </div>
                    
                    <div>
                        <label for="code" class="block text-gray-700 text-sm font-bold mb-2">Verification Code</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="fas fa-key text-gray-400"></i>
                            </span>
                            <input type="text" id="code" name="code" 
                                   class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                                   placeholder="6-digit code" required>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Enter the 6-digit code from your verification email</p>
                    </div>

                    <div>
                        <button type="submit" 
                                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                Verify Account
                        </button>
                    </div>
                </form>
                
                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-600">
                        Didn't receive the verification email? 
                        <a href="resend-verification.php" class="text-blue-600 hover:underline">Resend it</a>
                    </p>
                    
                    <p class="text-sm text-gray-600 mt-2">
                        <a href="login.php" class="text-blue-600 hover:underline">
                            Back to Login
                        </a>
                    </p>
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
     <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
</body>
</html>

