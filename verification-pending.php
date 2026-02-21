<?php
session_start();

// Check if email is set in session or GET parameter
$email = $_SESSION['pending_verification_email'] ?? $_GET['email'] ?? '';

// Clear the session variable after using it
if (isset($_SESSION['pending_verification_email'])) {
    unset($_SESSION['pending_verification_email']);
}

// Mask email for privacy if needed
function maskEmail($email) {
    if (empty($email)) return '';
    
    $parts = explode('@', $email);
    if (count($parts) != 2) return $email;
    
    $name = $parts[0];
    $domain = $parts[1];
    
    // Show first 2 chars and last char of name part, rest as asterisks
    $nameLength = strlen($name);
    if ($nameLength <= 3) {
        $maskedName = $name[0] . str_repeat('*', $nameLength - 1);
    } else {
        $maskedName = substr($name, 0, 2) . str_repeat('*', $nameLength - 3) . $name[$nameLength - 1];
    }
    
    return $maskedName . '@' . $domain;
}

$maskedEmail = !empty($email) ? $email : 'your email address';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Almost There - Verify Your Email | Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-wastewise {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
        }
        .text-wastewise {
            color: #4CAF50;
        }
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.5;
            }
        }
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
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

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-12 max-w-4xl">
        <div class="text-center mb-10">
            <h1 class="text-4xl font-bold text-gray-800 mb-2">Almost There - Check Email To Activate</h1>
            <p class="text-xl text-gray-600">Your Wastewise Account: <span class="font-medium"><?php echo htmlspecialchars($maskedEmail); ?></span></p>
        </div>

        <div class="flex flex-col md:flex-row gap-8">
            <!-- Info Box -->
            <div class="bg-blue-50 p-6 rounded-lg shadow-sm border border-blue-100 md:w-1/2">
                <div class="flex items-start">
                    <div class="bg-blue-100 rounded-full p-2 mr-4">
                        <i class="fas fa-info-circle text-blue-500 text-xl"></i>
                    </div>
                    <div>
                        <p class="font-medium text-gray-700 mb-2">NOTE:</p>
                        <p class="text-gray-600 mb-3">
                            Mark our email as "not spam" and add us to your contacts list to receive important emails 
                            regarding your account. Don't see an email? Check your spam or junk email box.
                        </p>
                        <p class="text-gray-600">
                            The verification link will expire in 24 hours.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Verification Info -->
            <div class="md:w-1/2">
                <div id="verification-pending">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-4">Check your inbox and follow the link in your email to activate your account.</h2>
                    
                    <div class="bg-white p-4 border border-gray-200 rounded-md mb-6">
                        <p class="text-gray-600 mb-1">Email Sent to:</p>
                        <p class="font-medium text-gray-800"><?php echo htmlspecialchars($email); ?></p>
                    </div>
                    
                    <p class="text-gray-700 mb-6">
                        You must click the activation link in the email in order to activate your account and be able to use all Wastewise features.
                    </p>
                    
                    <div class="space-y-4">
                        <a href="resend-verification.php" class="block text-center py-2 px-4 bg-wastewise text-white rounded-md hover:bg-green-700 transition duration-200">
                            <i class="fas fa-paper-plane mr-2"></i> Resend Verification Email
                        </a>
                        
                        <a href="manual-verify.php" class="block text-center py-2 px-4 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 transition duration-200">
                            <i class="fas fa-keyboard mr-2"></i> Enter Verification Code Manually
                        </a>
                        
                        <a href="login.php" class="block text-center py-2 px-4 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 transition duration-200">
                            <i class="fas fa-arrow-left mr-2"></i> Back to Login
                        </a>
                    </div>
                </div>
                
                <!-- Verification Success (Hidden by default) -->
                <div id="verification-success" class="hidden text-center">
                    <div class="bg-green-50 p-6 rounded-lg border border-green-200 mb-6">
                        <i class="fas fa-check-circle text-green-500 text-5xl mb-4"></i>
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">Email Verified Successfully!</h2>
                        <p class="text-gray-600">Your account has been activated and you can now access all Wastewise features.</p>
                    </div>
                    
                    <p class="text-gray-700 mb-6">
                        You will be redirected to the login page in <span id="countdown" class="font-bold">5</span> seconds.
                    </p>
                    
                    <a href="login.php" class="inline-block py-2 px-6 bg-wastewise text-white rounded-md hover:bg-green-700 transition duration-200">
                        <i class="fas fa-sign-in-alt mr-2"></i> Login Now
                    </a>
                </div>
                
                <!-- Checking Status Indicator -->
                <div id="checking-status" class="hidden text-center py-4">
                    <div class="inline-flex items-center px-4 py-2 bg-blue-50 border border-blue-200 rounded-md">
                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>Checking verification status...</span>
                    </div>
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
        // Store the email to check
        const emailToCheck = "<?php echo htmlspecialchars($email); ?>";
        let checkInterval;
        let isVerified = false;
        
        // Function to check verification status
        async function checkVerificationStatus() {
            if (!emailToCheck || isVerified) return;
            
            try {
                document.getElementById('checking-status').classList.remove('hidden');
                
                const response = await fetch(`verification-status.php?email=${encodeURIComponent(emailToCheck)}`);
                const data = await response.json();
                
                document.getElementById('checking-status').classList.add('hidden');
                
                if (data.error) {
                    console.error('Error checking verification status:', data.error);
                    return;
                }
                
                if (data.verified) {
                    // Account is verified, show success message
                    isVerified = true;
                    document.getElementById('verification-pending').classList.add('hidden');
                    document.getElementById('verification-success').classList.remove('hidden');
                    
                    // Start countdown for redirect
                    startCountdown();
                    
                    // Clear the interval since we don't need to check anymore
                    if (checkInterval) {
                        clearInterval(checkInterval);
                    }
                }
            } catch (error) {
                console.error('Error checking verification status:', error);
                document.getElementById('checking-status').classList.add('hidden');
            }
        }
        
        // Function to start countdown
        function startCountdown() {
            let seconds = 5;
            const countdownElement = document.getElementById('countdown');
            
            const interval = setInterval(function() {
                seconds--;
                countdownElement.textContent = seconds;
                
                if (seconds <= 0) {
                    clearInterval(interval);
                    window.location.href = 'login.php';
                }
            }, 1000);
        }
        
        // Check immediately when page loads
        if (emailToCheck) {
            checkVerificationStatus();
            
            // Then check every 5 seconds
            checkInterval = setInterval(checkVerificationStatus, 5000);
        }
        
        // Set up event source for real-time updates if browser supports it
        if (!!window.EventSource && emailToCheck) {
            try {
                const eventSource = new EventSource(`verification-events.php?email=${encodeURIComponent(emailToCheck)}`);
                
                eventSource.onmessage = function(event) {
                    const data = JSON.parse(event.data);
                    if (data.verified) {
                        // Account is verified, show success message
                        isVerified = true;
                        document.getElementById('verification-pending').classList.add('hidden');
                        document.getElementById('verification-success').classList.remove('hidden');
                        
                        // Start countdown for redirect
                        startCountdown();
                        
                        // Close the event source
                        eventSource.close();
                        
                        // Clear the interval since we don't need to check anymore
                        if (checkInterval) {
                            clearInterval(checkInterval);
                        }
                    }
                };
                
                eventSource.onerror = function() {
                    // If there's an error with SSE, fall back to polling
                    eventSource.close();
                };
            } catch (e) {
                console.log('EventSource not supported or error:', e);
                // Continue with polling as fallback
            }
        }
    </script>
</body>
</html>
