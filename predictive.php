<?php
session_start();
require_once 'config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Check if user is logged in
$is_logged_in = isset($_SESSION['user']);
$is_super_admin = $is_logged_in && ($_SESSION['user']['role'] ?? '') === 'super_admin';
$is_captain = $is_logged_in && ($_SESSION['user']['role'] ?? '') === 'captain';
$captain_barangay_name = '';

// Get captain's barangay name if applicable
if ($is_captain && isset($_SESSION['user']['barangay_id'])) {
    $stmt = $conn->prepare("SELECT barangay_name FROM barangay_registration WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user']['barangay_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $captain_barangay_name = $result->fetch_assoc()['barangay_name'];
    }
}

// ----------------------------
// 1️⃣ Get selected barangay
// ----------------------------
$selected_barangay = $_GET['barangay'] ?? '';
if (empty($selected_barangay)) {
    if (isset($_GET['format']) && $_GET['format'] === 'json') {
        echo json_encode(["error" => "Missing barangay parameter"]);
        exit;
    }
}

// ----------------------------
// 2️⃣ Fetch all barangays for dropdown
// ----------------------------
$barangays = [];
$barangay_result = $conn->query("SELECT barangay_name FROM barangay_registration ORDER BY barangay_name");
if ($barangay_result) {
    $barangays = $barangay_result->fetch_all(MYSQLI_ASSOC);
}

// ----------------------------
// 3️⃣ Fetch barangay info if selected
// ----------------------------
$barangay = null;
if (!empty($selected_barangay)) {
    $stmt = $conn->prepare("SELECT * FROM barangay_registration WHERE barangay_name = ? LIMIT 1");
    $stmt->bind_param("s", $selected_barangay);
    $stmt->execute();
    $barangay = $stmt->get_result()->fetch_assoc();

    if (!$barangay && (isset($_GET['format']) && $_GET['format'] === 'json')) {
        echo json_encode(['error' => 'Barangay not found']);
        exit;
    }
}

