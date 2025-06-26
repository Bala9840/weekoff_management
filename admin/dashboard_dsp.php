<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

date_default_timezone_set('Asia/Kolkata');
include '../config/db_connection.php';
include '../includes/header.php';

if ($_SESSION['rank'] != 'DSP') {
    header("Location: dashboard_" . strtolower($_SESSION['rank']) . ".php");
    exit();
}

// Initialize variables

$admin_rank = $_SESSION['rank'];
$station_name = $_SESSION['station_name'];


$dsp_name = $_SESSION['admin_name'];
$dsp_station = '';
$sub_division = '';
$availability_stats = ['available' => 0, 'not_available' => 0];
$weekoff_stats = ['all' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];
$active_count = 0;

// Handle date range filter - now applies to both availability and weekoff stats
// Use session to persist filter settings
if (isset($_GET['date_filter'])) {
    $_SESSION['dsp_date_filter'] = $_GET['date_filter'];
    $_SESSION['dsp_from_date'] = $_GET['from_date'] ?? date('Y-m-d');
    $_SESSION['dsp_to_date'] = $_GET['to_date'] ?? $_SESSION['dsp_from_date'];
}

// Initialize from session or defaults
$date_filter = $_SESSION['dsp_date_filter'] ?? 'today';
$from_date = $_SESSION['dsp_from_date'] ?? date('Y-m-d');
$to_date = $_SESSION['dsp_to_date'] ?? $from_date;

// Validate and set dates
if ($date_filter == 'custom' && !empty($from_date)) {
    // Ensure to_date is not before from_date
    if (strtotime($to_date) < strtotime($from_date)) {
        $to_date = $from_date;
    }
} else {
    $date_filter = 'today';
    $from_date = $to_date = date('Y-m-d');
}

// Get DSP's information
$dsp_info_sql = "SELECT station_name, sub_division FROM admin_login WHERE id = ?";
$stmt = $conn->prepare($dsp_info_sql);
if ($stmt) {
    $stmt->bind_param("i", $_SESSION['admin_id']);
    $stmt->execute();
    $dsp_info_result = $stmt->get_result();

    if ($dsp_info_result->num_rows > 0) {
        $dsp_info = $dsp_info_result->fetch_assoc();
        $dsp_station = $dsp_info['station_name'] ?? '';
        $sub_division = $dsp_info['sub_division'] ?? '';
    }
    $stmt->close();
}

// Get availability stats for sub-division (using selected date range)
$availability_sql = "SELECT 
    SUM(CASE WHEN a.availability_status = 'available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN a.availability_status = 'not available' THEN 1 ELSE 0 END) as not_available
    FROM availability_info a
    WHERE a.availability_updated_date BETWEEN ? AND ?
    AND a.sub_division = ?";
$stmt = $conn->prepare($availability_sql);
if ($stmt) {
    $stmt->bind_param("sss", $from_date, $to_date, $sub_division);
    $stmt->execute();
    $availability_result = $stmt->get_result();
    if ($availability_result) {
        $availability_stats = $availability_result->fetch_assoc();
    }
    $stmt->close();
}

// Get all stations in the sub-division
$stations_sql = "SELECT station_name FROM admin_login WHERE sub_division = ? AND rank = 'Inspector'";
$stmt = $conn->prepare($stations_sql);
$stations = [];
if ($stmt) {
    $stmt->bind_param("s", $sub_division);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $stations[] = $row['station_name'];
    }
    $stmt->close();
}

// Prepare station list for SQL query
$station_list = "'" . implode("','", $stations) . "'";

// Get weekoff stats for the sub-division based on date range
$weekoff_sql = "SELECT 
    COUNT(*) as all_count,
    SUM(CASE WHEN weekoff_status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN weekoff_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN weekoff_status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM weekoff_info 
    WHERE station_name IN ($station_list)
    AND date BETWEEN ? AND ?";
    
$stmt = $conn->prepare($weekoff_sql);
if ($stmt) {
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $weekoff_result = $stmt->get_result();
    if ($weekoff_result) {
        $weekoff_data = $weekoff_result->fetch_assoc();
        $weekoff_stats = [
            'all' => $weekoff_data['all_count'] ?? 0,
            'approved' => $weekoff_data['approved'] ?? 0,
            'rejected' => $weekoff_data['rejected'] ?? 0,
            'pending' => $weekoff_data['pending'] ?? 0
        ];
    }
    $stmt->close();
}

// Calculate active count (available officers minus those on approved weekoff)
// First get all available officers in date range
$available_sql = "SELECT DISTINCT officer_id FROM availability_info 
                 WHERE availability_status = 'available'
                 AND availability_updated_date BETWEEN ? AND ?
                 AND sub_division = ?";
$stmt = $conn->prepare($available_sql);
$available_officers = [];
if ($stmt) {
    $stmt->bind_param("sss", $from_date, $to_date, $sub_division);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $available_officers[] = $row['officer_id'];
    }
    $stmt->close();
}

