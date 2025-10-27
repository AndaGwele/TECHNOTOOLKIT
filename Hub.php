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
$username = "toolkit_3dlp_user";  // Change to your PostgreSQL username
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
        // This means it's a new user, insert their data into hub_users
        
        // First, get the user's basic info from the users table
        $stmt = $conn->prepare("SELECT full_name, email, user_type FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user_data) {
            // Insert the new user into hub_users table
            // Use proper boolean values (true/false) instead of strings
            $stmt = $conn->prepare("INSERT INTO hub_users (user_id, full_name, email, user_type, is_mentor) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                $user_data['full_name'],
                $user_data['email'],
                $user_data['user_type'],
                1
               
            ]);
            
            // Fetch the newly created user record
            $stmt = $conn->prepare("SELECT * FROM hub_users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $_SESSION['message'] = "Welcome to your learning hub! Get started by adding your skills and goals.";
        }
    }

    // Now $user contains the hub user data (either existing or newly created)
    $hub_user_id = $user['id'];

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create_bootcamp':
                $stmt = $conn->prepare("INSERT INTO bootcamps (user_id, name, description, duration, level, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $stmt->execute([
                    $hub_user_id,
                    $_POST['bootcamp_name'],
                    $_POST['bootcamp_description'],
                    $_POST['bootcamp_duration'],
                    $_POST['bootcamp_level']
                ]);
                $_SESSION['message'] = 'Bootcamp created successfully';
                break;

            case 'delete_bootcamp':
                $stmt = $conn->prepare("DELETE FROM bootcamps WHERE id = ? AND user_id = ?");
                $stmt->execute([$_POST['bootcamp_id'], $hub_user_id]);
                $_SESSION['message'] = 'Bootcamp deleted successfully';
                break;

            case 'create_skill':
                $stmt = $conn->prepare("INSERT INTO skills (user_id, name, category, level, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $hub_user_id,
                    $_POST['skill_name'],
                    $_POST['skill_category'],
                    $_POST['skill_level'],
                    $_POST['description'] ?? ''
                ]);
                $_SESSION['message'] = 'Skill added successfully';
                break;

            case 'delete_skill':
                $stmt = $conn->prepare("DELETE FROM skills WHERE id = ? AND user_id = ?");
                $stmt->execute([$_POST['skill_id'], $hub_user_id]);
                $_SESSION['message'] = 'Skill deleted successfully';
                break;

            case 'create_certification':
                $stmt = $conn->prepare("INSERT INTO certifications (user_id, name, issuer, date_earned, credential_id, credential_url) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $hub_user_id,
                    $_POST['cert_name'],
                    $_POST['cert_issuer'],
                    $_POST['cert_date'],
                    $_POST['cert_credential_id'] ?? '',
                    $_POST['cert_url'] ?? ''
                ]);
                $_SESSION['message'] = 'Certification added successfully';
                break;

            case 'delete_certification':
                $stmt = $conn->prepare("DELETE FROM certifications WHERE id = ? AND user_id = ?");
                $stmt->execute([$_POST['certification_id'], $hub_user_id]);
                $_SESSION['message'] = 'Certification deleted successfully';
                break;

            case 'request_mentorship':
                $stmt = $conn->prepare("INSERT INTO mentorship_requests (mentee_id, mentor_id, goals, frequency, status) VALUES (?, ?, ?, ?, 'pending')");
                $stmt->execute([
                    $hub_user_id,
                    $_POST['mentor_id'],
                    $_POST['mentorship_goals'],
                    $_POST['mentorship_frequency']
                ]);
                $_SESSION['message'] = 'Mentorship request sent successfully';
                break;

            case 'update_profile':
                $stmt = $conn->prepare("UPDATE hub_users SET full_name = ?, email = ?, bio = ?, expertise = ?, goals = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['profile_name'],
                    $_POST['profile_email'],
                    $_POST['profile_bio'] ?? '',
                    $_POST['profile_expertise'] ?? '',
                    $_POST['profile_goals'] ?? '',
                    $hub_user_id
                ]);
                $_SESSION['message'] = 'Profile updated successfully';
                break;
        }

        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Fetch data for display
    // Bootcamps
    $stmt = $conn->prepare("SELECT * FROM bootcamps WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$hub_user_id]);
    $bootcamps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Skills
    $stmt = $conn->prepare("SELECT * FROM skills WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$hub_user_id]);
    $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Certifications
    $stmt = $conn->prepare("SELECT * FROM certifications WHERE user_id = ? ORDER BY date_earned DESC");
    $stmt->execute([$hub_user_id]);
    $certifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mentors
    $stmt = $conn->prepare("SELECT * FROM mentors WHERE is_available = true ORDER BY rating DESC");
    $stmt->execute();
    $mentors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Dashboard stats
    $active_bootcamps = 0;
    foreach ($bootcamps as $bootcamp) {
        if ($bootcamp['status'] === 'active') {
            $active_bootcamps++;
        }
    }

    $skills_count = count($skills);
    $certs_count = count($certifications);

    // Mentors connected
    $stmt = $conn->prepare("SELECT COUNT(*) FROM mentorship_requests WHERE mentee_id = ? AND status = 'accepted'");
    $stmt->execute([$hub_user_id]);
    $mentors_count = $stmt->fetchColumn();

    // Recent activity
    $activities = [];
    foreach ($bootcamps as $bootcamp) {
        $activities[] = [
            'type' => 'bootcamp',
            'title' => 'Started bootcamp: ' . $bootcamp['name'],
            'date' => $bootcamp['created_at']
        ];
    }

    foreach ($certifications as $cert) {
        $activities[] = [
            'type' => 'certification',
            'title' => 'Earned certification: ' . $cert['name'],
            'date' => $cert['date_earned']
        ];
    }

    // Sort activities by date
    usort($activities, function ($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    // Get only the 5 most recent activities
    $recent_activities = array_slice($activities, 0, 5);

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
    <title>Skills & Mentorship Hub</title>
    <link rel="stylesheet" href="styles.css">
    <style>
    /* Add this for the user info display */
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

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
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

    .mentor-card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 15px;
        margin-bottom: 15px;
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
    }
        /* Fix for modal buttons */
.modal-content {
    max-height: 85vh;
    overflow-y: auto;
}

.modal-content form {
    min-height: auto;
}

.modal-content .btn-primary {
    width: 100%;
    margin-top: 20px;
    padding: 12px 20px;
    font-size: 16px;
    background: #007bff;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    position: relative;
    z-index: 10;
}

/* Ensure form groups don't push button out of view */
.form-group:last-of-type {
    margin-bottom: 30px;
}

    .close {
        float: right;
        font-size: 24px;
        font-weight: bold;
        cursor: pointer;
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

    .navbar {
        background: white;
        padding: 15px 0;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .nav-brand h1 {
        margin: 0;
        text-align: center;
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
        position: relative;
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

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
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

    /* CV Analysis Section */
    .cv-analysis-section {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 25px;
        margin-bottom: 20px;
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

    .feedback-strength {
        border-left-color: #28a745;
        background-color: #f8fff9;
    }

    .feedback-weakness {
        border-left-color: #dc3545;
        background-color: #fff8f8;
    }

    .feedback-improvement {
        border-left-color: #ffc107;
        background-color: #fffef0;
    }
    </style>
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
                <h1>🎓 Skills & Mentorship Hub</h1>
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
                
                <li><a href="#" data-section="mentorship" class="nav-link">Mentorship</a></li>
                <li><a href="CVision.html" data-section="cv-analysis" class="nav-link">Analyze CV</a></li>
                
                <?php if ($user['is_mentor']): ?>
                <li><a href="#" data-section="mentor-dashboard" class="nav-link">Mentor Dashboard</a></li>
                <?php endif; ?>
                
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
                        <h2>Welcome to Your Learning Journey</h2>
                        <p>Track your progress, find mentors, and master new skills</p>
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
                        <div class="stat-icon">🏆</div>
                        <div class="stat-content">
                            <h3>Certifications</h3>
                            <p class="stat-number"><?php echo $certs_count; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="activity-section">
                    <h3>Recent Activity</h3>
                    <div id="recent-activity" class="activity-list">
                        <?php if (empty($recent_activities)): ?>
                        <p class="empty-state">No recent activity yet. Start learning!</p>
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

            <!-- Keep the rest of your sections (Mentorship, Profile, Mentor Dashboard) the same -->
            <!-- ... -->
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
                        <div class="mentor-card">
                            <h3><?php echo htmlspecialchars($mentor['full_name']); ?></h3>
                            <p><strong>Expertise:</strong> <?php echo htmlspecialchars($mentor['expertise']); ?></p>
                            <p><strong>Experience:</strong> <?php echo $mentor['experience_years']; ?> years</p>
                            <p><strong>Rating:</strong> <?php echo $mentor['rating']; ?>/5</p>
                            <p><?php echo htmlspecialchars($mentor['bio']); ?></p>
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

            <!-- Skills Section -->
            <section id="skills" class="section">
                <div class="section-header">
                    <h2>Skill Tracking</h2>
                    <p>Monitor your skill development and progress</p>
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
                    <h2>Certifications</h2>
                    <p>Showcase your verified credentials</p>
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
                        <p><strong>Date Earned:</strong> <?php echo date('M j, Y', strtotime($cert['date_earned'])); ?>
                        </p>
                        <?php if (!empty($cert['credential_id'])): ?>
                        <p><strong>Credential ID:</strong> <?php echo htmlspecialchars($cert['credential_id']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($cert['credential_url'])): ?>
                        <p><strong>Credential URL:</strong> <a
                                href="<?php echo htmlspecialchars($cert['credential_url']); ?>" target="_blank">View</a>
                        </p>
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

            <!-- Profile Section -->
            <section id="profile" class="section">
                <div class="section-header">
                    <h2>Your Profile</h2>
                    <p>Manage your learning profile and preferences</p>
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
                            <label for="profile-bio">Bio</label>
                            <textarea id="profile-bio" name="profile_bio" rows="4"
                                placeholder="Tell us about yourself..."><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="profile-expertise">Areas of Expertise</label>
                            <input type="text" id="profile-expertise" name="profile_expertise"
                                value="<?php echo htmlspecialchars($user['expertise'] ?? ''); ?>"
                                placeholder="e.g., Web Development, Data Science">
                        </div>
                        <div class="form-group">
                            <label for="profile-goals">Learning Goals</label>
                            <textarea id="profile-goals" name="profile_goals" rows="4"
                                placeholder="What do you want to achieve?"><?php echo htmlspecialchars($user['goals'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Profile</button>
                    </form>
                </div>
            </section>

            <!-- Mentor Dashboard Section -->
            <section id="mentor-dashboard" class="section">
                <div class="section-header">
                    <h2>Mentor Dashboard</h2>
                    <p>Manage your mentorship requests and mentees</p>
                </div>

                <div class="mentor-stats">
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-content">
                            <h3>Active Mentees</h3>
                            <p class="stat-number">0</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📋</div>
                        <div class="stat-content">
                            <h3>Pending Requests</h3>
                            <p class="stat-number">0</p>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">⭐</div>
                        <div class="stat-content">
                            <h3>Your Rating</h3>
                            <p class="stat-number">0</p>
                        </div>
                    </div>
                </div>

                <div class="mentor-section">
                    <h3>Mentorship Requests</h3>
                    <div id="mentor-requests" class="requests-list">
                        <p class="empty-state">No pending requests</p>
                    </div>
                </div>

                <div class="mentor-section">
                    <h3>Your Mentees</h3>
                    <div id="mentor-mentees" class="mentees-list">
                        <p class="empty-state">No active mentees yet</p>
                    </div>
                </div>


        </main>
    </div>

    <script>
    // Simple JavaScript for UI interactions (no API calls)
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded'); // Debug log

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
                console.log('Bootcamp button clicked'); // Debug log
                document.getElementById('bootcamp-modal').style.display = 'block';
            });
        }

        // Skill modal
        const skillBtn = document.getElementById('skill-btn');
        if (skillBtn) {
            skillBtn.addEventListener('click', function() {
                console.log('Skill button clicked'); // Debug log
                document.getElementById('skill-modal').style.display = 'block';
            });
        }

        // Certification modal
        const certBtn = document.getElementById('cert-btn');
        if (certBtn) {
            certBtn.addEventListener('click', function() {
                console.log('Certification button clicked'); // Debug log
                document.getElementById('cert-modal').style.display = 'block';
            });
        }

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

        // Hide mentor dashboard if user is not a mentor
        const isMentor = <?php echo $user['is_mentor'] ? 1 : 0; ?>;
        console.log('Is mentor:', isMentor); // Debug log
        if (!isMentor) {
            const mentorNav = document.querySelector('[data-section="mentor-dashboard"]');
            if (mentorNav) {
                mentorNav.parentElement.style.display = 'none';
            }
        }
    });
    </script>

    <?php endif; ?>
</body>
</html>


