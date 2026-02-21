<?php
// Ensure this file is included and not accessed directly
if (!defined('ADMIN_PANEL')) {
    die('Direct access not permitted');
}

// Check if the role column exists in the users table, if not add it
$checkRoleColumn = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($checkRoleColumn->num_rows == 0) {
    $db->query("ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'user'");
    // Update existing admins to have admin role
    $db->query("UPDATE users SET role = 'admin' WHERE is_admin = 1");
    // Update existing sellers to have seller role
    $db->query("UPDATE users u SET role = 'seller' WHERE EXISTS (SELECT 1 FROM sellers s WHERE s.user_id = u.id)");
}

$checkSuspendColumn = $db->query("SHOW COLUMNS FROM users LIKE 'is_suspended'");
if ($checkSuspendColumn->num_rows == 0) {
    $db->query("ALTER TABLE users ADD COLUMN is_suspended INT DEFAULT 0");
}

// Function to get users with pagination and filtering
function getUsers($page = 1, $limit = 10, $search = '', $role_filter = 'all') {
    global $db;
    $offset = ($page - 1) * $limit;
    
    $query = "SELECT u.*, s.business_name FROM users u LEFT JOIN sellers s ON u.id = s.user_id WHERE 1=1";
    $countQuery = "SELECT COUNT(*) as total FROM users WHERE 1=1";
    
    if (!empty($search)) {
        $search = $db->real_escape_string($search);
        $query .= " AND (u.username LIKE '%$search%' OR u.email LIKE '%$search%')";
        $countQuery .= " AND (username LIKE '%$search%' OR email LIKE '%$search%')";
    }
    
    if ($role_filter != 'all') {
        $role_filter = $db->real_escape_string($role_filter);
        $query .= " AND u.role = '$role_filter'";
        $countQuery .= " AND role = '$role_filter'";
    }
    
    // Fix the LIMIT clause syntax
    $query .= " ORDER BY u.created_at DESC LIMIT {$offset}, {$limit}";
    
    $result = $db->query($query);
    if (!$result) {
        die("Database query failed: " . $db->error);
    }
    $users = $result->fetch_all(MYSQLI_ASSOC);
    
    $countResult = $db->query($countQuery);
    if (!$countResult) {
        die("Count query failed: " . $db->error);
    }
    $totalUsers = $countResult->fetch_assoc()['total'];
    
    return [
        'users' => $users,
        'total' => $totalUsers
    ];
}

