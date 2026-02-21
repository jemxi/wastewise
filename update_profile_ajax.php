<?php
session_start();
require_once 'db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

$user_id = $_SESSION['user_id'];
$username = trim($data['username']);
$emails = $data['emails'] ?? [];

if ($username === "") {
    echo json_encode(['success' => false, 'message' => 'Username cannot be empty']);
    exit;
}

// ✅ Update username
$stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
$stmt->bind_param("si", $username, $user_id);
$stmt->execute();
$stmt->close();

// ✅ Update first email in users table
if (!empty($emails)) {
    $main_email = $emails[0];
    $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
    $stmt->bind_param("si", $main_email, $user_id);
    $stmt->execute();
    $stmt->close();
}

// ✅ Update optional table user_emails (if you use one)
$conn->query("CREATE TABLE IF NOT EXISTS user_emails (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  email VARCHAR(255) NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$conn->query("DELETE FROM user_emails WHERE user_id = $user_id");
$insert = $conn->prepare("INSERT INTO user_emails (user_id, email) VALUES (?, ?)");
foreach ($emails as $em) {
    $em = trim($em);
    if ($em !== "") {
        $insert->bind_param("is", $user_id, $em);
        $insert->execute();
    }
}
$insert->close();

// ✅ Update session username (para mag-reflect agad)
$_SESSION['username'] = $username;

echo json_encode(['success' => true]);
?>
