<?php
session_start();
require_once 'config.php';

// Check if user is logged in
$is_logged_in = isset($_SESSION['user']);
$user_role = $_SESSION['user']['role'] ?? null;
$user_barangay_id = $_SESSION['user']['barangay_id'] ?? null;

// Set is_super_admin for compatibility
$is_super_admin = ($user_role === 'super_admin');

// Get analytics data with proper barangay filtering using census data
function getAnalyticsData($barangay_id = null) {
    global $conn;
    
    $data = [];
    
    // Build WHERE clause based on filters
    $whereClause = "";
    $params = [];
    $types = "";
    
    if ($barangay_id) {
        // Get barangay name for filtering
        $barangayNameQuery = "SELECT barangay_name FROM barangay_registration WHERE id = ?";
        $stmt = $conn->prepare($barangayNameQuery);
        $stmt->bind_param("i", $barangay_id);
        $stmt->execute();
        $barangayResult = $stmt->get_result();
        $barangay = $barangayResult->fetch_assoc();
        
        if ($barangay) {
            $whereClause = "WHERE cs.barangay = ?";
            $params = [$barangay['barangay_name']];
            $types = "s";
        } else {
            // Barangay not found, return empty data
            return getEmptyAnalyticsData();
        }
    } else {
        $whereClause = "WHERE 1=1";
    }
    
    // Age distribution from census_submissions
    $ageQuery = "SELECT 
        CASE 
            WHEN cs.age < 18 THEN '0-17'
            WHEN cs.age BETWEEN 18 AND 24 THEN '18-24'
            WHEN cs.age BETWEEN 25 AND 34 THEN '25-34'
            WHEN cs.age BETWEEN 35 AND 44 THEN '35-44'
            WHEN cs.age BETWEEN 45 AND 59 THEN '45-59'
            ELSE '60+'
        END AS age_group,
        COUNT(*) AS count
        FROM census_submissions cs
        $whereClause AND cs.age IS NOT NULL
        GROUP BY age_group ORDER BY age_group";
    
    $stmt = $conn->prepare($ageQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['age_distribution'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Employment status from census_submissions (using status_work_business)
    $employmentQuery = "SELECT 
        COALESCE(cs.status_work_business, 'Not Specified') AS employment_status, 
        COUNT(*) AS count 
        FROM census_submissions cs
        $whereClause AND cs.status_work_business IS NOT NULL AND cs.status_work_business != ''
        GROUP BY cs.status_work_business";
    
    $stmt = $conn->prepare($employmentQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['employment_status'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Gender ratio from census_submissions
    $genderQuery = "SELECT 
        COALESCE(cs.gender, 'Not Specified') AS gender, 
        COUNT(*) AS count 
        FROM census_submissions cs
        $whereClause AND cs.gender IS NOT NULL AND cs.gender != ''
        GROUP BY cs.gender";
    
    $stmt = $conn->prepare($genderQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['gender_ratio'] = $result->fetch_all(MYSQLI_ASSOC);
    
    // Total members count from census_submissions
    $membersQuery = "SELECT COUNT(*) as total_members FROM census_submissions cs $whereClause";
    
    $stmt = $conn->prepare($membersQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['total_members'] = $result->fetch_assoc()['total_members'] ?? 0;
    
    // Total households count from census_submissions
    $householdQuery = "SELECT COUNT(DISTINCT cs.id) as total_households 
                      FROM census_submissions cs 
                      $whereClause";
    
    $stmt = $conn->prepare($householdQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['total_households'] = $result->fetch_assoc()['total_households'] ?? 0;
    
    // Census submissions count
    $censusQuery = "SELECT COUNT(*) as total_census FROM census_submissions cs $whereClause";
    $stmt = $conn->prepare($censusQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data['total_census'] = $result->fetch_assoc()['total_census'] ?? 0;
    
    return $data;
}

// Helper function to return empty analytics data structure
function getEmptyAnalyticsData() {
    return [
        'age_distribution' => [],
        'employment_status' => [],
        'gender_ratio' => [],
        'total_members' => 0,
        'total_households' => 0,
        'total_census' => 0
    ];
}

// Get filters
$selected_barangay = isset($_GET['barangay']) ? intval($_GET['barangay']) : null;

// Determine which barangay to show data for
$current_barangay_id = null;

if ($user_role === 'super_admin') {
    // Super admin can select any barangay or view all
    $current_barangay_id = $selected_barangay;
} elseif (in_array($user_role, ['official', 'captain'])) {
    // Barangay officials can only view their own barangay
    $current_barangay_id = $user_barangay_id;
}

// Get all barangays for dropdown (only for super admin)
$barangays = [];
if ($user_role === 'super_admin') {
    $barangayQuery = "SELECT id, barangay_name FROM barangay_registration ORDER BY barangay_name";
    $result = $conn->query($barangayQuery);
    if ($result) {
        $barangays = $result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get current barangay name for display
$current_barangay_name = "All Barangays";
$current_barangay_data = null;

if ($current_barangay_id) {
    // Get specific barangay data
    $barangayQuery = "SELECT * FROM barangay_registration WHERE id = ?";
    $stmt = $conn->prepare($barangayQuery);
    $stmt->bind_param("i", $current_barangay_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_barangay_data = $result->fetch_assoc();
    
    if ($current_barangay_data) {
        $current_barangay_name = $current_barangay_data['barangay_name'];
    }
} elseif ($user_role !== 'super_admin' && $user_barangay_id) {
    // For barangay officials, get their barangay name
    $barangayQuery = "SELECT * FROM barangay_registration WHERE id = ?";
    $stmt = $conn->prepare($barangayQuery);
    $stmt->bind_param("i", $user_barangay_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current_barangay_data = $result->fetch_assoc();
    
    if ($current_barangay_data) {
        $current_barangay_name = $current_barangay_data['barangay_name'];
        $current_barangay_id = $user_barangay_id;
    }
}

// Get analytics data
$analyticsData = getAnalyticsData($current_barangay_id);

// Debug: Check what data we're getting
error_log("User Role: " . $user_role);
error_log("User Barangay ID: " . $user_barangay_id);
error_log("Current Barangay ID: " . $current_barangay_id);
error_log("Current Barangay Name: " . $current_barangay_name);
error_log("Age Distribution Count: " . count($analyticsData['age_distribution']));
error_log("Total Members: " . $analyticsData['total_members']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demographic Analytics - Barangay Profiling System</title>
    <link rel="stylesheet" href="analytics.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.css">
    <style>
        :root {
            --sidebar-width: 250px;
            --white: #ffffff;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-600: #6c757d;
            --primary-blue: #007bff;
            --shadow-sm: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .analytics-container {
            padding: 20px;
            margin-left: var(--sidebar-width);
        }
        
        .analytics-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-section {
            background-color: var(--white);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-row select, .filter-row button {
            padding: 8px 12px;
            border-radius: 4px;
            border: 1px solid var(--gray-300);
        }
        
        .filter-row button {
            background-color: var(--primary-blue);
            color: white;
            border: none;
            cursor: pointer;
        }
        
        .filter-row button:hover {
            background-color: #0056b3;
        }
        
        .current-view {
            background-color: var(--gray-100);
            padding: 10px 15px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }
        
        .analytics-card {
            background-color: var(--white);
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
        }
        
        .card-header {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .stat-item {
            background-color: var(--gray-100);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary-blue);
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--gray-600);
        }
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: var(--sidebar-width);
            background: #2c3e50;
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #34495e;
        }
        
        .sidebar-nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-nav li a {
            display: block;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            border-left: 4px solid transparent;
        }
        
        .sidebar-nav li a:hover,
        .sidebar-nav li a.active {
            background: #34495e;
            border-left-color: var(--primary-blue);
        }
        
        .sidebar-nav li a i {
            margin-right: 10px;
            width: 20px;
        }
        
        .welcome {
            margin-top: 10px;
            font-size: 14px;
        }
        
        .welcome a {
            color: #3498db;
            text-decoration: none;
        }
        
        .welcome a:hover {
            text-decoration: underline;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--gray-600);
            font-style: italic;
        }
        
        .data-source {
            font-size: 12px;
            color: var(--gray-600);
            margin-top: 10px;
            font-style: italic;
        }
        
        .user-role-info {
            background-color: #e7f3ff;
            padding: 10px 15px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 14px;
            border-left: 4px solid var(--primary-blue);
        }
        
        .barangay-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        
        .barangay-info h3 {
            margin-top: 0;
            color: #28a745;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
        }
        
        .info-label {
            font-weight: bold;
            margin-right: 10px;
            color: var(--gray-600);
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>Barangay Event And Program Planning System</h2>
            <?php if ($is_logged_in): ?>
                <div class="welcome">
                    <p>Welcome, <?php echo htmlspecialchars($_SESSION['user']['full_name'] ?? 'User'); ?></p>
                    <a href="logout.php" class="logout-btn">Logout</a>
                </div>
            <?php else: ?>
                <div class="welcome">
                    <a href="login.php" class="login-btn">Login</a>
                </div>
            <?php endif; ?>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li><a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-house-user"></i> Dashboard</a></li>
                <li><a href="resident.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'resident.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Residents</a></li>
                <li><a href="analytics.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'analytics.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Analytics</a></li>
                <li><a href="predictive.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'predictive.php' ? 'active' : ''; ?>"><i class="fas fa-brain"></i> Predictive Models</a></li>
                <li><a href="events.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'events.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-alt"></i> Events</a></li>
               
                <!-- Super Admin Only Links -->
                <?php if ($is_super_admin): ?>
                    <li><a href="superadmin.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'superadmin.php' ? 'active' : ''; ?>"><i class="fas fa-inbox"></i> Requests</a></li>
                <?php endif; ?>
                
                <?php if ($is_logged_in): ?>
                    <li><a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> Settings</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="analytics-container">
        <div class="analytics-header">
            <h1><i class="fas fa-chart-bar"></i> Demographic Analytics</h1>
        </div>
        
        <!-- Barangay Information -->
    
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="get" action="analytics.php">
                <div class="filter-row">
                    <?php if ($user_role === 'super_admin'): ?>
                        <label for="barangay">Filter by Barangay:</label>
                        <select name="barangay" id="barangay" onchange="this.form.submit()">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?php echo $barangay['id']; ?>" <?php echo $selected_barangay == $barangay['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="barangay" value="<?php echo $user_barangay_id; ?>">
                    <?php endif; ?>
                    
                    <button type="submit">Apply Filter</button>
                    <?php if ($selected_barangay && $user_role === 'super_admin'): ?>
                        <a href="analytics.php" class="clear-filter" style="padding: 8px 12px; background-color: #6c757d; color: white; text-decoration: none; border-radius: 4px;">Clear Filter</a>
                    <?php endif; ?>
                </div>
            </form>
            
            <!-- User Role Information -->
                        
            
            <!-- Current View Info -->
            <div class="current-view">
                <strong>Currently Viewing:</strong> 
                <?php echo htmlspecialchars($current_barangay_name); ?>
                <div class="data-source">Data Source: Census Submissions</div>
            </div>
        </div>
        
        <!-- Analytics Grid -->
        <div class="analytics-grid">
            <!-- Age Distribution Card -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3>Age Distribution - <?php echo htmlspecialchars($current_barangay_name); ?></h3>
                </div>
                <div class="chart-container">
                    <?php if (!empty($analyticsData['age_distribution'])): ?>
                        <canvas id="ageChart"></canvas>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-chart-bar fa-3x"></i>
                            <p>No age data available for <?php echo htmlspecialchars($current_barangay_name); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Employment Status Card -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3>Employment Status - <?php echo htmlspecialchars($current_barangay_name); ?></h3>
                </div>
                <div class="chart-container">
                    <?php if (!empty($analyticsData['employment_status'])): ?>
                        <canvas id="employmentChart"></canvas>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-briefcase fa-3x"></i>
                            <p>No employment data available for <?php echo htmlspecialchars($current_barangay_name); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Gender Ratio Card -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3>Gender Ratio - <?php echo htmlspecialchars($current_barangay_name); ?></h3>
                </div>
                <div class="chart-container">
                    <?php if (!empty($analyticsData['gender_ratio'])): ?>
                        <canvas id="genderChart"></canvas>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-venus-mars fa-3x"></i>
                            <p>No gender data available for <?php echo htmlspecialchars($current_barangay_name); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Stats Card -->
            <div class="analytics-card">
                <div class="card-header">
                    <h3>Quick Statistics - <?php echo htmlspecialchars($current_barangay_name); ?></h3>
                </div>
                <div class="stats-grid">
                    <?php
                    // Calculate total members from census_submissions
                    $totalMembers = $analyticsData['total_members'] ?? 0;
                    
                    // Calculate gender percentages
                    $maleCount = 0;
                    $femaleCount = 0;
                    $otherCount = 0;
                    foreach (($analyticsData['gender_ratio'] ?? []) as $gender) {
                        if ($gender['gender'] == 'Male') $maleCount = $gender['count'];
                        if ($gender['gender'] == 'Female') $femaleCount = $gender['count'];
                        if ($gender['gender'] == 'Other' || $gender['gender'] == 'Not Specified') $otherCount += $gender['count'];
                    }
                    $malePercent = $totalMembers > 0 ? round(($maleCount / $totalMembers) * 100, 1) : 0;
                    $femalePercent = $totalMembers > 0 ? round(($femaleCount / $totalMembers) * 100, 1) : 0;
                    $otherPercent = $totalMembers > 0 ? round(($otherCount / $totalMembers) * 100, 1) : 0;
                    
                    // Get total households and census
                    $totalHouseholds = $analyticsData['total_households'] ?? 0;
                    $totalCensus = $analyticsData['total_census'] ?? 0;
                    
                    // Calculate average household size
                    $avgHouseholdSize = $totalHouseholds > 0 ? round($totalMembers / $totalHouseholds, 1) : 0;
                    ?>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($totalMembers); ?></div>
                        <div class="stat-label">Total Residents</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($totalHouseholds); ?></div>
                        <div class="stat-label">Total Households</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $malePercent; ?>%</div>
                        <div class="stat-label">Male</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $femalePercent; ?>%</div>
                        <div class="stat-label">Female</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $avgHouseholdSize; ?></div>
                        <div class="stat-label">Avg. Household Size</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo number_format($totalCensus); ?></div>
                        <div class="stat-label">Census Records</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $otherPercent; ?>%</div>
                        <div class="stat-label">Other/Not Specified</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo count($analyticsData['employment_status'] ?? []); ?></div>
                        <div class="stat-label">Employment Categories</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
<script>
    // Prepare data for charts
    const ageData = {
        labels: <?php echo json_encode(array_column($analyticsData['age_distribution'] ?? [], 'age_group')); ?>,
        values: <?php echo json_encode(array_column($analyticsData['age_distribution'] ?? [], 'count')); ?>
    };
    
    const employmentData = {
        labels: <?php echo json_encode(array_column($analyticsData['employment_status'] ?? [], 'employment_status')); ?>,
        values: <?php echo json_encode(array_column($analyticsData['employment_status'] ?? [], 'count')); ?>
    };
    
    const genderData = {
        labels: <?php echo json_encode(array_column($analyticsData['gender_ratio'] ?? [], 'gender')); ?>,
        values: <?php echo json_encode(array_column($analyticsData['gender_ratio'] ?? [], 'count')); ?>
    };
    
    // Colors
    const chartColors = {
        blue: 'rgba(54, 162, 235, 0.7)',
        red: 'rgba(255, 99, 132, 0.7)',
        yellow: 'rgba(255, 206, 86, 0.7)',
        green: 'rgba(75, 192, 192, 0.7)',
        purple: 'rgba(153, 102, 255, 0.7)',
        orange: 'rgba(255, 159, 64, 0.7)',
        gray: 'rgba(201, 203, 207, 0.7)'
    };

    // Initialize charts when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Age Distribution Chart (Bar)
        <?php if (!empty($analyticsData['age_distribution'])): ?>
        const ageCtx = document.getElementById('ageChart').getContext('2d');
        new Chart(ageCtx, {
            type: 'bar',
            data: {
                labels: ageData.labels,
                datasets: [{
                    label: 'Number of Residents',
                    data: ageData.values,
                    backgroundColor: [
                        chartColors.blue,
                        chartColors.red,
                        chartColors.yellow,
                        chartColors.green,
                        chartColors.purple,
                        chartColors.orange
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Residents'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Age Group'
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Employment Status Chart (Doughnut)
        <?php if (!empty($analyticsData['employment_status'])): ?>
        const employmentCtx = document.getElementById('employmentChart').getContext('2d');
        new Chart(employmentCtx, {
            type: 'doughnut',
            data: {
                labels: employmentData.labels,
                datasets: [{
                    data: employmentData.values,
                    backgroundColor: [
                        chartColors.green,
                        chartColors.red,
                        chartColors.blue,
                        chartColors.orange,
                        chartColors.purple,
                        chartColors.yellow,
                        chartColors.gray
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
        <?php endif; ?>
        
        // Gender Ratio Chart (Pie)
        <?php if (!empty($analyticsData['gender_ratio'])): ?>
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'pie',
            data: {
                labels: genderData.labels,
                datasets: [{
                    data: genderData.values,
                    backgroundColor: [
                        chartColors.blue,
                        chartColors.red,
                        chartColors.purple
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
        <?php endif; ?>
    });
</script>
</body>
</html>