// Function to get total number of users by role
function getUserCountByRole($role = null) {
    global $db;
    $query = "SELECT COUNT(*) as total FROM users";
    
    if ($role !== null) {
        $role = $db->real_escape_string($role);
        $query .= " WHERE role = '$role'";
    }
    
    $result = $db->query($query);
    if (!$result) {
        die("Count query failed: " . $db->error);
    }
    return $result->fetch_assoc()['total'];
}

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        if ($_POST['action'] == 'delete' && $user_id > 0) {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $message = "User deleted successfully.";
        } elseif ($_POST['action'] == 'toggle_admin' && $user_id > 0) {
            $stmt = $db->prepare("UPDATE users SET is_admin = 1 - is_admin, role = CASE WHEN is_admin = 0 THEN 'admin' ELSE 'user' END WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $message = "User admin status toggled successfully.";
        } elseif ($_POST['action'] == 'change_role' && $user_id > 0) {
            $new_role = $_POST['role'];
            $allowed_roles = ['user', 'seller', 'admin'];
            
            if (in_array($new_role, $allowed_roles)) {
                $stmt = $db->prepare("UPDATE users SET role = ?, is_admin = CASE WHEN ? = 'admin' THEN 1 ELSE 0 END WHERE id = ?");
                $stmt->bind_param("ssi", $new_role, $new_role, $user_id);
                $stmt->execute();
                $message = "User role updated successfully.";
            } else {
                $error = "Invalid role selected.";
            }
        } elseif ($_POST['action'] == 'suspend' && $user_id > 0) {
            $stmt = $db->prepare("UPDATE users SET is_suspended = 1 WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $message = "User account has been suspended.";
        } elseif ($_POST['action'] == 'unsuspend' && $user_id > 0) {
            $stmt = $db->prepare("UPDATE users SET is_suspended = 0 WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $message = "User account has been unsuspended.";
        }
    }
}

// Get current page number from URL
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';

// Get users with pagination
$usersData = getUsers($page, 10, $search, $role_filter);
$users = $usersData['users'];
$totalUsers = $usersData['total'];
$totalPages = ceil($totalUsers / 10);

// Get user counts by role
$totalActiveUsers = getUserCountByRole();
$totalRegularUsers = getUserCountByRole('user');
$totalSellers = getUserCountByRole('seller');
$totalAdmins = getUserCountByRole('admin');
?>
<link rel="icon" type="image/png" href="logo.png">

<div class="container mx-auto px-4">
    <div class="bg-white rounded-lg shadow-md p-6 mb-6">
        <h2 class="text-2xl font-bold mb-4">User Management</h2>
        
        <!-- User Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-green-50 rounded-lg p-4">
                <h3 class="text-lg font-semibold text-green-700">Total Users</h3>
                <p class="text-3xl font-bold text-green-600"><?php echo $totalUsers; ?></p>
            </div>
            <div class="bg-blue-50 rounded-lg p-4">
                <h3 class="text-lg font-semibold text-blue-700">Regular Users</h3>
                <p class="text-3xl font-bold text-blue-600"><?php echo $totalRegularUsers; ?></p>
            </div>
            <div class="bg-yellow-50 rounded-lg p-4">
                <h3 class="text-lg font-semibold text-yellow-700">Sellers</h3>
                <p class="text-3xl font-bold text-yellow-600"><?php echo $totalSellers; ?></p>
            </div>
            <div class="bg-purple-50 rounded-lg p-4">
                <h3 class="text-lg font-semibold text-purple-700">Admins</h3>
                <p class="text-3xl font-bold text-purple-600"><?php echo $totalAdmins; ?></p>
            </div>
        </div>

        <!-- Role Filter Tabs -->
        <div class="flex flex-wrap mb-4">
            <a href="?page=user_management&role=all&search=<?php echo urlencode($search); ?>" 
               class="px-4 py-2 mr-2 mb-2 rounded-md <?php echo $role_filter == 'all' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'; ?>">
                All Users
            </a>
            <a href="?page=user_management&role=user&search=<?php echo urlencode($search); ?>" 
               class="px-4 py-2 mr-2 mb-2 rounded-md <?php echo $role_filter == 'user' ? 'bg-blue-600 text-white' : 'bg-blue-100 text-blue-800 hover:bg-blue-200'; ?>">
                Regular Users
            </a>
            <a href="?page=user_management&role=seller&search=<?php echo urlencode($search); ?>" 
               class="px-4 py-2 mr-2 mb-2 rounded-md <?php echo $role_filter == 'seller' ? 'bg-yellow-600 text-white' : 'bg-yellow-100 text-yellow-800 hover:bg-yellow-200'; ?>">
                Sellers
            </a>
            <a href="?page=user_management&role=admin&search=<?php echo urlencode($search); ?>" 
               class="px-4 py-2 mr-2 mb-2 rounded-md <?php echo $role_filter == 'admin' ? 'bg-purple-600 text-white' : 'bg-purple-100 text-purple-800 hover:bg-purple-200'; ?>">
                Admins
            </a>
        </div>

        <!-- Search Form -->
        <form action="" method="GET" class="mb-6">
            <input type="hidden" name="page" value="user_management">
            <input type="hidden" name="role" value="<?php echo htmlspecialchars($role_filter); ?>">
            <div class="flex gap-2">
                <input type="text" name="search" placeholder="Search users..." 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       class="flex-1 border rounded px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                <button type="submit" class="bg-green-500 text-white px-6 py-2 rounded hover:bg-green-600">
                    Search
                </button>
                <?php if (!empty($search)): ?>
                <a href="?page=user_management&role=<?php echo htmlspecialchars($role_filter); ?>" class="bg-gray-300 text-gray-700 px-4 py-2 rounded hover:bg-gray-400">
                    Clear
                </a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (isset($message)): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
            <p><?php echo $message; ?></p>
        </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
            <p><?php echo $error; ?></p>
        </div>
        <?php endif; ?>

        <!-- Users Table -->
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white">
                <thead>
                    <tr>
                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Username
                        </th>
                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Email
                        </th>
                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Registered
                        </th>
                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Role
                        </th>
                        <!-- Added Shop Name column that displays business_name for sellers -->
                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Shop Name
                        </th>
                        <!-- Added Status column showing suspended/active status -->
                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Status
                        </th>
                        <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                            No users found.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                <?php echo htmlspecialchars($user['username']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                <?php echo htmlspecialchars($user['email']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                <?php 
                                $role = isset($user['role']) ? $user['role'] : ($user['is_admin'] ? 'admin' : 'user');
                                $roleClass = '';
                                $roleIcon = '';
                                
                                switch($role) {
                                    case 'admin':
                                        $roleClass = 'bg-purple-100 text-purple-800';
                                        $roleIcon = '<i class="fas fa-user-shield mr-1"></i>';
                                        break;
                                    case 'seller':
                                        $roleClass = 'bg-yellow-100 text-yellow-800';
                                        $roleIcon = '<i class="fas fa-store mr-1"></i>';
                                        break;
                                    default:
                                        $roleClass = 'bg-blue-100 text-blue-800';
                                        $roleIcon = '<i class="fas fa-user mr-1"></i>';
                                }
                                ?>
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $roleClass; ?>">
                                    <?php echo $roleIcon . ucfirst($role); ?>
                                </span>
                            </td>
                            <!-- Added Shop Name column that displays business_name for sellers -->
                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                <?php 
                                $role = isset($user['role']) ? $user['role'] : ($user['is_admin'] ? 'admin' : 'user');
                                if ($role === 'seller' && !empty($user['business_name'])) {
                                    echo htmlspecialchars($user['business_name']);
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <!-- Added Status column showing suspended/active status -->
                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                <?php 
                                $is_suspended = isset($user['is_suspended']) ? $user['is_suspended'] : 0;
                                if ($is_suspended == 1) {
                                    echo '<span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800"><i class="fas fa-ban mr-1"></i> Suspended</span>';
                                } else {
                                    echo '<span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800"><i class="fas fa-check-circle mr-1"></i> Active</span>';
                                }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200 text-sm font-medium">
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <button type="button" onclick="showChangeRoleModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo $role; ?>')" class="text-blue-600 hover:text-blue-900 mr-2">
                                        Change Role
                                    </button>
                                    <!-- Added suspend/unsuspend buttons -->
                                    <?php if ($is_suspended == 1): ?>
                                        <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to unsuspend this user?');">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="action" value="unsuspend">
                                            <button type="submit" class="text-green-600 hover:text-green-900 mr-2">Unsuspend</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to suspend this user?');">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="action" value="suspend">
                                            <button type="submit" class="text-orange-600 hover:text-orange-900 mr-2">Suspend</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-gray-400">Current User</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="mt-4 flex justify-center">
            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=user_management&p=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo urlencode($role_filter); ?>" 
                       class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50
                              <?php echo $page === $i ? 'bg-green-50 text-green-600' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Change Role Modal -->
<div id="change-role-modal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg max-w-md w-full p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Change User Role</h3>
        <p class="text-gray-600 mb-4">You are changing the role for user: <span id="modal-username" class="font-medium"></span></p>
        
        <form action="" method="POST" id="change-role-form">
            <input type="hidden" name="action" value="change_role">
            <input type="hidden" name="user_id" id="modal-user-id">
            
            <div class="mb-4">
                <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Select Role</label>
                <select id="role" name="role" class="w-full px-3 py-2 rounded-lg border-2 border-gray-200 outline-none focus:border-blue-500" required>
                    <option value="user">Regular User</option>
                    <option value="seller">Seller</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Update Role
                </button>
            </div>
        </form>
    </div>
</div>
 <body onLoad="noBack();" onpageshow="if (event.persisted) noBack();" onUnload="">
    
    <script type="text/javascript">
    window.history.forward();
    function noBack()
    {
        window.history.forward();
    }
<script>
    // Change role modal functions
    function showChangeRoleModal(userId, username, currentRole) {
        document.getElementById('modal-user-id').value = userId;
        document.getElementById('modal-username').textContent = username;
        document.getElementById('role').value = currentRole;
        document.getElementById('change-role-modal').classList.remove('hidden');
    }
    
    function closeModal() {
        document.getElementById('change-role-modal').classList.add('hidden');
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('change-role-modal');
        if (event.target === modal) {
            closeModal();
        }
    });
</script>
