<?php
session_start(); // Required for session duration tracking
date_default_timezone_set('Asia/Manila'); // Set your timezone

// Track session start time
if (!isset($_SESSION['honeypot_start'])) {
    $_SESSION['honeypot_start'] = time();
}

$timestamp = date('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN_AGENT';
$request_uri = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN_URI';
$http_method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN_METHOD';
$referrer = $_SERVER['HTTP_REFERER'] ?? 'No Referrer';
$port = $_SERVER['REMOTE_PORT'] ?? 'UNKNOWN_PORT';
$protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'UNKNOWN_PROTOCOL';
$script = $_SERVER['SCRIPT_NAME'] ?? 'UNKNOWN_SCRIPT';
$query_string = $_SERVER['QUERY_STRING'] ?? 'None';
$content_type = $_SERVER['CONTENT_TYPE'] ?? 'Not Specified';
$encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? 'Not Specified';

// Session duration
$session_start_time = $_SESSION['honeypot_start'];
$session_duration = time() - $session_start_time;
$session_duration_formatted = gmdate("H:i:s", $session_duration);

// === Fetch GeoIP Location ===
$geo = ['country' => 'Unknown', 'city' => 'Unknown', 'isp' => 'Unknown'];
$geo_response = @file_get_contents("http://ip-api.com/json/{$ip}");
if ($geo_response) {
    $geo_data = json_decode($geo_response, true);
    if ($geo_data && $geo_data['status'] === 'success') {
        $geo['country'] = $geo_data['country'] ?? 'Unknown';
        $geo['city'] = $geo_data['city'] ?? 'Unknown';
        $geo['isp'] = $geo_data['isp'] ?? 'Unknown';
    }
}
$geo_location = "Country: {$geo['country']}, City: {$geo['city']}, ISP: {$geo['isp']}";

// === Begin structured log ===
$log_entry = "\n" . str_repeat('=', 80) . "\n";
$log_entry .= "🛑 HONEYPOT TRIGGERED - BOT OR ATTACKER ACTIVITY DETECTED\n";
$log_entry .= str_repeat('=', 80) . "\n";
$log_entry .= "Timestamp               : $timestamp\n";
$log_entry .= "IP Address              : $ip\n";
$log_entry .= "Port Accessed           : $port\n";
$log_entry .= "Protocol Used           : $protocol\n";
$log_entry .= "HTTP Method             : $http_method\n";
$log_entry .= "Request URI             : $request_uri\n";
$log_entry .= "Script Accessed         : $script\n";
$log_entry .= "Referrer                : $referrer\n";
$log_entry .= "Content-Type            : $content_type\n";
$log_entry .= "Encoding                : $encoding\n";
$log_entry .= "User Agent              : $user_agent\n";
$log_entry .= "Session Duration        : $session_duration_formatted\n";
$log_entry .= "GeoIP Location          : $geo_location\n";

// Log credentials if submitted
if ($http_method === 'POST') {
    $username = $_POST['username'] ?? '[EMPTY]';
    $password = $_POST['password'] ?? '[EMPTY]';
    $log_entry .= "Submitted Username      : $username\n";
    $log_entry .= "Submitted Password      : $password\n";
    $log_entry .= "Login Length (chars)    : " . strlen($username) . "\n";
    $log_entry .= "Password Length (chars) : " . strlen($password) . "\n";
}

$log_entry .= str_repeat('=', 80) . "\n\n";

// Save to log file
file_put_contents('log2.txt', $log_entry, FILE_APPEND | LOCK_EX);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Wastewise E-commerce</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-r from-blue-500 to-purple-600 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-2xl w-full max-w-md">
        <div class="text-center mb-8">
            <div class="flex justify-center mb-4">
                <span class="inline-flex items-center justify-center h-16 w-16 rounded-full bg-blue-100">
                    <i class="fas fa-user-shield text-3xl text-blue-600"></i>
                </span>
            </div>
            <h2 class="text-4xl font-bold text-gray-800">Admin Login</h2>
            <p class="text-gray-600 mt-2">Access Wastewise admin panel</p>
        </div>
        
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-6" autocomplete="off">
            <div>
                <label for="username" class="block text-gray-700 text-sm font-bold mb-2">Admin Username</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="fas fa-user-shield text-gray-400"></i>
                    </span>
                    <input type="text" id="username" name="username" 
                           class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-blue-500"
                           required autocomplete="off">
                </div>
            </div>

            <div>
                <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                        <i class="fas fa-lock text-gray-400"></i>
                    </span>
                    <input type="password" id="password" name="password" 
                           class="w-full pl-10 pr-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-blue-500"
                           required autocomplete="new-password">
                </div>
            </div>

            <div>
                <button type="submit" 
                        class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Sign in to Admin Panel
                </button>
            </div>
        </form>

        <div class="mt-6 text-center">
            <a href="login.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-gray-700 bg-gray-200 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                <i class="fas fa-arrow-left mr-2"></i>
                Back to User Login
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