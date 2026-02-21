<?php
session_start();
require_once 'db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = ''; // 'success' or 'error'

// Fetch user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :user_id");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header("Location: login.php");
        exit();
    }
} catch (PDOException $e) {
    die("Error fetching user data: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_address') {
    try {
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS);
        $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS);
        $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_SPECIAL_CHARS);
        $barangay = filter_input(INPUT_POST, 'barangay', FILTER_SANITIZE_SPECIAL_CHARS);

        // Validation
        if (empty($name)) {
            throw new Exception("Full name is required.");
        }
        if (empty($phone) || strlen($phone) !== 11 || !preg_match('/^09\d{9}$/', $phone)) {
            throw new Exception("Valid phone number (11 digits starting with 09) is required.");
        }
        if (empty($address) || strlen($address) < 3) {
            throw new Exception("Valid address (at least 3 characters) is required.");
        }
        if (empty($barangay)) {
            throw new Exception("Barangay selection is required.");
        }

        $update_stmt = $pdo->prepare("
            UPDATE users SET 
            name = :name,
            default_phone = :phone,
            default_address = :address,
            default_barangay = :barangay
            WHERE id = :user_id
        ");

        $update_stmt->execute([
            ':name' => $name,
            ':phone' => $phone,
            ':address' => $address,
            ':barangay' => $barangay,
            ':user_id' => $user_id
        ]);

        $message = "Address updated successfully!";
        $message_type = 'success';

        // Refresh user data
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'error';
    }
}

$barangays_guimba = [ 'Agcano', 'Ayos Lomboy', 'Bacayao', 'Bagong Barrio', 'Balbalino', 'Balingog East', 'Balingog West', 'Banitan', 'Bantug', 'Bulakid', 'Bunol', 'Caballero', 'Cabaruan', 'Caingin Tabing Ilog', 'Calem', 'Camiling', 'Cardinal', 'Casongsong', 'Catimon', 'Cavite', 'Cawayan Bugtong', 'Consuelo', 'Culong', 'Escano', 'Faigal', 'Galvan', 'Guiset', 'Lamorito', 'Lennec', 'Macamias', 'Macapabellag', 'Macatcatuit', 'Manacsac', 'Manggang Marikit', 'Maturanoc', 'Maybubon', 'Naglabrahan', 'Nagpandayan', 'Narvacan I', 'Narvacan II', 'Pacac', 'Partida I', 'Partida II', 'Pasong Inchic', 'Saint John District (Pob.)', 'San Agustin', 'San Andres', 'San Bernardino', 'San Marcelino', 'San Miguel', 'San Rafael', 'San Roque', 'Santa Ana', 'Santa Cruz', 'Santa Lucia', 'Santa Veronica District (Pob.)', 'Santo Cristo District (Pob.)', 'Saranay District (Pob.)', 'Sinulatan', 'Subol', 'Tampac I', 'Tampac II & III', 'Triala', 'Yuson' ];
sort($barangays_guimba);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Address - Wastewise</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="logo.png">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; display: flex; flex-direction: column; }
        header { position: fixed; top: 0; left: 0; width: 100%; height: 5.5rem; background-color: #2f855a; z-index: 50; display: flex; align-items: center; }
        header > .container { width: 100%; }
        body { padding-top: 5.5rem; display: flex; flex-direction: column; min-height: 100vh; background-color: #f7fafc; }
        main { flex-grow: 1; margin-top: 0; padding-bottom: 2rem; }
        footer { background-color: #2f855a; color: white; text-align: center; padding: 1.5rem 0; z-index: 30; width: 100%; margin-top: auto; }
        input:focus, select:focus, button:focus { outline: none; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2f855a; }
        select { -webkit-appearance: none; -moz-appearance: none; appearance: none; background-image: url('data:image/svg+xml;utf8,<svg fill="black" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>'); background-repeat: no-repeat; background-position: right 0.7em top 50%; background-size: 1.2em auto; padding-right: 2.5em; }
    </style>
</head>
<body class="bg-gray-100">
    <header class="bg-green-700 text-white py-6">
        <div class="container mx-auto px-4">
            <div class="flex flex-col md:flex-row justify-between items-center w-full gap-4">
                <div class="flex justify-start items-center w-full md:w-1/3 space-x-2">
                    <img src="logo.png" alt="Wastewise Logo" class="h-8 w-8">
                    <h1 class="text-2xl font-bold">Wastewise</h1>
                </div>
                <div class="flex justify-center items-center w-full md:w-1/3 gap-20 text-base">
                    <a href="home.php" class="hover:text-gray-300"><i class="fas fa-home"></i></a>
                    <a href="checkout.php" class="hover:text-gray-300"><i class="fas fa-shopping-cart"></i></a>
                </div>
                <div class="flex justify-end w-full md:w-1/3">
                    <a href="home.php" class="text-white px-4 py-2 hover:text-gray-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto">
            <!-- Success/Error Messages -->
            <?php if ($message): ?>
                <div class="mb-6 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>">
                    <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow-md p-6 md:p-8">
                <h2 class="text-2xl font-bold text-green-700 mb-6">
                    <i class="fas fa-edit mr-3"></i>Edit Your Address
                </h2>

                <form method="POST" class="space-y-5">
                    <input type="hidden" name="action" value="update_address">

                    <!-- Full Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                        <input type="text" id="name" name="name" required 
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                            value="<?php echo htmlspecialchars($user['name'] ?? $user['username'] ?? ''); ?>">
                    </div>

                    <!-- Phone Number -->
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                        <input type="tel" id="phone" name="phone" required maxlength="11" placeholder="0XXXXXXXXXX"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                            oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(this.value.length > 11) this.value = this.value.substring(0, 11);"
                            value="<?php echo htmlspecialchars($user['default_phone'] ?? ''); ?>">
                        <small class="text-gray-500 mt-1 block">Must be 11 digits starting with 09</small>
                    </div>

                    <!-- Address / Purok -->
                    <div>
                        <label for="address" class="block text-sm font-medium text-gray-700 mb-2">Purok / Street / Building</label>
                        <input type="text" id="address" name="address" required 
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                            value="<?php echo htmlspecialchars($user['default_address'] ?? ''); ?>">
                        <small class="text-gray-500 mt-1 block">Minimum 3 characters</small>
                    </div>

                    <!-- Barangay -->
                    <div>
                        <label for="barangay" class="block text-sm font-medium text-gray-700 mb-2">Barangay</label>
                        <select id="barangay" name="barangay" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <option value="">-- Select Barangay --</option>
                            <?php foreach ($barangays_guimba as $barangay_option): ?>
                                <option value="<?php echo htmlspecialchars($barangay_option); ?>" 
                                    <?php echo ($barangay_option === ($user['default_barangay'] ?? '')) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($barangay_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- City, Province info (Read-only) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">City</label>
                            <input type="text" disabled class="w-full px-4 py-2.5 border border-gray-300 bg-gray-100 rounded-lg text-gray-600" value="Guimba">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Province</label>
                            <input type="text" disabled class="w-full px-4 py-2.5 border border-gray-300 bg-gray-100 rounded-lg text-gray-600" value="Nueva Ecija">
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex gap-3 mt-8">
                        <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg transition duration-200 shadow-md">
                            <i class="fas fa-save mr-2"></i>Save Address
                        </button>
                        <a href="checkout.php" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white font-bold py-3 px-6 rounded-lg transition duration-200 shadow-md text-center">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <footer>
        <div class="container mx-auto px-4">
            <p class="text-sm text-gray-300">&copy; <?= date('Y') ?> Wastewise E-commerce. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
