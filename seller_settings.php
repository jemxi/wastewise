<?php
session_start();
require 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Function to check if a table exists
function tableExists($pdo, $table) {
    try {
        $result = $pdo->query("SELECT 1 FROM $table LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Create required tables if they don't exist
function createRequiredTables($pdo) {
    $tables_created = false;
    
    // Create seller_preferences table if it doesn't exist
    if (!tableExists($pdo, 'seller_preferences')) {
        try {
            $pdo->exec("
                CREATE TABLE `seller_preferences` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `seller_id` int(11) NOT NULL,
                  `email_notifications` tinyint(1) NOT NULL DEFAULT 1,
                  `order_notifications` tinyint(1) NOT NULL DEFAULT 1,
                  `product_notifications` tinyint(1) NOT NULL DEFAULT 1,
                  `promotion_notifications` tinyint(1) NOT NULL DEFAULT 1,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `seller_id` (`seller_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $tables_created = true;
        } catch (PDOException $e) {
            // Silently fail, we'll handle missing tables gracefully
        }
    }
    
    // Create seller_payment_info table if it doesn't exist
    if (!tableExists($pdo, 'seller_payment_info')) {
        try {
            $pdo->exec("
                CREATE TABLE `seller_payment_info` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `seller_id` int(11) NOT NULL,
                  `bank_name` varchar(255) NOT NULL,
                  `account_name` varchar(255) NOT NULL,
                  `account_number` varchar(255) NOT NULL,
                  `payment_method` varchar(50) NOT NULL DEFAULT 'bank_transfer',
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `seller_id` (`seller_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $tables_created = true;
        } catch (PDOException $e) {
            // Silently fail, we'll handle missing tables gracefully
        }
    }
    
    // Create seller_store_appearance table if it doesn't exist
    if (!tableExists($pdo, 'seller_store_appearance')) {
        try {
            $pdo->exec("
                CREATE TABLE `seller_store_appearance` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `seller_id` int(11) NOT NULL,
                  `store_theme` varchar(50) NOT NULL DEFAULT 'default',
                  `banner_text` varchar(255) DEFAULT NULL,
                  `primary_color` varchar(20) NOT NULL DEFAULT '#4CAF50',
                  `show_featured_products` tinyint(1) NOT NULL DEFAULT 1,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `seller_id` (`seller_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            $tables_created = true;
        } catch (PDOException $e) {
            // Silently fail, we'll handle missing tables gracefully
        }
    }
    
    return $tables_created;
}

// Create required tables if they don't exist
$tables_created = createRequiredTables($pdo);

// Check if user is a seller
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    
    if (!$user || $user['role'] !== 'seller') {
        // Not a seller, redirect to seller registration
        header('Location: seller-centre.php');
        exit();
    }
    
    // Get seller information
    $stmt = $pdo->prepare("SELECT * FROM sellers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $seller = $stmt->fetch();
    
    if (!$seller) {
        // Seller record not found, redirect to seller registration
        header('Location: seller-centre.php');
        exit();
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Handle status messages
$status_class = '';
$status_message = '';

switch ($seller['status']) {
    case 'pending':
        $status_class = 'bg-yellow-100 text-yellow-800 border-yellow-200';
        $status_message = 'Your seller account is pending approval. We will review your application and notify you once it\'s approved.';
        break;
    case 'approved':
        $status_class = 'bg-green-100 text-green-800 border-green-200';
        $status_message = 'Your seller account is approved. You can now start selling on Wastewise!';
        break;
    case 'rejected':
        $status_class = 'bg-red-100 text-red-800 border-red-200';
        $status_message = 'Your seller application was rejected. Reason: ' . $seller['rejection_reason'];
        break;
    case 'suspended':
        $status_class = 'bg-red-100 text-red-800 border-red-200';
        $status_message = 'Your seller account is currently suspended. Please contact support for more information.';
        break;
}

// Handle form submissions
$success_message = '';
$error_message = '';

// Handle business information update
if (isset($_POST['update_business'])) {
    $business_name = trim($_POST['business_name']);
    $business_type = trim($_POST['business_type']);
    $business_description = trim($_POST['business_description']);
    $business_address = trim($_POST['business_address']);
    $business_phone = trim($_POST['business_phone']);
    $business_website = trim($_POST['business_website']);
    
    // Validate inputs
    if (empty($business_name) || empty($business_type) || empty($business_address) || empty($business_phone)) {
        $error_message = "Please fill in all required fields.";
    } else {
        try {
            // Update seller information - using only columns that exist in the table
            $stmt = $pdo->prepare("
                UPDATE sellers 
                SET business_name = ?, business_type = ?, description = ?, 
                    business_address = ?, phone_number = ?, website = ?
                WHERE user_id = ?
            ");
            $stmt->execute([
                $business_name, $business_type, $business_description, 
                $business_address, $business_phone, $business_website, 
                $_SESSION['user_id']
            ]);
            
            // Refresh seller data
            $stmt = $pdo->prepare("SELECT * FROM sellers WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $seller = $stmt->fetch();
            
            $success_message = "Business information updated successfully.";
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Handle logo upload
if (isset($_FILES['business_logo']) && $_FILES['business_logo']['error'] == 0) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    $filename = $_FILES['business_logo']['name'];
    $filetype = pathinfo($filename, PATHINFO_EXTENSION);
    
    // Verify file extension
    if (in_array(strtolower($filetype), $allowed)) {
        // Create upload directory if it doesn't exist
        $upload_dir = 'uploads/seller_logos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // Create unique filename
        $new_filename = 'seller_' . $seller['id'] . '_' . time() . '.' . $filetype;
        $upload_path = $upload_dir . $new_filename;
        
        // Upload file
        if (move_uploaded_file($_FILES['business_logo']['tmp_name'], $upload_path)) {
            try {
                // Update logo path in database
                $stmt = $pdo->prepare("UPDATE sellers SET logo_url = ? WHERE user_id = ?");
                $stmt->execute([$upload_path, $_SESSION['user_id']]);
                
                // Refresh seller data
                $stmt = $pdo->prepare("SELECT * FROM sellers WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $seller = $stmt->fetch();
                
                $success_message = "Business logo updated successfully.";
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
            }
        } else {
            $error_message = "Failed to upload logo. Please try again.";
        }
    } else {
        $error_message = "Invalid file type. Please upload JPG, JPEG, PNG, or GIF files only.";
    }
}

// Handle password change
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = "Please fill in all password fields.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $error_message = "New password must be at least 8 characters long.";
    } else {
        try {
            // Verify current password
            if (password_verify($current_password, $user['password']) || hash('sha256', $current_password) === $user['password']) {
                // Update password - handle both password formats
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                
                $success_message = "Password changed successfully.";
            } else {
                $error_message = "Current password is incorrect.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Handle notification preferences
if (isset($_POST['update_notifications'])) {
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $order_notifications = isset($_POST['order_notifications']) ? 1 : 0;
    $product_notifications = isset($_POST['product_notifications']) ? 1 : 0;
    $promotion_notifications = isset($_POST['promotion_notifications']) ? 1 : 0;
    
    try {
        // Check if notification preferences exist
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM seller_preferences 
            WHERE seller_id = ?
        ");
        $stmt->execute([$seller['id']]);
        $preferences_exist = (bool)$stmt->fetchColumn();
        
        if ($preferences_exist) {
            // Update existing preferences
            $stmt = $pdo->prepare("
                UPDATE seller_preferences 
                SET email_notifications = ?, order_notifications = ?, 
                    product_notifications = ?, promotion_notifications = ?
                WHERE seller_id = ?
            ");
            $stmt->execute([
                $email_notifications, $order_notifications, 
                $product_notifications, $promotion_notifications, 
                $seller['id']
            ]);
        } else {
            // Insert new preferences
            $stmt = $pdo->prepare("
                INSERT INTO seller_preferences 
                (seller_id, email_notifications, order_notifications, product_notifications, promotion_notifications)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $seller['id'], $email_notifications, $order_notifications, 
                $product_notifications, $promotion_notifications
            ]);
        }
        
        $success_message = "Notification preferences updated successfully.";
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Handle payment information update
if (isset($_POST['update_payment'])) {
    $bank_name = trim($_POST['bank_name']);
    $account_name = trim($_POST['account_name']);
    $account_number = trim($_POST['account_number']);
    $payment_method = trim($_POST['payment_method']);
    
    // Validate inputs
    if (empty($bank_name) || empty($account_name) || empty($account_number) || empty($payment_method)) {
        $error_message = "Please fill in all payment information fields.";
    } else {
        try {
            // Check if payment information exists
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM seller_payment_info 
                WHERE seller_id = ?
            ");
            $stmt->execute([$seller['id']]);
            $payment_info_exists = (bool)$stmt->fetchColumn();
            
            if ($payment_info_exists) {
                // Update existing payment information
                $stmt = $pdo->prepare("
                    UPDATE seller_payment_info 
                    SET bank_name = ?, account_name = ?, account_number = ?, payment_method = ?
                    WHERE seller_id = ?
                ");
                $stmt->execute([
                    $bank_name, $account_name, $account_number, $payment_method, $seller['id']
                ]);
            } else {
                // Insert new payment information
                $stmt = $pdo->prepare("
                    INSERT INTO seller_payment_info 
                    (seller_id, bank_name, account_name, account_number, payment_method)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $seller['id'], $bank_name, $account_name, $account_number, $payment_method
                ]);
            }
            
            $success_message = "Payment information updated successfully.";
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Handle store appearance update
if (isset($_POST['update_appearance'])) {
    $store_theme = trim($_POST['store_theme']);
    $banner_text = trim($_POST['banner_text']);
    $primary_color = trim($_POST['primary_color']);
    $show_featured_products = isset($_POST['show_featured_products']) ? 1 : 0;
    
    try {
        // Check if store appearance exists
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM seller_store_appearance 
            WHERE seller_id = ?
        ");
        $stmt->execute([$seller['id']]);
        $appearance_exists = (bool)$stmt->fetchColumn();
        
        if ($appearance_exists) {
            // Update existing appearance
            $stmt = $pdo->prepare("
                UPDATE seller_store_appearance 
                SET store_theme = ?, banner_text = ?, primary_color = ?, show_featured_products = ?
                WHERE seller_id = ?
            ");
            $stmt->execute([
                $store_theme, $banner_text, $primary_color, $show_featured_products, $seller['id']
            ]);
        } else {
            // Insert new appearance
            $stmt = $pdo->prepare("
                INSERT INTO seller_store_appearance 
                (seller_id, store_theme, banner_text, primary_color, show_featured_products)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $seller['id'], $store_theme, $banner_text, $primary_color, $show_featured_products
            ]);
        }
        
        $success_message = "Store appearance updated successfully.";
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Get seller preferences
try {
    if (tableExists($pdo, 'seller_preferences')) {
        $stmt = $pdo->prepare("SELECT * FROM seller_preferences WHERE seller_id = ?");
        $stmt->execute([$seller['id']]);
        $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!isset($preferences) || !$preferences) {
        $preferences = [
            'email_notifications' => 1,
            'order_notifications' => 1,
            'product_notifications' => 1,
            'promotion_notifications' => 1
        ];
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $preferences = [
        'email_notifications' => 1,
        'order_notifications' => 1,
        'product_notifications' => 1,
        'promotion_notifications' => 1
    ];
}

// Get seller payment information
try {
    if (tableExists($pdo, 'seller_payment_info')) {
        $stmt = $pdo->prepare("SELECT * FROM seller_payment_info WHERE seller_id = ?");
        $stmt->execute([$seller['id']]);
        $payment_info = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $payment_info = null;
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $payment_info = null;
}

// Get seller store appearance
try {
    if (tableExists($pdo, 'seller_store_appearance')) {
        $stmt = $pdo->prepare("SELECT * FROM seller_store_appearance WHERE seller_id = ?");
        $stmt->execute([$seller['id']]);
        $store_appearance = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!isset($store_appearance) || !$store_appearance) {
        $store_appearance = [
            'store_theme' => 'default',
            'banner_text' => '',
            'primary_color' => '#4CAF50',
            'show_featured_products' => 1
        ];
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $store_appearance = [
        'store_theme' => 'default',
        'banner_text' => '',
        'primary_color' => '#4CAF50',
        'show_featured_products' => 1
    ];
}

// Active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'business';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Settings - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-wastewise {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
        }
        .text-wastewise {
            color: #4CAF50;
        }
        .border-wastewise {
            border-color: #4CAF50;
        }
        footer {
          background-color: #2f855a;
          color: white;
          text-align: center;
          padding: 1rem 0;
          z-index: 30;
      }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
 <header class="bg-green-700 text-white py-6">
<div class="container mx-auto px-4">
  <div class="flex flex-col md:flex-row justify-between items-center w-full gap-4">
    <div class="flex justify-start items-center w-full md:w-1/3 space-x-2">
      <img src="logo.png" alt="Wastewise Logo" class="h-8 w-8">
      <h1 class="text-2xl font-bold">Wastewise</h1>
    </div>
            <div class="flex justify-end w-full md:w-1/3">
      <div class="relative inline-block text-left">
         <button onclick="toggleDropdown()" class="flex items-center gap-2  text-white px-4 py-2">
          <i class="fas fa-cog"></i>
        </button>
        <div id="userMenuDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg py-2 z-50">
         <button onclick="openLogoutModal()" class="block w-full text-left px-4 py-2 text-red-600 hover:bg-red-100">
  <i class="fas fa-sign-out-alt mr-2"></i> Logout
</button>

        </div>
      </div>
    </div>
  </div>
</div>
</header>

<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
  <div class="bg-gray-200 bg-opacity-90 p-6 rounded-lg shadow-lg w-full max-w-sm text-center relative">
    <h2 class="text-xl font-semibold text-gray-900 mb-4">Are you sure you want to log out?</h2>
    
    <div class="flex justify-center gap-4">
      <form action="seller-logout.php" method="POST">
        <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Yes</button>
      </form>
      <button onclick="closeLogoutModal()" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">No</button>
    </div>
  </div>
</div>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <?php if ($tables_created): ?>
            <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-6">
                <p>Database tables were automatically created for the settings page. You're all set!</p>
            </div>
        <?php endif; ?>
        
        <!-- Status Banner -->
        <?php if ($status_message): ?>
            <div class="<?php echo $status_class; ?> border p-4 rounded-lg mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <?php if ($seller['status'] === 'approved'): ?>
                            <i class="fas fa-check-circle text-green-500"></i>
                        <?php elseif ($seller['status'] === 'pending'): ?>
                            <i class="fas fa-clock text-yellow-500"></i>
                        <?php else: ?>
                            <i class="fas fa-exclamation-circle text-red-500"></i>
                        <?php endif; ?>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium">
                            Account Status: <span class="font-bold"><?php echo ucfirst($seller['status']); ?></span>
                        </h3>
                        <div class="mt-2 text-sm">
                            <p><?php echo $status_message; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                <p><?php echo $success_message; ?></p>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error_message; ?></p>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <!-- Sidebar -->
            <div class="md:col-span-1">
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <div class="text-center mb-6">
                        <div class="w-24 h-24 rounded-full bg-gray-200 mx-auto mb-4 flex items-center justify-center">
                            <?php if (isset($seller['logo_url']) && $seller['logo_url']): ?>
                                <img src="<?php echo htmlspecialchars($seller['logo_url']); ?>" alt="Business Logo" class="w-full h-full rounded-full object-cover">
                            <?php else: ?>
                                <i class="fas fa-store text-gray-400 text-4xl"></i>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($seller['business_name']); ?></h2>
                        <p class="text-gray-600"><?php echo htmlspecialchars($seller['business_type']); ?></p>
                    </div>
                    
                    <nav class="space-y-2">
                        <a href="seller-dashboard.php?tab=dashboard" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-md">
                            <i class="fas fa-tachometer-alt w-5 mr-2"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="seller-dashboard.php?tab=products" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-md">
                            <i class="fas fa-box w-5 mr-2"></i>
                            <span>Products</span>
                        </a>
                        <a href="seller-dashboard.php?tab=archived" class="flex items-center px-4 py-2 <?php echo $active_tab === 'archived' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-archive w-5 mr-2"></i>
                            <span>Archived Products</span>
                        </a>
                        <a href="seller_orders.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-md">
                            <i class="fas fa-shopping-cart w-5 mr-2"></i>
                            <span>Orders</span>
                        </a>
                        <a href="seller_analytics.php" class="flex items-center px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-md">
                            <i class="fas fa-chart-line w-5 mr-2"></i>
                            <span>Analytics</span>
                        </a>
                        <a href="seller_settings.php" class="flex items-center px-4 py-2 bg-green-100 text-green-800 rounded-md">
                            <i class="fas fa-cog w-5 mr-2"></i>
                            <span>Settings</span>
                        </a>
                    </nav>
                </div>
                
                <!-- Settings Navigation -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Settings</h3>
                    <nav class="space-y-2">
                        <a href="?tab=business" class="flex items-center px-4 py-2 <?php echo $active_tab === 'business' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-store w-5 mr-2"></i>
                            <span>Business Information</span>
                        </a>
                        <a href="?tab=password" class="flex items-center px-4 py-2 <?php echo $active_tab === 'password' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-lock w-5 mr-2"></i>
                            <span>Change Password</span>
                        </a>
                        <a href="?tab=notifications" class="flex items-center px-4 py-2 <?php echo $active_tab === 'notifications' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-bell w-5 mr-2"></i>
                            <span>Notification Preferences</span>
                        </a>
                        <a href="?tab=payment" class="flex items-center px-4 py-2 <?php echo $active_tab === 'payment' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-credit-card w-5 mr-2"></i>
                            <span>Payment Information</span>
                        </a>
                        <a href="?tab=appearance" class="flex items-center px-4 py-2 <?php echo $active_tab === 'appearance' ? 'bg-green-100 text-green-800' : 'text-gray-700 hover:bg-gray-100'; ?> rounded-md">
                            <i class="fas fa-paint-brush w-5 mr-2"></i>
                            <span>Store Appearance</span>
                        </a>
                    </nav>
                </div>
            </div>
            
            <!-- Main Content Area -->
            <div class="md:col-span-3">
                <!-- Settings Header -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">
                        <?php if ($active_tab === 'business'): ?>
                            Business Information
                        <?php elseif ($active_tab === 'password'): ?>
                            Change Password
                        <?php elseif ($active_tab === 'notifications'): ?>
                            Notification Preferences
                        <?php elseif ($active_tab === 'payment'): ?>
                            Payment Information
                        <?php elseif ($active_tab === 'appearance'): ?>
                            Store Appearance
                        <?php endif; ?>
                    </h2>
                    <p class="text-gray-600">
                        <?php if ($active_tab === 'business'): ?>
                            Update your business details and profile
                        <?php elseif ($active_tab === 'password'): ?>
                            Manage your account security
                        <?php elseif ($active_tab === 'notifications'): ?>
                            Control how you receive notifications
                        <?php elseif ($active_tab === 'payment'): ?>
                            Manage your payment methods and payout details
                        <?php elseif ($active_tab === 'appearance'): ?>
                            Customize how your store looks to customers
                        <?php endif; ?>
                    </p>
                </div>
                
                <!-- Business Information Settings -->
                <?php if ($active_tab === 'business'): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Business Details</h3>
                        <form action="seller_settings.php?tab=business" method="POST" class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="business_name" class="block text-sm font-medium text-gray-700 mb-1">Business Name *</label>
                                    <input type="text" id="business_name" name="business_name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($seller['business_name']); ?>" required>
                                </div>
                                <div>
                                    <label for="business_type" class="block text-sm font-medium text-gray-700 mb-1">Business Type *</label>
                                    <select id="business_type" name="business_type" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required>
                                        <option value="Sole Proprietorship" <?php echo $seller['business_type'] === 'Sole Proprietorship' ? 'selected' : ''; ?>>Sole Proprietorship</option>
                                        <option value="Partnership" <?php echo $seller['business_type'] === 'Partnership' ? 'selected' : ''; ?>>Partnership</option>
                                        <option value="Corporation" <?php echo $seller['business_type'] === 'Corporation' ? 'selected' : ''; ?>>Corporation</option>
                                        <option value="Limited Liability Company (LLC)" <?php echo $seller['business_type'] === 'Limited Liability Company (LLC)' ? 'selected' : ''; ?>>Limited Liability Company (LLC)</option>
                                        <option value="Cooperative" <?php echo $seller['business_type'] === 'Cooperative' ? 'selected' : ''; ?>>Cooperative</option>
                                        <option value="Non-profit Organization" <?php echo $seller['business_type'] === 'Non-profit Organization' ? 'selected' : ''; ?>>Non-profit Organization</option>
                                        <option value="Social Enterprise" <?php echo $seller['business_type'] === 'Social Enterprise' ? 'selected' : ''; ?>>Social Enterprise</option>
                                        <option value="Franchise" <?php echo $seller['business_type'] === 'Franchise' ? 'selected' : ''; ?>>Franchise</option>
                                        <option value="Other" <?php echo $seller['business_type'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label for="business_description" class="block text-sm font-medium text-gray-700 mb-1">Business Description</label>
                                <textarea id="business_description" name="business_description" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"><?php echo htmlspecialchars($seller['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="business_address" class="block text-sm font-medium text-gray-700 mb-1">Business Address *</label>
                                    <input type="text" id="business_address" name="business_address" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($seller['business_address'] ?? ''); ?>" required>
                                </div>
                                <div>
                                    <label for="business_phone" class="block text-sm font-medium text-gray-700 mb-1">Business Phone *</label>
                                    <input type="tel" id="business_phone" name="business_phone" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($seller['phone_number'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div>
                                <label for="business_website" class="block text-sm font-medium text-gray-700 mb-1">Business Website</label>
                                <input type="url" id="business_website" name="business_website" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($seller['website'] ?? ''); ?>">
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" name="update_business" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Business Logo</h3>
                        <form action="seller_settings.php?tab=business" method="POST" enctype="multipart/form-data" class="space-y-4">
                            <div class="flex items-center space-x-6">
                                <div class="w-24 h-24 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden">
                                    <?php if (isset($seller['logo_url']) && $seller['logo_url']): ?>
                                        <img src="<?php echo htmlspecialchars($seller['logo_url']); ?>" alt="Business Logo" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <i class="fas fa-store text-gray-400 text-4xl"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload New Logo</label>
                                    <input type="file" name="business_logo" accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <p class="text-xs text-gray-500 mt-1">Recommended size: 500x500 pixels. Max file size: 2MB. Supported formats: JPG, PNG, GIF.</p>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    Upload Logo
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                
                <!-- Password Settings -->
                <?php if ($active_tab === 'password'): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Change Password</h3>
                        <form action="seller_settings.php?tab=password" method="POST" class="space-y-4">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password *</label>
                                <input type="password" id="current_password" name="current_password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required>
                            </div>
                            
                            <div>
                                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password *</label>
                                <input type="password" id="new_password" name="new_password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required>
                                <p class="text-xs text-gray-500 mt-1">Password must be at least 8 characters long and include a mix of letters, numbers, and symbols.</p>
                            </div>
                            
                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password *</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" name="change_password" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Account Security Tips</h3>
                        <ul class="space-y-2 text-gray-700">
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                                <span>Use a unique password that you don't use for other websites.</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                                <span>Include a mix of uppercase letters, lowercase letters, numbers, and symbols.</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                                <span>Avoid using easily guessable information like birthdays or names.</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                                <span>Change your password regularly, at least every 3-6 months.</span>
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                                <span>Never share your password with anyone, including Wastewise support staff.</span>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <!-- Notification Preferences -->
                <?php if ($active_tab === 'notifications'): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Notification Settings</h3>
                        <form action="seller_settings.php?tab=notifications" method="POST" class="space-y-4">
                            <div class="space-y-4">
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input type="checkbox" id="email_notifications" name="email_notifications" class="h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500" <?php echo $preferences['email_notifications'] ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="email_notifications" class="font-medium text-gray-700">Email Notifications</label>
                                        <p class="text-gray-500">Receive important updates and notifications via email.</p>
                                    </div>
                                </div>
                                
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input type="checkbox" id="order_notifications" name="order_notifications" class="h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500" <?php echo $preferences['order_notifications'] ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="order_notifications" class="font-medium text-gray-700">Order Notifications</label>
                                        <p class="text-gray-500">Receive notifications when you receive new orders, order updates, or cancellations.</p>
                                    </div>
                                </div>
                                
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input type="checkbox" id="product_notifications" name="product_notifications" class="h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500" <?php echo $preferences['product_notifications'] ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="product_notifications" class="font-medium text-gray-700">Product Notifications</label>
                                        <p class="text-gray-500">Receive notifications about product approvals, rejections, or when inventory is low.</p>
                                    </div>
                                </div>
                                
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input type="checkbox" id="promotion_notifications" name="promotion_notifications" class="h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500" <?php echo $preferences['promotion_notifications'] ? 'checked' : ''; ?>>
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="promotion_notifications" class="font-medium text-gray-700">Promotional Notifications</label>
                                        <p class="text-gray-500">Receive notifications about platform promotions, marketing opportunities, and seller events.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" name="update_notifications" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    Save Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                
                <!-- Payment Information -->
                <?php if ($active_tab === 'payment'): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Payment Details</h3>
                        <form action="seller_settings.php?tab=payment" method="POST" class="space-y-4">
                            <div>
                                <label for="payment_method" class="block text-sm font-medium text-gray-700 mb-1">Payment Method *</label>
                                <select id="payment_method" name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" required>
                                    <option value="bank_transfer" <?php echo isset($payment_info['payment_method']) && $payment_info['payment_method'] === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                    <option value="gcash" <?php echo isset($payment_info['payment_method']) && $payment_info['payment_method'] === 'gcash' ? 'selected' : ''; ?>>GCash</option>
                                    <option value="paymaya" <?php echo isset($payment_info['payment_method']) && $payment_info['payment_method'] === 'paymaya' ? 'selected' : ''; ?>>PayMaya</option>
                                    <option value="paypal" <?php echo isset($payment_info['payment_method']) && $payment_info['payment_method'] === 'paypal' ? 'selected' : ''; ?>>PayPal</option>
                                </select>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="bank_name" class="block text-sm font-medium text-gray-700 mb-1">Bank/Provider Name *</label>
                                    <input type="text" id="bank_name" name="bank_name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($payment_info['bank_name'] ?? ''); ?>" required>
                                </div>
                                <div>
                                    <label for="account_name" class="block text-sm font-medium text-gray-700 mb-1">Account Name *</label>
                                    <input type="text" id="account_name" name="account_name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($payment_info['account_name'] ?? ''); ?>" required>
                                </div>
                            </div>
                            
                            <div>
                                <label for="account_number" class="block text-sm font-medium text-gray-700 mb-1">Account Number/ID *</label>
                                <input type="text" id="account_number" name="account_number" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($payment_info['account_number'] ?? ''); ?>" required>
                                <p class="text-xs text-gray-500 mt-1">This information is securely stored and only used for processing your payouts.</p>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" name="update_payment" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    Save Payment Information
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Payout Schedule</h3>
                        <div class="space-y-4">
                            <p class="text-gray-700">Wastewise processes payouts to sellers on the following schedule:</p>
                            <ul class="space-y-2 text-gray-700">
                                <li class="flex items-start">
                                    <i class="fas fa-calendar-check text-green-500 mt-1 mr-2"></i>
                                    <span>Payouts are processed every 15th and 30th of the month.</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-info-circle text-green-500 mt-1 mr-2"></i>
                                    <span>Funds are typically available in your account within 3-5 business days after processing.</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-exclamation-triangle text-yellow-500 mt-1 mr-2"></i>
                                    <span>A minimum balance of ₱500 is required for payout processing.</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Store Appearance -->
                <?php if ($active_tab === 'appearance'): ?>
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Store Appearance</h3>
                        <form action="seller_settings.php?tab=appearance" method="POST" class="space-y-4">
                            <div>
                                <label for="store_theme" class="block text-sm font-medium text-gray-700 mb-1">Store Theme</label>
                                <select id="store_theme" name="store_theme" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                                    <option value="default" <?php echo $store_appearance['store_theme'] === 'default' ? 'selected' : ''; ?>>Default</option>
                                    <option value="minimal" <?php echo $store_appearance['store_theme'] === 'minimal' ? 'selected' : ''; ?>>Minimal</option>
                                    <option value="bold" <?php echo $store_appearance['store_theme'] === 'bold' ? 'selected' : ''; ?>>Bold</option>
                                    <option value="natural" <?php echo $store_appearance['store_theme'] === 'natural' ? 'selected' : ''; ?>>Natural</option>
                                    <option value="modern" <?php echo $store_appearance['store_theme'] === 'modern' ? 'selected' : ''; ?>>Modern</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="banner_text" class="block text-sm font-medium text-gray-700 mb-1">Banner Text</label>
                                <input type="text" id="banner_text" name="banner_text" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($store_appearance['banner_text']); ?>" placeholder="Welcome to our store!">
                                <p class="text-xs text-gray-500 mt-1">This text will appear at the top of your store page.</p>
                            </div>
                            
                            <div>
                                <label for="primary_color" class="block text-sm font-medium text-gray-700 mb-1">Primary Color</label>
                                <div class="flex items-center">
                                    <input type="color" id="primary_color" name="primary_color" class="h-10 w-10 border border-gray-300 rounded-md mr-2" value="<?php echo htmlspecialchars($store_appearance['primary_color']); ?>">
                                    <input type="text" id="primary_color_hex" class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" value="<?php echo htmlspecialchars($store_appearance['primary_color']); ?>" readonly>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">This color will be used for buttons, links, and accents in your store.</p>
                            </div>
                            
                            <div class="flex items-start">
                                <div class="flex items-center h-5">
                                    <input type="checkbox" id="show_featured_products" name="show_featured_products" class="h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500" <?php echo $store_appearance['show_featured_products'] ? 'checked' : ''; ?>>
                                </div>
                                <div class="ml-3 text-sm">
                                    <label for="show_featured_products" class="font-medium text-gray-700">Show Featured Products</label>
                                    <p class="text-gray-500">Display a featured products section at the top of your store page.</p>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" name="update_appearance" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                    Save Appearance
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Store Preview</h3>
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="bg-gray-100 p-4 rounded-t-lg" style="background-color: <?php echo htmlspecialchars($store_appearance['primary_color']); ?>20;">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <div class="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden mr-3">
                                            <?php if (isset($seller['logo_url']) && $seller['logo_url']): ?>
                                                <img src="<?php echo htmlspecialchars($seller['logo_url']); ?>" alt="Business Logo" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <i class="fas fa-store text-gray-400 text-xl"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <h4 class="font-bold text-gray-800"><?php echo htmlspecialchars($seller['business_name']); ?></h4>
                                            <p class="text-xs text-gray-600"><?php echo htmlspecialchars($seller['business_type']); ?></p>
                                        </div>
                                    </div>
                                    <button class="px-3 py-1 text-sm rounded-md" style="background-color: <?php echo htmlspecialchars($store_appearance['primary_color']); ?>; color: white;">
                                        Follow
                                    </button>
                                </div>
                                <?php if ($store_appearance['banner_text']): ?>
                                    <div class="mt-3 p-2 text-center text-sm rounded-md bg-white bg-opacity-80">
                                        <?php echo htmlspecialchars($store_appearance['banner_text']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="p-4">
                                <?php if ($store_appearance['show_featured_products']): ?>
                                    <div class="mb-4">
                                        <h5 class="font-medium text-gray-800 mb-2">Featured Products</h5>
                                        <div class="grid grid-cols-3 gap-2">
                                            <div class="bg-gray-100 rounded-md p-2 text-center">
                                                <div class="w-full h-12 bg-gray-200 rounded-md mb-2"></div>
                                                <div class="w-2/3 h-3 bg-gray-200 rounded-md mx-auto mb-1"></div>
                                                <div class="w-1/2 h-3 bg-gray-200 rounded-md mx-auto"></div>
                                            </div>
                                            <div class="bg-gray-100 rounded-md p-2 text-center">
                                                <div class="w-full h-12 bg-gray-200 rounded-md mb-2"></div>
                                                <div class="w-2/3 h-3 bg-gray-200 rounded-md mx-auto mb-1"></div>
                                                <div class="w-1/2 h-3 bg-gray-200 rounded-md mx-auto"></div>
                                            </div>
                                            <div class="bg-gray-100 rounded-md p-2 text-center">
                                                <div class="w-full h-12 bg-gray-200 rounded-md mb-2"></div>
                                                <div class="w-2/3 h-3 bg-gray-200 rounded-md mx-auto mb-1"></div>
                                                <div class="w-1/2 h-3 bg-gray-200 rounded-md mx-auto"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <h5 class="font-medium text-gray-800 mb-2">All Products</h5>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="bg-gray-100 rounded-md p-2 text-center">
                                        <div class="w-full h-16 bg-gray-200 rounded-md mb-2"></div>
                                        <div class="w-3/4 h-3 bg-gray-200 rounded-md mx-auto mb-1"></div>
                                        <div class="w-1/2 h-3 bg-gray-200 rounded-md mx-auto"></div>
                                    </div>
                                    <div class="bg-gray-100 rounded-md p-2 text-center">
                                        <div class="w-full h-16 bg-gray-200 rounded-md mb-2"></div>
                                        <div class="w-3/4 h-3 bg-gray-200 rounded-md mx-auto mb-1"></div>
                                        <div class="w-1/2 h-3 bg-gray-200 rounded-md mx-auto"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2 text-center">This is a simplified preview. Actual appearance may vary.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Footer -->
<footer class="bg-green-800 text-white py-8 mt-auto">
  <div class="container mx-auto px-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
          <div>
              <h3 class="text-lg font-semibold mb-4">About Wastewise</h3>
              <p class="text-sm">Committed to a sustainable future through recycling and eco-friendly shopping.</p>
          </div>
          <div>
              <h3 class="text-lg font-semibold mb-4">Quick Links</h3>
              <ul class="space-y-2 text-sm">
                  <li><a href="home.php" class="hover:text-green-200">Home</a></li>
                         <li><a href="community_impact.php" class="hover:text-green-200">Community Impact</a></li>
                  <li><a href="about.php" class="hover:text-green-200">About Us</a></li>
                  <li><a href="contact-uss.php" class="hover:text-green-200">Contact</a></li>
                  <li><a href="faq.php" class="hover:text-green-200">FAQ</a></li>
              </ul>
          </div>
          <div>
              <h3 class="text-lg font-semibold mb-4">Connect With Us</h3>
              <div class="flex space-x-4">
                  <a href="#" class="text-white hover:text-green-200"><i class="fab fa-facebook-f"></i></a>
                  <a href="#" class="text-white hover:text-green-200"><i class="fab fa-twitter"></i></a>
                  <a href="#" class="text-white hover:text-green-200"><i class="fab fa-instagram"></i></a>
                  <a href="#" class="text-white hover:text-green-200"><i class="fab fa-linkedin-in"></i></a>
              </div>
          </div>
      </div>
      <div class="border-t border-green-700 mt-6 pt-6 text-center">
          <p>&copy; <?= date('Y') ?> Wastewise E-commerce. All rights reserved.</p>
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
    </script>
    <script>
        // Update color input text when color picker changes
        document.getElementById('primary_color').addEventListener('input', function() {
            document.getElementById('primary_color_hex').value = this.value;
        });
         function openLogoutModal() {
            document.getElementById('logoutModal').classList.remove('hidden');
        }

        function closeLogoutModal() {
            document.getElementById('logoutModal').classList.add('hidden');
        }
        function toggleDropdown() {
    const dropdown = document.getElementById('userMenuDropdown');
    dropdown.classList.toggle('hidden');
  }

  function openProfileModal(event) {
    event.preventDefault();
    document.getElementById('profileModal').classList.remove('hidden');
  }

  function closeModal() {
    document.getElementById('profileModal').classList.add('hidden');
  }

  function openLogoutModal() {
    document.getElementById('logoutModal').classList.remove('hidden');
    document.getElementById('userMenuDropdown').classList.add('hidden');
  }

  function closeLogoutModal() {
    document.getElementById('logoutModal').classList.add('hidden');
  }

  // Optional: Click outside to close dropdown
  window.addEventListener('click', function (e) {
    const dropdown = document.getElementById('userMenuDropdown');
    if (!e.target.closest('.relative')) {
      dropdown.classList.add('hidden');
    }
  });
    </script>
</body>
</html>

