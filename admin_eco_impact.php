<?php
// Prevent direct access
if (!defined('ADMIN_PANEL')) {
    die('Direct access not permitted');
}

// Get current month metrics for admin (all sellers combined)
$current_month = date('n');
$current_year = date('Y');

try {
    // 1. Calculate total waste recycled (all sellers and users combined)
    // Get from completed orders across all sellers
    $waste_query = "SELECT COALESCE(SUM(oi.quantity * 1.5), 0) as estimated_weight_kg
                    FROM order_items oi 
                    JOIN orders o ON oi.order_id = o.id 
                    JOIN products p ON oi.product_id = p.id 
                    WHERE o.status IN ('delivered', 'shipped', 'processing', 'completed') 
                    AND p.category IN ('Paper', 'Plastic', 'Metal', 'Glass', 'Electronics', 'Textiles')
                    AND MONTH(o.created_at) = $current_month 
                    AND YEAR(o.created_at) = $current_year";

    $waste_result = $db->query($waste_query);
    $waste_recycled = 0;
    if ($waste_result && $waste_result->num_rows > 0) {
        $waste_recycled = $waste_result->fetch_assoc()['estimated_weight_kg'];
    }

    // 2. Calculate embroidery pieces (Textiles category + handmade items)
    $embroidery_query = "SELECT COALESCE(SUM(oi.quantity), 0) as embroidery_pieces
                         FROM order_items oi 
                         JOIN orders o ON oi.order_id = o.id 
                         JOIN products p ON oi.product_id = p.id 
                         WHERE o.status IN ('delivered', 'shipped', 'processing', 'completed') 
                         AND (p.category = 'Textiles' OR p.name LIKE '%handmade%' OR p.name LIKE '%embroidery%' OR p.name LIKE '%craft%')
                         AND MONTH(o.created_at) = $current_month 
                         AND YEAR(o.created_at) = $current_year";

    $embroidery_result = $db->query($embroidery_query);
    $embroidery_pieces = 0;
    if ($embroidery_result && $embroidery_result->num_rows > 0) {
        $embroidery_pieces = $embroidery_result->fetch_assoc()['embroidery_pieces'];
    }

    // 3. Calculate CO2 saved (2.5kg per kg of waste recycled)
    $co2_saved = $waste_recycled * 2.5;

    // 4. Get top product of the month (across all sellers)
    $top_product_query = "SELECT p.name, p.price, p.image_path as image, SUM(oi.quantity) as units_sold, p.category,
                                 s.business_name as seller_name
                          FROM order_items oi 
                          JOIN orders o ON oi.order_id = o.id 
                          JOIN products p ON oi.product_id = p.id 
                          LEFT JOIN sellers s ON p.seller_id = s.id
                          WHERE o.status IN ('delivered', 'shipped', 'processing', 'completed') 
                          AND MONTH(o.created_at) = $current_month 
                          AND YEAR(o.created_at) = $current_year
                          GROUP BY p.id, p.name, p.price, p.image_path, p.category, s.business_name
                          ORDER BY units_sold DESC 
                          LIMIT 1";

    $top_product_result = $db->query($top_product_query);
    $top_product = null;
    if ($top_product_result && $top_product_result->num_rows > 0) {
        $top_product = $top_product_result->fetch_assoc();
    }

    // 5. Get top seller of the month
    $top_seller_query = "SELECT s.id, s.business_name, s.logo_url, 
                                SUM(oi.quantity) as total_units_sold,
                                SUM(o.total_amount) as total_revenue,
                                COUNT(DISTINCT o.id) as total_orders,
                                COUNT(DISTINCT p.id) as products_sold
                         FROM order_items oi 
                         JOIN orders o ON oi.order_id = o.id 
                         JOIN products p ON oi.product_id = p.id 
                         JOIN sellers s ON p.seller_id = s.id
                         WHERE o.status IN ('delivered', 'shipped', 'processing', 'completed') 
                         AND MONTH(o.created_at) = $current_month 
                         AND YEAR(o.created_at) = $current_year
                         GROUP BY s.id, s.business_name, s.logo_url
                         ORDER BY total_revenue DESC 
                         LIMIT 1";

    $top_seller_result = $db->query($top_seller_query);
    $top_seller = null;
    if ($top_seller_result && $top_seller_result->num_rows > 0) {
        $top_seller = $top_seller_result->fetch_assoc();
    }

    // 6. Get platform statistics (all users and sellers)
    // Count active sellers
    $sellers_query = "SELECT COUNT(*) as total_sellers FROM sellers WHERE status = 'approved'";
    $sellers_result = $db->query($sellers_query);
    $total_sellers = 0;
    if ($sellers_result && $sellers_result->num_rows > 0) {
        $total_sellers = $sellers_result->fetch_assoc()['total_sellers'];
    }

    // Count total registered users (buyers + sellers)
    $users_query = "SELECT COUNT(*) as total_users FROM users WHERE created_at IS NOT NULL";
    $users_result = $db->query($users_query);
    $total_users = 0;
    if ($users_result && $users_result->num_rows > 0) {
        $total_users = $users_result->fetch_assoc()['total_users'];
    }

    // Count customers who made purchases
    $customers_query = "SELECT COUNT(DISTINCT o.user_id) as total_customers 
                        FROM orders o 
                        WHERE o.status IN ('delivered', 'shipped', 'processing', 'completed')";
    $customers_result = $db->query($customers_query);
    $total_customers = 0;
    if ($customers_result && $customers_result->num_rows > 0) {
        $total_customers = $customers_result->fetch_assoc()['total_customers'];
    }

    // Count total orders
    $orders_query = "SELECT COUNT(*) as total_orders FROM orders WHERE status IN ('delivered', 'shipped', 'processing', 'completed')";
    $orders_result = $db->query($orders_query);
    $total_orders = 0;
    if ($orders_result && $orders_result->num_rows > 0) {
        $total_orders = $orders_result->fetch_assoc()['total_orders'];
    }

    // Calculate total revenue across platform
    $revenue_query = "SELECT COALESCE(SUM(total_amount), 0) as total_revenue 
                      FROM orders 
                      WHERE status IN ('delivered', 'completed')";
    $revenue_result = $db->query($revenue_query);
    $total_revenue = 0;
    if ($revenue_result && $revenue_result->num_rows > 0) {
        $total_revenue = $revenue_result->fetch_assoc()['total_revenue'];
    }

    // Get monthly growth data
    $last_month = $current_month == 1 ? 12 : $current_month - 1;
    $last_month_year = $current_month == 1 ? $current_year - 1 : $current_year;

    $last_month_orders_query = "SELECT COUNT(*) as last_month_orders 
                                FROM orders 
                                WHERE status IN ('delivered', 'shipped', 'processing', 'completed')
                                AND MONTH(created_at) = $last_month 
                                AND YEAR(created_at) = $last_month_year";
    $last_month_orders_result = $db->query($last_month_orders_query);
    $last_month_orders = 0;
    if ($last_month_orders_result && $last_month_orders_result->num_rows > 0) {
        $last_month_orders = $last_month_orders_result->fetch_assoc()['last_month_orders'];
    }

    // Calculate growth percentage
    $current_month_orders_query = "SELECT COUNT(*) as current_month_orders 
                                   FROM orders 
                                   WHERE status IN ('delivered', 'shipped', 'processing', 'completed')
                                   AND MONTH(created_at) = $current_month 
                                   AND YEAR(created_at) = $current_year";
    $current_month_orders_result = $db->query($current_month_orders_query);
    $current_month_orders = 0;
    if ($current_month_orders_result && $current_month_orders_result->num_rows > 0) {
        $current_month_orders = $current_month_orders_result->fetch_assoc()['current_month_orders'];
    }

    $growth_percentage = 0;
    if ($last_month_orders > 0) {
        $growth_percentage = (($current_month_orders - $last_month_orders) / $last_month_orders) * 100;
    }

} catch (Exception $e) {
    // If any error occurs, set default values
    $waste_recycled = 0;
    $embroidery_pieces = 0;
    $co2_saved = 0;
    $top_product = null;
    $top_products_list = [];
    $top_seller = null;
    $total_sellers = 0;
    $total_users = 0;
    $total_customers = 0;
    $total_orders = 0;
    $total_revenue = 0;
    $growth_percentage = 0;
    $error_message = "Database error: " . $e->getMessage();
}

