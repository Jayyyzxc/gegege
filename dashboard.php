<?php
require_once 'config.php';

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Check if public access is enabled
$public_access = $settings['public_access'] ?? 1;
$is_logged_in = isLoggedIn();

// Check if user is super admin
$is_super_admin = false;
if ($is_logged_in && isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'super_admin') {
    $is_super_admin = true;
}

// Check if user is barangay captain
$is_captain = false;
$captain_barangay_id = null;
$captain_barangay_name = null;
if ($is_logged_in && isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'captain') {
    $is_captain = true;
    $captain_barangay_id = $_SESSION['user']['barangay_id'] ?? null;
    $captain_barangay_name = $_SESSION['user']['barangay_name'] ?? null;
}

// Get data from database using MySQLi based on user role
try {
    if ($is_super_admin) {
        // Super admin can see all data
        $total_residents = getResidentCount();
        $total_households = getHouseholdCount();
        $age_distribution = getAgeDistribution();
        $gender_distribution = getGenderDistribution();
        $employment_status = getEmploymentStatus();
    } elseif ($is_captain && $captain_barangay_name) {
        // Captain can only see data from their barangay
        $total_residents = getResidentCountByBarangay($captain_barangay_name);
        $total_households = getHouseholdCountByBarangay($captain_barangay_name);
        $age_distribution = getAgeDistributionByBarangay($captain_barangay_name);
        $gender_distribution = getGenderDistributionByBarangay($captain_barangay_name);
        $employment_status = getEmploymentStatusByBarangay($captain_barangay_name);
    } else {
        // Default data for public access or other roles
        $total_residents = getResidentCount();
        $total_households = getHouseholdCount();
        $age_distribution = getAgeDistribution();
        $gender_distribution = getGenderDistribution();
        $employment_status = getEmploymentStatus();
    }
} catch (Exception $e) {
    // Handle database errors gracefully
    error_log("Dashboard data error: " . $e->getMessage());
    $total_residents = 0;
    $total_households = 0;
    $age_distribution = [];
    $gender_distribution = [];
    $employment_status = [];
}

// Prepare data for charts - FIXED: Ensure arrays are properly initialized
$age_labels = [];
$age_data = [];
if (!empty($age_distribution)) {
    foreach ($age_distribution as $age) {
        $age_labels[] = $age['age_group'];
        $age_data[] = $age['count'];
    }
} else {
    // Default empty data if no results
    $age_labels = ['0-17', '18-24', '25-34', '35-44', '45-59', '60+'];
    $age_data = [0, 0, 0, 0, 0, 0];
}

$gender_labels = [];
$gender_data = [];
if (!empty($gender_distribution)) {
    foreach ($gender_distribution as $gender) {
        $gender_labels[] = $gender['gender'];
        $gender_data[] = $gender['count'];
    }
} else {
    // Default empty data if no results
    $gender_labels = ['Male', 'Female', 'Other'];
    $gender_data = [0, 0, 0];
}

$employment_labels = [];
$employment_data = [];
if (!empty($employment_status)) {
    foreach ($employment_status as $status) {
        $employment_labels[] = $status['employment_status'];
        $employment_data[] = $status['count'];
    }
} else {
    // Default empty data if no results
    $employment_labels = ['Employed', 'Unemployed', 'Student', 'Retired', 'Self-Employed'];
    $employment_data = [0, 0, 0, 0, 0];
}

