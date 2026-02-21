<?php
session_start();
require 'db_connection.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$error = '';
$success = '';
$verified = false;

// Check if token is provided
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];
    
    try {
        // Check if token exists and is valid
        $stmt = $pdo->prepare("SELECT * FROM pending_registrations WHERE verification_token = ? AND token_expires_at > NOW() AND is_seller = 1");
        $stmt->execute([$token]);
        $registration = $stmt->fetch();
        
        if ($registration) {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Create user account
            $stmt = $pdo->prepare("
                INSERT INTO users (
                    username,
                    email,
                    password,
                    created_at,
                    email_verified
                ) VALUES (?, ?, ?, ?, 1)
            ");
            
            $result = $stmt->execute([
                $registration['username'],
                $registration['email'],
                $registration['password'],
                date('Y-m-d H:i:s')
            ]);
            
            if (!$result) {
                throw new Exception("Failed to create user account.");
            }
            
            // Get the new user ID
            $user_id = $pdo->lastInsertId();
            
            // Check if there's pending seller info in the session
            if (isset($_SESSION['pending_seller_info']) && is_array($_SESSION['pending_seller_info'])) {
                // Insert seller information
                $seller_info = $_SESSION['pending_seller_info'];
                
                $stmt = $pdo->prepare("
                    INSERT INTO sellers (
                        user_id,
                        business_name,
                        business_type,
                        tax_id,
                        business_address,
                        city,
                        state,
                        postal_code,
                        country,
                        phone_number,
                        website,
                        description,
                        status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                
                $result = $stmt->execute([
                    $user_id,
                    $seller_info['business_name'],
                    $seller_info['business_type'],
                    $seller_info['tax_id'],
                    $seller_info['business_address'],
                    $seller_info['city'],
                    $seller_info['state'],
                    $seller_info['postal_code'],
                    $seller_info['country'],
                    $seller_info['phone_number'],
                    $seller_info['website'],
                    $seller_info['description']
                ]);
                
                if (!$result) {
                    throw new Exception("Failed to create seller account.");
                }
                
                // Clear the session data
                unset($_SESSION['pending_seller_info']);
            }
            
            // Delete the pending registration
            $stmt = $pdo->prepare("DELETE FROM pending_registrations WHERE id = ?");
            $stmt->execute([$registration['id']]);
            
            // Commit transaction
            $pdo->commit();
            
            $success = "Your email has been verified successfully!";
            $verified = true;
        } else {
            $error = "Invalid or expired verification token.";
        }
    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Database error: " . $e->getMessage();
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
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
            <div>
                <a href="login.php" class="text-green-600 hover:text-green-800 mr-4">
                    <i class="fas fa-sign-in-alt mr-1"></i> Login
                </a>
                <a href="register.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-user-plus mr-1"></i> Register
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8 max-w-md">
        <div class="bg-white p-8 rounded-lg shadow-lg">
            <div class="text-center mb-6">
                <i class="fas fa-envelope-open-text text-green-500 text-5xl mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-800">Email Verification</h1>
            </div>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error; ?></p>
                    <div class="mt-4">
                        <a href="login.php" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                            Go to Login
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p class="font-bold">Congratulations!</p>
                    <p class="mt-2">Your email has been verified as seller. Wait for admin's approval for your seller account.</p>
                    <div class="mt-6 text-center">
                        <a href="login.php" class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                            Login to Your Account
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!$error && !$success): ?>
                <div class="text-center">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-green-500 mx-auto"></div>
                    <p class="mt-4 text-gray-600">Verifying your email address...</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

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