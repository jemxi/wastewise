<?php
session_start();
require '//db_connection.php';
//ser is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header('Location: ../login.php');
    exit();
}

// Get counts for dashboard
try {//
    // Total users
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $total_users = $stmt->fetch()['count'];
    
    // Total sellers
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM sellers");
    $total_sellers = $stmt->fetch()['count'];
    
    // Pending sellers
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM sellers WHERE status = 'pending'");
    $pending_sellers = $stmt->fetch()['count'];
    
    // Recent users
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
    $recent_users = $stmt->fetchAll();
    
    // Recent sellers
    $stmt = $pdo->query("
        SELECT s.*, u.username, u.email 
        FROM sellers s
        JOIN users u ON s.user_id = u.id
        ORDER BY s.created_at DESC 
        LIMIT 5
    ");
    $recent_sellers = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-wastewise {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
        }
        .text-wastewise {
            color: #4CAF50;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header -->
    <header class="bg-white shadow-sm">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center">
                <img src="/assets/images/logo.png" alt="Wastewise Logo" class="h-10 mr-3">
                <span class="text-2xl font-bold text-gray-800">Wastewise Admin</span>
            </div>
            <div>
                <span class="text-gray-600 mr-4">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="../logout.php" class="text-red-600 hover:text-red-800">
                    <i class="fas fa-sign-out-alt mr-1"></i> Logout
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <div class="w-64 bg-gray-800 text-white p-4">
            <nav class="space-y-2">
                <a href="index.php" class="block px-4 py-2 bg-gray-700 rounded-md">
                    <i class="fas fa-tachometer-alt mr-2"></i> Dashboard
                </a>
                <a href="users.php" class="block px-4 py-2 rounded-md hover:bg-gray-700">
                    <i class="fas fa-users mr-2"></i> Users
                </a>
                <a href="manage-sellers.php" class="block px-4 py-2 rounded-md hover:bg-gray-700">
                    <i class="fas fa-store mr-2"></i> Sellers
                </a>
                <a href="products.php" class="block px-4 py-2 rounded-md hover:bg-gray-700">
                    <i class="fas fa-box mr-2"></i> Products
                </a>
                <a href="orders.php" class="block px-4 py-2 rounded-md hover:bg-gray-700">
                    <i class="fas fa-shopping-cart mr-2"></i> Orders
                </a>
                <a href="categories.php" class="block px-4 py-2 rounded-md hover:bg-gray-700">
                    <i class="fas fa-tags mr-2"></i> Categories
                </a>
                <a href="reports.php" class="block px-4 py-2 rounded-md hover:bg-gray-700">
                    <i class="fas fa-chart-bar mr-2"></i> Reports
                </a>
                <a href="settings.php" class="block px-4 py-2 rounded-md hover:bg-gray-700">
                    <i class="fas fa-cog mr-2"></i> Settings
                </a>
            </nav>
        </div>

        <!-- Content Area -->
        <div class="flex-1 p-8">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-800">Dashboard</h1>
                <p class="text-gray-600">Welcome to the Wastewise Admin Dashboard</p>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Stats Overview -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500">Total Users</p>
                            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($total_users); ?></h3>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <i class="fas fa-users text-blue-500 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="users.php" class="text-blue-600 hover:text-blue-800 text-sm">
                            View all users <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500">Total Sellers</p>
                            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($total_sellers); ?></h3>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <i class="fas fa-store text-green-500 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="manage-sellers.php" class="text-blue-600 hover:text-blue-800 text-sm">
                            View all sellers <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500">Pending Approvals</p>
                            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($pending_sellers); ?></h3>
                        </div>
                        <div class="bg-yellow-100 p-3 rounded-full">
                            <i class="fas fa-clock text-yellow-500 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="manage-sellers.php?status=pending" class="text-blue-600 hover:text-blue-800 text-sm">
                            View pending sellers <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Users -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold text-gray-800">Recent Users</h2>
                        <a href="users.php" class="text-blue-600 hover:text-blue-800 text-sm">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Joined</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($recent_users)): ?>
                                    <tr>
                                        <td colspan="3" class="px-6 py-4 text-center text-gray-500">No users found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_users as $user): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8 bg-gray-200 rounded-full flex items-center justify-center">
                                                        <i class="fas fa-user text-gray-400"></i>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['username']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($user['email']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Recent Sellers -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold text-gray-800">Recent Sellers</h2>
                        <a href="manage-sellers.php" class="text-blue-600 hover:text-blue-800 text-sm">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Business</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Owner</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($recent_sellers)): ?>
                                    <tr>
                                        <td colspan="3" class="px-6 py-4 text-center text-gray-500">No sellers found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_sellers as $seller): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-8 w-8 bg-gray-200 rounded-full flex items-center justify-center">
                                                        <i class="fas fa-store text-gray-400"></i>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($seller['business_name']); ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($seller['username']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($seller['status'] === 'pending'): ?>
                                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                        Pending
                                                    </span>
                                                <?php elseif ($seller['status'] === 'approved'): ?>
                                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                        Approved
                                                    </span>
                                                <?php elseif ($seller['status'] === 'rejected'): ?>
                                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                        Rejected
                                                    </span>
                                                <?php elseif ($seller['status'] === 'suspended'): ?>
                                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                        Suspended
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
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
