<?php
session_start();
header("Content-Type: text/html; charset=UTF-8");

// Check if user is logged in and is an entrepreneur
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'entrepreneur') {
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

    // Get entrepreneur data
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT * FROM hub_users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // First, get the user's basic info from the users table
        $stmt = $conn->prepare("SELECT full_name, email, user_type FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user_data) {
            // Insert the new user into hub_users table
            $is_mentor = ($user_data['user_type'] === 'mentor') ? 1 : 0;

            $stmt = $conn->prepare("INSERT INTO hub_users (user_id, full_name, email, user_type, is_mentor) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id,
                $user_data['full_name'],
                $user_data['email'],
                $user_data['user_type'],
                $is_mentor
            ]);

            // Fetch the newly created user record
            $stmt = $conn->prepare("SELECT * FROM hub_users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $_SESSION['message'] = "Welcome to your entrepreneur dashboard!";
        }
    }

    $hub_user_id = $user['id'];

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'add_to_potential_list':
                $stmt = $conn->prepare("INSERT INTO potential_candidates (entrepreneur_id, jobseeker_id, notes, status) VALUES (?, ?, ?, 'interested')");
                $stmt->execute([
                    $hub_user_id,
                    $_POST['jobseeker_id'],
                    $_POST['notes'] ?? ''
                ]);
                $_SESSION['message'] = 'Candidate added to potential list successfully';
                break;

            case 'remove_from_potential_list':
                $stmt = $conn->prepare("DELETE FROM potential_candidates WHERE id = ? AND entrepreneur_id = ?");
                $stmt->execute([$_POST['candidate_id'], $hub_user_id]);
                $_SESSION['message'] = 'Candidate removed from potential list';
                break;

            case 'update_inventory':
                $stmt = $conn->prepare("INSERT INTO entrepreneur_inventory (entrepreneur_id, item_name, category, quantity, unit_price, reorder_level) VALUES (?, ?, ?, ?, ?, ?) 
                                      ON CONFLICT (entrepreneur_id, item_name) 
                                      DO UPDATE SET quantity = ?, unit_price = ?, reorder_level = ?, updated_at = CURRENT_TIMESTAMP");
                $stmt->execute([
                    $hub_user_id,
                    $_POST['item_name'],
                    $_POST['category'],
                    $_POST['quantity'],
                    $_POST['unit_price'],
                    $_POST['reorder_level'],
                    $_POST['quantity'],
                    $_POST['unit_price'],
                    $_POST['reorder_level']
                ]);
                $_SESSION['message'] = 'Inventory updated successfully';
                break;

            case 'delete_inventory_item':
                $stmt = $conn->prepare("DELETE FROM entrepreneur_inventory WHERE id = ? AND entrepreneur_id = ?");
                $stmt->execute([$_POST['inventory_id'], $hub_user_id]);
                $_SESSION['message'] = 'Inventory item deleted successfully';
                break;
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Fetch job seekers with their skills and certifications
    $stmt = $conn->prepare("
        SELECT 
            hu.id, hu.full_name, hu.email, hu.bio, hu.expertise,
            COALESCE(
                ARRAY_AGG(DISTINCT s.name) FILTER (WHERE s.name IS NOT NULL),
                ARRAY[]::text[]
            ) as skills,
            COALESCE(
                ARRAY_AGG(DISTINCT c.name) FILTER (WHERE c.name IS NOT NULL),
                ARRAY[]::text[]
            ) as certifications
        FROM hub_users hu
        LEFT JOIN skills s ON hu.id = s.user_id
        LEFT JOIN certifications c ON hu.id = c.user_id
        WHERE hu.user_type = 'job-seeker'
        GROUP BY hu.id, hu.full_name, hu.email, hu.bio, hu.expertise
        ORDER BY hu.full_name
    ");
    $stmt->execute();
    $jobseekers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch entrepreneur's potential candidates
    $stmt = $conn->prepare("
        SELECT 
            pc.id as candidate_id,
            hu.id as jobseeker_id,
            hu.full_name,
            hu.email,
            hu.bio,
            hu.expertise,
            pc.notes,
            pc.created_at,
            COALESCE(
                ARRAY_AGG(DISTINCT s.name) FILTER (WHERE s.name IS NOT NULL),
                ARRAY[]::text[]
            ) as skills,
            COALESCE(
                ARRAY_AGG(DISTINCT c.name) FILTER (WHERE c.name IS NOT NULL),
                ARRAY[]::text[]
            ) as certifications
        FROM potential_candidates pc
        JOIN hub_users hu ON pc.jobseeker_id = hu.id
        LEFT JOIN skills s ON hu.id = s.user_id
        LEFT JOIN certifications c ON hu.id = c.user_id
        WHERE pc.entrepreneur_id = ?
        GROUP BY pc.id, hu.id, hu.full_name, hu.email, hu.bio, hu.expertise, pc.notes, pc.created_at
        ORDER BY pc.created_at DESC
    ");
    $stmt->execute([$hub_user_id]);
    $potential_candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch entrepreneur's inventory
    $stmt = $conn->prepare("SELECT * FROM entrepreneur_inventory WHERE entrepreneur_id = ? ORDER BY item_name");
    $stmt->execute([$hub_user_id]);
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate inventory stats
    $total_items = count($inventory);
    $low_stock_items = 0;
    $total_inventory_value = 0;

    foreach ($inventory as $item) {
        $item_value = $item['quantity'] * $item['unit_price'];
        $total_inventory_value += $item_value;

        if ($item['quantity'] <= $item['reorder_level']) {
            $low_stock_items++;
        }
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
    <title>Entrepreneur Dashboard - Smart Inventory & Talent Management</title>
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
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .nav-brand h1 {
            margin: 0;
            text-align: center;
            color: #2c5530;
        }

        .user-info {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .user-details {
            font-size: 16px;
            color: #2c5530;
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
            border-left: 5px solid #2c5530;
        }

        .stat-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
            color: #2c5530;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2c5530;
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
            color: #2c5530;
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
            background: #2c5530;
            color: white;
        }

        .btn-primary:hover {
            background: #1e3a24;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #1e7e34;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #2c5530;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .candidate-name {
            font-size: 18px;
            font-weight: bold;
            color: #2c5530;
            margin: 0;
        }

        .candidate-email {
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
            background: #e8f5e8;
            color: #2c5530;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 12px;
            border: 1px solid #2c5530;
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
            min-height: 80px;
        }

        .inventory-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .inventory-table th,
        .inventory-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .inventory-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c5530;
        }

        .inventory-table tr:hover {
            background: #f8f9fa;
        }

        .low-stock {
            background: #fff5f5 !important;
            color: #dc3545;
            font-weight: bold;
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
            color: #2c5530;
            border-bottom-color: #2c5530;
            font-weight: 600;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Navigation -->
        <nav class="navbar">
            <div class="nav-brand">
                <h1>🏪 Entrepreneur Dashboard - Smart Inventory & Talent Management</h1>
            </div>
        </nav>

        <!-- User Info Bar -->
        <div class="user-info">
            <div class="user-details">
                <strong>Welcome, <?php echo htmlspecialchars($user['full_name']); ?> (Entrepreneur)</strong>
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
                <div class="stat-number"><?php echo $total_items; ?></div>
                <div class="stat-label">Total Inventory Items</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⚠️</div>
                <div class="stat-number"><?php echo $low_stock_items; ?></div>
                <div class="stat-label">Low Stock Items</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <div class="stat-number">R<?php echo number_format($total_inventory_value, 2); ?></div>
                <div class="stat-label">Total Inventory Value</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-number"><?php echo count($potential_candidates); ?></div>
                <div class="stat-label">Potential Candidates</div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="nav-tabs">
            <button class="nav-tab active" data-tab="inventory">📦 Inventory Management</button>
            <button class="nav-tab" data-tab="talent">🎯 Talent Discovery</button>
            <button class="nav-tab" data-tab="potential">⭐ Potential Candidates</button>
        </div>

        <!-- Inventory Management Tab -->
        <div id="inventory" class="tab-content active">
            <div class="section">
                <div class="section-header">
                    <h2>Smart Inventory Management</h2>
                    <button class="btn btn-primary" id="open-inventory-modal">+ Add Inventory Item</button>
                </div>

                <?php if (empty($inventory)): ?>
                    <div class="empty-state">
                        <p>No inventory items yet. Add your first item to get started!</p>
                    </div>
                <?php else: ?>
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Total Value</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory as $item): ?>
                                <?php
                                $total_value = $item['quantity'] * $item['unit_price'];
                                $is_low_stock = $item['quantity'] <= $item['reorder_level'];
                                ?>
                                <tr class="<?php echo $is_low_stock ? 'low-stock' : ''; ?>">
                                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                                    <td><?php echo $item['quantity']; ?></td>
                                    <td>R<?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td>R<?php echo number_format($total_value, 2); ?></td>
                                    <td><?php echo $item['reorder_level']; ?></td>
                                    <td>
                                        <?php if ($is_low_stock): ?>
                                            <span style="color: #dc3545;">⚠️ Low Stock</span>
                                        <?php else: ?>
                                            <span style="color: #28a745;">✅ In Stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_inventory_item">
                                            <input type="hidden" name="inventory_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" class="btn btn-danger"
                                                onclick="return confirm('Are you sure you want to delete this item?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Talent Discovery Tab -->
        <div id="talent" class="tab-content">
            <div class="section">
                <div class="section-header">
                    <h2>Discover Skilled Job Seekers</h2>
                    <p>Browse and connect with talented individuals</p>
                </div>

                <?php if (empty($jobseekers)): ?>
                    <div class="empty-state">
                        <p>No job seekers available at the moment.</p>
                    </div>
                <?php else: ?>
                    <div class="candidates-list">
                        <?php foreach ($jobseekers as $jobseeker): ?>
                            <div class="card">
                                <div class="card-header">
                                    <div>
                                        <h3 class="candidate-name"><?php echo htmlspecialchars($jobseeker['full_name']); ?></h3>
                                        <p class="candidate-email"><?php echo htmlspecialchars($jobseeker['email']); ?></p>
                                        <?php if (!empty($jobseeker['expertise'])): ?>
                                            <p><strong>Expertise:</strong> <?php echo htmlspecialchars($jobseeker['expertise']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($jobseeker['bio'])): ?>
                                            <p><?php echo htmlspecialchars($jobseeker['bio']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn btn-success add-candidate-btn" 
                                        data-jobseeker-id="<?php echo $jobseeker['id']; ?>" 
                                        data-jobseeker-name="<?php echo htmlspecialchars($jobseeker['full_name']); ?>">
                                        Add to Potential List
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

        <!-- Potential Candidates Tab -->
        <div id="potential" class="tab-content">
            <div class="section">
                <div class="section-header">
                    <h2>Your Potential Candidates</h2>
                    <p>Manage your shortlisted talent</p>
                </div>

                <?php if (empty($potential_candidates)): ?>
                    <div class="empty-state">
                        <p>No potential candidates yet. Start discovering talent!</p>
                    </div>
                <?php else: ?>
                    <div class="candidates-list">
                        <?php foreach ($potential_candidates as $candidate): ?>
                            <div class="card">
                                <div class="card-header">
                                    <div>
                                        <h3 class="candidate-name"><?php echo htmlspecialchars($candidate['full_name']); ?></h3>
                                        <p class="candidate-email"><?php echo htmlspecialchars($candidate['email']); ?></p>
                                        <?php if (!empty($candidate['expertise'])): ?>
                                            <p><strong>Expertise:</strong> <?php echo htmlspecialchars($candidate['expertise']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($candidate['notes'])): ?>
                                            <p><strong>Your Notes:</strong> <?php echo htmlspecialchars($candidate['notes']); ?></p>
                                        <?php endif; ?>
                                        <p><small>Added on:
                                                <?php echo date('M j, Y', strtotime($candidate['created_at'])); ?></small></p>
                                    </div>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="action" value="remove_from_potential_list">
                                        <input type="hidden" name="candidate_id"
                                            value="<?php echo $candidate['candidate_id']; ?>">
                                        <button type="submit" class="btn btn-danger"
                                            onclick="return confirm('Remove this candidate from your list?')">Remove</button>
                                    </form>
                                </div>

                                <?php if (!empty($candidate['skills'])): ?>
                                    <div class="skills-list">
                                        <strong>Skills:</strong>
                                        <?php foreach ($candidate['skills'] as $skill): ?>
                                            <?php if (!empty($skill)): ?>
                                                <span class="skill-tag"><?php echo htmlspecialchars($skill); ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($candidate['certifications'])): ?>
                                    <div class="certs-list">
                                        <strong>Certifications:</strong>
                                        <?php foreach ($candidate['certifications'] as $cert): ?>
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

    <!-- Add Inventory Modal -->
    <div id="inventory-modal" class="modal">
        <div class="modal-content">
            <span class="close" id="close-inventory-modal">&times;</span>
            <h3>Add Inventory Item</h3>
            <form method="post">
                <input type="hidden" name="action" value="update_inventory">
                <div class="form-group">
                    <label for="item-name">Item Name</label>
                    <input type="text" id="item-name" name="item_name" required>
                </div>
                <div class="form-group">
                    <label for="category">Category</label>
                    <select id="category" name="category" required>
                        <option value="electronics">Electronics</option>
                        <option value="clothing">Clothing</option>
                        <option value="food">Food & Beverages</option>
                        <option value="hardware">Hardware</option>
                        <option value="stationery">Stationery</option>
                        <option value="furniture">Furniture</option>
                        <option value="appliances">Appliances</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity</label>
                    <input type="number" id="quantity" name="quantity" min="0" required>
                </div>
                <div class="form-group">
                    <label for="unit-price">Unit Price (R)</label>
                    <input type="number" id="unit-price" name="unit_price" min="0" step="0.01" required>
                </div>
                <div class="form-group">
                    <label for="reorder-level">Reorder Level</label>
                    <input type="number" id="reorder-level" name="reorder_level" min="0" required>
                </div>
                <button type="submit" class="btn btn-primary">Save Item</button>
            </form>
        </div>
    </div>

    <!-- Add Candidate Modal -->
    <div id="candidate-modal" class="modal">
        <div class="modal-content">
            <span class="close" id="close-candidate-modal">&times;</span>
            <h3>Add to Potential Candidates</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_to_potential_list">
                <input type="hidden" id="jobseeker-id" name="jobseeker_id">
                <div class="form-group">
                    <label for="candidate-name">Candidate Name</label>
                    <input type="text" id="candidate-name" readonly>
                </div>
                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" placeholder="Add any notes about this candidate..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Add to Potential List</button>
            </form>
        </div>
    </div>

    <script>
        // DOM elements
        const navTabs = document.querySelectorAll('.nav-tab');
        const tabContents = document.querySelectorAll('.tab-content');
        const openInventoryModalBtn = document.getElementById('open-inventory-modal');
        const closeInventoryModalBtn = document.getElementById('close-inventory-modal');
        const closeCandidateModalBtn = document.getElementById('close-candidate-modal');
        const inventoryModal = document.getElementById('inventory-modal');
        const candidateModal = document.getElementById('candidate-modal');
        const addCandidateBtns = document.querySelectorAll('.add-candidate-btn');
        const jobseekerIdInput = document.getElementById('jobseeker-id');
        const candidateNameInput = document.getElementById('candidate-name');

        // Tab switching functionality
        navTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabName = tab.getAttribute('data-tab');
                
                // Update active tab
                navTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                
                // Show corresponding content
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    if (content.id === tabName) {
                        content.classList.add('active');
                    }
                });
            });
        });

        // Inventory modal functionality
        openInventoryModalBtn.addEventListener('click', () => {
            inventoryModal.style.display = 'block';
        });

        closeInventoryModalBtn.addEventListener('click', () => {
            inventoryModal.style.display = 'none';
        });

        // Candidate modal functionality
        addCandidateBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const jobseekerId = btn.getAttribute('data-jobseeker-id');
                const jobseekerName = btn.getAttribute('data-jobseeker-name');
                
                jobseekerIdInput.value = jobseekerId;
                candidateNameInput.value = jobseekerName;
                candidateModal.style.display = 'block';
            });
        });

        closeCandidateModalBtn.addEventListener('click', () => {
            candidateModal.style.display = 'none';
        });

        // Close modals when clicking outside
        window.addEventListener('click', (e) => {
            if (e.target === inventoryModal) {
                inventoryModal.style.display = 'none';
            }
            if (e.target === candidateModal) {
                candidateModal.style.display = 'none';
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                inventoryModal.style.display = 'none';
                candidateModal.style.display = 'none';
            }
        });

        // Initialize the page
        document.addEventListener('DOMContentLoaded', () => {
            console.log('Entrepreneur Dashboard loaded successfully');
        });
    </script>
</body>
</html>
