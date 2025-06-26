<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

date_default_timezone_set('Asia/Kolkata');
include '../config/db_connection.php';
include '../includes/header.php';

if ($_SESSION['rank'] != 'SP') {
    header("Location: dashboard_".strtolower($_SESSION['rank']).".php");
    exit();
}

// Initialize variables
$sp_name = $_SESSION['admin_name'];

$admin_rank = $_SESSION['rank'];
$station_name = $_SESSION['station_name'];
$sub_division = ''; // SP sees all

// Handle date range filter - now applies to both availability and weekoff stats
// Use session to persist filter settings
if (isset($_GET['date_filter'])) {
    $_SESSION['sp_date_filter'] = $_GET['date_filter'];
    $_SESSION['sp_from_date'] = $_GET['from_date'] ?? date('Y-m-d');
    $_SESSION['sp_to_date'] = $_GET['to_date'] ?? $_SESSION['sp_from_date'];
}

// Initialize from session or defaults
$date_filter = $_SESSION['sp_date_filter'] ?? 'today';
$from_date = $_SESSION['sp_from_date'] ?? date('Y-m-d');
$to_date = $_SESSION['sp_to_date'] ?? $from_date;

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

// Get availability stats (using selected date range)
$availability_sql = "SELECT 
    SUM(CASE WHEN availability_status = 'available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN availability_status = 'not available' THEN 1 ELSE 0 END) as not_available
    FROM availability_info 
    WHERE availability_updated_date BETWEEN ? AND ?";
$stmt = $conn->prepare($availability_sql);
$availability_stats = ['available' => 0, 'not_available' => 0];
if ($stmt) {
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $availability_result = $stmt->get_result();
    if ($availability_result) {
        $availability_stats = $availability_result->fetch_assoc();
    }
    $stmt->close();
}

// Get weekoff stats based on date range
$weekoff_sql = "SELECT 
    COUNT(*) as all_count,
    SUM(CASE WHEN weekoff_status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN weekoff_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    SUM(CASE WHEN weekoff_status = 'pending' THEN 1 ELSE 0 END) as pending
    FROM weekoff_info 
    WHERE date BETWEEN ? AND ?";
    
$stmt = $conn->prepare($weekoff_sql);
$weekoff_stats = ['all' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];
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

// Calculate active count (using selected date range)
// First get all available officers in date range
$available_sql = "SELECT DISTINCT officer_id FROM availability_info 
                 WHERE availability_status = 'available'
                 AND availability_updated_date BETWEEN ? AND ?";
$stmt = $conn->prepare($available_sql);
$available_officers = [];
if ($stmt) {
    $stmt->bind_param("ss", $from_date, $to_date);
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
                        AND date BETWEEN ? AND ?";
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
                   ORDER BY a.name";
            $title = "Available Officers";
            $param_types = "ss";
            $params = [$from_date, $to_date];
            break;
            
        case 'not_available':
            $sql = "SELECT DISTINCT a.* FROM availability_info a
                   WHERE a.availability_status = 'not available'
                   AND a.availability_updated_date BETWEEN ? AND ?
                   ORDER BY a.name";
            $title = "Not Available Officers";
            $param_types = "ss";
            $params = [$from_date, $to_date];
            break;
            
        case 'weekoff':
            $sql = "SELECT DISTINCT a.* FROM availability_info a
                   JOIN weekoff_info w ON a.officer_id = w.officer_id
                   WHERE a.availability_status = 'available'
                   AND a.availability_updated_date BETWEEN ? AND ?
                   AND w.date BETWEEN ? AND ?
                   AND w.weekoff_status = 'approved'
                   ORDER BY a.name";
            $title = "Officers on Weekoff";
            $param_types = "ssss";
            $params = [$from_date, $to_date, $from_date, $to_date];
            break;
            
        case 'active':
            // Use the pre-calculated active officers list
            if (!empty($active_officers)) {
                $placeholders = implode(',', array_fill(0, count($active_officers), '?'));
                $sql = "SELECT DISTINCT a.* FROM availability_info a
                       WHERE a.officer_id IN ($placeholders)
                       AND a.availability_updated_date BETWEEN ? AND ?
                       ORDER BY a.name";
                $title = "Active Officers";
                $param_types = str_repeat("i", count($active_officers)) . "ss";
                $params = array_merge($active_officers, [$from_date, $to_date]);
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
               ORDER BY w.date DESC";
        $param_types = "ss";
        $params = [$from_date, $to_date];
    } else {
        $sql = "SELECT w.* FROM weekoff_info w
               WHERE w.weekoff_status = ?
               AND w.date BETWEEN ? AND ?
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
    <title>SP Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Custom SP Dashboard Styles */
        .dashboard {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
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


        .dashboard-header {
            margin-bottom: 20px;
        }
        
        .date-filter {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .date-filter form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .date-filter label {
            font-weight: 600;
            margin-right: 5px;
        }
        
        .date-filter select, 
        .date-filter input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .date-filter button {
            padding: 8px 15px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .date-filter button:hover {
            background: #2980b9;
        }
        
        .stats-row {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }
        
        .stats-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .stats-section h3 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .date-range {
            color: #7f8c8d;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .stat-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 1rem;
            color: #2c3e50;
        }
        
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
        }
        
        /* Status colors */
        .stat-card.available {
            background: #e8f8f5;
            border-left: 4px solid #2ecc71;
        }
        
        .stat-card.available .stat-value {
            color: #27ae60;
        }
        
        .stat-card.not-available {
            background: #fdedec;
            border-left: 4px solid #e74c3c;
        }
        
        .stat-card.not-available .stat-value {
            color: #e74c3c;
        }
        
        .stat-card.weekoff {
            background: #fef5e7;
            border-left: 4px solid #f39c12;
        }
        
        .stat-card.weekoff .stat-value {
            color: #f39c12;
        }
        
        .stat-card.active {
            background: #eaf2f8;
            border-left: 4px solid #3498db;
        }
        
        .stat-card.active .stat-value {
            color: #3498db;
        }
        
        .stat-card.all {
            border-left: 4px solid #7f8c8d;
        }
        
        .stat-card.approved {
            border-left: 4px solid #27ae60;
        }
        
        .stat-card.rejected {
            border-left: 4px solid #e74c3c;
        }
        
        .stat-card.pending {
            border-left: 4px solid #f39c12;
        }
        
        .stat-card-link {
            text-decoration: none;
            color: inherit;
        }
        
        .all-weekoffs-btn, .all-availability-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 10px;
            transition: background 0.2s;
        }
        
        .all-weekoffs-btn:hover, .all-availability-btn:hover {
            background: #2980b9;
        }
        
        .sub-divisions-container {
            margin-top: 30px;
        }
        
        .sub-divisions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .sub-divisions-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .sub-div-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid #3498db;
        }
        
        .sub-div-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .sub-div-header h4 {
            margin: 0;
            color: #2c3e50;
        }
        
        .weekoff-stats {
            font-size: 0.9rem;
        }
        
        .today-count {
            color: #e74c3c;
            font-weight: bold;
        }
        
        .stations-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .station-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .station-actions {
            display: flex;
            gap: 8px;
        }
        
        .view-btn, .today-btn, .officer-list-btn {
            padding: 5px 10px;
            font-size: 0.8rem;
            border-radius: 3px;
            text-decoration: none;
            transition: background 0.2s;
        }
        
        .view-btn {
            background: #3498db;
            color: white;
        }
        
        .today-btn {
            background: #e74c3c;
            color: white;
        }
        
        .officer-list-btn {
            background: #2ecc71;
            color: white;
        }
        
        .view-btn:hover {
            background: #2980b9;
        }
        
        .today-btn:hover {
            background: #c0392b;
        }
        
        .officer-list-btn:hover {
            background: #27ae60;
        }
        
        .details-table {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-top: 20px;
        }
        
        .details-table table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        .details-table th {
            background: #3498db;
            color: white;
            padding: 12px 15px;
            text-align: left;
        }
        
        .details-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
        }
        
        .details-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .status-available {
            color: #27ae60;
            background: #d5f5e3;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
        }
        
        .status-not-available {
            color: #e74c3c;
            background: #fadbd8;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
        }
        
        .status-weekoff {
            color: #f39c12;
            background: #fef5e7;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
        }
        
        .status-approved {
            color: #27ae60;
        }
        
        .status-rejected {
            color: #e74c3c;
        }
        
        .status-pending {
            color: #f39c12;
        }
        
        .back-btn {
            display: inline-block;
            padding: 8px 15px;
            background: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 15px;
            transition: background 0.2s;
        }
        
        .back-btn:hover {
            background: #7f8c8d;
        }
        
        .logout-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #e74c3c;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 30px;
            transition: background 0.2s;
        }
        
        .logout-btn:hover {
            background: #c0392b;
        }
        
        .filtered-results {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .filtered-results h3 {
            margin-top: 0;
            color: #2c3e50;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .date-filter form {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="dashboard-header">
            <h2>SP Dashboard - <?php echo htmlspecialchars($sp_name); ?></h2>
        </div>
        
        <!-- Date Filter (Applies to both availability and weekoff stats) -->
        <div class="date-filter">
            <form method="GET" action="" id="dateFilterForm">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter ?? '') ?>">
                <input type="hidden" name="weekoff_status" value="<?= htmlspecialchars($weekoff_status_filter ?? '') ?>">
                
                <div>
                    <label for="date_filter">Date Range:</label>
                    <select name="date_filter" id="date_filter" onchange="toggleCustomDates()">
                        <option value="today" <?= $date_filter == 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="custom" <?= $date_filter == 'custom' ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>
                
                <div id="custom_dates" style="display: <?= $date_filter == 'custom' ? 'flex' : 'none' ?>; gap: 15px;">
                    <div>
                        <label for="from_date">From:</label>
                        <input type="date" name="from_date" id="from_date" 
                               value="<?= htmlspecialchars($from_date) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div>
                        <label for="to_date">To:</label>
                        <input type="date" name="to_date" id="to_date" 
                               value="<?= htmlspecialchars($to_date) ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                
                <button type="submit" id="applyFilterBtn">Apply Filter</button>
            </form>
        </div>

        <?php if ($status_filter || $weekoff_status_filter): ?>
            <!-- Filtered Results Section -->
            <div class="filtered-results">
                <h3>
                    <?= $title ?>
                    <a href="dashboard_sp.php?date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="back-btn">
                        Back to Dashboard
                    </a>
                </h3>
                
                <?php if (!empty($officer_details)): ?>
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
                                        <?= ucfirst(str_replace('_', ' ', $status_filter)) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php elseif (!empty($weekoff_details)): ?>
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
                <?php else: ?>
                    <p>No records found matching your filters</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-container">
            <!-- Stats Container -->
            <div class="stats-row">
                <!-- Availability Stats -->
                <div class="stats-section">
                    <h3>Availability Status</h3>
                    <div class="date-range">
                        <?= $date_filter == 'today' ? 'Today' : date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date)) ?>
                    </div>
                    <div class="stats-grid">
                        <a href="?status=available&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                            <div class="stat-card available">
                                <h3>Available</h3>
                                <div class="stat-value"><?php echo $availability_stats['available'] ?? 0; ?></div>
                            </div>
                        </a>
                        
                        <a href="?status=not_available&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                            <div class="stat-card not-available">
                                <h3>Not Available</h3>
                                <div class="stat-value"><?php echo $availability_stats['not_available'] ?? 0; ?></div>
                            </div>
                        </a>
                        
                        <a href="?status=weekoff&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                            <div class="stat-card weekoff">
                                <h3>Weekoff</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['approved'] ?? 0; ?></div>
                            </div>
                        </a>
                        
                        <a href="?status=active&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                            <div class="stat-card active">
                                <h3>Active</h3>
                                <div class="stat-value"><?php echo $active_count; ?></div>
                            </div>
                        </a>
                    </div>
                    
                    <a href="view_availability.php?date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="all-availability-btn">
                        Show All Availability Status
                    </a>
                </div>
                
                <!-- Weekoff Stats -->
                <div class="stats-section">
                    <h3>Weekoff Status</h3>
                    <div class="date-range">
                        <?= $date_filter == 'today' ? 'Today' : date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date)) ?>
                    </div>
                    <div class="stats-grid">
                        <a href="?weekoff_status=all&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                            <div class="stat-card all">
                                <h3>All</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['all'] ?? 0; ?></div>
                            </div>
                        </a>
                        
                        <a href="?weekoff_status=approved&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                            <div class="stat-card approved">
                                <h3>Approved</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['approved'] ?? 0; ?></div>
                            </div>
                        </a>
                        
                        <a href="?weekoff_status=rejected&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                            <div class="stat-card rejected">
                                <h3>Rejected</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['rejected'] ?? 0; ?></div>
                            </div>
                        </a>
                        
                        <a href="?weekoff_status=pending&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="stat-card-link">
                            <div class="stat-card pending">
                                <h3>Pending</h3>
                                <div class="stat-value"><?php echo $weekoff_stats['pending'] ?? 0; ?></div>
                            </div>
                        </a>

