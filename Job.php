<?php
session_start();
header("Content-Type: text/html; charset=UTF-8");

// Check if user is logged in and is an employer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'employer') {
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

    // Get employer data
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT * FROM hub_users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Create employer profile if it doesn't exist
        $stmt = $conn->prepare("SELECT full_name, email, user_type FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user_data) {
            $stmt = $conn->prepare("INSERT INTO hub_users (user_id, full_name, email, user_type, is_mentor) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                $user_data['full_name'],
                $user_data['email'],
                $user_data['user_type'],
                0
            ]);

            $stmt = $conn->prepare("SELECT * FROM hub_users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    $employer_id = $user['id'];

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'create_job':
                $stmt = $conn->prepare("INSERT INTO jobs (
                    employer_id, title, company_name, description, responsibilities, 
                    requirements, location, salary_range, employment_type, category, 
                    experience_level, education_level, application_deadline, contact_email
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $employer_id,
                    $_POST['title'],
                    $_POST['company_name'],
                    $_POST['description'],
                    $_POST['responsibilities'],
                    $_POST['requirements'],
                    $_POST['location'],
                    $_POST['salary_range'],
                    $_POST['employment_type'],
                    $_POST['category'],
                    $_POST['experience_level'],
                    $_POST['education_level'],
                    $_POST['application_deadline'],
                    $_POST['contact_email']
                ]);
                $_SESSION['message'] = 'Job posted successfully!';
                break;

            case 'update_job':
                $stmt = $conn->prepare("UPDATE jobs SET 
                    title = ?, company_name = ?, description = ?, responsibilities = ?,
                    requirements = ?, location = ?, salary_range = ?, employment_type = ?,
                    category = ?, experience_level = ?, education_level = ?,
                    application_deadline = ?, contact_email = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND employer_id = ?");
                
                $stmt->execute([
                    $_POST['title'],
                    $_POST['company_name'],
                    $_POST['description'],
                    $_POST['responsibilities'],
                    $_POST['requirements'],
                    $_POST['location'],
                    $_POST['salary_range'],
                    $_POST['employment_type'],
                    $_POST['category'],
                    $_POST['experience_level'],
                    $_POST['education_level'],
                    $_POST['application_deadline'],
                    $_POST['contact_email'],
                    $_POST['job_id'],
                    $employer_id
                ]);
                $_SESSION['message'] = 'Job updated successfully!';
                break;

            case 'delete_job':
                $stmt = $conn->prepare("DELETE FROM jobs WHERE id = ? AND employer_id = ?");
                $stmt->execute([$_POST['job_id'], $employer_id]);
                $_SESSION['message'] = 'Job deleted successfully!';
                break;

            case 'update_application_status':
                $stmt = $conn->prepare("UPDATE job_applications SET status = ?, employer_notes = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['status'],
                    $_POST['employer_notes'] ?? '',
                    $_POST['application_id']
                ]);
                $_SESSION['message'] = 'Application status updated!';
                break;
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Fetch employer's jobs
    $stmt = $conn->prepare("SELECT * FROM jobs WHERE employer_id = ? ORDER BY created_at DESC");
    $stmt->execute([$employer_id]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch job applications with candidate details
    $stmt = $conn->prepare("
        SELECT 
            ja.*,
            j.title as job_title,
            hu.full_name, hu.email, hu.bio, hu.expertise,
            (SELECT STRING_AGG(DISTINCT s.name, ',') FROM skills s WHERE s.user_id = hu.id AND s.name IS NOT NULL) as skills_str,
            (SELECT STRING_AGG(DISTINCT c.name, ',') FROM certifications c WHERE c.user_id = hu.id AND c.name IS NOT NULL) as certifications_str
        FROM job_applications ja
        JOIN jobs j ON ja.job_id = j.id
        JOIN hub_users hu ON ja.jobseeker_id = hu.id
        WHERE j.employer_id = ?
        ORDER BY ja.applied_at DESC
    ");
    $stmt->execute([$employer_id]);
    $applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert skills and certifications to arrays
    foreach ($applications as &$application) {
        $application['skills'] = !empty($application['skills_str']) ? explode(',', $application['skills_str']) : [];
        $application['certifications'] = !empty($application['certifications_str']) ? explode(',', $application['certifications_str']) : [];
        unset($application['skills_str'], $application['certifications_str']);
    }
    unset($application);

    // Fetch all job seekers for candidate discovery
    $stmt = $conn->prepare("
        SELECT 
            hu.id, hu.full_name, hu.email, hu.bio, hu.expertise,
            (SELECT STRING_AGG(DISTINCT s.name, ',') FROM skills s WHERE s.user_id = hu.id AND s.name IS NOT NULL) as skills_str,
            (SELECT STRING_AGG(DISTINCT c.name, ',') FROM certifications c WHERE c.user_id = hu.id AND c.name IS NOT NULL) as certifications_str
        FROM hub_users hu
        WHERE hu.user_type = 'job-seeker'
        ORDER BY hu.full_name
    ");
    $stmt->execute();
    $jobseekers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert skills and certifications to arrays
    foreach ($jobseekers as &$jobseeker) {
        $jobseeker['skills'] = !empty($jobseeker['skills_str']) ? explode(',', $jobseeker['skills_str']) : [];
        $jobseeker['certifications'] = !empty($jobseeker['certifications_str']) ? explode(',', $jobseeker['certifications_str']) : [];
        unset($jobseeker['skills_str'], $jobseeker['certifications_str']);
    }
    unset($jobseeker);

    // Get unique skills and categories for filters
    $stmt = $conn->prepare("SELECT DISTINCT name FROM skills ORDER BY name");
    $stmt->execute();
    $all_skills = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $conn->prepare("SELECT DISTINCT category FROM jobs WHERE category IS NOT NULL ORDER BY category");
    $stmt->execute();
    $all_categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Calculate stats
    $total_jobs = count($jobs);
    $active_jobs = 0;
    $total_applications = count($applications);
    $pending_applications = 0;

    foreach ($jobs as $job) {
        if ($job['is_active']) $active_jobs++;
    }

    foreach ($applications as $application) {
        if ($application['status'] === 'pending') $pending_applications++;
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
    <title>Employer Dashboard - Talent Acquisition Platform</title>
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
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .navbar {
            background: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .nav-brand h1 {
            margin: 0;
            text-align: center;
            color: #2563eb;
        }

        .user-info {
            background: #e0f2fe;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .user-details {
            font-size: 16px;
            color: #1e40af;
        }

        .logout-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
        }

        .logout-btn:hover {
            background: #c82333;
        }

        .message {
            padding: 12px;
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
            border-left: 5px solid #2563eb;
        }

        .stat-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
            color: #2563eb;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2563eb;
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
            color: #2563eb;
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
            background: #2563eb;
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
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
            border-left: 4px solid #2563eb;
        }

        .job-card {
            border-left-color: #2563eb;
        }

        .application-card {
            border-left-color: #10b981;
        }

        .candidate-card {
            border-left-color: #f59e0b;
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
            color: #2563eb;
            margin: 0;
        }

        .card-subtitle {
            color: #666;
            margin: 5px 0;
        }

        .skills-list,
        .certs-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 0;
        }

        .skill-tag,
        .cert-tag {
            background: #dbeafe;
            color: #1e40af;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 12px;
            border: 1px solid #2563eb;
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
            margin: 2% auto;
            padding: 25px;
            border-radius: 10px;
            width: 800px;
            max-width: 95%;
            max-height: 90vh;
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

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2563eb;
        }

        .table tr:hover {
            background: #f8f9fa;
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

        .empty-state {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 40px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .nav-tabs {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .nav-tab {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            color: #666;
            border-bottom: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .nav-tab.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            font-weight: 600;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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

        .job-details {
            margin: 15px 0;
        }

        .job-detail-item {
            margin-bottom: 8px;
        }

        .job-detail-label {
            font-weight: bold;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Navigation -->
        <nav class="navbar">
            <div class="nav-brand">
                <h1>💼 Employer Dashboard - Talent Acquisition Platform</h1>
            </div>
        </nav>

        <!-- User Info Bar -->
        <div class="user-info">
            <div class="user-details">
                <strong>Welcome, <?php echo htmlspecialchars($user['full_name']); ?> (Employer)</strong>
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
                <div class="stat-icon">📊</div>
                <div class="stat-number"><?php echo $total_jobs; ?></div>
                <div class="stat-label">Total Jobs Posted</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-number"><?php echo $active_jobs; ?></div>
                <div class="stat-label">Active Jobs</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📨</div>
                <div class="stat-number"><?php echo $total_applications; ?></div>
                <div class="stat-label">Total Applications</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div class="stat-number"><?php echo $pending_applications; ?></div>
                <div class="stat-label">Pending Applications</div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="nav-tabs">
            <button class="nav-tab active" data-tab="jobs">📋 Job Management</button>
            <button class="nav-tab" data-tab="applications">📨 Applications</button>
            <button class="nav-tab" data-tab="candidates">👥 Candidate Discovery</button>
        </div>

        <!-- Job Management Tab -->
        <div id="jobs" class="tab-content active">
            <div class="section">
                <div class="section-header">
                    <h2>Job Management</h2>
                    <button class="btn btn-primary" id="open-job-modal">+ Post New Job</button>
                </div>

                <?php if (empty($jobs)): ?>
                    <div class="empty-state">
                        <p>No jobs posted yet. Create your first job posting!</p>
                    </div>
                <?php else: ?>
                    <div class="jobs-list">
                        <?php foreach ($jobs as $job): ?>
                            <div class="card job-card">
                                <div class="card-header">
                                    <div style="flex: 1;">
                                        <h3 class="card-title"><?php echo htmlspecialchars($job['title']); ?></h3>
                                        <p class="card-subtitle"><?php echo htmlspecialchars($job['company_name']); ?> • <?php echo htmlspecialchars($job['location']); ?></p>
                                        <p class="card-subtitle"><?php echo htmlspecialchars($job['employment_type']); ?> • <?php echo htmlspecialchars($job['salary_range']); ?></p>
                                        <div class="job-details">
                                            <div class="job-detail-item">
                                                <span class="job-detail-label">Category:</span> <?php echo htmlspecialchars($job['category']); ?>
                                            </div>
                                            <div class="job-detail-item">
                                                <span class="job-detail-label">Experience:</span> <?php echo htmlspecialchars($job['experience_level']); ?>
                                            </div>
                                            <?php if ($job['application_deadline']): ?>
                                                <div class="job-detail-item">
                                                    <span class="job-detail-label">Deadline:</span> <?php echo date('M j, Y', strtotime($job['application_deadline'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <p><strong>Views:</strong> <?php echo $job['views_count']; ?> • <strong>Applications:</strong> <?php echo $job['applications_count']; ?></p>
                                    </div>
                                    <div style="display: flex; gap: 10px; flex-direction: column;">
                                        <button class="btn btn-warning btn-sm edit-job-btn" 
                                            data-job='<?php echo htmlspecialchars(json_encode($job), ENT_QUOTES); ?>'>
                                            Edit
                                        </button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_job">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"
                                                onclick="return confirm('Are you sure you want to delete this job?')">Delete</button>
                                        </form>
                                    </div>
                                </div>
                                <div class="job-details">
                                    <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars(substr($job['description'], 0, 200))); ?>...</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Applications Tab -->
        <div id="applications" class="tab-content">
            <div class="section">
                <div class="section-header">
                    <h2>Job Applications</h2>
                    <p>Manage applications for your job postings</p>
                </div>

                <?php if (empty($applications)): ?>
                    <div class="empty-state">
                        <p>No applications received yet.</p>
                    </div>
                <?php else: ?>
                    <div class="filters">
                        <div class="filter-group">
                            <select class="filter-select" id="status-filter">
                                <option value="">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="reviewed">Reviewed</option>
                                <option value="accepted">Accepted</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            <select class="filter-select" id="job-filter">
                                <option value="">All Jobs</option>
                                <?php foreach ($jobs as $job): ?>
                                    <option value="<?php echo $job['id']; ?>"><?php echo htmlspecialchars($job['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="applications-list">
                        <?php foreach ($applications as $application): ?>
                            <div class="card application-card" data-status="<?php echo $application['status']; ?>" data-job="<?php echo $application['job_id']; ?>">
                                <div class="card-header">
                                    <div style="flex: 1;">
                                        <h3 class="card-title"><?php echo htmlspecialchars($application['full_name']); ?></h3>
                                        <p class="card-subtitle"><?php echo htmlspecialchars($application['email']); ?></p>
                                        <p class="card-subtitle"><strong>Applied for:</strong> <?php echo htmlspecialchars($application['job_title']); ?></p>
                                        <p class="card-subtitle"><strong>Applied on:</strong> <?php echo date('M j, Y', strtotime($application['applied_at'])); ?></p>
                                        <span class="status-badge status-<?php echo $application['status']; ?>">
                                            <?php echo ucfirst($application['status']); ?>
                                        </span>
                                    </div>
                                    <button class="btn btn-primary btn-sm view-application-btn" 
                                        data-application='<?php echo htmlspecialchars(json_encode($application), ENT_QUOTES); ?>'>
                                        View Details
                                    </button>
                                </div>
                                <?php if (!empty($application['skills'])): ?>
                                    <div class="skills-list">
                                        <strong>Skills:</strong>
                                        <?php foreach ($application['skills'] as $skill): ?>
                                            <?php if (!empty($skill)): ?>
                                                <span class="skill-tag"><?php echo htmlspecialchars($skill); ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Candidate Discovery Tab -->
        <div id="candidates" class="tab-content">
            <div class="section">
                <div class="section-header">
                    <h2>Candidate Discovery</h2>
                    <p>Browse and filter skilled job seekers</p>
                </div>

                <div class="filters">
                    <div class="filter-group">
                        <select class="filter-select" id="skill-filter">
                            <option value="">All Skills</option>
                            <?php foreach ($all_skills as $skill): ?>
                                <option value="<?php echo htmlspecialchars($skill); ?>"><?php echo htmlspecialchars($skill); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="category-filter">
                            <option value="">All Categories</option>
                            <?php foreach ($all_categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" id="name-filter" class="filter-select" placeholder="Search by name...">
                    </div>
                </div>

                <?php if (empty($jobseekers)): ?>
                    <div class="empty-state">
                        <p>No job seekers available at the moment.</p>
                    </div>
                <?php else: ?>
                    <div class="candidates-list">
                        <?php foreach ($jobseekers as $jobseeker): ?>
                            <div class="card candidate-card" 
                                 data-skills='<?php echo htmlspecialchars(json_encode($jobseeker['skills']), ENT_QUOTES); ?>'
                                 data-name="<?php echo htmlspecialchars($jobseeker['full_name']); ?>">
                                <div class="card-header">
                                    <div style="flex: 1;">
                                        <h3 class="card-title"><?php echo htmlspecialchars($jobseeker['full_name']); ?></h3>
                                        <p class="card-subtitle"><?php echo htmlspecialchars($jobseeker['email']); ?></p>
                                        <?php if (!empty($jobseeker['expertise'])): ?>
                                            <p><strong>Expertise:</strong> <?php echo htmlspecialchars($jobseeker['expertise']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($jobseeker['bio'])): ?>
                                            <p><?php echo nl2br(htmlspecialchars(substr($jobseeker['bio'], 0, 150))); ?>...</p>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn btn-success btn-sm view-candidate-btn" 
                                        data-candidate='<?php echo htmlspecialchars(json_encode($jobseeker), ENT_QUOTES); ?>'>
                                        View Profile
                                    </button>
                                </div>

                                <?php if (!empty($jobseeker['skills'])): ?>
                                    <div class="skills-list">
                                        <strong>Skills:</strong>
                                        <?php foreach ($jobseeker['skills'] as $skill): ?>
                                            <?php if (!empty($skill)): ?>
                                                <span class="skill-tag"><?php echo htmlspecialchars($skill); ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($jobseeker['certifications'])): ?>
                                    <div class="certs-list">
                                        <strong>Certifications:</strong>
                                        <?php foreach ($jobseeker['certifications'] as $cert): ?>
                                            <?php if (!empty($cert)): ?>
                                                <span class="cert-tag"><?php echo htmlspecialchars($cert); ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Job Modal -->
    <div id="job-modal" class="modal">
        <div class="modal-content">
            <span class="close" id="close-job-modal">&times;</span>
            <h3 id="job-modal-title">Post New Job</h3>
            <form method="post" id="job-form">
                <input type="hidden" name="action" id="job-action" value="create_job">
                <input type="hidden" name="job_id" id="job-id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="title">Job Title *</label>
                        <input type="text" id="title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="company_name">Company Name *</label>
                        <input type="text" id="company_name" name="company_name" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="location">Location *</label>
                        <input type="text" id="location" name="location" required>
                    </div>
                    <div class="form-group">
                        <label for="employment_type">Employment Type *</label>
                        <select id="employment_type" name="employment_type" required>
                            <option value="">Select Type</option>
                            <option value="Full-time">Full-time</option>
                            <option value="Part-time">Part-time</option>
                            <option value="Contract">Contract</option>
                            <option value="Freelance">Freelance</option>
                            <option value="Internship">Internship</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="salary_range">Salary Range</label>
                        <input type="text" id="salary_range" name="salary_range" placeholder="e.g., $50,000 - $70,000">
                    </div>
                    <div class="form-group">
                        <label for="category">Category *</label>
                        <select id="category" name="category" required>
                            <option value="">Select Category</option>
                            <option value="Technology">Technology</option>
                            <option value="Healthcare">Healthcare</option>
                            <option value="Finance">Finance</option>
                            <option value="Education">Education</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Sales">Sales</option>
                            <option value="Design">Design</option>
                            <option value="Engineering">Engineering</option>
                            <option value="Business">Business</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="experience_level">Experience Level *</label>
                        <select id="experience_level" name="experience_level" required>
                            <option value="">Select Level</option>
                            <option value="Entry Level">Entry Level</option>
                            <option value="Mid Level">Mid Level</option>
                            <option value="Senior Level">Senior Level</option>
                            <option value="Executive">Executive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="education_level">Education Level</label>
                        <input type="text" id="education_level" name="education_level" placeholder="e.g., Bachelor's Degree">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="application_deadline">Application Deadline</label>
                        <input type="date" id="application_deadline" name="application_deadline">
                    </div>
                    <div class="form-group">
                        <label for="contact_email">Contact Email *</label>
                        <input type="email" id="contact_email" name="contact_email" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Job Description *</label>
                    <textarea id="description" name="description" required placeholder="Describe the role, company culture, etc."></textarea>
                </div>

                <div class="form-group">
                    <label for="responsibilities">Key Responsibilities *</label>
                    <textarea id="responsibilities" name="responsibilities" required placeholder="List the main responsibilities of the role"></textarea>
                </div>

                <div class="form-group">
                    <label for="requirements">Requirements & Qualifications *</label>
                    <textarea id="requirements" name="requirements" required placeholder="List the required skills, experience, and qualifications"></textarea>
                </div>

                <button type="submit" class="btn btn-primary">Post Job</button>
            </form>
        </div>
    </div>

    <!-- Application Modal -->
    <div id="application-modal" class="modal">
        <div class="modal-content">
            <span class="close" id="close-application-modal">&times;</span>
            <h3>Application Details</h3>
            <div id="application-details"></div>
        </div>
    </div>

    <!-- Candidate Modal -->
    <div id="candidate-modal" class="modal">
        <div class="modal-content">
            <span class="close" id="close-candidate-modal">&times;</span>
            <h3>Candidate Profile</h3>
            <div id="candidate-details"></div>
        </div>
    </div>

    <script>
        // DOM elements
        const navTabs = document.querySelectorAll('.nav-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        const openJobModalBtn = document.getElementById('open-job-modal');
        const closeJobModalBtn = document.getElementById('close-job-modal');
        const closeApplicationModalBtn = document.getElementById('close-application-modal');
        const closeCandidateModalBtn = document.getElementById('close-candidate-modal');
        const jobModal = document.getElementById('job-modal');
        const applicationModal = document.getElementById('application-modal');
        const candidateModal = document.getElementById('candidate-modal');
        const jobForm = document.getElementById('job-form');
        const jobModalTitle = document.getElementById('job-modal-title');
        const jobAction = document.getElementById('job-action');
        const jobId = document.getElementById('job-id');
        const applicationDetails = document.getElementById('application-details');
        const candidateDetails = document.getElementById('candidate-details');

        // Tab switching functionality
        navTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabName = tab.getAttribute('data-tab');
                
                navTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    if (content.id === tabName) {
                        content.classList.add('active');
                    }
                });
            });
        });

        // Job modal functionality
        openJobModalBtn.addEventListener('click', () => {
            jobModalTitle.textContent = 'Post New Job';
            jobAction.value = 'create_job';
            jobId.value = '';
            jobForm.reset();
            jobModal.style.display = 'block';
        });

        // Edit job buttons
        document.querySelectorAll('.edit-job-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const job = JSON.parse(btn.getAttribute('data-job'));
                jobModalTitle.textContent = 'Edit Job';
                jobAction.value = 'update_job';
                jobId.value = job.id;
                
                // Fill form with job data
                document.getElementById('title').value = job.title;
                document.getElementById('company_name').value = job.company_name;
                document.getElementById('description').value = job.description;
                document.getElementById('responsibilities').value = job.responsibilities;
                document.getElementById('requirements').value = job.requirements;
                document.getElementById('location').value = job.location;
                document.getElementById('salary_range').value = job.salary_range;
                document.getElementById('employment_type').value = job.employment_type;
                document.getElementById('category').value = job.category;
                document.getElementById('experience_level').value = job.experience_level;
                document.getElementById('education_level').value = job.education_level;
                document.getElementById('application_deadline').value = job.application_deadline;
                document.getElementById('contact_email').value = job.contact_email;
                
                jobModal.style.display = 'block';
            });
        });

        closeJobModalBtn.addEventListener('click', () => {
            jobModal.style.display = 'none';
        });

        // Application modal functionality
        document.querySelectorAll('.view-application-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const application = JSON.parse(btn.getAttribute('data-application'));
                
                applicationDetails.innerHTML = `
                    <div class="card">
                        <div class="card-header">
                            <h3>${application.full_name}</h3>
                            <p>${application.email}</p>
                        </div>
                        <p><strong>Applied for:</strong> ${application.job_title}</p>
                        <p><strong>Applied on:</strong> ${new Date(application.applied_at).toLocaleDateString()}</p>
                        <p><strong>Status:</strong> <span class="status-badge status-${application.status}">${application.status.charAt(0).toUpperCase() + application.status.slice(1)}</span></p>
                        
                        ${application.bio ? `<div class="form-group"><strong>Bio:</strong><p>${application.bio}</p></div>` : ''}
                        
                        ${application.cover_letter ? `<div class="form-group"><strong>Cover Letter:</strong><p>${application.cover_letter}</p></div>` : ''}
                        
                        ${application.skills.length > 0 ? `
                            <div class="skills-list">
                                <strong>Skills:</strong>
                                ${application.skills.map(skill => `<span class="skill-tag">${skill}</span>`).join('')}
                            </div>
                        ` : ''}
                        
                        ${application.certifications.length > 0 ? `
                            <div class="certs-list">
                                <strong>Certifications:</strong>
                                ${application.certifications.map(cert => `<span class="cert-tag">${cert}</span>`).join('')}
                            </div>
                        ` : ''}
                        
                        <form method="post" class="form-group">
                            <input type="hidden" name="action" value="update_application_status">
                            <input type="hidden" name="application_id" value="${application.id}">
                            <div class="form-group">
                                <label for="status">Update Status:</label>
                                <select name="status" required>
                                    <option value="pending" ${application.status === 'pending' ? 'selected' : ''}>Pending</option>
                                    <option value="reviewed" ${application.status === 'reviewed' ? 'selected' : ''}>Reviewed</option>
                                    <option value="accepted" ${application.status === 'accepted' ? 'selected' : ''}>Accepted</option>
                                    <option value="rejected" ${application.status === 'rejected' ? 'selected' : ''}>Rejected</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="employer_notes">Notes:</label>
                                <textarea name="employer_notes" placeholder="Add internal notes about this candidate...">${application.employer_notes || ''}</textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Update Status</button>
                        </form>
                    </div>
                `;
                
                applicationModal.style.display = 'block';
            });
        });

        closeApplicationModalBtn.addEventListener('click', () => {
            applicationModal.style.display = 'none';
        });

        // Candidate modal functionality
        document.querySelectorAll('.view-candidate-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const candidate = JSON.parse(btn.getAttribute('data-candidate'));
                
                candidateDetails.innerHTML = `
                    <div class="card">
                        <div class="card-header">
                            <h3>${candidate.full_name}</h3>
                            <p>${candidate.email}</p>
                        </div>
                        
                        ${candidate.expertise ? `<p><strong>Expertise:</strong> ${candidate.expertise}</p>` : ''}
                        
                        ${candidate.bio ? `<div class="form-group"><strong>Bio:</strong><p>${candidate.bio}</p></div>` : ''}
                        
                        ${candidate.skills.length > 0 ? `
                            <div class="skills-list">
                                <strong>Skills:</strong>
                                ${candidate.skills.map(skill => `<span class="skill-tag">${skill}</span>`).join('')}
                            </div>
                        ` : ''}
                        
                        ${candidate.certifications.length > 0 ? `
                            <div class="certs-list">
                                <strong>Certifications:</strong>
                                ${candidate.certifications.map(cert => `<span class="cert-tag">${cert}</span>`).join('')}
                            </div>
                        ` : ''}
                    </div>
                `;
                
                candidateModal.style.display = 'block';
            });
        });

        closeCandidateModalBtn.addEventListener('click', () => {
            candidateModal.style.display = 'none';
        });

        // Filter functionality
        document.getElementById('status-filter')?.addEventListener('change', filterApplications);
        document.getElementById('job-filter')?.addEventListener('change', filterApplications);
        document.getElementById('skill-filter')?.addEventListener('change', filterCandidates);
        document.getElementById('category-filter')?.addEventListener('change', filterCandidates);
        document.getElementById('name-filter')?.addEventListener('input', filterCandidates);

        function filterApplications() {
            const statusFilter = document.getElementById('status-filter').value;
            const jobFilter = document.getElementById('job-filter').value;
            const applications = document.querySelectorAll('.application-card');
            
            applications.forEach(app => {
                const status = app.getAttribute('data-status');
                const job = app.getAttribute('data-job');
                
                const statusMatch = !statusFilter || status === statusFilter;
                const jobMatch = !jobFilter || job === jobFilter;
                
                app.style.display = statusMatch && jobMatch ? 'block' : 'none';
            });
        }

        function filterCandidates() {
            const skillFilter = document.getElementById('skill-filter').value;
            const categoryFilter = document.getElementById('category-filter').value;
            const nameFilter = document.getElementById('name-filter').value.toLowerCase();
            const candidates = document.querySelectorAll('.candidate-card');
            
            candidates.forEach(candidate => {
                const skills = JSON.parse(candidate.getAttribute('data-skills'));
                const name = candidate.getAttribute('data-name').toLowerCase();
                
                const skillMatch = !skillFilter || skills.includes(skillFilter);
                const nameMatch = !nameFilter || name.includes(nameFilter);
                // Note: Category filter would need additional data attribute
                
                candidate.style.display = skillMatch && nameMatch ? 'block' : 'none';
            });
        }

        // Close modals when clicking outside
        window.addEventListener('click', (e) => {
            if (e.target === jobModal) jobModal.style.display = 'none';
            if (e.target === applicationModal) applicationModal.style.display = 'none';
            if (e.target === candidateModal) candidateModal.style.display = 'none';
        });

        // Close modals with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                jobModal.style.display = 'none';
                applicationModal.style.display = 'none';
                candidateModal.style.display = 'none';
            }
        });

        // Initialize the page
        document.addEventListener('DOMContentLoaded', () => {
            console.log('Employer Dashboard loaded successfully');
        });
    </script>
</body>
</html>
