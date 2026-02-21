<?php
session_start();

$db = new mysqli('localhost', 'u255729624_wastewise', '/l5Dv04*K', 'u255729624_wastewise');

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Check if rider already has an application
$check_stmt = $db->prepare("SELECT * FROM riders WHERE user_id = ?");
$check_stmt->bind_param("i", $user_id);
$check_stmt->execute();
$existing_rider = $check_stmt->get_result()->fetch_assoc();
$check_stmt->close();

if ($existing_rider) {
    $status_color = $existing_rider['status'] === 'approved' ? 'bg-green-100 text-green-800' : 
                    ($existing_rider['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800');
    $status_text = ucfirst($existing_rider['status']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$existing_rider) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $city = trim($_POST['city']);
    $province = trim($_POST['province']);
    $barangay = trim($_POST['barangay']);
    $vehicle_type = trim($_POST['vehicle_type']);
    $orcr_number = trim($_POST['orcr_number']);
    $license_number = trim($_POST['license_number']);

    // Validation
    if (empty($first_name) || empty($last_name) || empty($phone) || empty($address) || 
        empty($city) || empty($province) || empty($barangay) || empty($vehicle_type) || 
        empty($orcr_number) || empty($license_number)) {
        $error = "All fields are required.";
    } elseif (strtolower($city) !== 'guimba' || strtolower($province) !== 'nueva ecija') {
        $error = "Riders must be residents of Guimba, Nueva Ecija only.";
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $error = "Invalid Philippine phone number format.";
    } else {
        // Handle file uploads
        $orcr_file = null;
        $license_file = null;
        $upload_dir = 'uploads/rider_documents/';

        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // Upload ORCR
        if (isset($_FILES['orcr_file']) && $_FILES['orcr_file']['error'] === UPLOAD_ERR_OK) {
            $file_ext = pathinfo($_FILES['orcr_file']['name'], PATHINFO_EXTENSION);
            $orcr_file = 'orcr_' . $user_id . '_' . time() . '.' . $file_ext;
            move_uploaded_file($_FILES['orcr_file']['tmp_name'], $upload_dir . $orcr_file);
        } else {
            $error = "ORCR document upload failed.";
        }

        // Upload Driver's License
        if (empty($error) && isset($_FILES['license_file']) && $_FILES['license_file']['error'] === UPLOAD_ERR_OK) {
            $file_ext = pathinfo($_FILES['license_file']['name'], PATHINFO_EXTENSION);
            $license_file = 'license_' . $user_id . '_' . time() . '.' . $file_ext;
            move_uploaded_file($_FILES['license_file']['tmp_name'], $upload_dir . $license_file);
        } else if (empty($error)) {
            $error = "Driver's License upload failed.";
        }

        // Insert into database
        if (empty($error)) {
            $stmt = $db->prepare("INSERT INTO riders (user_id, first_name, last_name, phone, address, city, province, barangay, vehicle_type, orcr_number, orcr_file, license_number, license_file, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->bind_param("isssssssssss", $user_id, $first_name, $last_name, $phone, $address, $city, $province, $barangay, $vehicle_type, $orcr_number, $orcr_file, $license_number, $license_file);
            
            if ($stmt->execute()) {
                $success = "Registration submitted successfully! Your application is pending approval.";
                $existing_rider = [
                    'status' => 'pending',
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'phone' => $phone
                ];
            } else {
                $error = "Error submitting application: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Registration Center - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .bg-gradient {
            background: linear-gradient(135deg, #FF9800 0%, #E65100 100%);
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navigation -->
    <nav class="bg-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex justify-between items-center">
                <div class="text-2xl font-bold text-orange-600">
                    <i class="fas fa-bicycle mr-2"></i> Wastewise Riders
                </div>
                <a href="seller_login.php" class="text-gray-600 hover:text-gray-900">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Login
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Header -->
        <div class="bg-gradient text-white rounded-lg shadow-lg p-6 sm:p-8 mb-8">
            <h1 class="text-3xl sm:text-4xl font-bold mb-2">
                <i class="fas fa-truck-moving mr-3"></i> Rider Registration Center
            </h1>
            <p class="text-orange-100">Join our delivery network and start earning today!</p>
        </div>

        <?php if ($existing_rider): ?>
            <!-- Application Status Section -->
            <div class="bg-white rounded-lg shadow-md p-6 sm:p-8 mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-clipboard-list mr-2"></i> Application Status
                </h2>
                
                <div class="space-y-4">
                    <div class="flex items-center justify-between bg-gray-50 p-4 rounded-lg">
                        <span class="text-gray-700 font-medium">Status:</span>
                        <span class="<?php echo $status_color; ?> px-4 py-2 rounded-full font-bold text-sm">
                            <?php echo $status_text; ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <p class="text-gray-600 text-sm">Full Name</p>
                            <p class="text-lg font-semibold text-gray-800">
                                <?php echo htmlspecialchars($existing_rider['first_name'] . ' ' . $existing_rider['last_name']); ?>
                            </p>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <p class="text-gray-600 text-sm">Phone Number</p>
                            <p class="text-lg font-semibold text-gray-800">
                                <?php echo htmlspecialchars($existing_rider['phone']); ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($existing_rider['status'] === 'rejected'): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 p-4 rounded">
                            <p class="text-red-800">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                Your application was rejected. Please review the requirements and reapply.
                            </p>
                        </div>
                    <?php elseif ($existing_rider['status'] === 'pending'): ?>
                        <div class="bg-yellow-100 border-l-4 border-yellow-500 p-4 rounded">
                            <p class="text-yellow-800">
                                <i class="fas fa-hourglass-half mr-2"></i>
                                Your application is under review. We'll notify you soon!
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="bg-green-100 border-l-4 border-green-500 p-4 rounded">
                            <p class="text-green-800">
                                <i class="fas fa-check-circle mr-2"></i>
                                Welcome! You're approved to start making deliveries. <a href="riders_login.php" class="font-bold underline">Login here</a>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Registration Form -->
            <div class="bg-white rounded-lg shadow-md p-6 sm:p-8 mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-edit mr-2"></i> Complete Your Registration
                </h2>

                <?php if ($error): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded">
                        <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded">
                        <i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <!-- Personal Information Section -->
                    <fieldset>
                        <legend class="text-lg font-bold text-gray-800 mb-4 pb-2 border-b-2 border-orange-500">
                            <i class="fas fa-user mr-2"></i> Personal Information
                        </legend>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="first_name" class="block text-gray-700 font-medium mb-2">
                                    First Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="first_name" name="first_name" required
                                       class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-orange-500 focus:outline-none">
                            </div>

                            <div>
                                <label for="last_name" class="block text-gray-700 font-medium mb-2">
                                    Last Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="last_name" name="last_name" required
                                       class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-orange-500 focus:outline-none">
                            </div>

                            <div>
                                <label for="phone" class="block text-gray-700 font-medium mb-2">
                                    Phone Number <span class="text-red-500">*</span>
                                </label>
                                <input type="tel" id="phone" name="phone" placeholder="09xxxxxxxxx" required
                                       class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-orange-500 focus:outline-none">
                                <p class="text-sm text-gray-500 mt-1">Format: 09xxxxxxxxx</p>
                            </div>

                            <div>
                                <label for="vehicle_type" class="block text-gray-700 font-medium mb-2">
                                    Vehicle Type <span class="text-red-500">*</span>
                                </label>
                                <select id="vehicle_type" name="vehicle_type" required
                                        class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-orange-500 focus:outline-none">
                                    <option value="">Select a vehicle type</option>
                                    <option value="bicycle">Bicycle</option>
                                    <option value="motorcycle">Motorcycle</option>
                                    <option value="tricycle">Tricycle</option>
                                    <option value="van">Van</option>
                                    <option value="truck">Truck</option>
                                </select>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Address Section -->
                    <fieldset>
                        <legend class="text-lg font-bold text-gray-800 mb-4 pb-2 border-b-2 border-orange-500">
                            <i class="fas fa-map-marker-alt mr-2"></i> Address (Guimba, Nueva Ecija ONLY)
                        </legend>

                        <div>
                            <label for="address" class="block text-gray-700 font-medium mb-2">
                                Street Address <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="address" name="address" placeholder="House No., Street Name" required
                                   class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-orange-500 focus:outline-none">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                            <div>
                                <label for="barangay" class="block text-gray-700 font-medium mb-2">
                                    Barangay <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="barangay" name="barangay" placeholder="e.g., Balangkas" required
                                       class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-orange-500 focus:outline-none">
                            </div>

                            <div>
                                <label for="city" class="block text-gray-700 font-medium mb-2">
                                    City <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="city" name="city" value="Guimba" readonly
                                       class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg bg-gray-100 cursor-not-allowed">
                            </div>

                            <div>
                                <label for="province" class="block text-gray-700 font-medium mb-2">
                                    Province <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="province" name="province" value="Nueva Ecija" readonly
                                       class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg bg-gray-100 cursor-not-allowed">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Document Verification Section -->
                    <fieldset>
                        <legend class="text-lg font-bold text-gray-800 mb-4 pb-2 border-b-2 border-orange-500">
                            <i class="fas fa-file-upload mr-2"></i> Document Verification
                        </legend>

                        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded">
                            <p class="text-blue-800 text-sm">
                                <i class="fas fa-info-circle mr-2"></i>
                                Upload clear photos of your ORCR and Driver's License. Accepted formats: JPG, PNG, PDF (Max 5MB each)
                            </p>
                        </div>

                        <div>
                            <label for="orcr_number" class="block text-gray-700 font-medium mb-2">
                                ORCR Number <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="orcr_number" name="orcr_number" placeholder="Vehicle OR-CR Number" required
                                   class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-orange-500 focus:outline-none">
                        </div>

                        <div class="mt-4">
                            <label for="orcr_file" class="block text-gray-700 font-medium mb-2">
                                ORCR Document <span class="text-red-500">*</span>
                            </label>
                            <div class="flex items-center justify-center border-2 border-dashed border-gray-300 rounded-lg p-6 hover:border-orange-500 transition">
                                <input type="file" id="orcr_file" name="orcr_file" accept=".jpg,.jpeg,.png,.pdf" required
                                       class="w-full" onchange="displayFileName(this, 'orcr_name')">
                            </div>
                            <p class="text-sm text-gray-500 mt-2">
                                <i class="fas fa-camera mr-1"></i> <span id="orcr_name">Click to select ORCR photo</span>
                            </p>
                        </div>

                        <div class="mt-6">
                            <label for="license_number" class="block text-gray-700 font-medium mb-2">
                                Driver's License Number <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="license_number" name="license_number" placeholder="License Number" required
                                   class="w-full px-4 py-2 border-2 border-gray-300 rounded-lg focus:border-orange-500 focus:outline-none">
                        </div>

                        <div class="mt-4">
                            <label for="license_file" class="block text-gray-700 font-medium mb-2">
                                Driver's License Document <span class="text-red-500">*</span>
                            </label>
                            <div class="flex items-center justify-center border-2 border-dashed border-gray-300 rounded-lg p-6 hover:border-orange-500 transition">
                                <input type="file" id="license_file" name="license_file" accept=".jpg,.jpeg,.png,.pdf" required
                                       class="w-full" onchange="displayFileName(this, 'license_name')">
                            </div>
                            <p class="text-sm text-gray-500 mt-2">
                                <i class="fas fa-camera mr-1"></i> <span id="license_name">Click to select Driver's License photo</span>
                            </p>
                        </div>
                    </fieldset>

                    <!-- Submit Section -->
                    <div class="flex flex-col sm:flex-row gap-4 pt-6 border-t-2 border-gray-200">
                        <button type="submit" class="flex-1 bg-gradient text-white font-bold py-3 rounded-lg hover:shadow-lg transition">
                            <i class="fas fa-check-circle mr-2"></i> Submit Application
                        </button>
                        <a href="seller_login.php" class="flex-1 bg-gray-300 text-gray-800 font-bold py-3 rounded-lg hover:bg-gray-400 transition text-center">
                            <i class="fas fa-times-circle mr-2"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Requirements Section -->
        <div class="bg-white rounded-lg shadow-md p-6 sm:p-8">
            <h3 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-list-check mr-2"></i> Requirements
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="border-l-4 border-orange-500 pl-4">
                    <h4 class="font-bold text-gray-800 mb-3">Documents Required:</h4>
                    <ul class="space-y-2 text-gray-700">
                        <li><i class="fas fa-check text-orange-500 mr-2"></i> Original Receipt/Certification (ORCR)</li>
                        <li><i class="fas fa-check text-orange-500 mr-2"></i> Valid Driver's License</li>
                        <li><i class="fas fa-check text-orange-500 mr-2"></i> Proof of Residence (Guimba, Nueva Ecija)</li>
                    </ul>
                </div>

                <div class="border-l-4 border-orange-500 pl-4">
                    <h4 class="font-bold text-gray-800 mb-3">Eligibility:</h4>
                    <ul class="space-y-2 text-gray-700">
                        <li><i class="fas fa-check text-orange-500 mr-2"></i> Must be 18 years or older</li>
                        <li><i class="fas fa-check text-orange-500 mr-2"></i> Resident of Guimba, Nueva Ecija</li>
                        <li><i class="fas fa-check text-orange-500 mr-2"></i> Own a registered vehicle</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        function displayFileName(input, targetId) {
            if (input.files && input.files[0]) {
                document.getElementById(targetId).textContent = input.files[0].name;
            }
        }

        // Enforce Guimba, Nueva Ecija location
        document.getElementById('city').addEventListener('blur', function() {
            if (this.value.toLowerCase() !== 'guimba') {
                this.value = 'Guimba';
            }
        });

        document.getElementById('province').addEventListener('blur', function() {
            if (this.value.toLowerCase() !== 'nueva ecija') {
                this.value = 'Nueva Ecija';
            }
        });
    </script>
</body>
</html>
