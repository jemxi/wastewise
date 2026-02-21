<?php
// Enhanced fake seller login page to trap attackers
session_start();

// Function to log fake page access with comprehensive details
function logFakePageAccess($ip, $user_agent) {
    $timestamp = date('Y-m-d H:i:s');
    
    // Get GeoIP information
    $geoData = @file_get_contents("http://ip-api.com/json/{$ip}");
    $geo = ['country' => 'Unknown', 'city' => 'Unknown', 'isp' => 'Unknown'];
    if ($geoData) {
        $geoDecoded = json_decode($geoData, true);
        if ($geoDecoded && $geoDecoded['status'] === 'success') {
            $geo = [
                'country' => $geoDecoded['country'] ?? 'Unknown',
                'city' => $geoDecoded['city'] ?? 'Unknown',
                'isp' => $geoDecoded['isp'] ?? 'Unknown'
            ];
        }
    }
    
    // Get all headers
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $header_name = str_replace('HTTP_', '', $key);
            $header_name = str_replace('_', '-', $header_name);
            $headers[$header_name] = $value;
        }
    }
    
    $log_entry = [
        '=== HONEYPOT FAKE PAGE ACCESS ===' => '🎭 ATTACKER TRAPPED IN FAKE PAGE 🎭',
        'Visitor IP Address' => $ip,
        'Timestamp' => $timestamp,
        'Requested URL or Endpoint' => $_SERVER['REQUEST_URI'] ?? '/seller-Login.php',
        'HTTP Method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'User-Agent' => $user_agent ?: 'Unknown/Not Provided',
        'Referrer' => $_SERVER['HTTP_REFERER'] ?? '(Direct Access)',
        'GeoIP Location' => "Country: {$geo['country']}, City: {$geo['city']}, ISP: {$geo['isp']}",
        'Port Accessed' => $_SERVER['SERVER_PORT'] ?? '80',
        'Protocol Used' => isset($_SERVER['HTTPS']) ? 'HTTPS' : 'HTTP',
        'Honeypot Path Accessed' => '/seller-Login.php (FAKE LOGIN PAGE)',
        'Attack Status' => 'TRAPPED - Viewing fake interface',
        'Headers' => $headers
    ];
    
    // Format log entry
    $formatted_log = "\n" . str_repeat('=', 80) . "\n";
    $formatted_log .= "🎭 FAKE PAGE ACCESS - ATTACKER TRAPPED\n";
    $formatted_log .= str_repeat('=', 80) . "\n";
    
    foreach ($log_entry as $key => $value) {
        if (is_array($value)) {
            $formatted_log .= sprintf("%-35s: %s\n", $key, json_encode($value, JSON_PRETTY_PRINT));
        } else {
            $formatted_log .= sprintf("%-35s: %s\n", $key, $value);
        }
    }
    
    $formatted_log .= str_repeat('=', 80) . "\n\n";
    
    file_put_contents('honeypot_log.txt', $formatted_log, FILE_APPEND | LOCK_EX);
}

