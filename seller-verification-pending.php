<?php
session_start();

// Check if there's a pending verification email
if (!isset($_SESSION['pending_verification_email']) || !isset($_SESSION['is_seller_registration'])) {
    header("Location: seller-centre.php");
    exit;
}

$email = $_SESSION['pending_verification_email'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Pending - Wastewise</title>
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
                <i class="fas fa-envelope text-green-500 text-5xl mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-800">Verification Email Sent</h1>
            </div>
            
            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200 mb-6">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-info-circle text-blue-500 mt-0.5"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-blue-800">Check your email</h3>
                        <div class="mt-2 text-sm text-blue-700">
                            <p>We've sent a verification email to <strong><?php echo htmlspecialchars($email); ?></strong>.</p>
                            <p class="mt-2">Please check your inbox and click on the verification link to complete your seller registration.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="space-y-4">
                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <h3 class="font-medium text-gray-800">Didn't receive the email?</h3>
                    <ul class="mt-2 text-sm text-gray-600 space-y-2">
                        <li><i class="fas fa-check-circle text-green-500 mr-2"></i> Check your spam or junk folder</li>
                        <li><i class="fas fa-check-circle text-green-500 mr-2"></i> Make sure you entered the correct email address</li>
                        <li><i class="fas fa-check-circle text-green-500 mr-2"></i> Wait a few minutes for the email to arrive</li>
                    </ul>
                    <div class="mt-4">
                        <button id="resend-btn" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                            <i class="fas fa-redo mr-1"></i> Resend verification email
                        </button>
                        <p id="resend-message" class="text-green-600 text-sm mt-2 hidden">Verification email has been resent!</p>
                    </div>
                </div>
                
                <div class="text-center mt-6">
                    <a href="seller-centre.php" class="text-gray-600 hover:text-gray-800 text-sm">
                        <i class="fas fa-arrow-left mr-1"></i> Return to Seller Registration
                    </a>
                </div>
            </div>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const resendBtn = document.getElementById('resend-btn');
            const resendMessage = document.getElementById('resend-message');
            
            resendBtn.addEventListener('click', function() {
                // Disable button and show loading state
                resendBtn.disabled = true;
                resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Sending...';
                
                // Simulate sending email (replace with actual AJAX call)
                setTimeout(function() {
                    resendBtn.innerHTML = '<i class="fas fa-redo mr-1"></i> Resend verification email';
                    resendBtn.disabled = false;
                    
                    // Show success message
                    resendMessage.classList.remove('hidden');
                    
                    // Hide message after 5 seconds
                    setTimeout(function() {
                        resendMessage.classList.add('hidden');
                    }, 5000);
                }, 2000);
            });
        });
    </script>
</body>
</html>