// Calculate population growth (placeholder - you can implement actual calculation)
$population_growth = "+5.2%";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        /* Additional CSS for better alignment */
        .dashboard-charts-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 20px;
        }
        
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .card-header {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 16px;
            color: #495057;
        }
        
        .card-body {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .chart-container {
            flex: 1;
            position: relative;
            height: 300px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #fff;
        }
        
        .stat-icon:nth-child(1) { background: #3498db; }
        .stat-icon:nth-child(2) { background: #2ecc71; }
        .stat-icon:nth-child(3) { background: #e74c3c; }
        
        .stat-info h3 {
            margin: 0 0 5px 0;
            font-size: 14px;
            color: #6c757d;
        }
        
        .stat-info p {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
            color: #495057;
        }

        .data-source-notice {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 10px 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #0066cc;
        }
        
        .data-source-notice i {
            margin-right: 8px;
        }
        
        @media (max-width: 1200px) {
            .dashboard-charts-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-charts-grid,
            .stats-container {
                grid-template-columns: 1fr;
            }
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
                    <?php if ($is_captain && $captain_barangay_name): ?>
                        <p>Welcome, <?php echo htmlspecialchars($_SESSION['user']['full_name'] ?? 'User'); ?> of <?php echo htmlspecialchars($captain_barangay_name); ?></p>
                    <?php else: ?>
                        <p>Welcome, <?php echo htmlspecialchars($_SESSION['user']['full_name'] ?? 'User'); ?></p>
                    <?php endif; ?>
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

    <div class="main-content">
        <div class="dashboard-header">
            <h2><i class="fas fa-house-user"></i> Dashboard</h2>
            <?php if ($is_super_admin): ?>
                <span class="admin-badge"><i class="fas fa-crown"></i> Super Admin</span>
            <?php elseif ($is_captain): ?>
                <span class="admin-badge"><i class="fas fa-user-shield"></i> Barangay Captain</span>
            <?php endif; ?>
        </div>
        <?php if (!$is_logged_in && !$public_access): ?>
            <div class="access-denied">
                <i class="fas fa-lock"></i>
                <h2>Public Access Disabled</h2>
                <p>Please login to view the dashboard</p>
                <a href="login.php" class="login-btn">Login</a>
            </div>
        <?php else: ?>
            <div class="dashboard-header">
                <h4>Overview - <?php echo date('F j, Y'); ?></h4>
                <?php if ($is_super_admin): ?>
                    <p class="admin-notice">You are viewing the system as Super Administrator</p>
                <?php elseif ($is_captain && $captain_barangay_name): ?>
                    <p class="admin-notice">You are viewing data for <?php echo htmlspecialchars($captain_barangay_name); ?> only</p>
                <?php endif; ?>
            </div>

            <!-- Data Source Notice -->
    
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #3498db;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Residents</h3>
                        <p><?php echo number_format($total_residents); ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #2ecc71;">
                        <i class="fas fa-home"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Total Households</h3>
                        <p><?php echo number_format($total_households); ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e74c3c;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Population Growth</h3>
                        <p><?php echo $population_growth; ?></p>
                    </div>
                </div>
            </div>

            <div class="dashboard-charts-grid">
                <!-- Column 1: Residents by Age Group -->
                <div class="card">
                    <div class="card-header">
                        <h3>Residents by Age Group</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="ageChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Column 2: Gender Distribution -->
                <div class="card">
                    <div class="card-header">
                        <h3>Gender Distribution</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="genderChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Column 3: Employment Status -->
                <div class="card">
                    <div class="card-header">
                        <h3>Employment Status</h3>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="employmentChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Chart colors
    const chartColors = {
        blue: 'rgba(54, 162, 235, 0.7)',
        red: 'rgba(255, 99, 132, 0.7)',
        yellow: 'rgba(255, 206, 86, 0.7)',
        green: 'rgba(75, 192, 192, 0.7)',
        purple: 'rgba(153, 102, 255, 0.7)',
        orange: 'rgba(255, 159, 64, 0.7)',
        gray: 'rgba(201, 203, 207, 0.7)'
    };

    // Age Distribution Chart
    const ageCtx = document.getElementById('ageChart').getContext('2d');
    const ageChart = new Chart(ageCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($age_labels); ?>,
            datasets: [{
                label: 'Residents by Age Group',
                data: <?php echo json_encode($age_data); ?>,
                backgroundColor: chartColors.blue,
                borderColor: chartColors.blue.replace('0.7', '1'),
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `${context.raw} residents`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    },
                    grid: {
                        display: false
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });

    // Gender Distribution Chart
    const genderCtx = document.getElementById('genderChart').getContext('2d');
    const genderChart = new Chart(genderCtx, {
        type: 'pie',
        data: {
            labels: <?php echo json_encode($gender_labels); ?>,
            datasets: [{
                label: 'Gender Distribution',
                data: <?php echo json_encode($gender_data); ?>,
                backgroundColor: [
                    chartColors.blue,
                    chartColors.red,
                    chartColors.yellow,
                    chartColors.green,
                    chartColors.purple
                ],
                borderColor: '#fff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12,
                        padding: 20
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? Math.round((context.raw / total) * 100) : 0;
                            return `${context.label}: ${context.raw} (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '0%'
        }
    });

    // Employment Status Chart
    const employmentCtx = document.getElementById('employmentChart').getContext('2d');
    const employmentChart = new Chart(employmentCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($employment_labels); ?>,
            datasets: [{
                label: 'Employment Status',
                data: <?php echo json_encode($employment_data); ?>,
                backgroundColor: [
                    chartColors.green,
                    chartColors.red,
                    chartColors.purple,
                    chartColors.orange,
                    chartColors.gray
                ],
                borderColor: '#fff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12,
                        padding: 20
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? Math.round((context.raw / total) * 100) : 0;
                            return `${context.label}: ${context.raw} (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '65%'
        }
    });

    // Make charts responsive to window resize
    window.addEventListener('resize', function() {
        ageChart.resize();
        genderChart.resize();
        employmentChart.resize();
    });
</script>
</body>
</html>