// Log this access
logFakePageAccess($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

$fake_error = '';

// Handle fake form submission - ALWAYS show error and log attempt
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_attempt = $_POST['login'] ?? '';
    $password_length = strlen($_POST['password'] ?? '');
    
    // Comprehensive fake login attempt logging
    $fake_log_entry = [
        '=== FAKE LOGIN ATTEMPT ===' => '🎯 ATTACKER ATTEMPTING LOGIN ON FAKE PAGE 🎯',
        'Visitor IP Address' => $_SERVER['REMOTE_ADDR'],
        'Timestamp' => date('Y-m-d H:i:s'),
        'Requested URL or Endpoint' => '/seller-Login.php (FAKE PAGE)',
        'HTTP Method' => 'POST',
        'User-Agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'Referrer' => $_SERVER['HTTP_REFERER'] ?? 'Direct access',
        'Login Attempted (Username / Password)' => $login_attempt . ':' . str_repeat('*', $password_length),
        'Exploit Payload or Input' => $login_attempt,
        'Attack Status' => 'PERSISTENT - Still trying on fake page',
        'Honeypot Path Accessed' => '/seller-Login.php (FAKE LOGIN INTERFACE)',
        'Time Wasted' => 'Attacker spending time on non-functional page'
    ];
    
    // Format and log
    $formatted_log = "\n" . str_repeat('=', 80) . "\n";
    $formatted_log .= "🎯 FAKE LOGIN ATTEMPT - WASTING ATTACKER TIME\n";
    $formatted_log .= str_repeat('=', 80) . "\n";
    
    foreach ($fake_log_entry as $key => $value) {
        $formatted_log .= sprintf("%-35s: %s\n", $key, $value);
    }
    
    $formatted_log .= str_repeat('=', 80) . "\n\n";
    
    file_put_contents('honeypot_log.txt', $formatted_log, FILE_APPEND | LOCK_EX);
    
    // Always show error to waste attacker's time
    $fake_error = "Invalid credentials. Please try again.";
    
    // Add random delay to waste more time
    sleep(rand(2, 5));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Login - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-wastewise {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
        }
        .password-toggle-icon {
            cursor: pointer;
            color: #9CA3AF;
        }
        .password-toggle-icon:hover {
            color: #4B5563;
        }
    </style>
</head>
<body class="bg-wastewise min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-2xl w-full max-w-md">
        <div class="text-center mb-8">
            <h2 class="text-4xl font-bold text-gray-800">Seller Login</h2>
            <p class="text-gray-600 mt-2">Access your Wastewise seller account</p>
        </div>

        <?php if ($fake_error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                <p><?php echo $fake_error; ?></p>
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-6" autocomplete="off">
            <div>
                <label for="login" class="block text-gray-700 text-sm font-bold mb-2">Username or Email</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="fas fa-user text-gray-400"></i>
                    </span>
                    <input type="text" id="login" name="login" value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>"
                           class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                           required>
                </div>
            </div>

            <div>
                <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="fas fa-lock text-gray-400"></i>
                    </span>
                    <input type="password" id="password" name="password"
                           class="w-full pl-10 pr-10 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-green-500"
                           required>
                    <span class="absolute inset-y-0 right-0 flex items-center pr-3 password-toggle-icon" onclick="togglePasswordVisibility('password')">
                        <i class="fas fa-eye" id="password-toggle-icon"></i>
                    </span>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <input type="checkbox" id="remember" name="remember" class="h-4 w-4 text-green-600 border-gray-300 rounded">
                    <label for="remember" class="ml-2 text-sm text-gray-700">Remember me</label>
                </div>
                <div class="text-sm">
                    <a href="#" class="text-green-600 hover:text-green-500">Forgot your password?</a>
                </div>
            </div>

            <div>
                <button type="submit" class="w-full py-2 px-4 text-white bg-green-600 hover:bg-green-700 rounded-md shadow-sm text-sm font-medium">
                    Sign in as Seller
                </button>
            </div>
        </form>

        <div class="mt-6">
            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-300"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-2 bg-white text-gray-500">Not a seller yet?</span>
                </div>
            </div>
            <div class="mt-6">
                <a href="#" class="w-full flex justify-center py-2 px-4 border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 rounded-md text-sm font-medium">
                    Register as a Seller
                </a>
            </div>
        </div>

        <p class="mt-8 text-center text-sm text-gray-600">
            <a href="#" class="text-green-600 hover:text-green-500">
                <i class="fas fa-arrow-left mr-1"></i> Back to Customer Login
            </a>
        </p>
    </div>
 <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
    <script>
        function togglePasswordVisibility(inputId) {
            const passwordInput = document.getElementById(inputId);
            const toggleIcon = document.getElementById(inputId + '-toggle-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Add some fake JavaScript to make it look more legitimate
        console.log('Seller login system initialized');
        
        // Fake loading delay
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                console.log('Authentication system ready');
            }, 1000);
        });
    </script>
</body>
</html>
