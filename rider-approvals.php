<?php
if (!defined('ADMIN_PANEL')) {
    die('Direct access not allowed');
}

$filter_status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch pending riders with search
try {
    $query = "SELECT r.*, u.username, u.email FROM riders r 
              JOIN users u ON r.user_id = u.id 
              WHERE r.status = ?";
    $params = [$filter_status];
    
    if (!empty($search_query)) {
        $query .= " AND (r.first_name LIKE ? OR r.last_name LIKE ? OR u.email LIKE ? OR r.phone LIKE ?)";
        $search_term = '%' . $search_query . '%';
        array_push($params, $search_term, $search_term, $search_term, $search_term);
    }
    
    $query .= " ORDER BY r.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $riders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    $riders = [];
    $error = "Error fetching riders: " . $e->getMessage();
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $rider_id = intval($_POST['rider_id']);
    $action = $_POST['action'];
    $verification_notes = isset($_POST['verification_notes']) ? trim($_POST['verification_notes']) : '';
    
    $new_status = ($action === 'approve') ? 'approved' : 'rejected';
    
    try {
        $stmt = $db->prepare("UPDATE riders SET status = ?, verification_notes = ?, verification_date = NOW() WHERE id = ?");
        $stmt->execute([$new_status, $verification_notes, $rider_id]);
        
        if ($stmt->affected_rows > 0) {
            // Get rider email for notification
            $stmt = $db->prepare("SELECT r.email, r.first_name, u.email as user_email FROM riders r JOIN users u ON r.user_id = u.id WHERE r.id = ?");
            $stmt->execute([$rider_id]);
            $rider = $stmt->get_result()->fetch_assoc();
            
            $message = ($action === 'approve') 
                ? "Congratulations! Your rider application has been approved. You can now start accepting deliveries."
                : "Unfortunately, your rider application has been rejected. Please contact support for more information.";
            
            // Send email (implement sendEmail function)
            // sendRiderStatusEmail($rider['user_email'], $rider['first_name'], $action, $message);
            
            $_SESSION['success_message'] = "Rider " . ($action === 'approve' ? 'approved' : 'rejected') . " successfully!";
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error processing rider: " . $e->getMessage();
    }
    
    header("Location: admin_panel.php?page=rider-approvals&status=" . $filter_status);
    exit;
}

// Count pending riders for notification
try {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM riders WHERE status = 'pending'");
    $stmt->execute();
    $pending_count = $stmt->get_result()->fetch_assoc()['count'];
} catch (Exception $e) {
    $pending_count = 0;
}
?>

<div>
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
            <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
            <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
        <h2 class="text-2xl font-bold text-gray-800">Rider Applications</h2>
        <div class="flex gap-2">
            <a href="?page=rider-approvals&status=pending" class="px-4 py-2 rounded <?php echo $filter_status === 'pending' ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                Pending (<?php echo $pending_count; ?>)
            </a>
            <a href="?page=rider-approvals&status=approved" class="px-4 py-2 rounded <?php echo $filter_status === 'approved' ? 'bg-green-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                Approved
            </a>
            <a href="?page=rider-approvals&status=rejected" class="px-4 py-2 rounded <?php echo $filter_status === 'rejected' ? 'bg-red-600 text-white' : 'bg-gray-200 text-gray-700'; ?>">
                Rejected
            </a>
        </div>
    </div>

    <div class="mb-4">
        <form method="GET" class="flex gap-2">
            <input type="hidden" name="page" value="rider-approvals">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
            <input type="text" name="search" placeholder="Search by name, email, or phone..." 
                   value="<?php echo htmlspecialchars($search_query); ?>"
                   class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-search"></i> Search
            </button>
        </form>
    </div>

    <?php if (!empty($riders)): ?>
        <div class="space-y-4">
            <?php foreach ($riders as $rider): ?>
                <div class="bg-white p-6 rounded-lg shadow-md border-l-4 <?php echo $rider['status'] === 'approved' ? 'border-green-500' : ($rider['status'] === 'rejected' ? 'border-red-500' : 'border-yellow-500'); ?>">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($rider['first_name'] . ' ' . $rider['last_name']); ?></h3>
                            <p class="text-gray-600"><i class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($rider['email']); ?></p>
                            <p class="text-gray-600"><i class="fas fa-phone mr-2"></i><?php echo htmlspecialchars($rider['phone']); ?></p>
                        </div>
                        
                        <div>
                            <p><strong>Vehicle Type:</strong> <?php echo htmlspecialchars($rider['vehicle_type']); ?></p>
                            <p><strong>License Plate:</strong> <?php echo htmlspecialchars($rider['license_plate'] ?? 'N/A'); ?></p>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars($rider['city'] . ', ' . $rider['province']); ?></p>
                        </div>
                        
                        <div>
                            <p><strong>Status:</strong> 
                                <span class="px-3 py-1 rounded-full text-white text-sm <?php 
                                    echo $rider['status'] === 'approved' ? 'bg-green-600' : 
                                         ($rider['status'] === 'rejected' ? 'bg-red-600' : 'bg-yellow-600'); 
                                ?>">
                                    <?php echo ucfirst($rider['status']); ?>
                                </span>
                            </p>
                            <p><strong>Applied:</strong> <?php echo date('M d, Y', strtotime($rider['created_at'])); ?></p>
                        </div>
                    </div>

                    <div class="mb-4 p-3 bg-gray-50 rounded">
                        <p><strong>ORCR Number:</strong> <?php echo htmlspecialchars($rider['orcr_number']); ?></p>
                        <p><strong>Driver License:</strong> <?php echo htmlspecialchars($rider['driver_license_number']); ?></p>
                    </div>

                    <div class="mb-4 flex gap-2 flex-wrap">
                        <a href="<?php echo htmlspecialchars($rider['orcr_file']); ?>" target="_blank" class="px-3 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 text-sm">
                            <i class="fas fa-file mr-1"></i> View ORCR
                        </a>
                        <a href="<?php echo htmlspecialchars($rider['driver_license_file']); ?>" target="_blank" class="px-3 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 text-sm">
                            <i class="fas fa-file mr-1"></i> View License
                        </a>
                    </div>

                    <?php if ($rider['status'] === 'pending'): ?>
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="rider_id" value="<?php echo $rider['id']; ?>">
                            
                            <div>
                                <textarea name="verification_notes" placeholder="Optional verification notes..." 
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" 
                                          rows="2"></textarea>
                            </div>
                            
                            <div class="flex gap-2">
                                <button type="submit" name="action" value="approve" class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                                    <i class="fas fa-check mr-2"></i> Approve
                                </button>
                                <button type="submit" name="action" value="reject" class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                    <i class="fas fa-times mr-2"></i> Reject
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="p-3 bg-gray-100 rounded">
                            <p><strong>Verification Notes:</strong></p>
                            <p class="text-gray-700"><?php echo htmlspecialchars($rider['verification_notes'] ?? 'No notes provided'); ?></p>
                            <p class="text-sm text-gray-500 mt-2">Verified on: <?php echo date('M d, Y H:i', strtotime($rider['verification_date'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4">
            <p><i class="fas fa-info-circle mr-2"></i> No riders found.</p>
        </div>
    <?php endif; ?>
</div>
