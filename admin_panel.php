<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auto logout after 10 minutes (you can change 600 seconds)
$timeout_duration = 60; // 600 = 10 minutes

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?session=expired");
    exit();
}

$_SESSION['LAST_ACTIVITY'] = time(); // Update activity time

$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');


if ($db->connect_error) {
  die("Connection failed: " . $db->connect_error);
}

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
  header("Location: login.php");
  exit();
}

// Define constant to prevent direct access to included files
define('ADMIN_PANEL', true);

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Count pending seller applications for notification badge
$pendingSellersQuery = "SELECT COUNT(*) as count FROM sellers WHERE status = 'pending'";
$pendingSellersResult = $db->query($pendingSellersQuery);
$pendingSellersCount = 0;

if ($pendingSellersResult && $pendingSellersResult->num_rows > 0) {
  $pendingSellersCount = $pendingSellersResult->fetch_assoc()['count'];
}

// Count pending riders for notification badge
$pendingRidersQuery = "SELECT COUNT(*) as count FROM riders WHERE status = 'pending'";
$pendingRidersResult = $db->query($pendingRidersQuery);
$pendingRidersCount = 0;

if ($pendingRidersResult && $pendingRidersResult->num_rows > 0) {
  $pendingRidersCount = $pendingRidersResult->fetch_assoc()['count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Wastewise E-commerce Admin Panel</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .notification-badge {
      background-color: #EF4444;
      color: white;
      border-radius: 9999px;
      padding: 0.1rem 0.5rem;
      font-size: 0.75rem;
      margin-left: 0.5rem;
    }
  </style>
</head>
<body class="bg-gray-100">
  <div class="flex h-screen">
      <!-- Sidebar -->
      <nav class="w-64 bg-green-800 text-white p-6">
          <h1 class="text-2xl font-bold mb-8">Admin Panel</h1>
          <ul>
              <li class="mb-4">
                  <a href="?page=dashboard" class="flex items-center <?php echo $page == 'dashboard' ? 'text-green-300' : ''; ?>">
                      <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
                  </a>
              </li>
              <li class="mb-4">
                  <a href="?page=user_management" class="flex items-center <?php echo $page == 'user_management' ? 'text-green-300' : ''; ?>">
                      <i class="fas fa-users mr-3"></i> User Management
                  </a>
              </li>
              <li class="mb-4">
                  <a href="?page=seller-approvals" class="flex items-center <?php echo $page == 'seller-approvals' ? 'text-green-300' : ''; ?>">
                      <i class="fas fa-store mr-3"></i> Seller Approvals
                      <?php if ($pendingSellersCount > 0): ?>
                          <span class="notification-badge"><?php echo $pendingSellersCount; ?></span>
                      <?php endif; ?>
                  </a>
              </li>
              <li class="mb-4">
                  <a href="?page=rider-approvals" class="flex items-center <?php echo $page == 'rider-approvals' ? 'text-green-300' : ''; ?>">
                      <i class="fas fa-motorcycle mr-3"></i> Rider Approvals
                      <?php if ($pendingRidersCount > 0): ?>
                          <span class="notification-badge"><?php echo $pendingRidersCount; ?></span>
                      <?php endif; ?>
                  </a>
              </li>
              <li class="mb-4">
                  <a href="?page=eco_impact" class="flex items-center <?php echo $page == 'eco_impact' ? 'text-green-300' : ''; ?>">
                      <i class="fas fa-leaf mr-3"></i> Eco Impact Dashboard
                  </a>
              </li>
              <li class="mb-4">
                  <a href="?page=featured_products" class="flex items-center <?php echo $page == 'featured_products' ? 'text-green-300' : ''; ?>">
                      <i class="fas fa-star mr-3"></i> Featured Products
                  </a>
              </li>
              <li class="mt-8">
                <button onclick="openLogoutModal()" class="block w-full text-left px-4 py-2 text-red-600 hover:bg-red-100">
  <i class="fas fa-sign-out-alt mr-2"></i> Logout
</button>

              </li>
          </ul>
      </nav>
<!-- Logout Confirmation Modal -->
<div id="logoutModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
  <div class="bg-gray-200 bg-opacity-90 p-6 rounded-lg shadow-lg w-full max-w-sm text-center relative">
    <h2 class="text-xl font-semibold text-gray-900 mb-4">Are you sure you want to log out?</h2>
    
    <div class="flex justify-center gap-4">
      <form action="logout.php" method="POST">
        <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Yes</button>
      </form>
      <button onclick="closeLogoutModal()" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">No</button>
    </div>
  </div>
</div>

      <!-- Main Content -->
      <div class="flex-1 p-10 overflow-y-auto">
          <?php
          // Enable error reporting for debugging
          error_reporting(E_ALL);
          ini_set('display_errors', 1);
          
          // Check if the file exists before including
          $file_to_include = '';
          
          switch ($page) {
              case 'dashboard':
                  $file_to_include = 'admin_dashboard.php';
                  break;
              case 'user_management':
                  $file_to_include = 'user_management.php';
                  break;
              case 'seller-approvals':
                  if (file_exists('seller-approvals.php')) {
                      $file_to_include = 'seller-approvals.php';
                  } elseif (file_exists('admin/seller-approvals.php')) {
                      $file_to_include = 'admin/seller-approvals.php';
                  } elseif (file_exists('seller-verification.php')) {
                      $file_to_include = 'seller-verification.php';
                  } elseif (file_exists('admin/seller-verification.php')) {
                      $file_to_include = 'admin/seller-verification.php';
                  }
                  break;
              case 'rider-approvals':
                  $file_to_include = 'rider-approvals.php';
                  break;
              case 'eco_impact':
                  $file_to_include = 'admin_eco_impact.php';
                  break;
              case 'featured_products':
                  $file_to_include = 'admin_featured_products.php';
                  break;
              default:
                  echo "<h2 class='text-2xl font-bold mb-4'>404 - Page not found</h2>";
          }
          
          if (!empty($file_to_include)) {
              if (file_exists($file_to_include)) {
                  include $file_to_include;
              } else {
                  echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>";
                  echo "<p class='font-bold'>Error:</p>";
                  echo "<p>File not found: " . htmlspecialchars($file_to_include) . "</p>";
                  echo "<p>Current directory: " . htmlspecialchars(getcwd()) . "</p>";
                  echo "<p>Available files in current directory:</p>";
                  echo "<ul class='list-disc ml-8'>";
                  foreach (scandir('.') as $file) {
                      if ($file != '.' && $file != '..') {
                          echo "<li>" . htmlspecialchars($file) . "</li>";
                      }
                  }
                  echo "</ul>";
                  echo "</div>";
              }
          } else {
              echo "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4'>";
              echo "<p>No file was selected to include for page: " . htmlspecialchars($page) . "</p>";
              echo "</div>";
          }
          ?>
      </div>
  </div>
   <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
    </script>
  <script>
  function openLogoutModal() {
    document.getElementById('logoutModal').classList.remove('hidden');
  }

  function closeLogoutModal() {
    document.getElementById('logoutModal').classList.add('hidden');
  }

  // If admin switches tab or minimizes browser, start logout timer
  let logoutTimer;

  // Function to log out admin automatically
  function autoLogout() {
    window.location.href = "logout.php";
  }

  // When admin leaves the tab
  document.addEventListener("visibilitychange", function() {
    if (document.hidden) {
      // Start timer (3 seconds delay before logout — change as needed)
      logoutTimer = setTimeout(autoLogout, 1000);
    } else {
      // Cancel logout if admin returns quickly
      clearTimeout(logoutTimer);
    }
  });
</script>

</body>
</html>