// If barangay is selected, proceed with data processing
if (!empty($selected_barangay) && $barangay) {
    // ----------------------------
    // 🌦️ 4️⃣ Fetch Weather Forecast (PAGASA + fallback)
    // ----------------------------
    $pagasa_url = "https://api.pagasa.dost.gov.ph/weather/pampanga";
    $weather_data = @json_decode(file_get_contents($pagasa_url), true);

    // Fallback simulated data if API not reachable
    if (!$weather_data || !isset($weather_data['rainfall'])) {
        $weather_data = [
            "rainfall" => [200, 250, 300, 400, 450, 500, 550, 480, 350, 250, 200, 150],
            "temperature" => [30, 31, 33, 34, 35, 36, 35, 34, 33, 32, 31, 30]
        ];
    }

    $avg_rainfall = array_sum($weather_data['rainfall']) / count($weather_data['rainfall']);
    $avg_temp = array_sum($weather_data['temperature']) / count($weather_data['temperature']);

    // Weather-based risk indicators
    $dengue_risk  = min(100, ($avg_rainfall / 500) * 100);
    $flood_risk   = min(100, ($avg_rainfall / 550) * 100);
    $heat_risk    = max(0, (($avg_temp - 30) / 10) * 100);
    $drought_risk = max(0, (1 - ($avg_rainfall / 500)) * 100);

    // ----------------------------
    // 5️⃣ Fetch census + members
    // ----------------------------
    $census_stmt = $conn->prepare("SELECT * FROM census_submissions WHERE barangay = ?");
    $census_stmt->bind_param("s", $selected_barangay);
    $census_stmt->execute();
    $census_results = $census_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $household_members = [];
    if ($census_results) {
        $census_ids = array_column($census_results, 'id');
        $placeholders = implode(',', array_fill(0, count($census_ids), '?'));
        $types = str_repeat('i', count($census_ids));
        $stmt = $conn->prepare("SELECT * FROM household_members WHERE household_id IN ($placeholders)");
        $stmt->bind_param($types, ...$census_ids);
        $stmt->execute();
        $household_members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    $total_households = (int)($barangay['total_households'] ?? 0);
    $total_population = (int)($barangay['total_population'] ?? 0);

    // ----------------------------
    // 6️⃣ Weighted Socioeconomic Analysis
    // ----------------------------
    $household_income = [];
    foreach ($household_members as $member) {
        if (!empty($member['monthly_income']) && !empty($member['household_id'])) {
            $income_text = strtolower(trim($member['monthly_income']));
            $id = $member['household_id'];
            if (strpos($income_text, '₱5,000 and below') !== false) $income_value = 5000;
            elseif (strpos($income_text, '₱5,001 - ₱10,000') !== false) $income_value = 10000;
            elseif (strpos($income_text, '₱10,001 - ₱15,000') !== false) $income_value = 15000;
            elseif (strpos($income_text, '₱15,001 - ₱20,000') !== false) $income_value = 20000;
            elseif (strpos($income_text, '₱20,001 and above') !== false) $income_value = 25000;
            else $income_value = 0;

            $household_income[$id][] = $income_value;
        }
    }

    $total_people_in_low_income_households = 0;
    $total_population_from_households = 0;
    foreach ($household_income as $household_id => $incomes) {
        $average_income = array_sum($incomes) / max(1, count($incomes));
        $household_size = count(array_filter($household_members, fn($m) => $m['household_id'] == $household_id));
        $total_population_from_households += $household_size;
        if ($average_income <= 10000) $total_people_in_low_income_households += $household_size;
    }

    $low_income_rate = ($total_population_from_households > 0)
        ? ($total_people_in_low_income_households / $total_population_from_households) * 100
        : 0;

    // ----------------------------
    // 7️⃣ Waste & Health Data
    // ----------------------------
    $total_people_in_poor_waste = 0;
    $total_people_with_health_issues = 0;
    foreach ($census_results as $row) {
        $household_id = $row['id'];
        $household_size = count(array_filter($household_members, fn($m) => $m['household_id'] == $household_id));

        $garbage_disposal = strtolower(trim($row['garbage_disposal'] ?? ''));
        $segregate = strtolower(trim($row['segregate'] ?? ''));
        $poor_methods = ['burning', 'burying', 'dumping', 'none', 'open burning'];
        if (in_array($garbage_disposal, $poor_methods) || $segregate === 'no')
            $total_people_in_poor_waste += $household_size;

        if (!empty($row['disease_1']) || !empty($row['disease_2']) || !empty($row['disease_3']))
            $total_people_with_health_issues += $household_size;
    }

    $waste_problem_rate = ($total_population_from_households > 0)
        ? ($total_people_in_poor_waste / $total_population_from_households) * 100
        : 0;

    $health_issue_rate = ($total_population_from_households > 0)
        ? ($total_people_with_health_issues / $total_population_from_households) * 100
        : 0;

    // ----------------------------
    // 8️⃣ Youth Analysis
    // ----------------------------
    $total_youth = 0;
    $total_enrolled_youth = 0;
    foreach ($household_members as $member) {
        $age = (int)($member['age'] ?? 0);
        if ($age >= 5 && $age <= 24) {
            $total_youth++;
            if (trim($member['currently_enrolled'] ?? '') === 'Yes')
                $total_enrolled_youth++;
        }
    }
    $youth_rate = ($total_youth > 0) ? ($total_enrolled_youth / $total_youth) * 100 : 0;

    $flood_prone = strtolower($barangay['flood_prone'] ?? '') === 'yes';

    // ----------------------------
    // 9️⃣ Integrate Rules + Weather
    // ----------------------------
    $rules_query = $conn->query("SELECT * FROM event_rules");
    $results = [];
    $rule_descriptions = [];

    while ($rule = $rules_query->fetch_assoc()) {
        $event = $rule['event_name'];
        $condition = strtolower($rule['rule_condition']);
        $score = 0;

        // Store description for later use
        $rule_descriptions[$event] = $rule['description'] ?? '';

        // Existing conditions
        if (strpos($condition, 'flood_prone') !== false && $flood_prone) $score += $rule['score'];
        if (strpos($condition, 'low_income') !== false && $low_income_rate > 30) $score += $rule['score'];
        if (strpos($condition, 'waste') !== false && $waste_problem_rate > 20) $score += $rule['score'];
        if (strpos($condition, 'disease') !== false && $health_issue_rate > 10) $score += $rule['score'];
        if (strpos($condition, 'youth') !== false && $youth_rate > 20) $score += $rule['score'];

        // 🌦️ NEW weather-based factors
        if (strpos($condition, 'rain') !== false && $avg_rainfall > 450) $score += $rule['score'];
        if (strpos($condition, 'heat') !== false && $avg_temp > 34) $score += $rule['score'];
        if (strpos($condition, 'drought') !== false && $avg_rainfall < 200) $score += $rule['score'];

        $results[$event] = ($results[$event] ?? 0) + $score;
    }

    // Normalize prediction scores
    $max_score = $results ? max($results) : 0;
    $predictions = [];
    foreach ($results as $event => $score) {
        $normalized = ($max_score > 0) ? round(($score / $max_score) * 100, 2) : 0;
        if ($normalized >= 50) {
            $predictions[] = [
                'event' => $event,
                'score' => $normalized,
                'reason' => 'Based on socioeconomic and weather risk indicators',
                'description' => $rule_descriptions[$event] ?? ''
            ];
        }
    }

    // ----------------------------
    // 🔟 Summary
    // ----------------------------
    $summary = [
        'total_population' => $total_population,
        'total_households' => $total_households,
        'low_income_rate' => round($low_income_rate, 2) . '%',
        'waste_problem_rate' => round($waste_problem_rate, 2) . '%',
        'health_issue_rate' => round($health_issue_rate, 2) . '%',
        'youth_enrollment_rate' => round($youth_rate, 2) . '%',
        'flood_prone' => $flood_prone ? 'Yes' : 'No',
        'annual_budget' => $barangay['annual_budget'] ?? 0,
        'avg_rainfall_mm' => round($avg_rainfall, 1),
        'avg_temperature_c' => round($avg_temp, 1),
        'dengue_risk' => round($dengue_risk, 1) . '%',
        'flood_risk' => round($flood_risk, 1) . '%',
        'heat_risk' => round($heat_risk, 1) . '%',
        'drought_risk' => round($drought_risk, 1) . '%'
    ];
}

// ----------------------------
// 🔟 Output
// ----------------------------
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    if (empty($selected_barangay) || !$barangay) {
        echo json_encode(['error' => 'Barangay not found or not selected']);
    } else {
        echo json_encode([
            'barangay' => $selected_barangay,
            'summary' => $summary,
            'predictions' => $predictions
        ], JSON_PRETTY_PRINT);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Predictive Analytics</title>
<link rel="stylesheet" href="predictive.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f5f6fa;
    margin: 0;
    display: flex;
}

/* Dashboard Container */
.dashboard-container {
    display: flex;
    width: 100%;
    min-height: 100vh;
}

/* Main Content */
.main-content {
    flex: 1;
    margin-left: 280px;
    padding: 20px;
    width: calc(100% - 280px);
}

.container {
    background: white;
    padding: 20px;
    border-radius: 10px;
    max-width: 1200px;
    margin: 0 auto;
    box-shadow: 0 0 8px rgba(0,0,0,0.1);
}

h1 {
    color: #1e3a8a;
    text-align: center;
    margin-bottom: 20px;
}

.barangay-selector {
    margin: 20px 0;
    text-align: center;
}

.barangay-selector select {
    padding: 10px;
    font-size: 16px;
    border-radius: 5px;
    border: 1px solid #ccc;
    margin-right: 10px;
}

.barangay-selector button {
    padding: 10px 20px;
    font-size: 16px;
    background: #1e40af;
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
}

.barangay-selector button:hover {
    background: #1e3a8a;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

th, td {
    padding: 12px;
    border-bottom: 1px solid #ddd;
    text-align: center;
}

th {
    background: #1e40af;
    color: white;
}

tr:hover {
    background: #f1f5f9;
}

.summary {
    background: #e0f2fe;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.weather-card {
    background: #f8fafc;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
    border: 1px solid #e2e8f0;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    padding: 20px;
    border-radius: 10px;
    max-width: 500px;
    width: 90%;
}

.close-btn {
    float: right;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.close-btn:hover {
    color: #000;
}

.modal-header {
    font-size: 20px;
    font-weight: bold;
    margin-bottom: 15px;
    color: #1e3a8a;
}

.risk-item {
    margin: 10px 0;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.risk-item:last-child {
    border-bottom: none;
}

.chart-container {
    margin-top: 20px;
    height: 400px;
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

    <!-- Main Content -->
    <div class="main-content">
        <div class="container">
            <h1>📊 Predictive Analytics</h1>
            
            <!-- Barangay Selection Form -->
            <div class="barangay-selector">
                <form method="GET" action="">
                    <select name="barangay" required>
                        <option value="">Select a Barangay</option>
                        <?php foreach ($barangays as $brgy): ?>
                            <option value="<?= htmlspecialchars($brgy['barangay_name']) ?>" 
                                <?= $selected_barangay === $brgy['barangay_name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($brgy['barangay_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Analyze</button>
                </form>
            </div>

            <?php if (!empty($selected_barangay) && $barangay): ?>
                <h2 style="text-align: center; color: #1e3a8a; margin: 20px 0;">Analysis for: <?= htmlspecialchars($selected_barangay) ?></h2>
                
                <div class="summary">
                    <strong>Total Population:</strong> <?= $summary['total_population'] ?><br>
                    <strong>Total Households:</strong> <?= $summary['total_households'] ?><br>
                    <strong>Low Income Rate:</strong> <?= $summary['low_income_rate'] ?><br>
                    <strong>Waste Problem Rate:</strong> <?= $summary['waste_problem_rate'] ?><br>
                    <strong>Health Issue Rate:</strong> <?= $summary['health_issue_rate'] ?><br>
                    <strong>Youth Enrollment Rate:</strong> <?= $summary['youth_enrollment_rate'] ?><br>
                    <strong>Flood Prone:</strong> <?= $summary['flood_prone'] ?><br>
                    <strong>Average Rainfall:</strong> <?= $summary['avg_rainfall_mm'] ?> mm<br>
                    <strong>Average Temperature:</strong> <?= $summary['avg_temperature_c'] ?> °C<br>
                    <strong>Dengue Risk:</strong> <?= $summary['dengue_risk'] ?><br>
                    <strong>Flood Risk:</strong> <?= $summary['flood_risk'] ?><br>
                    <strong>Heat Risk:</strong> <?= $summary['heat_risk'] ?><br>
                    <strong>Drought Risk:</strong> <?= $summary['drought_risk'] ?><br>
                    <strong>Annual Budget:</strong> ₱<?= number_format($summary['annual_budget']) ?><br>
                </div>

                <div class="weather-card">
                    <h2>🌤️ Weather Risk Forecast</h2>
                    <h4>Weather-Based Monthly Risk Forecast</h4>
                    <div id="riskModal" class="modal">
                        <div class="modal-content">
                            <span class="close-btn">&times;</span>
                            <div class="modal-header">Risk Details</div>
                            <div class="risk-item"><strong>Rainfall:</strong> <span id="modalRainfall"></span> mm</div>
                            <div class="risk-item"><strong>Temperature:</strong> <span id="modalTemperature"></span> °C</div>
                            <div class="risk-item"><strong>Main Risk:</strong> <span id="modalMainRisk"></span></div>
                            <div class="risk-item"><strong>Recommendation:</strong> <span id="modalRecommendation"></span></div>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="weatherChart"></canvas>
                    </div>
                </div>

                <h2>Predicted Priority Events</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Score (%)</th>
                            <th>Approximate Budget (₱)</th>
                            <th>Reason</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        usort($predictions, fn($a, $b) => $b['score'] <=> $a['score']);
                        $annualBudget = (float)$summary['annual_budget'];

                        foreach ($predictions as $p):
                            $minPercent = 0.2;
                            $maxPercent = 0.6;
                            $approxBudget = round($annualBudget * ($minPercent + (($maxPercent - $minPercent) * ($p['score']/100))), -3);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($p['event']) ?></td>
                            <td><strong><?= $p['score'] ?>%</strong></td>
                            <td>₱<?= number_format($approxBudget) ?></td>
                            <td><?= htmlspecialchars($p['reason']) ?></td>
                            <td><?= htmlspecialchars($p['description']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($predictions)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center;">No priority events predicted for this barangay.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <script>
                // Weather chart data
                const monthlyLabels = ["Jan","Feb","Mar","Apr","May","Jun","Jul","Aug","Sep","Oct","Nov","Dec"];
                const rainfallData = <?= json_encode($weather_data['rainfall'] ?? [200, 250, 300, 400, 450, 500, 550, 480, 350, 250, 200, 150]) ?>;
                const temperatureData = <?= json_encode($weather_data['temperature'] ?? [30, 31, 33, 34, 35, 36, 35, 34, 33, 32, 31, 30]) ?>;

                // Risk levels as percentage
                const dengueRisk = [40, 50, 60, 70, 68, 65, 60, 55, 50, 45, 40, 35];
                const floodRisk = [30, 35, 40, 50, 61.8, 55, 50, 45, 40, 35, 30, 25];
                const heatRisk = [0, 10, 20, 25, 28.3, 30, 28, 25, 20, 15, 10, 5];
                const droughtRisk = [60, 55, 50, 40, 32, 30, 28, 25, 30, 35, 40, 45];

                const ctx = document.getElementById('weatherChart').getContext('2d');
                const weatherChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: monthlyLabels,
                        datasets: [
                            { label: 'Dengue/Flood Risk', data: dengueRisk, borderColor: '#e11d48', fill: true, tension: 0.4 },
                            { label: 'Heat Risk', data: heatRisk, borderColor: '#facc15', fill: true, tension: 0.4 },
                            { label: 'Drought Risk', data: droughtRisk, borderColor: '#0d9488', fill: true, tension: 0.4 }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { position: 'top' },
                            tooltip: { mode: 'index', intersect: false }
                        },
                        interaction: { mode: 'nearest', axis: 'x', intersect: false },
                        scales: {
                            y: { beginAtZero: true, max: 100, title: { display: true, text: 'Risk Level (%)' } }
                        },
                        onClick: (evt, elements) => {
                            if (!elements.length) return;
                            const idx = elements[0].index;

                            const modal = document.getElementById('riskModal');
                            document.getElementById('modalRainfall').innerText = rainfallData[idx];
                            document.getElementById('modalTemperature').innerText = temperatureData[idx];

                            // Decide main risk
                            const risks = [
                                {name: 'Dengue/Flood', value: dengueRisk[idx]},
                                {name: 'Heat', value: heatRisk[idx]},
                                {name: 'Drought', value: droughtRisk[idx]}
                            ];
                            risks.sort((a,b) => b.value - a.value);
                            document.getElementById('modalMainRisk').innerText = risks[0].name;
                            document.getElementById('modalRecommendation').innerText = 'Normal monitoring.';

                            modal.style.display = 'flex';
                        }
                    }
                });

                // Close modal
                document.querySelector('.close-btn').onclick = () => {
                    document.getElementById('riskModal').style.display = 'none';
                };
                window.onclick = (e) => {
                    if (e.target.id === 'riskModal') document.getElementById('riskModal').style.display = 'none';
                };
                </script>

            <?php elseif (!empty($selected_barangay)): ?>
                <div style="text-align:center; color:red; margin:20px;">
                    Barangay "<?= htmlspecialchars($selected_barangay) ?>" not found in the database.
                </div>
            <?php else: ?>
                <div style="text-align:center; margin:20px;">
                    Please select a barangay from the dropdown above to view predictive analytics.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>