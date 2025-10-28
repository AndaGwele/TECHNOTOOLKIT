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

    // Get user data
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT * FROM hub_users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
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
            
            $stmt = $conn->prepare("SELECT * FROM hub_users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    $hub_user_id = $user['id'];

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'update_mentorship_status':
                $stmt = $conn->prepare("UPDATE mentorship_requests SET status = ?, mentor_notes = ? WHERE id = ? AND mentor_id = ?");
                $stmt->execute([
                    $_POST['status'],
                    $_POST['mentor_notes'] ?? '',
                    $_POST['request_id'],
                    $hub_user_id
                ]);
                $_SESSION['message'] = 'Mentorship request updated successfully';
                break;

            case 'update_mentor_profile':
                $stmt = $conn->prepare("UPDATE hub_users SET bio = ?, expertise = ?, hourly_rate = ?, availability = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['mentor_bio'] ?? '',
                    $_POST['mentor_expertise'] ?? '',
                    $_POST['hourly_rate'] ?? 0,
                    $_POST['availability'] ?? '',
                    $hub_user_id
                ]);
                $_SESSION['message'] = 'Mentor profile updated successfully';
                break;

            case 'update_mentor_availability':
                $stmt = $conn->prepare("UPDATE mentors SET is_available = ? WHERE user_id = ?");
                $stmt->execute([
                    $_POST['is_available'] ? 1 : 0,
                    $hub_user_id
                ]);
                $_SESSION['message'] = 'Availability updated successfully';
                break;
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Fetch mentorship requests
    $stmt = $conn->prepare("
        SELECT 
            mr.*,
            hu.full_name as mentee_name,
            hu.email as mentee_email,
            hu.bio as mentee_bio,
            hu.expertise as mentee_expertise
        FROM mentorship_requests mr
        JOIN hub_users hu ON mr.mentee_id = hu.id
        WHERE mr.mentor_id = ?
        ORDER BY mr.created_at DESC
    ");
    $stmt->execute([$hub_user_id]);
    $mentorship_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch active mentees
    $stmt = $conn->prepare("
        SELECT 
            mr.*,
            hu.full_name,
            hu.email,
            hu.bio,
            hu.expertise
        FROM mentorship_requests mr
        JOIN hub_users hu ON mr.mentee_id = hu.id
        WHERE mr.mentor_id = ? AND mr.status = 'accepted'
        ORDER BY mr.accepted_at DESC
    ");
    $stmt->execute([$hub_user_id]);
    $active_mentees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch mentor stats
    $stmt = $conn->prepare("SELECT COUNT(*) FROM mentorship_requests WHERE mentor_id = ? AND status = 'accepted'");
    $stmt->execute([$hub_user_id]);
    $active_mentees_count = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM mentorship_requests WHERE mentor_id = ? AND status = 'pending'");
    $stmt->execute([$hub_user_id]);
    $pending_requests_count = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT AVG(rating) FROM mentorship_sessions WHERE mentor_id = ?");
    $stmt->execute([$hub_user_id]);
    $average_rating = $stmt->fetchColumn();
    $average_rating = $average_rating ? round($average_rating, 1) : 0;

    // Fetch mentor profile data
    $stmt = $conn->prepare("SELECT * FROM mentors WHERE user_id = ?");
    $stmt->execute([$hub_user_id]);
    $mentor_profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no mentor profile exists, create one
    if (!$mentor_profile && $user['is_mentor']) {
        $stmt = $conn->prepare("INSERT INTO mentors (user_id, full_name, expertise, is_available) VALUES (?, ?, ?, true)");
        $stmt->execute([
            $hub_user_id,
            $user['full_name'],
            $user['expertise'] ?? 'General'
        ]);
        $mentor_profile = ['is_available' => 1];
    }

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: signupLogin.html");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mentor Dashboard - Skills & Mentorship Hub</title>
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

        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 25px;
            text-align: center;
            border-left: 5px solid #007bff;
        }

        .stat-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
            color: #007bff;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #007bff;
            margin: 10px 0;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
        }

        .section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 25px;
            margin-bottom: 25px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }

        .section-header h2 {
            color: #007bff;
            margin: 0;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0056b3;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #1e7e34;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 14px;
        }

        .card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #007bff;
        }

        .request-card {
            border-left-color: #ffc107;
        }

        .mentee-card {
            border-left-color: #28a745;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .card-title {
            font-size: 18px;
            font-weight: bold;
            color: #007bff;
            margin: 0;
        }

        .card-subtitle {
            color: #666;
            margin: 5px 0;
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

        .status-accepted {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .empty-state {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 40px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .availability-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background-color: #28a745;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        /* Modal Styles */
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
            margin: 5% auto;
            padding: 25px;
            border-radius: 10px;
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

        .nav-link.active {
            font-weight: bold;
            color: #007bff;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Navigation -->
        <nav class="navbar">
            <div class="nav-brand">
                <h1>🎓 Mentor Dashboard - Skills & Mentorship Hub</h1>
            </div>
            <ul class="nav-menu">
                <li><a href="skills_hub.php" class="nav-link">← Back to Learning Hub</a></li>
                <li><a href="#" class="nav-link active">Mentor Dashboard</a></li>
            </ul>
        </nav>

        <!-- User Info Bar -->
        <div class="user-info">
            <div class="user-details">
                <strong>Welcome, <?php echo htmlspecialchars($user['full_name']); ?> (Mentor)</strong>
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

        <!-- Dashboard Stats -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-number"><?php echo $active_mentees_count; ?></div>
                <div class="stat-label">Active Mentees</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📋</div>
                <div class="stat-number"><?php echo $pending_requests_count; ?></div>
                <div class="stat-label">Pending Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⭐</div>
                <div class="stat-number"><?php echo $average_rating; ?>/5</div>
                <div class="stat-label">Average Rating</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-number"><?php echo $mentor_profile['is_available'] ? 'Available' : 'Busy'; ?></div>
                <div class="stat-label">Availability</div>
            </div>
        </div>

        <!-- Mentor Profile Section -->
        <div class="section">
            <div class="section-header">
                <h2>Mentor Profile</h2>
                <button class="btn btn-primary" id="edit-profile-btn">Edit Profile</button>
            </div>

            <div class="profile-info">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Profile Information</h3>
                    </div>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($user['full_name']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                    <p><strong>Expertise:</strong> <?php echo htmlspecialchars($user['expertise'] ?? 'Not specified'); ?></p>
                    <p><strong>Bio:</strong> <?php echo htmlspecialchars($user['bio'] ?? 'No bio added'); ?></p>
                    <?php if ($mentor_profile && isset($mentor_profile['hourly_rate'])): ?>
                        <p><strong>Hourly Rate:</strong> $<?php echo htmlspecialchars($mentor_profile['hourly_rate']); ?></p>
                    <?php endif; ?>
                    <?php if ($mentor_profile && isset($mentor_profile['availability'])): ?>
                        <p><strong>Availability:</strong> <?php echo htmlspecialchars($mentor_profile['availability']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Availability Toggle -->
            <form method="post" class="availability-section" style="margin-top: 20px;">
                <input type="hidden" name="action" value="update_mentor_availability">
                <div class="form-group availability-toggle">
                    <label for="is_available">Available for Mentorship:</label>
                    <label class="toggle-switch">
                        <input type="checkbox" name="is_available" id="is_available" 
                               <?php echo ($mentor_profile && $mentor_profile['is_available']) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <span id="availability-status">
                        <?php echo ($mentor_profile && $mentor_profile['is_available']) ? 'Available' : 'Not Available'; ?>
                    </span>
                </div>
                <button type="submit" class="btn btn-primary">Update Availability</button>
            </form>
        </div>

        <!-- Mentorship Requests Section -->
        <div class="section">
            <div class="section-header">
                <h2>Mentorship Requests</h2>
            </div>

            <?php if (empty($mentorship_requests)): ?>
                <div class="empty-state">
                    <p>No mentorship requests yet.</p>
                </div>
            <?php else: ?>
                <div class="requests-list">
                    <?php foreach ($mentorship_requests as $request): ?>
                        <div class="card request-card">
                            <div class="card-header">
                                <div>
                                    <h3 class="card-title"><?php echo htmlspecialchars($request['mentee_name']); ?></h3>
                                    <p class="card-subtitle"><?php echo htmlspecialchars($request['mentee_email']); ?></p>
                                    <span class="status-badge status-<?php echo $request['status']; ?>">
                                        <?php echo ucfirst($request['status']); ?>
                                    </span>
                                </div>
                                <div>
                                    <small>Requested: <?php echo date('M j, Y', strtotime($request['created_at'])); ?></small>
                                </div>
                            </div>
                            
                            <p><strong>Goals:</strong> <?php echo htmlspecialchars($request['goals']); ?></p>
                            <p><strong>Preferred Frequency:</strong> <?php echo ucfirst($request['frequency']); ?></p>
                            
                            <?php if ($request['mentee_bio']): ?>
                                <p><strong>Mentee Bio:</strong> <?php echo htmlspecialchars($request['mentee_bio']); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($request['mentee_expertise']): ?>
                                <p><strong>Mentee Expertise:</strong> <?php echo htmlspecialchars($request['mentee_expertise']); ?></p>
                            <?php endif; ?>

                            <?php if ($request['status'] === 'pending'): ?>
                                <div style="margin-top: 15px; display: flex; gap: 10px;">
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="action" value="update_mentorship_status">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <input type="hidden" name="status" value="accepted">
                                        <button type="submit" class="btn btn-success btn-sm">Accept Request</button>
                                    </form>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="action" value="update_mentorship_status">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <input type="hidden" name="status" value="rejected">
                                        <button type="submit" class="btn btn-danger btn-sm" 
                                                onclick="return confirm('Are you sure you want to reject this request?')">
                                            Reject Request
                                        </button>
                                    </form>
                                </div>
                            <?php elseif ($request['status'] === 'accepted'): ?>
                                <div style="margin-top: 15px;">
                                    <p><strong>Accepted on:</strong> <?php echo date('M j, Y', strtotime($request['accepted_at'])); ?></p>
                                    <?php if ($request['mentor_notes']): ?>
                                        <p><strong>Your Notes:</strong> <?php echo htmlspecialchars($request['mentor_notes']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Active Mentees Section -->
        <div class="section">
            <div class="section-header">
                <h2>Active Mentees</h2>
            </div>

            <?php if (empty($active_mentees)): ?>
                <div class="empty-state">
                    <p>No active mentees yet.</p>
                </div>
            <?php else: ?>
                <div class="mentees-list">
                    <?php foreach ($active_mentees as $mentee): ?>
                        <div class="card mentee-card">
                            <div class="card-header">
                                <div>
                                    <h3 class="card-title"><?php echo htmlspecialchars($mentee['full_name']); ?></h3>
                                    <p class="card-subtitle"><?php echo htmlspecialchars($mentee['email']); ?></p>
                                </div>
                                <div>
                                    <small>Started: <?php echo date('M j, Y', strtotime($mentee['accepted_at'])); ?></small>
                                </div>
                            </div>
                            
                            <p><strong>Goals:</strong> <?php echo htmlspecialchars($mentee['goals']); ?></p>
                            <p><strong>Frequency:</strong> <?php echo ucfirst($mentee['frequency']); ?></p>
                            
                            <?php if ($mentee['bio']): ?>
                                <p><strong>Bio:</strong> <?php echo htmlspecialchars($mentee['bio']); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($mentee['expertise']): ?>
                                <p><strong>Expertise:</strong> <?php echo htmlspecialchars($mentee['expertise']); ?></p>
                            <?php endif; ?>

                            <div style="margin-top: 15px;">
                                <button class="btn btn-primary btn-sm send-message-btn" 
                                        data-mentee-id="<?php echo $mentee['mentee_id']; ?>"
                                        data-mentee-name="<?php echo htmlspecialchars($mentee['full_name']); ?>">
                                    Send Message
                                </button>
                                <button class="btn btn-warning btn-sm schedule-session-btn"
                                        data-mentee-id="<?php echo $mentee['mentee_id']; ?>"
                                        data-mentee-name="<?php echo htmlspecialchars($mentee['full_name']); ?>">
                                    Schedule Session
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div id="edit-profile-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3>Edit Mentor Profile</h3>
            <form method="post">
                <input type="hidden" name="action" value="update_mentor_profile">
                
                <div class="form-group">
                    <label for="mentor_bio">Bio</label>
                    <textarea id="mentor_bio" name="mentor_bio" rows="4" placeholder="Tell mentees about your background and experience..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="mentor_expertise">Areas of Expertise</label>
                    <input type="text" id="mentor_expertise" name="mentor_expertise" 
                           value="<?php echo htmlspecialchars($user['expertise'] ?? ''); ?>" 
                           placeholder="e.g., Web Development, Data Science, Career Coaching">
                </div>
                
                <div class="form-group">
                    <label for="hourly_rate">Hourly Rate ($)</label>
                    <input type="number" id="hourly_rate" name="hourly_rate" min="0" step="0.01"
                           value="<?php echo htmlspecialchars($mentor_profile['hourly_rate'] ?? '0'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="availability">Availability Schedule</label>
                    <textarea id="availability" name="availability" rows="3" placeholder="e.g., Weekdays 6-9 PM EST, Weekends 10 AM - 2 PM EST"><?php echo htmlspecialchars($mentor_profile['availability'] ?? ''); ?></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Profile</button>
            </form>
        </div>
    </div>

    <!-- Send Message Modal -->
    <div id="message-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3>Send Message to Mentee</h3>
            <form id="message-form">
                <input type="hidden" id="message-mentee-id" name="mentee_id">
                
                <div class="form-group">
                    <label for="recipient-name">To</label>
                    <input type="text" id="recipient-name" readonly>
                </div>
                
                <div class="form-group">
                    <label for="message-subject">Subject</label>
                    <input type="text" id="message-subject" name="subject" required>
                </div>
                
                <div class="form-group">
                    <label for="message-content">Message</label>
                    <textarea id="message-content" name="content" rows="6" required></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">Send Message</button>
            </form>
        </div>
    </div>

    <!-- Schedule Session Modal -->
    <div id="session-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3>Schedule Mentoring Session</h3>
            <form id="session-form">
                <input type="hidden" id="session-mentee-id" name="mentee_id">
                
                <div class="form-group">
                    <label for="session-mentee-name">With Mentee</label>
                    <input type="text" id="session-mentee-name" readonly>
                </div>
                
                <div class="form-group">
                    <label for="session-date">Session Date</label>
                    <input type="date" id="session-date" name="session_date" required>
                </div>
                
                <div class="form-group">
                    <label for="session-time">Session Time</label>
                    <input type="time" id="session-time" name="session_time" required>
                </div>
                
                <div class="form-group">
                    <label for="session-duration">Duration (minutes)</label>
                    <select id="session-duration" name="session_duration" required>
                        <option value="30">30 minutes</option>
                        <option value="60">60 minutes</option>
                        <option value="90">90 minutes</option>
                        <option value="120">120 minutes</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="session-topic">Session Topic</label>
                    <input type="text" id="session-topic" name="session_topic" required>
                </div>
                
                <div class="form-group">
                    <label for="session-notes">Notes</label>
                    <textarea id="session-notes" name="session_notes" rows="3"></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">Schedule Session</button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Modal elements
            const editProfileModal = document.getElementById('edit-profile-modal');
            const messageModal = document.getElementById('message-modal');
            const sessionModal = document.getElementById('session-modal');
            const editProfileBtn = document.getElementById('edit-profile-btn');
            const closeButtons = document.querySelectorAll('.close');
            const modals = document.querySelectorAll('.modal');

            // Availability toggle
            const availabilityToggle = document.getElementById('is_available');
            const availabilityStatus = document.getElementById('availability-status');

            if (availabilityToggle) {
                availabilityToggle.addEventListener('change', function() {
                    availabilityStatus.textContent = this.checked ? 'Available' : 'Not Available';
                });
            }

            // Edit Profile Modal
            if (editProfileBtn) {
                editProfileBtn.addEventListener('click', function() {
                    editProfileModal.style.display = 'block';
                });
            }

            // Message buttons
            document.querySelectorAll('.send-message-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const menteeId = this.getAttribute('data-mentee-id');
                    const menteeName = this.getAttribute('data-mentee-name');
                    
                    document.getElementById('message-mentee-id').value = menteeId;
                    document.getElementById('recipient-name').value = menteeName;
                    messageModal.style.display = 'block';
                });
            });

            // Session buttons
            document.querySelectorAll('.schedule-session-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const menteeId = this.getAttribute('data-mentee-id');
                    const menteeName = this.getAttribute('data-mentee-name');
                    
                    document.getElementById('session-mentee-id').value = menteeId;
                    document.getElementById('session-mentee-name').value = menteeName;
                    
                    // Set default date to tomorrow
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    document.getElementById('session-date').value = tomorrow.toISOString().split('T')[0];
                    
                    sessionModal.style.display = 'block';
                });
            });

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

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    modals.forEach(modal => {
                        modal.style.display = 'none';
                    });
                }
            });

            // Form submissions (for demo purposes)
            document.getElementById('message-form')?.addEventListener('submit', function(e) {
                e.preventDefault();
                alert('Message sent successfully!');
                messageModal.style.display = 'none';
                this.reset();
            });

            document.getElementById('session-form')?.addEventListener('submit', function(e) {
                e.preventDefault();
                alert('Session scheduled successfully!');
                sessionModal.style.display = 'none';
                this.reset();
            });

            console.log('Mentor Dashboard loaded successfully');
        });
    </script>
</body>
</html>