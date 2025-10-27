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
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get form data directly from POST
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Validate required fields
    if (empty($email) || empty($password)) {
        throw new Exception("Email and password are required");
    }

    // Check if user exists
    $query = "SELECT id, full_name, email, password, user_type, is_active 
              FROM users 
              WHERE email = ? 
              LIMIT 1";

    $stmt = $conn->prepare($query);
    $stmt->execute([$email]);

    if ($stmt->rowCount() == 0) {
        throw new Exception("Invalid email or password");
    }

    // Get user data
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if account is active
    if (!$user['is_active']) {
        throw new Exception("Account is deactivated. Please contact support.");
    }

    // Verify password
    if (!password_verify($password, $user['password'])) {
        throw new Exception("Invalid email or password");
    }

    // Start session and store user data
    session_start();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_type'] = $user['user_type'];

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Login successful!',
        'data' => [
            'id' => $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'user_type' => $user['user_type']
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>