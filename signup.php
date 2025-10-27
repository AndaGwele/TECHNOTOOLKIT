<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = "dpg-d3vnf0ngi27c73ahg940-a.oregon-postgres.render.com";
$port = "5432";
$db_name = "toolkit_3dlp";
$username = "toolkit_3dlp_user";  // Change to your PostgreSQL username
$password = "RMMOboK8xw6MBqXRswfdacOHjGXCkLE8";   // Change to your PostgreSQL password


try {
    // Create database connection
    $conn = new PDO("pgsql:host=$host;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get form data directly from POST
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $user_type = $_POST['user_type'] ?? '';

    // Validate required fields
    if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password) || empty($user_type)) {
        throw new Exception("All fields are required");
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email format");
    }

    // Validate password length
    if (strlen($password) < 6) {
        throw new Exception("Password must be at least 6 characters");
    }

    // Check if passwords match
    if ($password !== $confirm_password) {
        throw new Exception("Passwords do not match");
    }

    // Check if email already exists
    $check_query = "SELECT id FROM users WHERE email = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->execute([$email]);

    if ($check_stmt->rowCount() > 0) {
        throw new Exception("Email already registered");
    }

    // Hash password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);

    // Insert new user
    $insert_query = "INSERT INTO users (full_name, email, password, user_type, is_active) 
                     VALUES (?, ?, ?, ?, 1)";
    $insert_stmt = $conn->prepare($insert_query);
    $insert_stmt->execute([$full_name, $email, $hashed_password, $user_type]);

    // Get the new user ID
    $user_id = $conn->lastInsertId();

    // Start session and store user data
    session_start();
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $full_name;
    $_SESSION['user_type'] = $user_type;

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'User registered successfully!',
        'data' => [
            'id' => $user_id,
            'full_name' => $full_name,
            'email' => $email,
            'user_type' => $user_type
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

?>
