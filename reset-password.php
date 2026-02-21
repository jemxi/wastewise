<?php
session_start();
require 'db_connection.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$error = '';
$success = '';
$token = '';
$valid_token = false;
$user_id = null;

// Check if token is provided
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];
    
    try {
        // Get current timestamp
        $now = date('Y-m-d H:i:s');
        
        // Find the reset record
        $stmt = $pdo->prepare("
            SELECT pr.*, u.username, u.email 
            FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token = ? AND pr.expires_at > ? AND pr.is_used = 0
        ");
        $stmt->execute([$token, $now]);
        $reset = $stmt->fetch();
        
        if ($reset) {
            $valid_token = true;
            $user_id = $reset['user_id'];
        } else {
            $error = "Invalid or expired password reset link.";
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
} else {
    $error = "No reset token provided.";
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $valid_token) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($password) || empty($confirm_password)) {
        $error = "Both password fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Hash the new password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Update user's password
            $stmt = $pdo->prepare("
                UPDATE users 
                SET password = ?
                WHERE id = ?
            ");
            $stmt->execute([$hashed_password, $user_id]);
            
            // Mark the token as used
            $stmt = $pdo->prepare("
                UPDATE password_resets 
                SET is_used = 1, used_at = ?
                WHERE token = ?
            ");
            $stmt->execute([date('Y-m-d H:i:s'), $token]);
            
            // Commit transaction
            $pdo->commit();
            
            $success = "Your password has been reset successfully! You can now log in with your new password.";
            
        } catch (PDOException $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-wastewise {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
        }
    </style>
</head>
<body class="bg-wastewise min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-2xl w-full max-w-md">
        <div class="text-center mb-8">
            <h2 class="text-4xl font-bold text-gray-800">Reset Password</h2>
            <p class="text-gray-600 mt-2">Create a new password for your Wastewise account</p>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                <p><?php echo $error; ?></p>
                
                <?php if (strpos($error, "expired") !== false || strpos($error, "Invalid") !== false): ?>
                    <div class="mt-4">
                        <a href="forgot-password.php" class="text-blue-600 hover:underline">
                            Request a new password reset link
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
                <a href="login.php" class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-md">
                    Go to Login
                </a>
            </div>
        <?php elseif ($valid_token): ?>
            <form action="?token=<?php echo htmlspecialchars($token); ?>" method="POST" class="space-y-6">
                <div>
                    <label for="password" class="block text-gray-700 text-sm font-bold mb-2">New Password</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                            <i class="fas fa-lock text-gray-400"></i>
                        </span>
                        <input type="password" id="password" name="password" 
                               class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                               required minlength="8">
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Password must be at least 8 characters long</p>
                </div>

                <div>
                    <label for="confirm_password" class="block text-gray-700 text-sm font-bold mb-2">Confirm New Password</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                            <i class="fas fa-lock text-gray-400"></i>
                        </span>
                        <input type="password" id="confirm_password" name="confirm_password" 
                               class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                               required minlength="8">
                    </div>
                </div>

                <div>
                    <button type="submit" 
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        Reset Password
                    </button>
                </div>
            </form>
        <?php endif; ?>
        
        <div class="mt-6 text-center">
            <a href="login.php" class="text-blue-600 hover:underline">
                Back to Login
            </a>
        </div>
    </div>
     <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
</body>
</html>