// Set dynamic targets based on current performance
$waste_target = max(3000, $waste_recycled * 1.2);
$embroidery_target = max(1500, $embroidery_pieces * 1.3);
$co2_target = max(5000, $co2_saved * 1.2);

// Calculate percentages
$waste_percentage = $waste_target > 0 ? ($waste_recycled / $waste_target) * 100 : 0;
$embroidery_percentage = $embroidery_target > 0 ? ($embroidery_pieces / $embroidery_target) * 100 : 0;
$co2_percentage = $co2_target > 0 ? ($co2_saved / $co2_target) * 100 : 0;
?>

<div class="bg-white rounded-lg shadow-lg">
    <!-- Header -->
    <div class="bg-gradient-to-r from-green-600 to-green-700 text-white p-6 rounded-t-lg">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <i class="fas fa-leaf text-2xl"></i>
                <div>
                    <h1 class="text-2xl font-bold">WasteWise Community Impact Dashboard</h1>
                    <p class="text-green-100">Environmental impact across our entire platform community</p>
                </div>
            </div>
            <div class="text-right">
                <p class="text-green-100">Current Month</p>
                <p class="text-xl font-bold"><?php echo date('F Y'); ?></p>
                <?php if ($growth_percentage != 0): ?>
                    <p class="text-sm <?php echo $growth_percentage > 0 ? 'text-green-200' : 'text-red-200'; ?>">
                        <?php echo $growth_percentage > 0 ? '+' : ''; ?><?php echo number_format($growth_percentage, 1); ?>% vs last month
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 m-6">
            <p class="font-bold">Database Error:</p>
            <p><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php endif; ?>

    <div class="p-6">
        <!-- Main Metrics Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Waste Recycled -->
            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-6 border border-green-200">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center space-x-3">
                        <div class="bg-green-600 p-3 rounded-full">
                            <i class="fas fa-recycle text-white text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Waste Recycled</h3>
                            <p class="text-sm text-gray-600">Community Total</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold text-green-700"><?php echo number_format($waste_recycled, 1); ?></div>
                        <div class="text-sm text-gray-600">kg</div>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <div class="mb-2">
                    <div class="flex justify-between text-sm text-gray-600 mb-1">
                        <span>Progress</span>
                        <span><?php echo number_format($waste_recycled, 0); ?> / <?php echo number_format($waste_target, 0); ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-gradient-to-r from-green-500 to-green-600 h-2 rounded-full transition-all duration-500" 
                             style="width: <?php echo min($waste_percentage, 100); ?>%"></div>
                    </div>
                </div>
                <div class="text-sm text-gray-600"><?php echo number_format($waste_percentage, 1); ?>% of monthly target</div>
            </div>

            <!-- Embroidery Pieces -->
            <div class="bg-gradient-to-br from-amber-50 to-amber-100 rounded-lg p-6 border border-amber-200">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center space-x-3">
                        <div class="bg-amber-600 p-3 rounded-full">
                            <i class="fas fa-cut text-white text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Handmade Items</h3>
                            <p class="text-sm text-gray-600">Crafted This Month</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold text-amber-700"><?php echo number_format($embroidery_pieces); ?></div>
                        <div class="text-sm text-gray-600">pieces</div>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <div class="mb-2">
                    <div class="flex justify-between text-sm text-gray-600 mb-1">
                        <span>Progress</span>
                        <span><?php echo number_format($embroidery_pieces); ?> / <?php echo number_format($embroidery_target); ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-gradient-to-r from-amber-500 to-amber-600 h-2 rounded-full transition-all duration-500" 
                             style="width: <?php echo min($embroidery_percentage, 100); ?>%"></div>
                    </div>
                </div>
                <div class="text-sm text-gray-600"><?php echo number_format($embroidery_percentage, 1); ?>% of monthly target</div>
            </div>

            <!-- CO2 Saved -->
            <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-lg p-6 border border-emerald-200">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center space-x-3">
                        <div class="bg-emerald-600 p-3 rounded-full">
                            <i class="fas fa-leaf text-white text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">CO₂ Saved</h3>
                            <p class="text-sm text-gray-600">Environmental Impact</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-2xl font-bold text-emerald-700"><?php echo number_format($co2_saved, 1); ?></div>
                        <div class="text-sm text-gray-600">kg</div>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <div class="mb-2">
                    <div class="flex justify-between text-sm text-gray-600 mb-1">
                        <span>Progress</span>
                        <span><?php echo number_format($co2_saved, 0); ?> / <?php echo number_format($co2_target, 0); ?></span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-gradient-to-r from-emerald-500 to-emerald-600 h-2 rounded-full transition-all duration-500" 
                             style="width: <?php echo min($co2_percentage, 100); ?>%"></div>
                    </div>
                </div>
                <div class="text-sm text-gray-600"><?php echo number_format($co2_percentage, 1); ?>% of monthly target</div>
            </div>
        </div>

        <!-- Top Seller Section -->
        <?php if ($top_seller): ?>
        <div class="bg-gradient-to-r from-purple-50 to-pink-50 rounded-lg p-6 border border-purple-200 mb-8">
            <div class="flex items-center space-x-3 mb-4">
                <div class="bg-gradient-to-r from-purple-500 to-pink-500 p-3 rounded-full">
                    <i class="fas fa-crown text-white text-xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800">Top Seller This Month</h3>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-center">
                <div class="flex items-center space-x-4">
                    <div class="w-24 h-24 bg-gray-200 rounded-full overflow-hidden border-4 border-purple-300">
                        <?php if (!empty($top_seller['logo_url'])): ?>
                            <img src="<?php echo htmlspecialchars($top_seller['logo_url']); ?>" 
                                 alt="<?php echo htmlspecialchars($top_seller['business_name']); ?>"
                                 class="w-full h-full object-cover">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center bg-purple-300">
                                <i class="fas fa-store text-white text-2xl"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h4 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($top_seller['business_name']); ?></h4>
                        <div class="flex items-center space-x-2 mt-2">
                            <span class="px-3 py-1 bg-purple-100 text-purple-800 text-xs font-semibold rounded-full">
                                <i class="fas fa-star mr-1"></i>Top Performer
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white rounded-lg p-4 border border-purple-100 text-center">
                        <div class="text-2xl font-bold text-purple-600">₱<?php echo number_format($top_seller['total_revenue'], 0); ?></div>
                        <div class="text-xs text-gray-600 mt-1">Total Revenue</div>
                    </div>
                    <div class="bg-white rounded-lg p-4 border border-purple-100 text-center">
                        <div class="text-2xl font-bold text-pink-600"><?php echo number_format($top_seller['total_orders']); ?></div>
                        <div class="text-xs text-gray-600 mt-1">Orders</div>
                    </div>
                    <div class="bg-white rounded-lg p-4 border border-purple-100 text-center">
                        <div class="text-2xl font-bold text-indigo-600"><?php echo number_format($top_seller['total_units_sold']); ?></div>
                        <div class="text-xs text-gray-600 mt-1">Units Sold</div>
                    </div>
                    <div class="bg-white rounded-lg p-4 border border-purple-100 text-center">
                        <div class="text-2xl font-bold text-violet-600"><?php echo number_format($top_seller['products_sold']); ?></div>
                        <div class="text-xs text-gray-600 mt-1">Products</div>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-purple-100 to-pink-100 rounded-lg p-4 text-center border border-purple-200">
                    <div class="text-sm text-gray-700 mb-2">
                        <i class="fas fa-leaf text-green-600 mr-1"></i>
                        <strong>Environmental Impact:</strong>
                    </div>
                    <div class="text-2xl font-bold text-green-600">
                        <?php echo number_format($top_seller['total_units_sold'] * 2.3, 1); ?>kg
                    </div>
                    <div class="text-xs text-gray-600">CO₂ Saved</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Top Product Section -->
        <?php if ($top_product): ?>
        <div class="bg-gradient-to-r from-yellow-50 to-orange-50 rounded-lg p-6 border border-yellow-200 mb-8">
            <div class="flex items-center space-x-3 mb-4">
                <div class="bg-gradient-to-r from-yellow-500 to-orange-500 p-3 rounded-full">
                    <i class="fas fa-trophy text-white text-xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800">Top Eco-Product This Month</h3>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-center">
                <div class="flex items-center space-x-4">
                    <div class="w-20 h-20 bg-gray-200 rounded-lg overflow-hidden">
                        <?php if (!empty($top_product['image'])): ?>
                            <img src="<?php echo htmlspecialchars($top_product['image']); ?>" 
                                 alt="<?php echo htmlspecialchars($top_product['name']); ?>"
                                 class="w-full h-full object-cover">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center bg-gray-300">
                                <i class="fas fa-image text-gray-500"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h4 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($top_product['name']); ?></h4>
                        <div class="flex items-center space-x-2 mt-1">
                            <span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full">
                                <?php echo htmlspecialchars($top_product['category']); ?>
                            </span>
                            <span class="text-sm text-gray-600">₱<?php echo number_format($top_product['price'], 2); ?></span>
                        </div>
                        <?php if (!empty($top_product['seller_name'])): ?>
                            <p class="text-sm text-gray-500 mt-1">by <?php echo htmlspecialchars($top_product['seller_name']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="text-center lg:text-right">
                    <div class="text-3xl font-bold text-orange-600"><?php echo number_format($top_product['units_sold']); ?></div>
                    <div class="text-sm text-gray-600">Units Sold</div>
                    <div class="mt-2 text-sm text-gray-700">
                        Environmental Impact: <span class="font-semibold text-green-600">
                        <?php echo number_format($top_product['units_sold'] * 2.3, 1); ?>kg CO₂ saved</span>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 mb-8 text-center">
            <i class="fas fa-info-circle text-gray-400 text-2xl mb-2"></i>
            <p class="text-gray-600">No sales data available for this month yet.</p>
        </div>
        <?php endif; ?>

        <!-- Top 5 Products List -->
        <?php if (!empty($top_products_list)): ?>
        <div class="bg-gradient-to-r from-blue-50 to-cyan-50 rounded-lg p-6 border border-blue-200 mb-8">
            <div class="flex items-center space-x-3 mb-4">
                <div class="bg-gradient-to-r from-blue-500 to-cyan-500 p-3 rounded-full">
                    <i class="fas fa-chart-bar text-white text-xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800">Top 5 Products This Month</h3>
            </div>
            
            <div class="space-y-3">
                <?php foreach ($top_products_list as $index => $product): ?>
                <div class="flex items-center justify-between bg-white rounded-lg p-4 border border-blue-100 hover:shadow-md transition-shadow">
                    <div class="flex items-center space-x-4 flex-1">
                        <div class="flex items-center justify-center w-8 h-8 bg-blue-500 text-white rounded-full font-bold text-sm">
                            <?php echo $index + 1; ?>
                        </div>
                        <div class="w-12 h-12 bg-gray-200 rounded-lg overflow-hidden">
                            <?php if (!empty($product['image'])): ?>
                                <img src="<?php echo htmlspecialchars($product['image']); ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                                     class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-gray-300">
                                    <i class="fas fa-image text-gray-500 text-xs"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1">
                            <h4 class="font-semibold text-gray-800"><?php echo htmlspecialchars($product['name']); ?></h4>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($product['seller_name']); ?></p>
                        </div>
                    </div>
                    
                    <div class="flex items-center space-x-6 text-right">
                        <div>
                            <div class="text-sm font-semibold text-gray-800"><?php echo number_format($product['units_sold']); ?></div>
                            <div class="text-xs text-gray-500">units</div>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-green-600">₱<?php echo number_format($product['revenue'], 0); ?></div>
                            <div class="text-xs text-gray-500">revenue</div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Platform Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg p-4 border border-gray-200 text-center">
                <div class="text-2xl font-bold text-green-600"><?php echo number_format($total_sellers); ?></div>
                <div class="text-sm text-gray-600">Active Sellers</div>
            </div>
            
            <div class="bg-white rounded-lg p-4 border border-gray-200 text-center">
                <div class="text-2xl font-bold text-blue-600"><?php echo number_format($total_users); ?></div>
                <div class="text-sm text-gray-600">Total Users</div>
            </div>
            
            <div class="bg-white rounded-lg p-4 border border-gray-200 text-center">
                <div class="text-2xl font-bold text-purple-600"><?php echo number_format($total_customers); ?></div>
                <div class="text-sm text-gray-600">Active Customers</div>
            </div>
            
            <div class="bg-white rounded-lg p-4 border border-gray-200 text-center">
                <div class="text-2xl font-bold text-orange-600"><?php echo number_format($total_orders); ?></div>
                <div class="text-sm text-gray-600">Total Orders</div>
            </div>
        </div>

        <!-- Revenue and Growth -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-lg p-6 border border-blue-200">
                <div class="flex items-center space-x-3 mb-2">
                    <div class="bg-blue-600 p-2 rounded-full">
                        <i class="fas fa-money-bill-wave text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800">Platform Revenue</h3>
                </div>
                <div class="text-2xl font-bold text-blue-700">₱<?php echo number_format($total_revenue, 2); ?></div>
                <div class="text-sm text-gray-600">Total completed sales</div>
            </div>
            
            <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-6 border border-purple-200">
                <div class="flex items-center space-x-3 mb-2">
                    <div class="bg-purple-600 p-2 rounded-full">
                        <i class="fas fa-chart-line text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800">Monthly Growth</h3>
                </div>
                <div class="text-2xl font-bold <?php echo $growth_percentage >= 0 ? 'text-green-700' : 'text-red-700'; ?>">
                    <?php echo $growth_percentage > 0 ? '+' : ''; ?><?php echo number_format($growth_percentage, 1); ?>%
                </div>
                <div class="text-sm text-gray-600">Orders vs last month</div>
            </div>
        </div>

        <!-- Community Impact Summary -->
        <div class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg p-6 border border-green-200">
            <h3 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-users text-green-600 mr-2"></i>
                Community Impact Summary
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-center">
                <div>
                    <div class="text-lg font-bold text-green-700"><?php echo number_format($waste_recycled + ($embroidery_pieces * 0.15), 1); ?>kg</div>
                    <div class="text-sm text-gray-600">Total Materials Saved</div>
                </div>
                <div>
                    <div class="text-lg font-bold text-emerald-700"><?php echo number_format($co2_saved, 1); ?>kg</div>
                    <div class="text-sm text-gray-600">CO₂ Emissions Prevented</div>
                </div>
                <div>
                    <div class="text-lg font-bold text-blue-700"><?php echo number_format($total_customers + $total_sellers); ?></div>
                    <div class="text-sm text-gray-600">Community Members</div>
                </div>
            </div>
            <div class="mt-4 text-center text-sm text-gray-700">
                <p><strong>Together, our WasteWise community is making a real difference!</strong></p>
                <p>Every purchase supports sustainable practices and helps reduce environmental impact.</p>
            </div>
        </div>

        <!-- Last Updated -->
        <div class="mt-6 text-center text-sm text-gray-500">
            <i class="fas fa-clock mr-1"></i>
            Last updated: <?php echo date('F j, Y g:i A'); ?>
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