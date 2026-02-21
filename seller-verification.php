<?php
// Ensure this file is included and not accessed directly
if (!defined('ADMIN_PANEL')) {
    die('Direct access not permitted');
}

// Function to get sellers with pagination and filtering
function getSellers($page = 1, $limit = 10, $search = '', $status_filter = 'pending') {
    global $db;
    $offset = ($page - 1) * $limit;
    
    $query = "SELECT s.*, u.username, u.email, u.created_at as user_created_at 
              FROM sellers s
              JOIN users u ON s.user_id = u.id
              WHERE 1=1";
    $countQuery = "SELECT COUNT(*) as total 
                   FROM sellers s
                   JOIN users u ON s.user_id = u.id
                   WHERE 1=1";
    
    if (!empty($search)) {
        $search = $db->real_escape_string($search);
        $query .= " AND (u.username LIKE '%$search%' OR u.email LIKE '%$search%' OR s.business_name LIKE '%$search%')";
        $countQuery .= " AND (u.username LIKE '%$search%' OR u.email LIKE '%$search%' OR s.business_name LIKE '%$search%')";
    }
    
    if ($status_filter != 'all') {
        $status = $db->real_escape_string($status_filter);
        $query .= " AND s.status = '$status'";
        $countQuery .= " AND s.status = '$status'";
    }
    
    // Order by status (pending first) and then by creation date
    $query .= " ORDER BY 
                CASE 
                    WHEN s.status = 'pending' THEN 1
                    WHEN s.status = 'approved' THEN 2
                    WHEN s.status = 'rejected' THEN 3
                END,
                s.created_at DESC
                LIMIT {$offset}, {$limit}";
    
    $result = $db->query($query);
    if (!$result) {
        die("Database query failed: " . $db->error);
    }
    $sellers = $result->fetch_all(MYSQLI_ASSOC);
    
    $countResult = $db->query($countQuery);
    if (!$countResult) {
        die("Count query failed: " . $db->error);
    }
    $totalSellers = $countResult->fetch_assoc()['total'];
    
    return [
        'sellers' => $sellers,
        'total' => $totalSellers
    ];
}

// Function to get seller documents
function getSellerDocuments($seller_id) {
    global $db;
    $seller_id = intval($seller_id);
    
    $query = "SELECT * FROM seller_documents WHERE seller_id = $seller_id";
    $result = $db->query($query);
    
    if (!$result) {
        die("Database query failed: " . $db->error);
    }
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Function to get seller counts by status
function getSellerCountByStatus($status = null) {
    global $db;
    $query = "SELECT COUNT(*) as total FROM sellers";
    
    if ($status !== null) {
        $status = $db->real_escape_string($status);
        $query .= " WHERE status = '$status'";
    }
    
    $result = $db->query($query);
    if (!$result) {
        die("Count query failed: " . $db->error);
    }
    return $result->fetch_assoc()['total'];
}

// Handle seller approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['seller_id'])) {
    $seller_id = intval($_POST['seller_id']);
    $action = $_POST['action'];
    $admin_notes = isset($_POST['admin_notes']) ? trim($_POST['admin_notes']) : '';
    
    // Begin transaction
    $db->begin_transaction();
    
    try {
        if ($action == 'approve') {
            // Update seller status to approved
            $stmt = $db->prepare("UPDATE sellers SET status = 'approved', approved_at = NOW(), approved_by = ?, admin_notes = ? WHERE id = ?");
            $stmt->bind_param("isi", $_SESSION['user_id'], $admin_notes, $seller_id);
            $stmt->execute();
            
            // Get seller and user information for email
            $query = "SELECT s.*, u.email, u.username, u.id as user_id 
                      FROM sellers s
                      JOIN users u ON s.user_id = u.id
                      WHERE s.id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $seller_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $seller = $result->fetch_assoc();
            
            if ($seller) {
                // Update user role to 'seller'
                $stmt = $db->prepare("UPDATE users SET role = 'seller' WHERE id = ?");
                $stmt->bind_param("i", $seller['user_id']);
                $stmt->execute();
                
                // Send approval email (you can implement this)
                // sendApprovalEmail($seller['email'], $seller['username'], $seller['business_name']);
                
                $message = "Seller application for {$seller['business_name']} has been approved successfully.";
            }
        } elseif ($action == 'reject') {
            // Require rejection reason
            if (empty($admin_notes)) {
                throw new Exception("Rejection reason is required.");
            }
            
            // Update seller status to rejected
            $stmt = $db->prepare("UPDATE sellers SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, admin_notes = ? WHERE id = ?");
            $stmt->bind_param("isi", $_SESSION['user_id'], $admin_notes, $seller_id);
            $stmt->execute();
            
            // Get seller and user information for email
            $query = "SELECT s.*, u.email, u.username 
                      FROM sellers s
                      JOIN users u ON s.user_id = u.id
                      WHERE s.id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $seller_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $seller = $result->fetch_assoc();
            
            if ($seller) {
                // Send rejection email (you can implement this)
                // sendRejectionEmail($seller['email'], $seller['username'], $seller['business_name'], $admin_notes);
                
                $message = "Seller application for {$seller['business_name']} has been rejected.";
            }
        }
        
        // Commit transaction
        $db->commit();
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollback();
        $error = $e->getMessage();
    }
}

