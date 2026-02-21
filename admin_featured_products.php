<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');


if ($db->connect_error) {
  die("Connection failed: " . $db->connect_error);
}

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
  header("Location: login.php");
  exit();
}

// Create featured_products table if it doesn't exist
$createTableQuery = "CREATE TABLE IF NOT EXISTS featured_products (
  id INT PRIMARY KEY AUTO_INCREMENT,
  product_id INT NOT NULL UNIQUE,
  featured_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)";
$db->query($createTableQuery);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  $action = $_POST['action'];

  if ($action === 'add_featured') {
    $product_id = intval($_POST['product_id']);
    
    // Check if product already featured
    $checkQuery = "SELECT id FROM featured_products WHERE product_id = ?";
    $stmt = $db->prepare($checkQuery);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
      echo json_encode(['success' => false, 'message' => 'Product is already featured']);
      exit();
    }
    
    // Get max order
    $maxQuery = "SELECT MAX(featured_order) as max_order FROM featured_products";
    $maxResult = $db->query($maxQuery);
    $maxRow = $maxResult->fetch_assoc();
    $nextOrder = ($maxRow['max_order'] ?? 0) + 1;
    
    // Add to featured products
    $insertQuery = "INSERT INTO featured_products (product_id, featured_order) VALUES (?, ?)";
    $stmt = $db->prepare($insertQuery);
    $stmt->bind_param("ii", $product_id, $nextOrder);
    
    if ($stmt->execute()) {
      echo json_encode(['success' => true, 'message' => 'Product added to featured']);
    } else {
      echo json_encode(['success' => false, 'message' => 'Error adding product']);
    }
    exit();
  }

  if ($action === 'remove_featured') {
    $product_id = intval($_POST['product_id']);
    
    $deleteQuery = "DELETE FROM featured_products WHERE product_id = ?";
    $stmt = $db->prepare($deleteQuery);
    $stmt->bind_param("i", $product_id);
    
    if ($stmt->execute()) {
      echo json_encode(['success' => true, 'message' => 'Product removed from featured']);
    } else {
      echo json_encode(['success' => false, 'message' => 'Error removing product']);
    }
    exit();
  }

  if ($action === 'reorder_featured') {
    $order = $_POST['order']; // Array of product IDs in new order
    
    foreach ($order as $index => $product_id) {
      $product_id = intval($product_id);
      $new_order = $index + 1;
      
      $updateQuery = "UPDATE featured_products SET featured_order = ? WHERE product_id = ?";
      $stmt = $db->prepare($updateQuery);
      $stmt->bind_param("ii", $new_order, $product_id);
      $stmt->execute();
    }
    
    echo json_encode(['success' => true, 'message' => 'Featured products reordered']);
    exit();
  }
}

// Get all featured products
$featuredQuery = "SELECT fp.id, fp.product_id, fp.featured_order, p.name, p.price, p.image_path, p.stock, s.business_name
                  FROM featured_products fp
                  JOIN products p ON fp.product_id = p.id
                  LEFT JOIN sellers s ON p.seller_id = s.id
                  ORDER BY fp.featured_order ASC";
$featuredResult = $db->query($featuredQuery);
$featuredProducts = $featuredResult->fetch_all(MYSQLI_ASSOC);

// Get all available products (not featured)
$availableQuery = "SELECT p.id, p.name, p.price, p.image_path, p.stock, p.category, s.business_name
                   FROM products p
                   LEFT JOIN sellers s ON p.seller_id = s.id
                   WHERE p.archived = 0 AND p.id NOT IN (SELECT product_id FROM featured_products)
                   ORDER BY p.created_at DESC
                   LIMIT 100";
$availableResult = $db->query($availableQuery);
$availableProducts = $availableResult->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Featured Products Management - Admin Panel</title>
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    .featured-item {
      cursor: grab;
    }
    .featured-item:active {
      cursor: grabbing;
    }
    .featured-item.dragging {
      opacity: 0.5;
    }
  </style>
