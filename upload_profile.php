<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

session_start();
$db = new mysqli('localhost', 'root', '', 'wastewise'); // adjust db creds

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if file is uploaded
if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {
    $targetDir = "uploads/";
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $fileName = time() . "_" . basename($_FILES["profile_pic"]["name"]);
    $targetFilePath = $targetDir . $fileName;

    $fileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));
    $allowedTypes = ['jpg','jpeg','png','gif'];

    if (in_array($fileType, $allowedTypes)) {
        if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $targetFilePath)) {
            // Save to database
            $stmt = $db->prepare("UPDATE users SET profile_pic=? WHERE id=?");
            $stmt->bind_param("si", $fileName, $user_id);
            $stmt->execute();
            $stmt->close();

            header("Location: index.php?upload=success");
            exit();
        }
    }
}

header("Location: index.php?upload=fail");
exit();
