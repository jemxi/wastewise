<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
  header("Location: login.php");
  exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');

// Check DB connection
if ($db->connect_error) {
  die("Connection failed: " . $db->connect_error);
}

$checkSuspendColumn = $db->query("SHOW COLUMNS FROM sellers LIKE 'is_suspended'");
if ($checkSuspendColumn->num_rows == 0) {
    $db->query("ALTER TABLE sellers ADD COLUMN is_suspended INT DEFAULT 0");
}

// Fetch user's email from the database
$email = '';
$stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
  $user = $result->fetch_assoc();
  $email = $user['email'];
} else {
  $email = 'Email not found';
}

  // Handle follow/unfollow action
  if (isset($_POST['follow_action']) && isset($_SESSION['user_id']) && isset($_POST['seller_id'])) {
      $action = $_POST['follow_action'];
      $target_seller_id = intval($_POST['seller_id']);
      
      if ($target_seller_id > 0) {
          if ($action === 'follow') {
              // Add follow relationship
              $stmt = $db->prepare("INSERT INTO seller_followers (user_id, seller_id, followed_at) VALUES (?, ?, NOW())");
              $stmt->bind_param("ii", $_SESSION['user_id'], $target_seller_id);
              $stmt->execute();
          } elseif ($action === 'unfollow') {
              // Remove follow relationship
              $stmt = $db->prepare("DELETE FROM seller_followers WHERE user_id = ? AND seller_id = ?");
              $stmt->bind_param("ii", $_SESSION['user_id'], $target_seller_id);
              $stmt->execute();
          }
          
          // Redirect back to seller-search.php with current search/filter parameters
          $redirect_url = "seller-search.php";
          $query_params = [];
          if (!empty($search)) {
              $query_params[] = "search=" . urlencode($search);
          }
          if (!empty($category)) {
              $query_params[] = "category=" . urlencode($category);
          }
          if (!empty($query_params)) {
              $redirect_url .= "?" . implode("&", $query_params);
          }
          header("Location: " . $redirect_url);
          exit();
      }
  }

// Get search parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';

// Get all approved sellers with their appearance settings
function getSellers($search = '', $category = '') {
  global $db;
  
  $query = "SELECT s.*, u.username, 
            (SELECT COUNT(*) FROM seller_followers WHERE seller_id = s.id) as follower_count,
            (SELECT COUNT(*) FROM products WHERE seller_id = s.id AND archived = 0) as product_count,
            sa.store_theme, sa.banner_text, sa.primary_color, sa.show_featured_products
            FROM sellers s 
            JOIN users u ON s.user_id = u.id 
            LEFT JOIN seller_store_appearance sa ON s.id = sa.seller_id
            WHERE s.status = 'approved' AND (u.is_suspended = 0 OR u.is_suspended IS NULL)";
  
  $params = [];
  
  if (!empty($search)) {
      $query .= " AND (s.business_name LIKE ? OR s.description LIKE ? OR s.city LIKE ? OR s.state LIKE ?)";
      $search_param = "%$search%";
      $params[] = $search_param;
      $params[] = $search_param;
      $params[] = $search_param;
      $params[] = $search_param;
  }
  
  if (!empty($category)) {
      $query .= " AND EXISTS (
          SELECT 1 FROM products p 
          JOIN seller_categories sc ON p.seller_id = s.id 
          JOIN product_categories pc ON sc.category_id = pc.id 
          WHERE p.category = ? AND p.seller_id = s.id
      )";
      $params[] = $category;
  }
  
  $query .= " ORDER BY follower_count DESC, business_name ASC";
  
  $stmt = $db->prepare($query);
  
  if (!empty($params)) {
      $types = str_repeat('s', count($params));
      $stmt->bind_param($types, ...$params);
  }
  
  $stmt->execute();
  $result = $stmt->get_result();
  return $result->fetch_all(MYSQLI_ASSOC);
}