// Get current page number and filters from URL
$page = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'pending';

// Get seller details if viewing a specific seller
$seller_details = null;
$seller_documents = [];
if (isset($_GET['view']) && !empty($_GET['view'])) {
    $seller_id = intval($_GET['view']);
    
    // Get seller details
    $query = "SELECT s.*, u.username, u.email, u.created_at as user_created_at 
              FROM sellers s
              JOIN users u ON s.user_id = u.id
              WHERE s.id = $seller_id";
    $result = $db->query($query);
    
    if ($result && $result->num_rows > 0) {
        $seller_details = $result->fetch_assoc();
        
        // Get seller documents
        $seller_documents = getSellerDocuments($seller_id);
    }
}

// Get sellers with pagination if not viewing a specific seller
if (!$seller_details) {
    $sellersData = getSellers($page, 10, $search, $status_filter);
    $sellers = $sellersData['sellers'];
    $totalSellers = $sellersData['total'];
    $totalPages = ceil($totalSellers / 10);
}

// Get seller counts by status
$totalPendingSellers = getSellerCountByStatus('pending');
$totalApprovedSellers = getSellerCountByStatus('approved');
$totalRejectedSellers = getSellerCountByStatus('rejected');
$totalAllSellers = getSellerCountByStatus();
?>

<div class="container mx-auto px-4">
    <?php if ($seller_details): ?>
        <!-- Seller Details View -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Seller Application Details</h2>
                    <p class="text-gray-600">Reviewing application for <?php echo htmlspecialchars($seller_details['business_name']); ?></p>
                </div>
                <a href="?page=seller_verification<?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?>" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                    <i class="fas fa-arrow-left mr-2"></i> Back to List
                </a>
            </div>
            
            <?php if (isset($message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                    <p><?php echo $message; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Seller Information -->
                <div class="md:col-span-2">
                    <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Business Information</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-500">Business Name</p>
                                <p class="font-medium"><?php echo htmlspecialchars($seller_details['business_name']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Business Type</p>
                                <p class="font-medium"><?php echo htmlspecialchars($seller_details['business_type']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Tax ID / Registration Number</p>
                                <p class="font-medium"><?php echo htmlspecialchars($seller_details['tax_id'] ?: 'Not provided'); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Phone Number</p>
                                <p class="font-medium"><?php echo htmlspecialchars($seller_details['phone_number']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Website</p>
                                <p class="font-medium">
                                    <?php if ($seller_details['website']): ?>
                                        <a href="<?php echo htmlspecialchars($seller_details['website']); ?>" target="_blank" class="text-blue-600 hover:underline">
                                            <?php echo htmlspecialchars($seller_details['website']); ?>
                                            <i class="fas fa-external-link-alt text-xs ml-1"></i>
                                        </a>
                                    <?php else: ?>
                                        Not provided
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Application Date</p>
                                <p class="font-medium"><?php echo date('M d, Y h:i A', strtotime($seller_details['created_at'])); ?></p>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <p class="text-sm text-gray-500">Business Address</p>
                            <p class="font-medium">
                                <?php echo htmlspecialchars($seller_details['business_address']); ?><br>
                                <?php echo htmlspecialchars($seller_details['city'] . ', ' . $seller_details['state'] . ' ' . $seller_details['postal_code']); ?><br>
                                <?php echo htmlspecialchars($seller_details['country']); ?>
                            </p>
                        </div>
                        
                        <?php if ($seller_details['description']): ?>
                            <div class="mt-4">
                                <p class="text-sm text-gray-500">Business Description</p>
                                <p class="font-medium"><?php echo nl2br(htmlspecialchars($seller_details['description'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 mt-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">User Information</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-500">Username</p>
                                <p class="font-medium"><?php echo htmlspecialchars($seller_details['username']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Email</p>
                                <p class="font-medium"><?php echo htmlspecialchars($seller_details['email']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">User ID</p>
                                <p class="font-medium"><?php echo htmlspecialchars($seller_details['user_id']); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">User Created</p>
                                <p class="font-medium"><?php echo date('M d, Y h:i A', strtotime($seller_details['user_created_at'])); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($seller_documents)): ?>
                        <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 mt-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Uploaded Documents</h3>
                            
                            <div class="grid grid-cols-1 gap-4">
                                <?php foreach ($seller_documents as $document): ?>
                                    <div class="border border-gray-200 rounded-lg p-4">
                                        <div class="flex justify-between items-center mb-2">
                                            <h4 class="font-medium"><?php echo htmlspecialchars($document['document_type']); ?></h4>
                                            <a href="<?php echo htmlspecialchars($document['document_url']); ?>" target="_blank" class="text-blue-600 hover:underline">
                                                <i class="fas fa-external-link-alt mr-1"></i> View Full Size
                                            </a>
                                        </div>
                                        
                                        <?php
                                        $file_extension = pathinfo($document['document_url'], PATHINFO_EXTENSION);
                                        $is_image = in_array(strtolower($file_extension), ['jpg', 'jpeg', 'png', 'gif']);
                                        ?>
                                        
                                        <?php if ($is_image): ?>
                                            <img src="<?php echo htmlspecialchars($document['document_url']); ?>" alt="<?php echo htmlspecialchars($document['document_type']); ?>" class="max-w-full h-auto max-h-64 object-contain">
                                        <?php else: ?>
                                            <div class="bg-gray-100 p-4 rounded flex items-center justify-center">
                                                <i class="fas fa-file-pdf text-red-500 text-4xl mr-3"></i>
                                                <div>
                                                    <p class="font-medium">PDF Document</p>
                                                    <p class="text-sm text-gray-500">Click "View Full Size" to open</p>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Action Panel -->
                <div>
                    <div class="bg-gray-50 rounded-lg p-6 border border-gray-200 sticky top-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Application Status</h3>
                        
                        <div class="mb-4">
                            <?php if ($seller_details['status'] == 'pending'): ?>
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs font-semibold uppercase">
                                    <i class="fas fa-clock mr-1"></i> Pending Review
                                </span>
                            <?php elseif ($seller_details['status'] == 'approved'): ?>
                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-semibold uppercase">
                                    <i class="fas fa-check-circle mr-1"></i> Approved
                                </span>
                                <p class="text-sm text-gray-500 mt-2">
                                    Approved on <?php echo date('M d, Y h:i A', strtotime($seller_details['approved_at'])); ?>
                                </p>
                            <?php elseif ($seller_details['status'] == 'rejected'): ?>
                                <span class="px-2 py-1 bg-red-100 text-red-800 rounded-full text-xs font-semibold uppercase">
                                    <i class="fas fa-times-circle mr-1"></i> Rejected
                                </span>
                                <p class="text-sm text-gray-500 mt-2">
                                    Rejected on <?php echo date('M d, Y h:i A', strtotime($seller_details['rejected_at'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($seller_details['admin_notes']): ?>
                            <div class="mb-4">
                                <h4 class="text-sm font-medium text-gray-700 mb-1">Admin Notes</h4>
                                <div class="bg-white p-3 rounded border border-gray-200">
                                    <?php echo nl2br(htmlspecialchars($seller_details['admin_notes'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($seller_details['status'] == 'pending'): ?>
                            <div class="space-y-4">
                                <form action="" method="POST" id="approve-form">
                                    <input type="hidden" name="seller_id" value="<?php echo $seller_details['id']; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    
                                    <div class="mb-4">
                                        <label for="approve_notes" class="block text-sm font-medium text-gray-700 mb-1">Admin Notes (Optional)</label>
                                        <textarea id="approve_notes" name="admin_notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Add any notes or comments..."></textarea>
                                    </div>
                                    
                                    <button type="submit" class="w-full py-2 px-4 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                        <i class="fas fa-check-circle mr-2"></i> Approve Application
                                    </button>
                                </form>
                                
                                <div class="relative">
                                    <div class="absolute inset-0 flex items-center">
                                        <div class="w-full border-t border-gray-300"></div>
                                    </div>
                                    <div class="relative flex justify-center text-sm">
                                        <span class="px-2 bg-gray-50 text-gray-500">OR</span>
                                    </div>
                                </div>
                                
                                <form action="" method="POST" id="reject-form">
                                    <input type="hidden" name="seller_id" value="<?php echo $seller_details['id']; ?>">
                                    <input type="hidden" name="action" value="reject">
                                    
                                    <div class="mb-4">
                                        <label for="reject_notes" class="block text-sm font-medium text-gray-700 mb-1">Rejection Reason (Required)</label>
                                        <textarea id="reject_notes" name="admin_notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Provide reason for rejection..." required></textarea>
                                    </div>
                                    
                                    <button type="submit" class="w-full py-2 px-4 bg-red-600 hover:bg-red-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                        <i class="fas fa-times-circle mr-2"></i> Reject Application
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($seller_details['status'] == 'approved' || $seller_details['status'] == 'rejected'): ?>
                            <div class="mt-4">
                                <a href="?page=seller_verification&status=pending" class="block w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md text-center">
                                    <i class="fas fa-list mr-2"></i> View Pending Applications
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Seller Applications List -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Seller Verification</h2>
                    <p class="text-gray-600">Manage seller applications and approvals</p>
                </div>
            </div>
            
            <!-- Seller Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-yellow-50 rounded-lg p-4">
                    <h3 class="text-lg font-semibold text-yellow-700">Pending</h3>
                    <p class="text-3xl font-bold text-yellow-600"><?php echo $totalPendingSellers; ?></p>
                </div>
                <div class="bg-green-50 rounded-lg p-4">
                    <h3 class="text-lg font-semibold text-green-700">Approved</h3>
                    <p class="text-3xl font-bold text-green-600"><?php echo $totalApprovedSellers; ?></p>
                </div>
                <div class="bg-red-50 rounded-lg p-4">
                    <h3 class="text-lg font-semibold text-red-700">Rejected</h3>
                    <p class="text-3xl font-bold text-red-600"><?php echo $totalRejectedSellers; ?></p>
                </div>
                <div class="bg-blue-50 rounded-lg p-4">
                    <h3 class="text-lg font-semibold text-blue-700">Total</h3>
                    <p class="text-3xl font-bold text-blue-600"><?php echo $totalAllSellers; ?></p>
                </div>
            </div>
            
            <!-- Status Filter Tabs -->
            <div class="flex flex-wrap mb-4">
                <a href="?page=seller_verification&status=pending&search=<?php echo urlencode($search); ?>" 
                   class="px-4 py-2 mr-2 mb-2 rounded-md <?php echo $status_filter == 'pending' ? 'bg-yellow-600 text-white' : 'bg-yellow-100 text-yellow-800 hover:bg-yellow-200'; ?>">
                    <i class="fas fa-clock mr-1"></i> Pending
                </a>
                <a href="?page=seller_verification&status=approved&search=<?php echo urlencode($search); ?>" 
                   class="px-4 py-2 mr-2 mb-2 rounded-md <?php echo $status_filter == 'approved' ? 'bg-green-600 text-white' : 'bg-green-100 text-green-800 hover:bg-green-200'; ?>">
                    <i class="fas fa-check-circle mr-1"></i> Approved
                </a>
                <a href="?page=seller_verification&status=rejected&search=<?php echo urlencode($search); ?>" 
                   class="px-4 py-2 mr-2 mb-2 rounded-md <?php echo $status_filter == 'rejected' ? 'bg-red-600 text-white' : 'bg-red-100 text-red-800 hover:bg-red-200'; ?>">
                    <i class="fas fa-times-circle mr-1"></i> Rejected
                </a>
                <a href="?page=seller_verification&status=all&search=<?php echo urlencode($search); ?>" 
                   class="px-4 py-2 mr-2 mb-2 rounded-md <?php echo $status_filter == 'all' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-800 hover:bg-gray-300'; ?>">
                    <i class="fas fa-list mr-1"></i> All
                </a>
            </div>
            
            <!-- Search Form -->
            <form action="" method="GET" class="mb-6">
                <input type="hidden" name="page" value="seller_verification">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <div class="flex gap-2">
                    <input type="text" name="search" placeholder="Search by business name, username, or email..." 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           class="flex-1 border rounded px-4 py-2 focus:outline-none focus:ring-2 focus:ring-green-500">
                    <button type="submit" class="bg-green-500 text-white px-6 py-2 rounded hover:bg-green-600">
                        Search
                    </button>
                    <?php if (!empty($search)): ?>
                    <a href="?page=seller_verification&status=<?php echo htmlspecialchars($status_filter); ?>" class="bg-gray-300 text-gray-700 px-4 py-2 rounded hover:bg-gray-400">
                        Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>
            
            <?php if (isset($message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                    <p><?php echo $message; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Sellers Table -->
            <div class="overflow-x-auto">
                <?php if (empty($sellers)): ?>
                    <div class="p-6 text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                            <i class="fas fa-store text-3xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-1">No seller applications found</h3>
                        <p class="text-gray-500">
                            <?php if ($status_filter == 'pending'): ?>
                                There are no pending seller applications at this time.
                            <?php elseif ($status_filter == 'approved'): ?>
                                There are no approved seller applications.
                            <?php elseif ($status_filter == 'rejected'): ?>
                                There are no rejected seller applications.
                            <?php else: ?>
                                There are no seller applications in the system.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <table class="min-w-full bg-white">
                        <thead>
                            <tr>
                                <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Business
                                </th>
                                <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Owner
                                </th>
                                <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Type
                                </th>
                                <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Date
                                </th>
                                <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Status
                                </th>
                                <th class="px-6 py-3 border-b-2 border-gray-200 bg-gray-50 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sellers as $seller): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($seller['business_name']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($seller['city'] . ', ' . $seller['state']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($seller['username']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($seller['email']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                        <div class="text-sm text-gray-900"><?php echo htmlspecialchars($seller['business_type']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                        <div class="text-sm text-gray-900"><?php echo date('M d, Y', strtotime($seller['created_at'])); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo date('h:i A', strtotime($seller['created_at'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200">
                                        <?php if ($seller['status'] == 'pending'): ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                <i class="fas fa-clock mr-1"></i> Pending
                                            </span>
                                        <?php elseif ($seller['status'] == 'approved'): ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle mr-1"></i> Approved
                                            </span>
                                        <?php elseif ($seller['status'] == 'rejected'): ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                <i class="fas fa-times-circle mr-1"></i> Rejected
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap border-b border-gray-200 text-sm font-medium text-right">
                                        <a href="?page=seller_verification&view=<?php echo $seller['id']; ?><?php echo isset($_GET['status']) ? '&status=' . $_GET['status'] : ''; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                            <i class="fas fa-eye mr-1"></i> View
                                        </a>
                                        <?php if ($seller['status'] == 'pending'): ?>
                                            <a href="?page=seller_verification&view=<?php echo $seller['id']; ?>" class="text-green-600 hover:text-green-900 mr-3">
                                                <i class="fas fa-check mr-1"></i> Review
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="mt-4 flex justify-center">
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <a href="?page=seller_verification&p=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>" 
                                   class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50
                                          <?php echo $page === $i ? 'bg-green-50 text-green-600' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </nav>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Quick Action Modals for Approve/Reject -->
<div id="quick-approve-modal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg max-w-md w-full p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Approve Seller Application</h3>
        <form action="" method="POST" id="quick-approve-form">
            <input type="hidden" name="seller_id" id="quick-approve-id">
            <input type="hidden" name="action" value="approve">
            
            <div class="mb-4">
                <label for="quick-approve-notes" class="block text-sm font-medium text-gray-700 mb-1">Admin Notes (Optional)</label>
                <textarea id="quick-approve-notes" name="admin_notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500" placeholder="Add any notes or comments..."></textarea>
            </div>
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeModal('quick-approve-modal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    Approve
                </button>
            </div>
        </form>
    </div>
</div>

<div id="quick-reject-modal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg max-w-md w-full p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Reject Seller Application</h3>
        <form action="" method="POST" id="quick-reject-form">
            <input type="hidden" name="seller_id" id="quick-reject-id">
            <input type="hidden" name="action" value="reject">
            
            <div class="mb-4">
                <label for="quick-reject-notes" class="block text-sm font-medium text-gray-700 mb-1">Rejection Reason (Required)</label>
                <textarea id="quick-reject-notes" name="admin_notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Provide reason for rejection..." required></textarea>
            </div>
            
            <div class="flex justify-end space-x-3">
                <button type="button" onclick="closeModal('quick-reject-modal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                    Reject
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
    // Quick approve function
    function quickApprove(sellerId) {
        document.getElementById('quick-approve-id').value = sellerId;
        document.getElementById('quick-approve-modal').classList.remove('hidden');
    }
    
    // Quick reject function
    function quickReject(sellerId) {
        document.getElementById('quick-reject-id').value = sellerId;
        document.getElementById('quick-reject-modal').classList.remove('hidden');
    }
    
    // Close modal function
    function closeModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
    }
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        const approveModal = document.getElementById('quick-approve-modal');
        const rejectModal = document.getElementById('quick-reject-modal');
        
        if (event.target === approveModal) {
            approveModal.classList.add('hidden');
        }
        
        if (event.target === rejectModal) {
            rejectModal.classList.add('hidden');
        }
    });
</script>