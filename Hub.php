<?php
session_start();
header("Content-Type: text/html; charset=UTF-8");

// Check if user is logged in and is a job seeker
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'job-seeker') {
    header("Location: signupLogin.html");
    exit();
}

// Database configuration
$host = "dpg-d3vnf0ngi27c73ahg940-a.oregon-postgres.render.com";
$port = "5432";
$db_name = "toolkit_3dlp";
$username = "toolkit_3dlp_user";
$password = "RMMOboK8xw6MBqXRswfdacOHjGXCkLE8";

try {
    $conn = new PDO("pgsql:host=$host;port=$port;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get or create hub user
    $user_id = $_SESSION['user_id'];
    $user = getOrCreateHubUser($conn, $user_id);
    
    if (!$user) {
        throw new Exception("Unable to create user profile");
    }

    $hub_user_id = $user['id'];

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handleFormSubmission($conn, $hub_user_id);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Fetch all data for display
    $data = fetchUserData($conn, $hub_user_id);
    
    // Calculate dashboard stats
    $stats = calculateDashboardStats($data, $hub_user_id, $conn);
    
    // Calculate recent activities
    $recent_activities = calculateRecentActivities($data);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
} catch (Exception $e) {
    error_log("Application error: " . $e->getMessage());
    die("An error occurred. Please try again.");
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: signupLogin.html");
    exit();
}

// Helper functions
function getOrCreateHubUser($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM hub_users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Get basic user info
        $stmt = $conn->prepare("SELECT full_name, email, user_type FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user_data) {
            $is_mentor = ($user_data['user_type'] === 'mentor') ? 1 : 0;
            
            $stmt = $conn->prepare("INSERT INTO hub_users (user_id, full_name, email, user_type, is_mentor) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                $user_data['full_name'],
                $user_data['email'],
                $user_data['user_type'],
                $is_mentor
            ]);
            
            // Fetch the newly created user
            $stmt = $conn->prepare("SELECT * FROM hub_users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $_SESSION['message'] = "Welcome to your learning hub! Get started by adding your skills and goals.";
        }
    }
    
    return $user;
}

function handleFormSubmission($conn, $hub_user_id) {
    $action = $_POST['action'] ?? '';
    
    $handlers = [
        'create_bootcamp' => function() use ($conn, $hub_user_id) {
            $stmt = $conn->prepare("INSERT INTO bootcamps (user_id, name, description, duration, level, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->execute([
                $hub_user_id,
                htmlspecialchars($_POST['bootcamp_name']),
                htmlspecialchars($_POST['bootcamp_description']),
                (int)$_POST['bootcamp_duration'],
                htmlspecialchars($_POST['bootcamp_level'])
            ]);
            $_SESSION['message'] = 'Bootcamp created successfully';
        },
        
        'delete_bootcamp' => function() use ($conn, $hub_user_id) {
            $stmt = $conn->prepare("DELETE FROM bootcamps WHERE id = ? AND user_id = ?");
            $stmt->execute([(int)$_POST['bootcamp_id'], $hub_user_id]);
            $_SESSION['message'] = 'Bootcamp deleted successfully';
        },
        
        'create_skill' => function() use ($conn, $hub_user_id) {
            $stmt = $conn->prepare("INSERT INTO skills (user_id, name, category, level, description) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $hub_user_id,
                htmlspecialchars($_POST['skill_name']),
                htmlspecialchars($_POST['skill_category']),
                htmlspecialchars($_POST['skill_level']),
                htmlspecialchars($_POST['description'] ?? '')
            ]);
            $_SESSION['message'] = 'Skill added successfully';
        },

        'delete_skill' => function() use ($conn, $hub_user_id) {
            $stmt = $conn->prepare("DELETE FROM skills WHERE id = ? AND user_id = ?");
            $stmt->execute([(int)$_POST['skill_id'], $hub_user_id]);
            $_SESSION['message'] = 'Skill deleted successfully';
        },

        'create_certification' => function() use ($conn, $hub_user_id) {
            $stmt = $conn->prepare("INSERT INTO certifications (user_id, name, issuer, date_earned, credential_id, credential_url) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $hub_user_id,
                htmlspecialchars($_POST['cert_name']),
                htmlspecialchars($_POST['cert_issuer']),
                htmlspecialchars($_POST['cert_date']),
                htmlspecialchars($_POST['cert_credential_id'] ?? ''),
                htmlspecialchars($_POST['cert_url'] ?? '')
            ]);
            $_SESSION['message'] = 'Certification added successfully';
        },

        'delete_certification' => function() use ($conn, $hub_user_id) {
            $stmt = $conn->prepare("DELETE FROM certifications WHERE id = ? AND user_id = ?");
            $stmt->execute([(int)$_POST['certification_id'], $hub_user_id]);
            $_SESSION['message'] = 'Certification deleted successfully';
        },

        'apply_job' => function() use ($conn, $hub_user_id) {
            // Check if already applied
            $stmt = $conn->prepare("SELECT id FROM job_applications WHERE job_id = ? AND jobseeker_id = ?");
            $stmt->execute([$_POST['job_id'], $hub_user_id]);
            $existing_application = $stmt->fetch();

            if (!$existing_application) {
                $stmt = $conn->prepare("INSERT INTO job_applications (job_id, jobseeker_id, cover_letter, status) VALUES (?, ?, ?, 'pending')");
                $stmt->execute([
                    $_POST['job_id'],
                    $hub_user_id,
                    htmlspecialchars($_POST['cover_letter'] ?? '')
                ]);
                
                // Update applications count
                $stmt = $conn->prepare("UPDATE jobs SET applications_count = applications_count + 1 WHERE id = ?");
                $stmt->execute([$_POST['job_id']]);
                
                $_SESSION['message'] = 'Job application submitted successfully!';
            } else {
                $_SESSION['message'] = 'You have already applied for this job.';
            }
        },

        'request_mentorship' => function() use ($conn, $hub_user_id) {
            $stmt = $conn->prepare("INSERT INTO mentorship_requests (mentee_id, mentor_id, goals, frequency, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([
                $hub_user_id,
                $_POST['mentor_id'],
                htmlspecialchars($_POST['mentorship_goals']),
                htmlspecialchars($_POST['mentorship_frequency'])
            ]);
            $_SESSION['message'] = 'Mentorship request sent successfully';
        },

        'update_profile' => function() use ($conn, $hub_user_id) {
            $stmt = $conn->prepare("UPDATE hub_users SET full_name = ?, email = ?, bio = ?, expertise = ?, goals = ? WHERE id = ?");
            $stmt->execute([
                htmlspecialchars($_POST['profile_name']),
                htmlspecialchars($_POST['profile_email']),
                htmlspecialchars($_POST['profile_bio'] ?? ''),
                htmlspecialchars($_POST['profile_expertise'] ?? ''),
                htmlspecialchars($_POST['profile_goals'] ?? ''),
                $hub_user_id
            ]);
            $_SESSION['message'] = 'Profile updated successfully';
        }
    ];
    
    if (isset($handlers[$action])) {
        $handlers[$action]();
    }
}