// ✅ Fixed Cart Count Query (shows total quantity, defaults to 0)
$cart_count = 0;
if (!empty($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $cart_query = "SELECT COALESCE(SUM(quantity), 0) AS cart_count FROM cart_items WHERE user_id = ?";
    if ($cart_stmt = $db->prepare($cart_query)) {
        $cart_stmt->bind_param("i", $user_id);
        $cart_stmt->execute();
        $cart_result = $cart_stmt->get_result();
        if ($row = $cart_result->fetch_assoc()) {
            $cart_count = (int)$row['cart_count'];
        }
        $cart_stmt->close();
    }
}

// Get product categories for filter
$stmt = $db->prepare("SELECT DISTINCT name FROM product_categories ORDER BY name ASC");
$stmt->execute();
$categories_result = $stmt->get_result();
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
  $categories[] = $row['name'];
}

// Get sellers based on search/filter
$sellers = getSellers($search, $category);

// Get followed sellers for the current user
$followed_sellers = [];
if (isset($_SESSION['user_id'])) {
  $stmt = $db->prepare("SELECT seller_id FROM seller_followers WHERE user_id = ?");
  $stmt->bind_param("i", $_SESSION['user_id']);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
      $followed_sellers[] = $row['seller_id'];
  }
}

// Get user information
$user_query = "SELECT * FROM users WHERE id = ?";
$user_stmt = $db->prepare($user_query);
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();

// Default appearance settings
$default_primary_color = '#4CAF50';
$default_theme = 'default';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Find Sellers - Wastewise</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="icon" type="image/png" href="logo.png">
  <style>
      /* Header */
      header {
          position: fixed;
          top: 0;
          left: 0;
          width: 100%;
          height: 5.5rem;
          background-color: #2f855a;
          z-index: 50;
      }

      /* Main Content */
      main {
          margin-top: 5.5rem;
          padding: 2rem 0;
          flex: 1;
      }

      /* Footer */
      footer {
          background-color: #2f855a;
          color: white;
          text-align: center;
          padding: 1rem 0;
          z-index: 30;
      }

      
      /* Cart Number Styling */
      .cart-number {
          position: absolute;
          top: -5px;
          right: -5px;
          background-color: red;
          color: white;
          border-radius: 50%;
          font-size: 10px;
          width: 18px;
          height: 18px;
          display: flex;
          justify-content: center;
          align-items: center;
      }
      
      /* Active nav item */
      .nav-active {
          color: #fde68a;
          font-weight: bold;
      }
      
      /* Seller card hover effect */
      .seller-card {
          transition: transform 0.3s ease, box-shadow 0.3s ease;
      }
      
      .seller-card:hover {
          transform: translateY(-5px);
          box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
      }
      
      /* Button hover effects */
      .btn-hover {
          transition: all 0.3s ease;
      }
      
      .btn-hover:hover {
          transform: translateY(-2px);
      }
      
      /* Theme styles */
      .theme-default {
          /* Default theme - already styled */
      }
      
      .theme-minimal {
          background-color: #ffffff;
          border: 1px solid #e5e7eb;
      }
      
      .theme-bold {
          font-weight: 600;
      }
      
      .theme-natural {
          background-color: #f8f9fa;
      }
      
      .theme-modern {
          border-radius: 0.75rem;
      }
  </style>