<a href="upcoming_weekoffs.php" class="stat-card-link">
    <div class="stat-card upcoming">
        <div class="stat-icon"><i class="fas fa-calendar-plus"></i></div>
        <div class="stat-content">
            <h3>Upcoming Weekoffs</h3>
            <div class="stat-value">
                <?php echo getUpcomingWeekoffCount($conn, $_SESSION['rank'], $_SESSION['station_name'], $_SESSION['sub_division'] ?? ''); ?>
            </div>
        </div>
    </div>
</a>

                    </div>
                    
                    <a href="view_station_weekoffs.php?filter=all&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" class="all-weekoffs-btn">
                        Show All Weekoffs
                    </a>
                </div>
            </div>

            <!-- Sub-Divisions Section -->
            <?php if (!$status_filter && !$weekoff_status_filter): ?>
                <div class="sub-divisions-container">
                    <h3>All Sub-Divisions</h3>
                    <div class="sub-divisions-grid">
                        <?php
                        $sub_div_sql = "SELECT DISTINCT sub_division FROM admin_login 
                                       WHERE sub_division IS NOT NULL AND sub_division != '' 
                                       AND rank = 'DSP'";
                        $sub_div_result = $conn->query($sub_div_sql);
                        
                        if ($sub_div_result && $sub_div_result->num_rows > 0) {
                            while($sub_div_row = $sub_div_result->fetch_assoc()): 
                                $sub_division = $sub_div_row['sub_division'];
                                $stations_sql = "SELECT station_name FROM admin_login 
                                                 WHERE sub_division = ? 
                                                 AND rank = 'Inspector'";
                                $stmt = $conn->prepare($stations_sql);
                                $stations = [];
                                
                                if ($stmt) {
                                    $stmt->bind_param("s", $sub_division);
                                    $stmt->execute();
                                    $stations_result = $stmt->get_result();
                                    
                                    while($station_row = $stations_result->fetch_assoc()) {
                                        $stations[] = $station_row['station_name'];
                                    }
                                    $stmt->close();
                                }
                                
                                // Get weekoff count for this sub-division based on date range
                                $weekoff_sql = "SELECT COUNT(*) as count FROM weekoff_info 
                                               WHERE station_name IN (
                                                   SELECT station_name FROM admin_login 
                                                   WHERE sub_division = ?
                                               )
                                               AND date BETWEEN ? AND ?
                                               AND weekoff_status = 'approved'";
                                $stmt = $conn->prepare($weekoff_sql);
                                $weekoff_count = 0;
                                if ($stmt) {
                                    $stmt->bind_param("sss", $sub_division, $from_date, $to_date);
                                    $stmt->execute();
                                    $weekoff_result = $stmt->get_result();
                                    if ($weekoff_result->num_rows > 0) {
                                        $weekoff_count = $weekoff_result->fetch_assoc()['count'];
                                    }
                                    $stmt->close();
                                }
                        ?>
                            <div class="sub-div-card">
                                <div class="sub-div-header">
                                    <h4><?php echo htmlspecialchars($sub_division); ?></h4>
                                    <div class="weekoff-stats">
                                        <span class="today-count"><?php echo $weekoff_count; ?> weekoffs</span>
                                    </div>
                                </div>
                                <div class="stations-list">
                                    <?php foreach ($stations as $station): ?>
                                        <div class="station-item">
                                            <span><?php echo htmlspecialchars($station); ?></span>
                                            <div class="station-actions">
                                                <a href="view_station_weekoffs.php?station=<?php echo urlencode($station); ?>&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                                                   class="view-btn">View All</a>
                                                <a href="view_station_weekoffs.php?station=<?php echo urlencode($station); ?>&filter=today&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                                                   class="today-btn">Today</a>
                                                <a href="officer_list.php?station=<?php echo urlencode($station); ?>&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                                                   class="officer-list-btn">Officers</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endwhile;
                        } else {
                            echo "<p>No sub-divisions found in the system.</p>";
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <a href="../logout.php" class="logout-btn">Logout</a>
        </div>
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
</body>
</html>

<?php include '../includes/footer.php'; ?>