function fetchUserData($conn, $hub_user_id) {
    $data = [];
    
    // Bootcamps
    $stmt = $conn->prepare("SELECT * FROM bootcamps WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$hub_user_id]);
    $data['bootcamps'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Skills
    $stmt = $conn->prepare("SELECT * FROM skills WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$hub_user_id]);
    $data['skills'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Certifications
    $stmt = $conn->prepare("SELECT * FROM certifications WHERE user_id = ? ORDER BY date_earned DESC");
    $stmt->execute([$hub_user_id]);
    $data['certifications'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Available Jobs
    $stmt = $conn->prepare("
        SELECT 
            j.*,
            hu.full_name as employer_name,
            (SELECT COUNT(*) FROM job_applications WHERE job_id = j.id AND jobseeker_id = ?) as has_applied
        FROM jobs j 
        JOIN hub_users hu ON j.employer_id = hu.id 
        WHERE j.is_active = true 
        AND (j.application_deadline IS NULL OR j.application_deadline >= CURRENT_DATE)
        ORDER BY j.created_at DESC
    ");
    $stmt->execute([$hub_user_id]);
    $data['available_jobs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Job Applications
    $stmt = $conn->prepare("
        SELECT 
            ja.*,
            j.title as job_title,
            j.company_name,
            j.location
        FROM job_applications ja
        JOIN jobs j ON ja.job_id = j.id
        WHERE ja.jobseeker_id = ?
        ORDER BY ja.applied_at DESC
    ");
    $stmt->execute([$hub_user_id]);
    $data['job_applications'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mentors
    $stmt = $conn->prepare("
        SELECT 
            hu.*,
            m.expertise,
            m.rating,
            m.is_available
        FROM hub_users hu
        JOIN mentors m ON hu.id = m.user_id
        WHERE m.is_available = true 
        AND hu.user_type = 'mentor'
        ORDER BY m.rating DESC
    ");
    $stmt->execute();
    $data['mentors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $data;
}

function calculateDashboardStats($data, $hub_user_id, $conn) {
    $stats = [];
    
    $stats['active_bootcamps'] = array_reduce($data['bootcamps'], function($count, $bootcamp) {
        return $count + ($bootcamp['status'] === 'active' ? 1 : 0);
    }, 0);
    
    $stats['skills_count'] = count($data['skills']);
    $stats['certs_count'] = count($data['certifications']);
    $stats['jobs_applied_count'] = count($data['job_applications']);
    
    // Mentors connected
    $stmt = $conn->prepare("SELECT COUNT(*) FROM mentorship_requests WHERE mentee_id = ? AND status = 'accepted'");
    $stmt->execute([$hub_user_id]);
    $stats['mentors_count'] = $stmt->fetchColumn();
    
    return $stats;
}

function calculateRecentActivities($data) {
    $activities = [];
    
    foreach ($data['bootcamps'] as $bootcamp) {
        $activities[] = [
            'type' => 'bootcamp',
            'title' => 'Started bootcamp: ' . $bootcamp['name'],
            'date' => $bootcamp['created_at']
        ];
    }

    foreach ($data['certifications'] as $cert) {
        $activities[] = [
            'type' => 'certification',
            'title' => 'Earned certification: ' . $cert['name'],
            'date' => $cert['date_earned']
        ];
    }

    foreach ($data['job_applications'] as $application) {
        $activities[] = [
            'type' => 'job_application',
            'title' => 'Applied for: ' . $application['job_title'],
            'date' => $application['applied_at']
        ];
    }

    // Sort activities by date
    usort($activities, function ($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    // Get only the 5 most recent activities
    return array_slice($activities, 0, 5);
}

// Extract variables for use in HTML
$bootcamps = $data['bootcamps'];
$skills = $data['skills'];
$certifications = $data['certifications'];
$available_jobs = $data['available_jobs'];
$job_applications = $data['job_applications'];
$mentors = $data['mentors'];

$active_bootcamps = $stats['active_bootcamps'];
$skills_count = $stats['skills_count'];
$certs_count = $stats['certs_count'];
$jobs_applied_count = $stats['jobs_applied_count'];
$mentors_count = $stats['mentors_count'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skills & Career Hub</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
        background: #f8f9fa;
        color: #333;
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    .navbar {
        background: white;
        padding: 15px 0;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .nav-brand h1 {
        margin: 0;
        text-align: center;
        color: #007bff;
    }

    .user-info {
        background: #f8f9fa;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .user-details {
        font-size: 14px;
        color: #666;
    }

    .logout-btn {
        background: #dc3545;
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 3px;
        cursor: pointer;
        text-decoration: none;
    }

    .logout-btn:hover {
        background: #c82333;
    }

    .access-denied {
        text-align: center;
        padding: 50px;
        color: #dc3545;
    }

    .message {
        padding: 10px;
        margin: 10px 0;
        border-radius: 5px;
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 15px;
        margin-bottom: 15px;
    }

    .job-card {
        border-left: 4px solid #28a745;
    }

    .application-card {
        border-left: 4px solid #17a2b8;
    }

    .mentor-card {
        border-left: 4px solid #ffc107;
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
    }

    .delete-btn {
        background: #dc3545;
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 3px;
        cursor: pointer;
    }

    .apply-btn {
        background: #28a745;
        color: white;
        border: none;
        padding: 8px 15px;
        border-radius: 4px;
        cursor: pointer;
        margin-top: 10px;
    }

    .applied-btn {
        background: #6c757d;
        color: white;
        border: none;
        padding: 8px 15px;
        border-radius: 4px;
        cursor: not-allowed;
        margin-top: 10px;
    }

    .request-btn {
        background: #007bff;
        color: white;
        border: none;
        padding: 8px 15px;
        border-radius: 4px;
        cursor: pointer;
        margin-top: 10px;
    }

    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
    }

    .modal-content {
        background-color: white;
        margin: 10% auto;
        padding: 20px;
        border-radius: 8px;
        width: 500px;
        max-width: 90%;
        max-height: 85vh;
        overflow-y: auto;
    }

    .close {
        float: right;
        font-size: 24px;
        font-weight: bold;
        cursor: pointer;
        color: #666;
    }

    .close:hover {
        color: #000;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }

    .btn {
        padding: 10px 15px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }

    .btn-primary {
        background: #007bff;
        color: white;
    }

    .section {
        display: none;
    }

    .section.active {
        display: block;
    }

    .nav-link.active {
        font-weight: bold;
        color: #007bff;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .section-header h2 {
        margin: 0;
    }

    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 20px;
        display: flex;
        align-items: center;
    }

    .stat-icon {
        font-size: 2em;
        margin-right: 15px;
    }

    .stat-content h3 {
        margin: 0 0 5px 0;
        font-size: 14px;
        color: #666;
    }

    .stat-number {
        font-size: 24px;
        font-weight: bold;
        margin: 0;
        color: #333;
    }

    .activity-section {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 20px;
    }

    .empty-state {
        text-align: center;
        color: #666;
        font-style: italic;
        padding: 20px;
    }

    .nav-menu {
        display: flex;
        list-style: none;
        padding: 0;
        margin: 15px 0 0 0;
        justify-content: center;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
    }

    .nav-menu li {
        margin: 0;
    }

    .nav-link {
        text-decoration: none;
        color: #333;
        padding: 5px 10px;
        border-radius: 4px;
        transition: background-color 0.3s;
        display: block;
    }

    .nav-link:hover {
        background-color: #f8f9fa;
    }

    /* Dropdown Styles */
    .dropdown {
        position: relative;
    }

    .dropdown-toggle {
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .dropdown-toggle::after {
        content: '▼';
        font-size: 10px;
        margin-left: 5px;
    }

    .dropdown-menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        background: white;
        min-width: 200px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        border-radius: 6px;
        padding: 8px 0;
        z-index: 1000;
        list-style: none;
        margin: 5px 0 0 0;
    }

    .dropdown-menu li {
        margin: 0;
    }

    .dropdown-menu .nav-link {
        padding: 8px 15px;
        border-radius: 0;
        white-space: nowrap;
    }

    .dropdown-menu .nav-link:hover {
        background-color: #007bff;
        color: white;
    }

    .dropdown:hover .dropdown-menu {
        display: block;
    }

    /* Job-specific styles */
    .job-details {
        margin: 10px 0;
    }

    .job-detail-item {
        margin-bottom: 5px;
    }

    .job-detail-label {
        font-weight: bold;
        color: #666;
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: bold;
    }

    .status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-reviewed {
        background: #cce7ff;
        color: #004085;
    }

    .status-accepted {
        background: #d4edda;
        color: #155724;
    }

    .status-rejected {
        background: #f8d7da;
        color: #721c24;
    }

    .filters {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .filter-group {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }

    .filter-select {
        min-width: 200px;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 5px;
    }

    /* CVision Modal Styles */
    .form-card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 25px;
        margin-bottom: 20px;
    }

    .result-card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 20px;
        margin-top: 20px;
    }

    .loading {
        text-align: center;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        margin: 20px 0;
    }

    .success-text {
        background: #d4edda;
        color: #155724;
        padding: 10px;
        border-radius: 4px;
        text-align: center;
        margin: 10px 0;
    }

    .feedback-strength {
        border-left: 4px solid #28a745;
        background-color: #f8fff9;
        padding: 12px;
        margin: 8px 0;
        border-radius: 4px;
    }

    .feedback-weakness {
        border-left: 4px solid #dc3545;
        background-color: #fff8f8;
        padding: 12px;
        margin: 8px 0;
        border-radius: 4px;
    }

    .feedback-improvement {
        border-left: 4px solid #ffc107;
        background-color: #fffef0;
        padding: 12px;
        margin: 8px 0;
        border-radius: 4px;
    }

    .upload-area {
        border: 2px dashed #ddd;
        border-radius: 8px;
        padding: 30px;
        text-align: center;
        margin-bottom: 20px;
        transition: border-color 0.3s;
    }

    .upload-area:hover {
        border-color: #007bff;
    }

    .upload-area.dragover {
        border-color: #007bff;
        background-color: #f8fbff;
    }

    .analysis-results {
        margin-top: 25px;
    }

    .feedback-item {
        padding: 12px;
        margin: 8px 0;
        border-radius: 6px;
        border-left: 4px solid #007bff;
    }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js"></script>
</head>

<body>
    <?php if ($_SESSION['user_type'] !== 'job-seeker'): ?>
    <div class="access-denied">
        <h1>Access Denied</h1>
        <p>This hub is only available for job seekers.</p>
        <a href="index.html" class="btn btn-primary">Return to Home</a>
    </div>
    <?php else: ?>
    <div class="container">
        <!-- Navigation -->
        <nav class="navbar">
            <div class="nav-brand">
                <h1>🎓 Skills & Career Hub</h1>
            </div>
            <ul class="nav-menu">
                <li><a href="#" data-section="dashboard" class="nav-link active">Dashboard</a></li>
                
                <!-- Learning Resources Dropdown -->
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">Learning Resources</a>
                    <ul class="dropdown-menu">
                        <li><a href="#" data-section="bootcamps" class="nav-link">Bootcamps</a></li>
                        <li><a href="#" data-section="skills" class="nav-link">Skills</a></li>
                        <li><a href="#" data-section="certifications" class="nav-link">Certifications</a></li>
                    </ul>
                </li>
                
                <li><a href="#" data-section="jobs" class="nav-link">Job Opportunities</a></li>
                <li><a href="#" data-section="applications" class="nav-link">My Applications</a></li>
                <li><a href="#" data-section="mentorship" class="nav-link">Find Mentors</a></li>
                <li><a href="#" class="nav-link" id="open-cvision-modal">Analyze CV</a></li>
                <li><a href="#" data-section="profile" class="nav-link">Profile</a></li>
            </ul>
        </nav>
        <!-- User Info Bar -->
        <div class="user-info">
            <div class="user-details">
                <strong>Welcome, <?php echo htmlspecialchars($user['full_name']); ?></strong>
            </div>
            <a href="?logout=true" class="logout-btn">Logout</a>
        </div>

        <!-- Success Message -->
        <?php if (isset($_SESSION['message'])): ?>
        <div class="message">
            <?php
                    echo $_SESSION['message'];
                    unset($_SESSION['message']);
                    ?>
        </div>
        <?php endif; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Dashboard Section -->
            <section id="dashboard" class="section active">
                <div class="section-header">
                    <div>
                        <h2>Welcome to Your Career Journey</h2>
                        <p>Track your progress, develop skills, and find job opportunities</p>
                    </div>
                </div>

                <div class="dashboard-grid">
                    <!-- Stats Cards -->
                    <div class="stat-card">
                        <div class="stat-icon">📚</div>
                        <div class="stat-content">
                            <h3>Active Bootcamps</h3>
                            <p class="stat-number"><?php echo $active_bootcamps; ?></p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-content">
                            <h3>Mentors Connected</h3>
                            <p class="stat-number"><?php echo $mentors_count; ?></p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">⭐</div>
                        <div class="stat-content">
                            <h3>Skills Mastered</h3>
                            <p class="stat-number"><?php echo $skills_count; ?></p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">💼</div>
                        <div class="stat-content">
                            <h3>Jobs Applied</h3>
                            <p class="stat-number"><?php echo $jobs_applied_count; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="activity-section">
                    <h3>Recent Activity</h3>
                    <div id="recent-activity" class="activity-list">
                        <?php if (empty($recent_activities)): ?>
                        <p class="empty-state">No recent activity yet. Start learning and applying!</p>
                        <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="card">
                            <p><?php echo htmlspecialchars($activity['title']); ?></p>
                            <small><?php echo date('M j, Y', strtotime($activity['date'])); ?></small>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Bootcamps Section -->
            <section id="bootcamps" class="section">
                <div class="section-header">
                    <div>
                        <h2>Personalized Bootcamps</h2>
                        <p>Intensive learning programs tailored to your goals</p>
                    </div>
                    <button class="btn btn-primary" id="bootcamp-btn">+ Create Bootcamp</button>
                </div>

                <div class="bootcamps-container" id="bootcamps-list">
                    <?php if (empty($bootcamps)): ?>
                    <p class="empty-state">No bootcamps yet. Create one to get started!</p>
                    <?php else: ?>
                    <?php foreach ($bootcamps as $bootcamp): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3><?php echo htmlspecialchars($bootcamp['name']); ?></h3>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="delete_bootcamp">
                                <input type="hidden" name="bootcamp_id" value="<?php echo $bootcamp['id']; ?>">
                                <button type="submit" class="delete-btn"
                                    onclick="return confirm('Are you sure you want to delete this bootcamp?')">Delete</button>
                            </form>
                        </div>
                        <p><?php echo htmlspecialchars($bootcamp['description']); ?></p>
                        <p><strong>Duration:</strong> <?php echo $bootcamp['duration']; ?> weeks</p>
                        <p><strong>Level:</strong> <?php echo ucfirst($bootcamp['level']); ?></p>
                        <p><strong>Status:</strong> <?php echo ucfirst($bootcamp['status']); ?></p>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Create Bootcamp Modal -->
                <div id="bootcamp-modal" class="modal">
                    <div class="modal-content">
                        <span class="close">&times;</span>
                        <h3>Create New Bootcamp</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="create_bootcamp">
                            <div class="form-group">
                                <label for="bootcamp-name">Bootcamp Name</label>
                                <input type="text" id="bootcamp-name" name="bootcamp_name" required>
                            </div>
                            <div class="form-group">
                                <label for="bootcamp-description">Description</label>
                                <textarea id="bootcamp-description" name="bootcamp_description" rows="4" required></textarea>
                            </div>
                            <div class="form-group">
                                <label for="bootcamp-duration">Duration (weeks)</label>
                                <input type="number" id="bootcamp-duration" name="bootcamp_duration" min="1" required>
                            </div>
                            <div class="form-group">
                                <label for="bootcamp-level">Level</label>
                                <select id="bootcamp-level" name="bootcamp_level" required>
                                    <option value="beginner">Beginner</option>
                                    <option value="intermediate">Intermediate</option>
                                    <option value="advanced">Advanced</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Create Bootcamp</button>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Skills Section -->
            <section id="skills" class="section">
                <div class="section-header">
                    <div>
                        <h2>Skill Tracking</h2>
                        <p>Monitor your skill development and progress</p>
                    </div>
                    <button class="btn btn-primary" id="skill-btn">+ Add Skill</button>
                </div>

                <div class="skills-container">
                    <div class="skills-list" id="skills-list">
                        <?php if (empty($skills)): ?>
                        <p class="empty-state">No skills tracked yet. Add your first skill!</p>
                        <?php else: ?>
                        <?php foreach ($skills as $skill): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3><?php echo htmlspecialchars($skill['name']); ?></h3>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_skill">
                                    <input type="hidden" name="skill_id" value="<?php echo $skill['id']; ?>">
                                    <button type="submit" class="delete-btn"
                                        onclick="return confirm('Are you sure you want to delete this skill?')">Delete</button>
                                </form>
                            </div>
                            <p><strong>Category:</strong>
                                <?php echo ucfirst(str_replace('-', ' ', $skill['category'])); ?></p>
                            <p><strong>Level:</strong> <?php echo ucfirst($skill['level']); ?></p>
                            <?php if (!empty($skill['description'])): ?>
                            <p><?php echo htmlspecialchars($skill['description']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Add Skill Modal -->
                <div id="skill-modal" class="modal">
                    <div class="modal-content">
                        <span class="close">&times;</span>
                        <h3>Add New Skill</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="create_skill">
                            <div class="form-group">
                                <label for="skill-name">Skill Name</label>
                                <input type="text" id="skill-name" name="skill_name" required>
                            </div>
                            <div class="form-group">
                                <label for="skill-category">Category</label>
                                <select id="skill-category" name="skill_category" required>
                                    <option value="technical">Technical</option>
                                    <option value="soft-skills">Soft Skills</option>
                                    <option value="business">Business</option>
                                    <option value="creative">Creative</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="skill-level">Current Level</label>
                                <select id="skill-level" name="skill_level" required>
                                    <option value="beginner">Beginner</option>
                                    <option value="intermediate">Intermediate</option>
                                    <option value="advanced">Advanced</option>
                                    <option value="expert">Expert</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="description">Description</label>
                                <input type="text" id="description" name="description">
                            </div>
                            <button type="submit" class="btn btn-primary">Add Skill</button>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Certifications Section -->
            <section id="certifications" class="section">
                <div class="section-header">
                    <div>
                        <h2>Certifications</h2>
                        <p>Showcase your verified credentials</p>
                    </div>
                    <button class="btn btn-primary" id="cert-btn">+ Add Certification</button>
                </div>

                <div class="certifications-container" id="certifications-list">
                    <?php if (empty($certifications)): ?>
                    <p class="empty-state">No certifications yet. Earn your first one!</p>
                    <?php else: ?>
                    <?php foreach ($certifications as $cert): ?>
                    <div class="card">
                        <div class="card-header">
                            <h3><?php echo htmlspecialchars($cert['name']); ?></h3>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="delete_certification">
                                <input type="hidden" name="certification_id" value="<?php echo $cert['id']; ?>">
                                <button type="submit" class="delete-btn"
                                    onclick="return confirm('Are you sure you want to delete this certification?')">Delete</button>
                            </form>
                        </div>
                        <p><strong>Issuer:</strong> <?php echo htmlspecialchars($cert['issuer']); ?></p>
                        <p><strong>Date Earned:</strong> <?php echo date('M j, Y', strtotime($cert['date_earned'])); ?></p>
                        <?php if (!empty($cert['credential_id'])): ?>
                        <p><strong>Credential ID:</strong> <?php echo htmlspecialchars($cert['credential_id']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($cert['credential_url'])): ?>
                        <p><strong>Credential URL:</strong> <a href="<?php echo htmlspecialchars($cert['credential_url']); ?>" target="_blank">View</a></p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Add Certification Modal -->
                <div id="cert-modal" class="modal">
                    <div class="modal-content">
                        <span class="close">&times;</span>
                        <h3>Add Certification</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="create_certification">
                            <div class="form-group">
                                <label for="cert-name">Certification Name</label>
                                <input type="text" id="cert-name" name="cert_name" required>
                            </div>
                            <div class="form-group">
                                <label for="cert-issuer">Issuing Organization</label>
                                <input type="text" id="cert-issuer" name="cert_issuer" required>
                            </div>
                            <div class="form-group">
                                <label for="cert-date">Date Earned</label>
                                <input type="date" id="cert-date" name="cert_date" required>
                            </div>
                            <div class="form-group">
                                <label for="cert-credential-id">Credential ID</label>
                                <input type="text" id="cert-credential-id" name="cert_credential_id">
                            </div>
                            <div class="form-group">
                                <label for="cert-url">Credential URL</label>
                                <input type="url" id="cert-url" name="cert_url">
                            </div>
                            <button type="submit" class="btn btn-primary">Add Certification</button>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Jobs Section -->
            <section id="jobs" class="section">
                <div class="section-header">
                    <h2>Available Job Opportunities</h2>
                    <p>Find and apply for jobs that match your skills</p>
                </div>

                <div class="filters">
                    <div class="filter-group">
                        <select class="filter-select" id="category-filter">
                            <option value="">All Categories</option>
                            <option value="Technology">Technology</option>
                            <option value="Healthcare">Healthcare</option>
                            <option value="Finance">Finance</option>
                            <option value="Education">Education</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Sales">Sales</option>
                            <option value="Design">Design</option>
                            <option value="Engineering">Engineering</option>
                            <option value="Business">Business</option>
                        </select>
                        <select class="filter-select" id="employment-type-filter">
                            <option value="">All Employment Types</option>
                            <option value="Full-time">Full-time</option>
                            <option value="Part-time">Part-time</option>
                            <option value="Contract">Contract</option>
                            <option value="Freelance">Freelance</option>
                            <option value="Internship">Internship</option>
                        </select>
                        <input type="text" id="location-filter" class="filter-select" placeholder="Filter by location...">
                    </div>
                </div>

                <div class="jobs-container">
                    <?php if (empty($available_jobs)): ?>
                    <p class="empty-state">No job opportunities available at the moment. Check back later!</p>
                    <?php else: ?>
                    <?php foreach ($available_jobs as $job): ?>
                    <div class="card job-card" 
                         data-category="<?php echo htmlspecialchars($job['category']); ?>"
                         data-employment-type="<?php echo htmlspecialchars($job['employment_type']); ?>"
                         data-location="<?php echo htmlspecialchars($job['location']); ?>">
                        <div class="card-header">
                            <div style="flex: 1;">
                                <h3><?php echo htmlspecialchars($job['title']); ?></h3>
                                <p class="card-subtitle"><?php echo htmlspecialchars($job['company_name']); ?> • <?php echo htmlspecialchars($job['location']); ?></p>
                                <p class="card-subtitle">Posted by: <?php echo htmlspecialchars($job['employer_name']); ?></p>
                            </div>
                            <div>
                                <small>Posted: <?php echo date('M j, Y', strtotime($job['created_at'])); ?></small>
                            </div>
                        </div>
                        
                        <div class="job-details">
                            <div class="job-detail-item">
                                <span class="job-detail-label">Employment Type:</span> <?php echo htmlspecialchars($job['employment_type']); ?>
                            </div>
                            <div class="job-detail-item">
                                <span class="job-detail-label">Salary Range:</span> <?php echo htmlspecialchars($job['salary_range']); ?>
                            </div>
                            <div class="job-detail-item">
                                <span class="job-detail-label">Experience Level:</span> <?php echo htmlspecialchars($job['experience_level']); ?>
                            </div>
                            <div class="job-detail-item">
                                <span class="job-detail-label">Category:</span> <?php echo htmlspecialchars($job['category']); ?>
                            </div>
                            <?php if ($job['application_deadline']): ?>
                            <div class="job-detail-item">
                                <span class="job-detail-label">Application Deadline:</span> <?php echo date('M j, Y', strtotime($job['application_deadline'])); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars(substr($job['description'], 0, 200))); ?>...</p>
                        
                        <div style="margin-top: 15px;">
                            <?php if ($job['has_applied']): ?>
                                <button class="applied-btn" disabled>Already Applied</button>
                            <?php else: ?>
                                <button class="apply-btn" 
                                        data-job-id="<?php echo $job['id']; ?>"
                                        data-job-title="<?php echo htmlspecialchars($job['title']); ?>"
                                        data-company-name="<?php echo htmlspecialchars($job['company_name']); ?>">
                                    Apply Now
                                </button>
                            <?php endif; ?>
                            <button class="btn btn-primary btn-sm view-job-btn" 
                                    data-job='<?php echo htmlspecialchars(json_encode($job), ENT_QUOTES); ?>'
                                    style="margin-left: 10px;">
                                View Details
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Job Application Modal -->
                <div id="job-application-modal" class="modal">
                    <div class="modal-content">
                        <span class="close">&times;</span>
                        <h3>Apply for Job</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="apply_job">
                            <input type="hidden" id="apply-job-id" name="job_id">
                            
                            <div class="form-group">
                                <label for="job-title">Job Title</label>
                                <input type="text" id="job-title" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="company-name">Company</label>
                                <input type="text" id="company-name" readonly>
                            </div>
                            
                            <div class="form-group">
                                <label for="cover-letter">Cover Letter (Optional)</label>
                                <textarea id="cover-letter" name="cover_letter" rows="6" 
                                          placeholder="Tell the employer why you're a great fit for this position..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Submit Application</button>
                        </form>
                    </div>
                </div>

                <!-- Job Details Modal -->
                <div id="job-details-modal" class="modal">
                    <div class="modal-content" style="width: 90%; max-width: 800px;">
                        <span class="close">&times;</span>
                        <h3 id="job-details-title">Job Details</h3>
                        <div id="job-details-content"></div>
                    </div>
                </div>
            </section>

            <!-- Applications Section -->
            <section id="applications" class="section">
                <div class="section-header">
                    <h2>My Job Applications</h2>
                    <p>Track the status of your job applications</p>
                </div>

                <?php if (empty($job_applications)): ?>
                    <div class="empty-state">
                        <p>You haven't applied for any jobs yet. <a href="#" data-section="jobs" class="nav-link">Browse available jobs</a> to get started!</p>
                    </div>
                <?php else: ?>
                    <div class="applications-list">
                        <?php foreach ($job_applications as $application): ?>
                            <div class="card application-card">
                                <div class="card-header">
                                    <div style="flex: 1;">
                                        <h3><?php echo htmlspecialchars($application['job_title']); ?></h3>
                                        <p class="card-subtitle"><?php echo htmlspecialchars($application['company_name']); ?> • <?php echo htmlspecialchars($application['location']); ?></p>
                                    </div>
                                    <div>
                                        <span class="status-badge status-<?php echo $application['status']; ?>">
                                            <?php echo ucfirst($application['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <p><strong>Applied on:</strong> <?php echo date('M j, Y', strtotime($application['applied_at'])); ?></p>
                                
                                <?php if ($application['cover_letter']): ?>
                                    <p><strong>Your Cover Letter:</strong> <?php echo nl2br(htmlspecialchars(substr($application['cover_letter'], 0, 150))); ?>...</p>
                                <?php endif; ?>
                                
                                <?php if ($application['employer_notes']): ?>
                                    <p><strong>Employer Notes:</strong> <?php echo htmlspecialchars($application['employer_notes']); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Mentorship Section -->
            <section id="mentorship" class="section">
                <div class="section-header">
                    <h2>Find Your Mentor</h2>
                    <p>Connect with experienced professionals in your field</p>
                </div>

                <div class="mentorship-container">
                    <!-- Mentors Grid -->
                    <div class="mentors-grid" id="mentors-list">
                        <?php if (empty($mentors)): ?>
                        <p class="empty-state">No mentors available at the moment.</p>
                        <?php else: ?>
                        <?php foreach ($mentors as $mentor): ?>
                        <div class="card mentor-card">
                            <h3><?php echo htmlspecialchars($mentor['full_name']); ?></h3>
                            <p><strong>Expertise:</strong> <?php echo htmlspecialchars($mentor['expertise']); ?></p>
                            <p><strong>Experience:</strong> <?php echo $mentor['experience_years'] ?? 'Not specified'; ?> years</p>
                            <p><strong>Rating:</strong> <?php echo $mentor['rating'] ?? 'Not rated'; ?>/5</p>
                            <p><?php echo htmlspecialchars($mentor['bio'] ?? 'No bio available'); ?></p>
                            <button class="request-btn" data-mentor-id="<?php echo $mentor['id']; ?>"
                                data-mentor-name="<?php echo htmlspecialchars($mentor['full_name']); ?>">Request
                                Mentorship</button>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Mentor Request Modal -->
                <div id="mentor-modal" class="modal">
                    <div class="modal-content">
                        <span class="close">&times;</span>
                        <h3>Request Mentorship</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="request_mentorship">
                            <input type="hidden" id="mentor-id" name="mentor_id" value="">
                            <div class="form-group">
                                <label for="mentor-name">Mentor</label>
                                <input type="text" id="mentor-name" readonly>
                            </div>
                            <div class="form-group">
                                <label for="mentorship-goals">Your Goals</label>
                                <textarea id="mentorship-goals" name="mentorship_goals" rows="4"
                                    placeholder="What do you want to achieve?" required></textarea>
                            </div>
                            <div class="form-group">
                                <label for="mentorship-frequency">Preferred Frequency</label>
                                <select id="mentorship-frequency" name="mentorship_frequency" required>
                                    <option value="weekly">Weekly</option>
                                    <option value="biweekly">Bi-weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Send Request</button>
                        </form>
                    </div>
                </div>
            </section>

            <!-- Profile Section -->
            <section id="profile" class="section">
                <div class="section-header">
                    <h2>Your Profile</h2>
                    <p>Manage your career profile and preferences</p>
                </div>

                <div class="profile-container">
                    <form method="post">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-group">
                            <label for="profile-name">Full Name</label>
                            <input type="text" id="profile-name" name="profile_name"
                                value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="profile-email">Email</label>
                            <input type="email" id="profile-email" name="profile_email"
                                value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="profile-bio">Professional Bio</label>
                            <textarea id="profile-bio" name="profile_bio" rows="4"
                                placeholder="Tell employers about yourself, your experience, and career goals..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="profile-expertise">Areas of Expertise</label>
                            <input type="text" id="profile-expertise" name="profile_expertise"
                                value="<?php echo htmlspecialchars($user['expertise'] ?? ''); ?>"
                                placeholder="e.g., Web Development, Data Science, Digital Marketing">
                        </div>
                        <div class="form-group">
                            <label for="profile-goals">Career Goals</label>
                            <textarea id="profile-goals" name="profile_goals" rows="4"
                                placeholder="What are your career objectives and what type of roles are you seeking?"><?php echo htmlspecialchars($user['goals'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Profile</button>
                    </form>
                </div>
            </section>
        </main>
    </div>

    <!-- CVision Modal -->
    <div id="cvision-modal" class="modal">
        <div class="modal-content" style="width: 95%; max-width: 1000px; max-height: 90vh; overflow-y: auto;">
            <span class="close">&times;</span>
            
            <div class="form-card">
                <div class="card-header">
                    <h2 style="margin: 0;">Resume Analysis</h2>
                </div>

                <div class="form-group">
                    <label for="cvision-document-upload">Upload Your Resume (PDF, max 5MB):</label>
                    <input type="file" id="cvision-document-upload" accept=".pdf" />
                </div>

                <div class="form-group">
                    <label for="cvision-job-description">Job Description:</label>
                    <textarea
                        id="cvision-job-description"
                        placeholder="Paste the job description here..."
                        rows="6"></textarea>
                </div>

                <button type="button" class="btn btn-primary" id="cvision-analyze-btn">
                    Analyze Resume
                </button>
            </div>

            <div id="cvision-loading" class="loading" style="display: none;">
                <p>Processing your resume and analyzing against job description...</p>
            </div>

            <!-- AI Feedback Results -->
            <div id="cvision-ai-feedback" class="result-card" style="display: none;">
                <div class="card-header">
                    <h3>AI Analysis & Recommendations</h3>
                </div>
                <div id="cvision-feedback-content"></div>
            </div>

            <!-- Fallback Keyword Match -->
            <div id="cvision-match-result" class="result-card" style="display: none;">
                <div class="card-header">
                    <h3>Basic Keyword Analysis</h3>
                </div>
                <div class="match-result">
                    <p><strong>AI unavailable. Showing basic keyword match analysis.</strong></p>
                    <p><strong>Match Percentage:</strong> <span id="cvision-match-percentage"></span>%</p>
                    <p><strong>Missing Keywords:</strong> <span id="cvision-missing-keywords"></span></p>
                </div>
            </div>

            <div id="cvision-success-text" class="success-text" style="display: none;">
                ✅ Resume text successfully extracted and ready for analysis
            </div>
        </div>
    </div>

    <script>
      // Simple JavaScript for UI interactions
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded');

    // Navigation
    const navLinks = document.querySelectorAll('.nav-link');
    const sections = document.querySelectorAll('.section');

    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetSection = this.getAttribute('data-section');

            // Update active nav link
            navLinks.forEach(l => l.classList.remove('active'));
            this.classList.add('active');

            // Show target section
            sections.forEach(s => s.classList.remove('active'));
            document.getElementById(targetSection).classList.add('active');
        });
    });

    // Modal handling
    const modals = document.querySelectorAll('.modal');
    const closeButtons = document.querySelectorAll('.close');

    // Bootcamp modal
    const bootcampBtn = document.getElementById('bootcamp-btn');
    if (bootcampBtn) {
        bootcampBtn.addEventListener('click', function() {
            document.getElementById('bootcamp-modal').style.display = 'block';
        });
    }

    // Skill modal
    const skillBtn = document.getElementById('skill-btn');
    if (skillBtn) {
        skillBtn.addEventListener('click', function() {
            document.getElementById('skill-modal').style.display = 'block';
        });
    }

    // Certification modal
    const certBtn = document.getElementById('cert-btn');
    if (certBtn) {
        certBtn.addEventListener('click', function() {
            document.getElementById('cert-modal').style.display = 'block';
        });
    }

    // Job application modal
    const applyButtons = document.querySelectorAll('.apply-btn');
    applyButtons.forEach(button => {
        button.addEventListener('click', function() {
            const jobId = this.getAttribute('data-job-id');
            const jobTitle = this.getAttribute('data-job-title');
            const companyName = this.getAttribute('data-company-name');

            document.getElementById('apply-job-id').value = jobId;
            document.getElementById('job-title').value = jobTitle;
            document.getElementById('company-name').value = companyName;
            document.getElementById('job-application-modal').style.display = 'block';
        });
    });

    // Job details modal
    const viewJobButtons = document.querySelectorAll('.view-job-btn');
    viewJobButtons.forEach(button => {
        button.addEventListener('click', function() {
            const job = JSON.parse(this.getAttribute('data-job'));
            
            document.getElementById('job-details-title').textContent = job.title;
            
            const content = `
                <div class="card">
                    <div class="card-header">
                        <h3>${job.title}</h3>
                        <p>${job.company_name} • ${job.location}</p>
                        <p>Posted by: ${job.employer_name}</p>
                    </div>
                    
                    <div class="job-details">
                        <div class="job-detail-item">
                            <span class="job-detail-label">Company:</span> ${job.company_name}
                        </div>
                        <div class="job-detail-item">
                            <span class="job-detail-label">Location:</span> ${job.location}
                        </div>
                        <div class="job-detail-item">
                            <span class="job-detail-label">Employment Type:</span> ${job.employment_type}
                        </div>
                        <div class="job-detail-item">
                            <span class="job-detail-label">Salary Range:</span> ${job.salary_range}
                        </div>
                        <div class="job-detail-item">
                            <span class="job-detail-label">Experience Level:</span> ${job.experience_level}
                        </div>
                        <div class="job-detail-item">
                            <span class="job-detail-label">Category:</span> ${job.category}
                        </div>
                        <div class="job-detail-item">
                            <span class="job-detail-label">Education Level:</span> ${job.education_level || 'Not specified'}
                        </div>
                        ${job.application_deadline ? `
                        <div class="job-detail-item">
                            <span class="job-detail-label">Application Deadline:</span> ${new Date(job.application_deadline).toLocaleDateString()}
                        </div>
                        ` : ''}
                        <div class="job-detail-item">
                            <span class="job-detail-label">Contact Email:</span> ${job.contact_email}
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <h4>Job Description</h4>
                        <p>${job.description.replace(/\n/g, '<br>')}</p>
                    </div>
                    
                    <div class="form-group">
                        <h4>Key Responsibilities</h4>
                        <p>${job.responsibilities ? job.responsibilities.replace(/\n/g, '<br>') : 'Not specified'}</p>
                    </div>
                    
                    <div class="form-group">
                        <h4>Requirements & Qualifications</h4>
                        <p>${job.requirements ? job.requirements.replace(/\n/g, '<br>') : 'Not specified'}</p>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        ${job.has_applied ? 
                            '<button class="applied-btn" disabled>Already Applied</button>' : 
                            `<button class="apply-btn" 
                                    data-job-id="${job.id}"
                                    data-job-title="${job.title}"
                                    data-company-name="${job.company_name}">
                                Apply Now
                            </button>`
                        }
                    </div>
                </div>
            `;
            
            document.getElementById('job-details-content').innerHTML = content;
            
            // Re-attach event listener for apply button in modal
            const modalApplyBtn = document.querySelector('#job-details-content .apply-btn');
            if (modalApplyBtn) {
                modalApplyBtn.addEventListener('click', function() {
                    const jobId = this.getAttribute('data-job-id');
                    const jobTitle = this.getAttribute('data-job-title');
                    const companyName = this.getAttribute('data-company-name');

                    document.getElementById('apply-job-id').value = jobId;
                    document.getElementById('job-title').value = jobTitle;
                    document.getElementById('company-name').value = companyName;
                    document.getElementById('job-details-modal').style.display = 'none';
                    document.getElementById('job-application-modal').style.display = 'block';
                });
            }
            
            document.getElementById('job-details-modal').style.display = 'block';
        });
    });

    // Mentor request modal
    const requestButtons = document.querySelectorAll('.request-btn');
    requestButtons.forEach(button => {
        button.addEventListener('click', function() {
            const mentorId = this.getAttribute('data-mentor-id');
            const mentorName = this.getAttribute('data-mentor-name');

            document.getElementById('mentor-id').value = mentorId;
            document.getElementById('mentor-name').value = mentorName;
            document.getElementById('mentor-modal').style.display = 'block';
        });
    });

    // CV Analysis Functionality
    const cvisionModal = document.getElementById('cvision-modal');
    const openCvisionBtn = document.getElementById('open-cvision-modal');
    const cvisionCloseBtn = cvisionModal.querySelector('.close');
    const analyzeBtn = document.getElementById('cvision-analyze-btn');
    const fileUpload = document.getElementById('cvision-document-upload');
    const jobDescriptionTextarea = document.getElementById('cvision-job-description');
    const loadingElement = document.getElementById('cvision-loading');
    const aiFeedbackElement = document.getElementById('cvision-ai-feedback');
    const matchResultElement = document.getElementById('cvision-match-result');
    const feedbackContentElement = document.getElementById('cvision-feedback-content');
    const successTextElement = document.getElementById('cvision-success-text');
    
    let extractedText = '';

    // Open CVision Modal
    if (openCvisionBtn) {
        openCvisionBtn.addEventListener('click', function(e) {
            e.preventDefault();
            cvisionModal.style.display = 'block';
        });
    }

    // Close CVision Modal
    if (cvisionCloseBtn) {
        cvisionCloseBtn.addEventListener('click', function() {
            cvisionModal.style.display = 'none';
        });
    }

    // Handle file upload and text extraction
    if (fileUpload) {
        fileUpload.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            // Validate file type and size
            if (file.type !== 'application/pdf') {
                alert('Please upload a PDF file.');
                return;
            }

            if (file.size > 5 * 1024 * 1024) { // 5MB limit
                alert('File size must be less than 5MB.');
                return;
            }

            extractTextFromPDF(file);
        });
    }

    // Extract text from PDF using PDF.js
    function extractTextFromPDF(file) {
        loadingElement.style.display = 'block';
        successTextElement.style.display = 'none';
        aiFeedbackElement.style.display = 'none';
        matchResultElement.style.display = 'none';

        const fileReader = new FileReader();
        
        fileReader.onload = function() {
            const typedarray = new Uint8Array(this.result);
            
            // Load PDF using PDF.js
            pdfjsLib.getDocument(typedarray).promise.then(function(pdf) {
                let text = '';
                const numPages = pdf.numPages;
                const pagesPromises = [];

                // Extract text from each page
                for (let pageNum = 1; pageNum <= numPages; pageNum++) {
                    pagesPromises.push(
                        pdf.getPage(pageNum).then(function(page) {
                            return page.getTextContent().then(function(textContent) {
                                return textContent.items.map(item => item.str).join(' ');
                            });
                        })
                    );
                }

                // Combine all pages text
                Promise.all(pagesPromises).then(function(pagesText) {
                    text = pagesText.join('\n');
                    extractedText = text;
                    
                    loadingElement.style.display = 'none';
                    successTextElement.style.display = 'block';
                    
                    console.log('Text extracted successfully:', text.substring(0, 200) + '...');
                });
            }).catch(function(error) {
                console.error('Error extracting PDF text:', error);
                loadingElement.style.display = 'none';
                alert('Error extracting text from PDF. Please try another file.');
            });
        };
         if (!extractedText) {
            alert('Please upload a resume PDF first.');
            return;
        }

        if (!jobDescription) {
            alert('Please enter a job description.');
            return;
        }
       

        fileReader.readAsArrayBuffer(file);
    }

    // Analyze resume
    if (analyzeBtn) {
        analyzeBtn.addEventListener('click', function() {
            const jobDescription = jobDescriptionTextarea.value.trim();
            
      
            analyzeResume(extractedText, jobDescription);
        });
    }

    // Send analysis request to backend
    function analyzeResume(cvText, jobDescription) {
        loadingElement.style.display = 'block';
        aiFeedbackElement.style.display = 'none';
        matchResultElement.style.display = 'none';

        fetch('cv_analysis.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                cv_text: cvText,
                job_description: jobDescription
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            loadingElement.style.display = 'none';
            
            if (data.error) {
                throw new Error(data.error);
            }

            // Display AI analysis results
            displayAIResults(data);
        })
        .catch(error => {
            console.error('Error analyzing resume:', error);
            loadingElement.style.display = 'none';
            
            // Fallback to basic keyword analysis if AI fails
            performBasicKeywordAnalysis(extractedText, jobDescription);
        });
    }

    // Display AI analysis results
    function displayAIResults(aiData) {
        // Assuming the AI response has a structure with candidates[0].content.parts[0].text
        let analysisText = '';
        
        if (aiData.candidates && aiData.candidates[0] && aiData.candidates[0].content) {
            analysisText = aiData.candidates[0].content.parts[0].text;
        } else if (aiData.text) {
            analysisText = aiData.text;
        } else {
            // If structure is different, try to display the raw response
            analysisText = typeof aiData === 'string' ? aiData : JSON.stringify(aiData, null, 2);
        }

        // Format the text with line breaks and basic styling
        const formattedText = analysisText
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');

        feedbackContentElement.innerHTML = formattedText;
        aiFeedbackElement.style.display = 'block';
        matchResultElement.style.display = 'none';
    }

    // Fallback basic keyword analysis
    function performBasicKeywordAnalysis(cvText, jobDescription) {
        // Extract keywords from job description (simple approach)
        const jobKeywords = extractKeywords(jobDescription);
        const cvKeywords = extractKeywords(cvText);
        
        // Find matching keywords
        const matchingKeywords = jobKeywords.filter(keyword => 
            cvKeywords.includes(keyword)
        );
        
        // Calculate match percentage
        const matchPercentage = jobKeywords.length > 0 
            ? Math.round((matchingKeywords.length / jobKeywords.length) * 100)
            : 0;
        
        // Find missing keywords
        const missingKeywords = jobKeywords.filter(keyword => 
            !cvKeywords.includes(keyword)
        );

        // Display basic analysis results
        document.getElementById('cvision-match-percentage').textContent = matchPercentage;
        document.getElementById('cvision-missing-keywords').textContent = 
            missingKeywords.length > 0 ? missingKeywords.join(', ') : 'None';
        
        matchResultElement.style.display = 'block';
        aiFeedbackElement.style.display = 'none';
    }

    // Simple keyword extraction function
    function extractKeywords(text) {
        return text
            .toLowerCase()
            .replace(/[^\w\s]/g, '')
            .split(/\s+/)
            .filter(word => word.length > 3) // Filter out short words
            .filter((word, index, array) => array.indexOf(word) === index) // Remove duplicates
            .slice(0, 20); // Limit to top 20 keywords
    }

    // Close modals
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            this.closest('.modal').style.display = 'none';
        });
    });

    // Close modal when clicking outside
    window.addEventListener('click', function(e) {
        modals.forEach(modal => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    });

    // Job filters
    const categoryFilter = document.getElementById('category-filter');
    const employmentTypeFilter = document.getElementById('employment-type-filter');
    const locationFilter = document.getElementById('location-filter');

    if (categoryFilter) {
        categoryFilter.addEventListener('change', filterJobs);
    }
    if (employmentTypeFilter) {
        employmentTypeFilter.addEventListener('change', filterJobs);
    }
    if (locationFilter) {
        locationFilter.addEventListener('input', filterJobs);
    }

    function filterJobs() {
        const categoryValue = categoryFilter.value;
        const employmentTypeValue = employmentTypeFilter.value;
        const locationValue = locationFilter.value.toLowerCase();
        const jobCards = document.querySelectorAll('.job-card');
        
        jobCards.forEach(card => {
            const category = card.getAttribute('data-category');
            const employmentType = card.getAttribute('data-employment-type');
            const location = card.getAttribute('data-location').toLowerCase();
            
            const categoryMatch = !categoryValue || category === categoryValue;
            const employmentTypeMatch = !employmentTypeValue || employmentType === employmentTypeValue;
            const locationMatch = !locationValue || location.includes(locationValue);
            
            card.style.display = categoryMatch && employmentTypeMatch && locationMatch ? 'block' : 'none';
        });
    }

    // Close modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            modals.forEach(modal => {
                modal.style.display = 'none';
            });
        }
    });
});
    </script>

    <?php endif; ?>
</body>
</html>