<header class="bg-green-700 text-white py-6">
  <div class="container mx-auto px-4">
    <div class="flex flex-col md:flex-row justify-between items-center w-full gap-4 relative">

      <!-- Left: Logo and Title -->
      <div class="flex justify-start items-center w-full md:w-1/3 space-x-2">
        <img src="logo.png" alt="Wastewise Logo" class="h-8 w-8">
        <h1 class="text-2xl font-bold">Wastewise</h1>
      </div>

      <!-- Center: Navigation Buttons -->
      <div class="flex justify-center items-center w-full md:w-1/3 gap-20 text-base">
        <a href="home.php" data-title="Home" class="hover:text-gray-300"><i class="fas fa-home"></i></a>
        <a href="community_impact.php" data-title="Community Impact" class="hover:text-gray-300"><i class="fas fa-leaf"></i></a>
        <a href="my_orders.php" data-title="My Orders" class="hover:text-gray-300"><i class="fas fa-shopping-bag"></i></a>
        <a href="cart.php" data-title="Cart" class="relative flex items-center hover:text-gray-300">
          <i class="fas fa-shopping-cart"></i>
          <?php if ($cart_count > 0): ?>
            <span class="cart-number"><?php echo $cart_count; ?></span>
          <?php endif; ?>
        </a>
        <a href="seller-search.php" data-title="Find Sellers" class="hover:text-gray-300"><i class="fas fa-store"></i></a>
      </div>

      <!-- Right: Settings Icon (opens sidebar) -->
      <div class="flex justify-end w-full md:w-1/3">
        <button onclick="toggleSidebar()" class="flex items-center gap-2 text-white px-4 py-2">
          <i class="fas fa-cog text-xl"></i>
        </button>
      </div>

    </div>
  </div>
</header>

<!-- Sidebar -->
<div id="settingsSidebar" class="fixed top-0 right-0 w-64 h-full bg-white shadow-lg transform translate-x-full transition-transform duration-300 ease-in-out z-50">
  <div class="flex justify-between items-center p-4 border-b">
    <h2 class="text-lg font-semibold text-green-700">Settings</h2>
    <button onclick="toggleSidebar()" class="text-gray-600 hover:text-gray-900 text-xl font-bold">&times;</button>
  </div>

  <div class="p-4 space-y-3">
    <button onclick="openProfileModal(event)" class="w-full flex items-center text-gray-700 hover:bg-gray-100 px-3 py-2 rounded">
      <i class="fas fa-user-circle mr-3 text-green-600"></i> Profile
    </button>

    <button onclick="openLogoutModal()" class="w-full flex items-center text-red-600 hover:bg-red-100 px-3 py-2 rounded">
      <i class="fas fa-sign-out-alt mr-3"></i> Logout
    </button>
  </div>
</div>

<!-- Profile Modal -->
<div id="profileModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50">
  <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md relative">
    <button onclick="closeModal()" class="absolute top-3 right-3 text-gray-600 hover:text-gray-900 text-xl font-bold">&times;</button>

    <h2 class="text-2xl font-bold text-green-700 text-center mb-6">Profile Information</h2>
    <div class="space-y-4">
      <p><strong>Username:</strong> <?= htmlspecialchars($_SESSION['username']); ?></p>
      <p><strong>Email:</strong> <?= htmlspecialchars($email); ?></p>
    </div>
  </div>