// Then get all officers with approved weekoffs in date range
$weekoff_officers_sql = "SELECT DISTINCT officer_id FROM weekoff_info 
                        WHERE weekoff_status = 'approved'
                        AND date BETWEEN ? AND ?
                        AND station_name IN ($station_list)";
$stmt = $conn->prepare($weekoff_officers_sql);
$weekoff_officers = [];
if ($stmt) {
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $weekoff_officers[] = $row['officer_id'];
    }
    $stmt->close();
}

// Active officers are those available but not on weekoff
$active_officers = array_diff($available_officers, $weekoff_officers);
$active_count = count($active_officers);

// Handle officer list filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : null;
$weekoff_status_filter = isset($_GET['weekoff_status']) ? $_GET['weekoff_status'] : null;
$officer_details = [];
$weekoff_details = [];

if ($status_filter) {
    switch($status_filter) {
        case 'available':
            $sql = "SELECT DISTINCT a.* FROM availability_info a
                   WHERE a.availability_status = 'available'
                   AND a.availability_updated_date BETWEEN ? AND ?
                   AND a.sub_division = ?
                   ORDER BY a.name";
            $title = "Available Officers";
            $param_types = "sss";
            $params = [$from_date, $to_date, $sub_division];
            break;
            
        case 'not_available':
            $sql = "SELECT DISTINCT a.* FROM availability_info a
                   WHERE a.availability_status = 'not available'
                   AND a.availability_updated_date BETWEEN ? AND ?
                   AND a.sub_division = ?
                   ORDER BY a.name";
            $title = "Not Available Officers";
            $param_types = "sss";
            $params = [$from_date, $to_date, $sub_division];
            break;
            
        case 'weekoff':
            $sql = "SELECT DISTINCT a.* FROM availability_info a
                   JOIN weekoff_info w ON a.officer_id = w.officer_id
                   WHERE a.availability_status = 'available'
                   AND a.availability_updated_date BETWEEN ? AND ?
                   AND a.sub_division = ?
                   AND w.date BETWEEN ? AND ?
                   AND w.weekoff_status = 'approved'
                   ORDER BY a.name";
            $title = "Officers on Weekoff";
            $param_types = "sssss";
            $params = [$from_date, $to_date, $sub_division, $from_date, $to_date];
            break;
            
        case 'active':
            // Use the pre-calculated active officers list
            if (!empty($active_officers)) {
                $placeholders = implode(',', array_fill(0, count($active_officers), '?'));
                $sql = "SELECT DISTINCT a.* FROM availability_info a
                       WHERE a.officer_id IN ($placeholders)
                       AND a.availability_updated_date BETWEEN ? AND ?
                       AND a.sub_division = ?
                       ORDER BY a.name";
                $title = "Active Officers";
                $param_types = str_repeat("i", count($active_officers)) . "sss";
                $params = array_merge($active_officers, [$from_date, $to_date, $sub_division]);
            }
            break;
    }
    
    if (isset($sql)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $officer_details = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
} elseif ($weekoff_status_filter) {
    // Weekoff status filter
    if ($weekoff_status_filter == 'all') {
        $sql = "SELECT w.* FROM weekoff_info w
               WHERE w.date BETWEEN ? AND ?
               AND w.station_name IN ($station_list)
               ORDER BY w.date DESC";
        $param_types = "ss";
        $params = [$from_date, $to_date];
    } else {
        $sql = "SELECT w.* FROM weekoff_info w
               WHERE w.weekoff_status = ?
               AND w.date BETWEEN ? AND ?
               AND w.station_name IN ($station_list)
               ORDER BY w.date DESC";
        $param_types = "sss";
        $params = [$weekoff_status_filter, $from_date, $to_date];
    }
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $weekoff_details = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    $title = ucfirst($weekoff_status_filter) . " Weekoffs";
}

function getUpcomingWeekoffCount($conn, $rank, $station_name, $sub_division) {
    $today = date('Y-m-d');
    $next_week = date('Y-m-d', strtotime('+7 days'));
    
    $where_clauses = [
        "date BETWEEN '$today' AND '$next_week'", 
        "date != '$today'", // Exclude today's date
        "weekoff_status = 'approved'"
    ];
    
    if ($rank == 'DSP') {
        $where_clauses[] = "station_name IN (SELECT station_name FROM admin_login WHERE sub_division = '$sub_division')";
    } elseif ($rank == 'Inspector') {
        $where_clauses[] = "station_name = '$station_name'";
    }
    
    $sql = "SELECT COUNT(*) as count FROM weekoff_info WHERE " . implode(" AND ", $where_clauses);
    $result = $conn->query($sql);
    
    return $result ? $result->fetch_assoc()['count'] : 0;
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>DSP Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Base Styles */
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #1abc9c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --gray-color: #95a5a6;
            --white-color: #ffffff;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
            --transition: all 0.3s ease;
        }


        /* Add to stat card colors */
.upcoming {
    background-color: #9b59b6;
}

/* Style for date picker */
.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.form-group input[type="date"] {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 16px;
}


        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .dashboard {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #fff;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }

        /* User Info */
        .user-info {
            background-color: var(--white-color);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 25px;
            text-align: center;
        }

        .user-info h2 {
            color: var(--dark-color);
            margin-bottom: 10px;
        }

        .user-info p {
            margin: 5px 0;
            color: var(--gray-color);
        }

        /* Date Filter */
        .date-filter {
            background-color: var(--white-color);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 25px;
        }

        .filter-group {
            margin-bottom: 15px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: var(--dark-color);
        }

        .filter-group select, 
        .filter-group input[type="date"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 16px;
        }

        .custom-date-range {
            display: flex;
            gap: 15px;
            margin-top: 15px;
        }

        .apply-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
        }

        .apply-btn:hover {
            background-color: var(--secondary-color);
        }

        /* Stats Container */
        .stats-container {
            margin-bottom: 30px;
        }

        .stats-section {
            background-color: var(--white-color);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 25px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .section-header h3 {
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-range {
            color: var(--gray-color);
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        .stat-card-link {
            text-decoration: none;
        }

        .stat-card {
            padding: 20px;
            border-radius: var(--border-radius);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 15px;
            height: 100%;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            font-size: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.3);
        }

        .stat-content h3 {
            margin-bottom: 5px;
            font-size: 16px;
            color: var(--white-color);
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--white-color);
        }

        /* Stat Card Colors */
        .available {
            background-color: var(--success-color);
        }

        .not-available {
            background-color: var(--danger-color);
        }

        .weekoff {
            background-color: var(--warning-color);
        }

        .active {
            background-color: var(--info-color);
        }

        .all {
            background-color: var(--gray-color);
        }

        .approved {
            background-color: var(--success-color);
        }

        .rejected {
            background-color: var(--danger-color);
        }

        .pending {
            background-color: var(--warning-color);
        }

        /* Quick Actions */
        .quick-actions {
            margin-bottom: 30px;
        }

        .action-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: var(--white-color);
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            text-decoration: none;
            color: var(--dark-color);
            font-weight: 600;
            transition: var(--transition);
        }

        .action-btn:hover {
            background-color: #f8f9fa;
            transform: translateY(-3px);
        }

        .action-btn i {
            margin-right: 10px;
            color: var(--primary-color);
        }

        .badge {
            background-color: var(--primary-color);
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 14px;
        }

        /* Zone Stations */
        .zone-stations {
            background-color: var(--white-color);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .zone-stations h3 {
            color: var(--dark-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stations-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .station-card {
            border: 1px solid #eee;
            border-radius: var(--border-radius);
            padding: 15px;
            transition: var(--transition);
        }

        .station-card:hover {
            box-shadow: var(--shadow);
        }

        .station-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .station-header h4 {
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .weekoff-stats {
            font-size: 14px;
        }

        .today-count {
            background-color: #f8f9fa;
            padding: 5px 10px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .station-actions {
            display: flex;
            gap: 10px;
        }

        .station-actions a {
            flex: 1;
            text-align: center;
            padding: 8px;
            border-radius: var(--border-radius);
            font-size: 14px;
            text-decoration: none;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .view-btn {
            background-color: #e3f2fd;
            color: var(--primary-color);
        }

        .today-btn {
            background-color: #fff8e1;
            color: var(--warning-color);
        }

        .officer-list-btn {
            background-color: #e8f5e9;
            color: var(--success-color);
        }

        .view-btn:hover, .today-btn:hover, .officer-list-btn:hover {
            opacity: 0.9;
        }

        .no-stations {
            text-align: center;
            color: var(--gray-color);
            padding: 20px;
        }

        /* Filtered Results Section */
        .filtered-results {
            background-color: var(--white-color);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .filtered-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .filtered-header h3 {
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn {
            background-color: var(--light-color);
            color: var(--dark-color);
            padding: 8px 15px;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-size: 14px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .back-btn:hover {
            background-color: #dfe6e9;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        table th {
            background-color: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: var(--dark-color);
        }

        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .status-available .status-badge {
            background-color: #d5f5e3;
            color: var(--success-color);
        }

        .status-not_available .status-badge {
            background-color: #fadbd8;
            color: var(--danger-color);
        }

        .status-weekoff .status-badge {
            background-color: #fdebd0;
            color: var(--warning-color);
        }

        .status-active .status-badge {
            background-color: #d0f2ff;
            color: var(--info-color);
        }

        .status-approved {
            color: var(--success-color);
        }

        .status-rejected {
            color: var(--danger-color);
        }

        .status-pending {
            color: var(--warning-color);
        }

        /* Logout Button */
        .logout-container {
            text-align: right;
            margin-top: 30px;
        }

        .logout-btn {
            background-color: var(--danger-color);
            color: white;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background-color: #c0392b;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stations-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .custom-date-range {
                flex-direction: column;
                gap: 10px;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .filtered-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .back-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="user-info">
            <h2>Welcome, <?php echo htmlspecialchars($dsp_name); ?></h2>
            <p><b>Sub-Division: </b><?php echo htmlspecialchars($sub_division); ?></p>
            <p><b>Station: </b><?php echo htmlspecialchars($dsp_station); ?></p>
        </div>

        <!-- Date Range Filter -->
        <div class="date-filter">
            <form method="GET" action="" id="dateFilterForm">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter ?? '') ?>">
                <input type="hidden" name="weekoff_status" value="<?= htmlspecialchars($weekoff_status_filter ?? '') ?>">
                
                <div class="filter-group">
                    <label for="date_filter">Date Range:</label>
                    <select name="date_filter" id="date_filter" onchange="toggleCustomDates()">
                        <option value="today" <?= $date_filter == 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="custom" <?= $date_filter == 'custom' ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>
                
                <div id="custom_dates" class="custom-date-range" style="display: <?= $date_filter == 'custom' ? 'flex' : 'none' ?>;">
                    <div class="filter-group">
                        <label for="from_date">From:</label>
                        <input type="date" name="from_date" id="from_date" value="<?= $from_date ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="filter-group">
                        <label for="to_date">To:</label>
                        <input type="date" name="to_date" id="to_date" value="<?= $to_date ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                
                <button type="submit" class="apply-btn">Apply Filter</button>
            </form>
        </div>

        <?php if ($status_filter || $weekoff_status_filter): ?>
            <!-- Filtered Results Section -->
            <div class="filtered-results">
                <div class="filtered-header">
                    <h3><i class="fas fa-filter"></i> <?= $title ?></h3>
                    <a href="dashboard_dsp.php?date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
                
                <?php if (!empty($officer_details)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Officer Name</th>
                                    <th>Rank</th>
                                    <th>Station</th>
                                    <th>Sub-Division</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $serial = 1; ?>
                                <?php foreach ($officer_details as $officer): ?>
                                    <tr>
                                        <td><?= $serial++ ?></td>
                                        <td><?= htmlspecialchars($officer['name']) ?></td>
                                        <td><?= htmlspecialchars($officer['rank']) ?></td>
                                        <td><?= htmlspecialchars($officer['station_name']) ?></td>
                                        <td><?= htmlspecialchars($officer['sub_division'] ?? 'N/A') ?></td>
                                        <td class="status-<?= str_replace('_', '-', $status_filter) ?>">
                                            <span class="status-badge"><?= ucfirst(str_replace('_', ' ', $status_filter)) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif (!empty($weekoff_details)): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Officer Name</th>
                                    <th>Rank</th>
                                    <th>Station</th>
                                    <th>Weekoff Date</th>
                                    <th>Status</th>
                                    <th>Requested On</th>
                                    <th>Processed On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $serial = 1; ?>
                                <?php foreach ($weekoff_details as $weekoff): ?>
                                    <tr>
                                        <td><?= $serial++ ?></td>
                                        <td><?= htmlspecialchars($weekoff['name']) ?></td>
                                        <td><?= htmlspecialchars($weekoff['rank']) ?></td>
                                        <td><?= htmlspecialchars($weekoff['station_name']) ?></td>
                                        <td><?= date('d-M-Y', strtotime($weekoff['date'])) ?></td>
                                        <td class="status-<?= $weekoff['weekoff_status']?>">
                                            <?= ucfirst($weekoff['weekoff_status']) ?>
                                        </td>
                                        <td><?= date('d-M-Y H:i', strtotime($weekoff['request_time'])) ?></td>
                                        <td><?= $weekoff['approved_time'] ? date('d-M-Y H:i', strtotime($weekoff['approved_time'])) : 'N/A' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No records found matching your filters</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Stats Container -->
        <div class="stats-container">
            <!-- Availability Stats -->
            <div class="stats-section availability-section">
                <div class="section-header">
                    <h3><i class="fas fa-user-check"></i> Availability Status</h3>
                    <div class="date-range">
                        <?= $date_filter == 'today' ? 'Today' : date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date)) ?>
                    </div>
                </div>
                <div class="stats-grid">
                    <a href="?status=available&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                        <div class="stat-card available">
                            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="stat-content">
                                <h3>Available</h3>
                                <div class="stat-value"><?php echo $availability_stats['available'] ?? 0; ?></div>
                            </div>
                        </div>
                    </a>
                    
                    <a href="?status=not_available&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                        <div class="stat-card not-available">
                            <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                            <div class="stat-content">
                                <h3>Not Available</h3>
                                <div class="stat-value"><?php echo $availability_stats['not_available'] ?? 0; ?></div>
                            </div>
                        </div>
                    </a>
                    
                    <a href="?status=weekoff&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                        <div class="stat-card weekoff">
                            <div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
                            <div class="stat-content">
                                <h3>Weekoff</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['approved'] ?? 0; ?></div>
                            </div>
                        </div>
                    </a>
                    
                    <a href="?status=active&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                        <div class="stat-card active">
                            <div class="stat-icon"><i class="fas fa-user-shield"></i></div>
                            <div class="stat-content">
                                <h3>Active</h3>
                                <div class="stat-value"><?php echo $active_count; ?></div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
            
            <!-- Weekoff Stats -->
            <div class="stats-section weekoff-section">
                <div class="section-header">
                    <h3><i class="fas fa-calendar-alt"></i> Weekoff Status</h3>
                    <div class="date-range"><?= $date_filter == 'today' ? 'Today' : date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date)) ?></div>
                </div>
                <div class="stats-grid">
                    <a href="?weekoff_status=all&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                        <div class="stat-card all">
                            <div class="stat-icon"><i class="fas fa-list"></i></div>
                            <div class="stat-content">
                                <h3>All</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['all'] ?? 0; ?></div>
                            </div>
                        </div>
                    </a>
                    
                    <a href="?weekoff_status=approved&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                        <div class="stat-card approved">
                            <div class="stat-icon"><i class="fas fa-check"></i></div>
                            <div class="stat-content">
                                <h3>Approved</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['approved'] ?? 0; ?></div>
                            </div>
                        </div>
                    </a>
                    
                    <a href="?weekoff_status=rejected&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                        <div class="stat-card rejected">
                            <div class="stat-icon"><i class="fas fa-times"></i></div>
                            <div class="stat-content">
                                <h3>Rejected</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['rejected'] ?? 0; ?></div>
                            </div>
                        </div>
                    </a>




<a href="upcoming_weekoffs.php?filter=sub_division&sub_division=<?= urlencode($sub_division) ?>" class="stat-card-link">
    <div class="stat-card upcoming">
        <div class="stat-icon"><i class="fas fa-calendar-plus"></i></div>
        <div class="stat-content">
            <h3>Upcoming Weekoffs</h3>
            <div class="stat-value">
                <?php echo getUpcomingWeekoffCount($conn, $admin_rank, $station_name, $sub_division); ?>
            </div>
        </div>
    </div>
</a>


                    
                    <a href="?weekoff_status=pending&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                        <div class="stat-card pending">
                            <div class="stat-icon"><i class="fas fa-clock"></i></div>
                            <div class="stat-content">
                                <h3>Pending</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['pending'] ?? 0; ?></div>
                            </div>
                        </div>
                    </a>
                    
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="view_station_weekoffs.php?filter=sub_division&sub_division=<?= urlencode($sub_division) ?>&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
               class="action-btn">
               <i class="fas fa-list-ol"></i> Show All Weekoffs in <?php echo htmlspecialchars($sub_division); ?> Sub-Division
               <span class="badge"><?php echo $weekoff_stats['all'] ?? 0; ?> Weekoff</span>
            </a>
        </div>

        <!-- Zone Stations -->
        <div class="zone-stations">
            <h3><i class="fas fa-map-marker-alt"></i> Stations in <?php echo htmlspecialchars($sub_division); ?> Sub-Division</h3>
            <?php
            $stations_sql = "SELECT station_name FROM admin_login 
                            WHERE sub_division = ? 
                            AND rank = 'Inspector'
                            ORDER BY station_name";
            $stmt = $conn->prepare($stations_sql);
            if ($stmt) {
                $stmt->bind_param("s", $sub_division);
                $stmt->execute();
                $stations_result = $stmt->get_result();

                if ($stations_result->num_rows > 0): ?>
                    <div class="stations-grid">
                        <?php while ($station_row = $stations_result->fetch_assoc()): 
                            $station = $station_row['station_name'];
                            $weekoff_sql = "SELECT COUNT(*) as count FROM weekoff_info 
                                           WHERE station_name = ? 
                                           AND date BETWEEN ? AND ?
                                           AND weekoff_status = 'approved'";
                            $stmt2 = $conn->prepare($weekoff_sql);
                            $station_weekoff_count = 0;
                            if ($stmt2) {
                                $stmt2->bind_param("sss", $station, $from_date, $to_date);
                                $stmt2->execute();
                                $weekoff_result = $stmt2->get_result();
                                if ($weekoff_result) {
                                    $station_weekoff_count = $weekoff_result->fetch_assoc()['count'] ?? 0;
                                }
                                $stmt2->close();
                            }
                        ?>
                            <div class="station-card">
                                <div class="station-header">
                                    <h4><i class="fas fa-building"></i> <?php echo htmlspecialchars($station); ?></h4>
                                    <div class="weekoff-stats">
                                        <span class="today-count"><i class="fas fa-calendar-day"></i> <?php echo $station_weekoff_count; ?> weekoffs</span>
                                    </div>
                                </div>
                                <div class="station-actions">
                                    <a href="view_station_weekoffs.php?station=<?php echo urlencode($station); ?>&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                                       class="view-btn"><i class="fas fa-eye"></i> View All</a>
                                    <a href="view_station_weekoffs.php?station=<?php echo urlencode($station); ?>&filter=today&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                                       class="today-btn"><i class="fas fa-calendar-day"></i> Today</a>
                                    <a href="officer_list.php?station=<?php echo urlencode($station); ?>&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                                       class="officer-list-btn"><i class="fas fa-users"></i> Officer List</a>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p class="no-stations">No stations found in <?php echo htmlspecialchars($sub_division); ?> sub-division.</p>
                <?php endif;
                $stmt->close();
            }
            ?>
        </div>
        
        <div class="logout-container">
            <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>

        <script>
            function toggleCustomDates() {
                const dateFilter = document.getElementById('date_filter');
                const customDates = document.getElementById('custom_dates');
                
                if (dateFilter.value === 'custom') {
                    customDates.style.display = 'flex';
                } else {
                    customDates.style.display = 'none';
                }
            }
            
            // Auto-submit form when date range changes
            document.addEventListener('DOMContentLoaded', function() {
                const dateFilterForm = document.getElementById('dateFilterForm');
                const dateFilterSelect = document.getElementById('date_filter');
                const fromDateInput = document.getElementById('from_date');
                const toDateInput = document.getElementById('to_date');
                
                // Set min/max dates and auto-submit
                if (fromDateInput && toDateInput) {
                    fromDateInput.addEventListener('change', function() {
                        toDateInput.min = this.value;
                        if (new Date(toDateInput.value) < new Date(this.value)) {
                            toDateInput.value = this.value;
                        }
                        dateFilterForm.submit();
                    });
                    
                    toDateInput.addEventListener('change', function() {
                        if (fromDateInput.value) {
                            dateFilterForm.submit();
                        }
                    });
                }
                
                // Auto-submit when date filter changes
                dateFilterSelect.addEventListener('change', function() {
                    dateFilterForm.submit();
                });
                
                // Preserve filter when page is refreshed
                const urlParams = new URLSearchParams(window.location.search);
                const dateFilter = urlParams.get('date_filter');
                
                if (dateFilter === 'custom') {
                    document.getElementById('date_filter').value = 'custom';
                    document.getElementById('custom_dates').style.display = 'flex';
                }
            });
        </script>
    </div>
</body>
</html>

<?php include '../includes/footer.php'; ?>