</head>
<body>
  <div class="max-w-7xl mx-auto">
    <h1 class="text-3xl font-bold text-gray-800 mb-8">Featured Products Management</h1>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
      <!-- Featured Products Section -->
      <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow-md p-6">
          <h2 class="text-2xl font-bold text-gray-800 mb-4">
            <i class="fas fa-star text-yellow-400 mr-2"></i>Featured Products
          </h2>
          <p class="text-gray-600 mb-4">These products will be displayed on the home page. Drag to reorder.</p>

          <?php if (empty($featuredProducts)): ?>
            <div class="text-center py-8 bg-gray-50 rounded-lg">
              <i class="fas fa-inbox text-gray-400 text-4xl mb-2"></i>
              <p class="text-gray-600">No featured products yet. Add products from the list below.</p>
            </div>
          <?php else: ?>
            <div id="featured-list" class="space-y-3">
              <?php foreach ($featuredProducts as $product): ?>
                <div class="featured-item bg-gradient-to-r from-yellow-50 to-yellow-100 border-2 border-yellow-300 rounded-lg p-4 flex items-center justify-between" draggable="true" data-product-id="<?= $product['product_id'] ?>">
                  <div class="flex items-center flex-1">
                    <i class="fas fa-grip-vertical text-gray-400 mr-4 cursor-grab"></i>
                    <div class="flex items-center flex-1">
                      <?php if (!empty($product['image_path'])): ?>
                        <img src="<?= htmlspecialchars($product['image_path']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="w-12 h-12 object-cover rounded mr-4">
                      <?php else: ?>
                        <div class="w-12 h-12 bg-gray-300 rounded mr-4 flex items-center justify-center">
                          <i class="fas fa-box text-gray-500"></i>
                        </div>
                      <?php endif; ?>
                      <div>
                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($product['name']) ?></p>
                        <p class="text-sm text-gray-600"><?= htmlspecialchars($product['business_name']) ?></p>
                      </div>
                    </div>
                    <div class="text-right mr-4">
                      <p class="font-bold text-green-600">₱<?= number_format($product['price'], 2) ?></p>
                      <p class="text-sm text-gray-600">Stock: <?= $product['stock'] ?></p>
                    </div>
                  </div>
                  <button type="button" onclick="removeFeatured(<?= $product['product_id'] ?>)" class="ml-4 px-3 py-2 bg-red-500 text-white rounded hover:bg-red-600 transition">
                    <i class="fas fa-trash"></i>
                  </button>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Available Products Section -->
      <div class="lg:col-span-1">
        <div class="bg-white rounded-lg shadow-md p-6">
          <h2 class="text-2xl font-bold text-gray-800 mb-4">
            <i class="fas fa-plus text-green-500 mr-2"></i>Add Products
          </h2>
          <p class="text-gray-600 mb-4 text-sm">Select products to add to featured section.</p>

          <div class="space-y-2 max-h-96 overflow-y-auto">
            <?php if (empty($availableProducts)): ?>
              <div class="text-center py-8 bg-gray-50 rounded-lg">
                <p class="text-gray-600 text-sm">All products are already featured or archived.</p>
              </div>
            <?php else: ?>
              <?php foreach ($availableProducts as $product): ?>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 hover:bg-gray-100 transition">
                  <div class="flex items-start justify-between mb-2">
                    <div class="flex-1">
                      <p class="font-semibold text-gray-800 text-sm line-clamp-2"><?= htmlspecialchars($product['name']) ?></p>
                      <p class="text-xs text-gray-600"><?= htmlspecialchars($product['business_name']) ?></p>
                    </div>
                  </div>
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-bold text-green-600">₱<?= number_format($product['price'], 2) ?></span>
                    <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded"><?= $product['stock'] ?> stock</span>
                  </div>
                  <button type="button" onclick="addFeatured(<?= $product['id'] ?>, '<?= htmlspecialchars(addslashes($product['name'])) ?>')" class="w-full px-3 py-2 bg-green-500 text-white rounded text-sm hover:bg-green-600 transition">
                    <i class="fas fa-plus mr-1"></i>Add
                  </button>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
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
    </script>
  <script>
    let draggedElement = null;

    // Drag and drop functionality
    document.addEventListener('dragstart', function(e) {
      if (e.target.classList.contains('featured-item')) {
        draggedElement = e.target;
        e.target.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
      }
    });

    document.addEventListener('dragend', function(e) {
      if (e.target.classList.contains('featured-item')) {
        e.target.classList.remove('dragging');
      }
    });

    document.addEventListener('dragover', function(e) {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
    });

    document.addEventListener('drop', function(e) {
      e.preventDefault();
      const featuredList = document.getElementById('featured-list');
      if (draggedElement && featuredList.contains(e.target.closest('.featured-item'))) {
        const afterElement = getDragAfterElement(featuredList, e.clientY);
        if (afterElement == null) {
          featuredList.appendChild(draggedElement);
        } else {
          featuredList.insertBefore(draggedElement, afterElement);
        }
        saveFeaturedOrder();
      }
    });

    function getDragAfterElement(container, y) {
      const draggableElements = [...container.querySelectorAll('.featured-item:not(.dragging)')];
      return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
          return { offset: offset, element: child };
        } else {
          return closest;
        }
      }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    function addFeatured(productId, productName) {
      fetch('admin_featured_products.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=add_featured&product_id=${productId}`
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert(`${productName} added to featured products!`);
          location.reload();
        } else {
          alert('Error: ' + data.message);
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('An error occurred');
      });
    }

    function removeFeatured(productId) {
      if (confirm('Remove this product from featured?')) {
        fetch('admin_featured_products.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
          },
          body: `action=remove_featured&product_id=${productId}`
        })
        .then(response => response.json())
        .then(data => {
          if (data.success) {
            alert('Product removed from featured');
            location.reload();
          } else {
            alert('Error: ' + data.message);
          }
        })
        .catch(error => {
          console.error('Error:', error);
          alert('An error occurred');
        });
      }
    }

    function saveFeaturedOrder() {
      const featuredList = document.getElementById('featured-list');
      const items = featuredList.querySelectorAll('.featured-item');
      const order = Array.from(items).map(item => item.dataset.productId);

      fetch('admin_featured_products.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=reorder_featured&order=${order.join(',')}`
      })
      .then(response => response.json())
      .then(data => {
        if (!data.success) {
          console.error('Error saving order:', data.message);
        }
      })
      .catch(error => console.error('Error:', error));
    }
  </script>
</body>
</html>