</div>

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
  <main class="container mx-auto px-4">
      <div class="bg-white rounded-lg shadow-md p-6 mb-8">
          <h1 class="text-3xl font-bold text-gray-800 mb-6">Find Sellers</h1>
          
          <!-- Search and Filter Form -->
          <form action="" method="GET" class="mb-8">
              <div class="flex flex-col md:flex-row gap-4">
                  <div class="flex-1">
                      <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search Sellers</label>
                      <div class="relative">
                          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                              <i class="fas fa-search text-gray-400"></i>
                          </div>
                          <input type="text" id="search" name="search" placeholder="Search by name, description, or location..." 
                              value="<?= htmlspecialchars($search) ?>" 
                              class="w-full pl-10 px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-green-500">
                      </div>
                  </div>
                  
                  <div class="w-full md:w-1/3">
                      <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Filter by Product Category</label>
                      <div class="relative">
                          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                              <i class="fas fa-tag text-gray-400"></i>
                          </div>
                          <select id="category" name="category" class="w-full pl-10 px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-green-500">
                              <option value="">All Categories</option>
                              <?php foreach ($categories as $cat): ?>
                                  <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                              <?php endforeach; ?>
                          </select>
                          <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none">
                              <i class="fas fa-chevron-down text-gray-400"></i>
                          </div>
                      </div>
                  </div>
                  
                  <div class="flex items-end">
                      <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-500 transition duration-300 btn-hover">
                          <i class="fas fa-search mr-2"></i>Search
                      </button>
                  </div>
              </div>
          </form>
          
          <!-- Results Summary -->
          <div class="mb-6">
              <p class="text-gray-600">
                  <?php if (!empty($search) || !empty($category)): ?>
                      Showing results <?= count($sellers) ?> 
                      <?php if (!empty($search)): ?>
                          for "<?= htmlspecialchars($search) ?>"
                      <?php endif; ?>
                      <?php if (!empty($category)): ?>
                          in category "<?= htmlspecialchars($category) ?>"
                      <?php endif; ?>
                  <?php else: ?>
                      Showing all sellers (<?= count($sellers) ?>)
                  <?php endif; ?>
              </p>
          </div>
          
          <!-- Sellers List -->
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              <?php if (empty($sellers)): ?>
                  <div class="col-span-full text-center py-12">
                      <i class="fas fa-store text-gray-400 text-5xl mb-4"></i>
                      <h3 class="text-xl font-semibold text-gray-800 mb-2">No Sellers Found</h3>
                      <p class="text-gray-600">Try adjusting your search criteria or check back later.</p>
                      <a href="seller-search.php" class="inline-block mt-4 bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-500 transition duration-300">
                          <i class="fas fa-redo mr-2"></i>Reset Search
                      </a>
                  </div>
              <?php else: ?>
                  <?php foreach ($sellers as $seller): ?>
                      <?php 
                      // Set default values if appearance settings are not available
                      $primary_color = !empty($seller['primary_color']) ? $seller['primary_color'] : $default_primary_color;
                      $store_theme = !empty($seller['store_theme']) ? $seller['store_theme'] : $default_theme;
                      $banner_text = !empty($seller['banner_text']) ? $seller['banner_text'] : '';
                      $show_featured = isset($seller['show_featured_products']) ? (bool)$seller['show_featured_products'] : true;
                      

                      // Theme class based on store theme
                      $theme_class = 'theme-' . $store_theme;
                      ?>
                      
                      <div class="seller-card bg-white rounded-lg overflow-hidden shadow-md border border-gray-200 <?= $theme_class ?>">
                          <div class="h-24 flex items-center justify-center relative" style="background-color: <?= $primary_color ?>20;">
                              <?php if (isset($seller['logo_url']) && $seller['logo_url']): ?>
                                  <img src="<?= htmlspecialchars($seller['logo_url']) ?>" alt="<?= htmlspecialchars($seller['business_name']) ?>" class="h-16 w-16 rounded-full object-cover border-2 border-white">
                              <?php else: ?>
                                  <div class="h-16 w-16 rounded-full flex items-center justify-center border-2 border-white" style="background-color: <?= $primary_color ?>;">
                                      <i class="fas fa-store text-white text-2xl"></i>
                                  </div>
                              <?php endif; ?>
                              
                              <?php if (!empty($banner_text)): ?>
                                  <div class="absolute bottom-0 left-0 right-0 bg-white bg-opacity-80 text-center py-1 px-2 text-sm">
                                      <?= htmlspecialchars($banner_text) ?>
                                  </div>
                              <?php endif; ?>
                          </div>
                          <div class="p-4">
                              <h3 class="text-xl font-semibold mb-2"><?= htmlspecialchars($seller['business_name']); ?></h3>
                              <p class="text-gray-600 text-sm mb-2"><?= htmlspecialchars($seller['business_type']); ?></p>
                              <p class="text-gray-500 text-sm mb-2">
                                  <i class="fas fa-map-marker-alt mr-1"></i>
                                  <?= htmlspecialchars($seller['city']) ?>, <?= htmlspecialchars($seller['state']) ?>
                              </p>
                              
                              <div class="flex justify-between items-center text-sm text-gray-500 mb-4">
                                  <span><i class="fas fa-users mr-1"></i><?= $seller['follower_count'] ?> followers</span>
                                  <span><i class="fas fa-box mr-1"></i><?= $seller['product_count'] ?> products</span>
                              </div>
                              
                              <?php if (isset($seller['description']) && $seller['description']): ?>
                                  <p class="text-gray-600 text-sm mb-4"><?= htmlspecialchars(substr($seller['description'], 0, 100)) . (strlen($seller['description']) > 100 ? '...' : ''); ?></p>
                              <?php endif; ?>
                              
                              <div class="flex justify-between items-center mt-4">
                                  <a href="seller-shop.php?id=<?= $seller['id'] ?>" class="text-white px-4 py-2 rounded-lg transition duration-300 btn-hover" style="background-color: <?= $primary_color ?>;">
                                      <i class="fas fa-store mr-2"></i>Visit Shop
                                  </a>
                                  
                                  <?php if (in_array($seller['id'], $followed_sellers)): ?>
                                      <form action="seller-search.php?search=<?= htmlspecialchars($search) ?>&category=<?= htmlspecialchars($category) ?>" method="POST" class="inline">
                                          <input type="hidden" name="follow_action" value="unfollow">
                                          <input type="hidden" name="seller_id" value="<?= $seller['id'] ?>">
                                          <button type="submit" class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition duration-300 btn-hover">
                                              <i class="fas fa-user-minus mr-2"></i>Unfollow
                                          </button>
                                      </form>
                                  <?php else: ?>
                                      <form action="seller-search.php?search=<?= htmlspecialchars($search) ?>&category=<?= htmlspecialchars($category) ?>" method="POST" class="inline">
                                          <input type="hidden" name="follow_action" value="follow">
                                          <input type="hidden" name="seller_id" value="<?= $seller['id'] ?>">
                                          <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition duration-300 btn-hover">
                                              <i class="fas fa-user-plus mr-2"></i>Follow
                                          </button>
                                      </form>
                                  <?php endif; ?>
                              </div>
                          </div>
                      </div>
                  <?php endforeach; ?>
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
<script src="preload.js"></script>
  <script>
      // Auto-submit form when category changes
      document.getElementById('category').addEventListener('change', function() {
          this.form.submit();
      });
      
      // Add hover effects to seller cards
      const sellerCards = document.querySelectorAll('.seller-card');
      sellerCards.forEach(card => {
          card.addEventListener('mouseenter', () => {
              card.classList.add('shadow-lg');
          });
          card.addEventListener('mouseleave', () => {
              card.classList.remove('shadow-lg');
          });
      });
      
      // Prevent caching to ensure latest changes are shown
      window.addEventListener('pageshow', function(event) {
          if (event.persisted) {
              window.location.reload();
          }
      });
      // Function to toggle dropdown visibility
  function toggleDropdown() {
    const dropdown = document.getElementById('userMenuDropdown');
    dropdown.classList.toggle('hidden');
  }

  // Function to open the profile modal
  function openModal() {
    document.getElementById('profileModal').classList.remove('hidden');
  }

  // Function to close the profile modal
  function closeModal() {
    document.getElementById('profileModal').classList.add('hidden');
  }

  // Prevent the profile link from redirecting and open the modal instead
  function openProfileModal(event) {
    event.preventDefault(); // Prevent the default link behavior
    openModal(); // Open the modal
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
  
     function toggleSidebar() {
    const sidebar = document.getElementById('settingsSidebar');
    sidebar.classList.toggle('translate-x-full');
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
  }

  function closeLogoutModal() {
    document.getElementById('logoutModal').classList.add('hidden');
  }
  
  </script>
</body>
</html>
