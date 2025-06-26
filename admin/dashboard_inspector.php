<?php
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['rank'] != 'Inspector') {
    header("Location: ../index.php");
    exit();
}

include '../config/db_connection.php';
include '../includes/header.php';


$admin_rank = $_SESSION['rank'];
$station_name = $_SESSION['station_name'];
$sub_division = ''; // Inspectors don't have sub-division

// Function to copy previous day's data to today
function copyPreviousDayData($conn, $station_name) {
    // Check if today's data already exists
    $check_sql = "SELECT COUNT(*) as count FROM availability_info 
                  WHERE availability_updated_date = CURDATE()
                  AND station_name = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("s", $station_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row['count'] == 0) {
        // Get yesterday's data for this station
        $yesterday_sql = "SELECT * FROM availability_info 
                         WHERE availability_updated_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                         AND station_name = ?";
        $stmt = $conn->prepare($yesterday_sql);
        $stmt->bind_param("s", $station_name);
        $stmt->execute();
        $yesterday_data = $stmt->get_result();
        $stmt->close();
        
        if ($yesterday_data->num_rows > 0) {
            // Insert yesterday's data for today
            $insert_sql = "INSERT INTO availability_info 
                          (officer_id, name, username, rank, station_name, sub_division, 
                          availability_status, availability_updated_date)
                          VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())";
            $stmt = $conn->prepare($insert_sql);
            
            while ($row = $yesterday_data->fetch_assoc()) {
                $stmt->bind_param("issssss", 
                    $row['officer_id'],
                    $row['name'],
                    $row['username'],
                    $row['rank'],
                    $row['station_name'],
                    $row['sub_division'],
                    $row['availability_status']);
                $stmt->execute();
            }
            $stmt->close();
        } else {
            // If no yesterday data, insert default 'available' for all officers
            $officers_sql = "SELECT id, name, username, rank, station_name FROM officer_login 
                             WHERE station_name = ?";
            $stmt = $conn->prepare($officers_sql);
            $stmt->bind_param("s", $station_name);
            $stmt->execute();
            $officers = $stmt->get_result();
            $stmt->close();
            
            // Get sub_division from admin table
            $sub_division_sql = "SELECT sub_division FROM admin_login 
                                WHERE station_name = ? LIMIT 1";
            $stmt = $conn->prepare($sub_division_sql);
            $stmt->bind_param("s", $station_name);
            $stmt->execute();
            $sub_result = $stmt->get_result();
            $sub_division = $sub_result->fetch_assoc()['sub_division'] ?? '';
            $stmt->close();
            
            $insert_sql = "INSERT INTO availability_info 
                          (officer_id, name, username, rank, station_name, sub_division, 
                          availability_status, availability_updated_date)
                          VALUES (?, ?, ?, ?, ?, ?, 'available', CURDATE())";
            $stmt = $conn->prepare($insert_sql);
            
            while ($officer = $officers->fetch_assoc()) {
                $stmt->bind_param("isssss", 
                    $officer['id'],
                    $officer['name'],
                    $officer['username'],
                    $officer['rank'],
                    $officer['station_name'],
                    $sub_division);
                $stmt->execute();
            }
            $stmt->close();
        }
    }
}

// Call the function to ensure today's data exists
copyPreviousDayData($conn, $station_name);

// Handle POST availability update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['availability'])) {
        foreach ($_POST['availability'] as $officer_id => $status) {
            $officer_id = (int)$officer_id;
            $status = $conn->real_escape_string($status);
            $remarks = isset($_POST['remarks'][$officer_id]) ? $conn->real_escape_string($_POST['remarks'][$officer_id]) : '';
            $to_station = isset($_POST['to_station'][$officer_id]) ? $conn->real_escape_string($_POST['to_station'][$officer_id]) : 'N/A';

            // Get officer details
            $officer_sql = "SELECT name, username, rank, station_name FROM officer_login WHERE id = ?";
            $stmt = $conn->prepare($officer_sql);
            $stmt->bind_param("i", $officer_id);
            $stmt->execute();
            $officer = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Check if record exists
            $check_sql = "SELECT id FROM availability_info 
                         WHERE officer_id = ? 
                         AND availability_updated_date = CURDATE()";
            $stmt = $conn->prepare($check_sql);
            $stmt->bind_param("i", $officer_id);
            $stmt->execute();
            $check_result = $stmt->get_result();
            $record_exists = $check_result->num_rows > 0;
            $stmt->close();

            if ($record_exists) {
                $update_sql = "UPDATE availability_info SET 
                               availability_status = ?,
                               remarks = ?,
                               to_station = ?
                               WHERE officer_id = ? 
                               AND availability_updated_date = CURDATE()";
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("sssi", $status, $remarks, $to_station, $officer_id);
                $stmt->execute();
                $stmt->close();
            } else {
                $insert_sql = "INSERT INTO availability_info 
                              (officer_id, name, username, rank, station_name, sub_division, 
                              availability_status, availability_updated_date, remarks, to_station)
                              VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)";
                $stmt = $conn->prepare($insert_sql);
                $stmt->bind_param("issssssss", 
                    $officer_id,
                    $officer['name'],
                    $officer['username'],
                    $officer['rank'],
                    $officer['station_name'],
                    $sub_division,
                    $status,
                    $remarks,
                    $to_station);
                $stmt->execute();
                $stmt->close();
            }

            // Handle OD records if status is 'not available' and remarks is 'OD'
            if ($status == 'not available' && $remarks == 'OD' && $to_station != 'N/A') {
                // Check if OD record already exists for today
                $check_od_sql = "SELECT id FROM od_records 
                                WHERE officer_id = ? 
                                AND od_start_date = CURDATE()";
                $stmt = $conn->prepare($check_od_sql);
                $stmt->bind_param("i", $officer_id);
                $stmt->execute();
                $od_exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if (!$od_exists) {
                    // Default OD period is 7 days
                    $od_end_date = date('Y-m-d', strtotime('+7 days'));
                    
                    $insert_od_sql = "INSERT INTO od_records 
                                     (officer_id, officer_name, mother_station, od_station, od_start_date, od_end_date)
                                     VALUES (?, ?, ?, ?, CURDATE(), ?)";
                    $stmt = $conn->prepare($insert_od_sql);
                    $stmt->bind_param("issss", 
                        $officer_id,
                        $officer['name'],
                        $officer['station_name'],
                        $to_station,
                        $od_end_date);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        $_SESSION['success'] = "Availability status updated successfully";
        header("Location: dashboard_inspector.php");
        exit();
    }
}


// Get availability stats
$availability_sql = "SELECT 
    SUM(CASE WHEN availability_status = 'available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN availability_status = 'not available' THEN 1 ELSE 0 END) as not_available
    FROM availability_info 
    WHERE availability_updated_date = CURDATE()
    AND station_name = '$station_name'";
$availability_result = $conn->query($availability_sql);
$availability_stats = $availability_result->fetch_assoc();

// Get weekoff count
$weekoff_sql = "SELECT COUNT(*) as weekoff_count FROM weekoff_info 
               WHERE date = CURDATE() 
               AND weekoff_status = 'approved'
               AND station_name = '$station_name'";
$weekoff_result = $conn->query($weekoff_sql);
$weekoff_count = $weekoff_result->fetch_assoc()['weekoff_count'];

// Calculate active count
$active_count = ($availability_stats['available'] ?? 0) - $weekoff_count;

// Get pending requests count
$pending_sql = "SELECT COUNT(*) as count FROM weekoff_info 
               WHERE station_name = '$station_name' 
               AND weekoff_status = 'pending'";
$pending_result = $conn->query($pending_sql);
$pending_count = $pending_result->fetch_assoc()['count'];

// Handle officer list filter
$status_filter = isset($_GET['status']) ? $_GET['status'] : null;
$officer_details = [];

if ($status_filter) {
    switch($status_filter) {
        case 'available':
            $sql = "SELECT a.* FROM availability_info a
                   WHERE a.availability_status = 'available'
                   AND a.availability_updated_date = CURDATE()
                   AND a.station_name = '$station_name'
                   ORDER BY a.name";
            $title = "Available Officers";
            break;
            
        case 'not_available':
            $sql = "SELECT a.* FROM availability_info a
                   WHERE a.availability_status = 'not available'
                   AND a.availability_updated_date = CURDATE()
                   AND a.station_name = '$station_name'
                   ORDER BY a.name";
            $title = "Not Available Officers";
            break;
            
        case 'weekoff':
            $sql = "SELECT a.*, 
                   (SELECT COUNT(*) FROM weekoff_info 
                    WHERE officer_id = a.officer_id 
                    AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                    AND weekoff_status = 'approved') as monthly_count,
                   (SELECT COUNT(*) FROM weekoff_info 
                    WHERE officer_id = a.officer_id 
                    AND weekoff_status = 'approved') as total_count
                   FROM availability_info a
                   JOIN weekoff_info w ON a.officer_id = w.officer_id
                   WHERE a.availability_status = 'available'
                   AND a.availability_updated_date = CURDATE()
                   AND a.station_name = '$station_name'
                   AND w.date = CURDATE()
                   AND w.weekoff_status = 'approved'
                   ORDER BY a.name";
            $title = "Officers on Weekoff";
            break;
            
        case 'active':
            $sql = "SELECT a.* FROM availability_info a
                   LEFT JOIN weekoff_info w ON a.officer_id = w.officer_id 
                       AND w.date = CURDATE() 
                       AND w.weekoff_status = 'approved'
                   WHERE a.availability_status = 'available'
                   AND a.availability_updated_date = CURDATE()
                   AND a.station_name = '$station_name'
                   AND w.id IS NULL
                   ORDER BY a.name";
            $title = "Active Officers";
            break;
    }
    
    $result = $conn->query($sql);
    $officer_details = $result->fetch_all(MYSQLI_ASSOC);
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
    <title>Inspector Dashboard</title>
    <style>
          .dashboard {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .dashboard-header h2 {
            margin: 0;
            color: #2c3e50;
        }
        
        .header-buttons {
            display: flex;
            gap: 10px;
        }
        
        .officer-list-btn {
            padding: 8px 15px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        .officer-list-btn:hover {
            background: #2980b9;
        }
        
        /* Officer Details Table */
        .officer-details-table {
            margin-bottom: 30px;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
        }
        
        .officer-details-table h3 {
            margin-top: 0;
            color: #2c3e50;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        
        .officer-details-table table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .officer-details-table th {
            background: #3498db;
            color: white;
            padding: 12px 15px;
            text-align: left;
        }
        
        .officer-details-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #ddd;
        }
        
        .officer-details-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .officer-details-table tr:hover {
            background: #f1f1f1;
        }
        
        /* Availability Stats */
        .availability-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            border-left: 4px solid;
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
            border-left-color: #2ecc71;
        }
        
        .stat-card.available .stat-value {
            color: #27ae60;
        }
        
        .stat-card.not-available {
            border-left-color: #e74c3c;
        }
        
        .stat-card.not-available .stat-value {
            color: #e74c3c;
        }
        
        .stat-card.weekoff {
            border-left-color: #f39c12;
        }
        
        .stat-card.weekoff .stat-value {
            color: #f39c12;
        }
        
        .stat-card.active {
            border-left-color: #3498db;
        }
        
        .stat-card.active .stat-value {
            color: #3498db;
        }
        
        .stat-card-link {
            text-decoration: none;
            color: inherit;
        }
        
        /* Inspector Dashboard Content */
        .inspector-dashboard {
            margin-top: 20px;
        }
        
        .user-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .user-info p {
            margin: 8px 0;
            font-size: 1.05rem;
        }
        
        .user-info b {
            display: inline-block;
            width: 150px;
            color: #2c3e50;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .stat-header i {
            color: #3498db;
            font-size: 1.2rem;
        }
        
        .stat-header h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.1rem;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #3498db;
            margin: 5px 0;
        }
        
        .stat-date {
            color: #7f8c8d;
            font-size: 0.9rem;
        }
        
        .stat-action {
            display: inline-block;
            padding: 5px 10px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.9rem;
            margin-top: 10px;
            transition: background 0.2s;
        }
        
        .stat-action:hover {
            background: #2980b9;
        }
        
        /* Tables */
        .todays-weekoffs table, 
        .officer-details-table table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .todays-weekoffs th, 
        .officer-details-table th {
            background: #3498db;
            color: white;
            padding: 12px 15px;
            text-align: left;
        }
        
        .todays-weekoffs td, 
        .officer-details-table td {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
        }
        
        .todays-weekoffs tr:nth-child(even), 
        .officer-details-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .status-approved {
            color: #27ae60;
            background: #d5f5e3;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
        }
        
        .status-rejected {
            color: #e74c3c;
            background: #fadbd8;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
        }
        
        .status-pending {
            color: #f39c12;
            background: #fef5e7;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
        }
        
        /* Quick Actions */
        .quick-actions {
            margin: 20px 0;
        }
        
        .action-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        .action-btn:hover {
            background: #2980b9;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 6px;
            background: #e74c3c;
            color: white;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 5px;
        }
        
        /* Logout Button */
        .logout-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            margin-top: 20px;
            transition: background 0.2s;
            cursor: pointer;
        }
        
        .logout-btn:hover {
            background: #c0392b;
        }
        
        /* Success/Error Messages */
        .success-message {
            background: #d5f5e3;
            color: #27ae60;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .error-message {
            background: #fadbd8;
            color: #e74c3c;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .availability-stats {
                grid-template-columns: 1fr 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .user-info b {
                width: 120px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="dashboard-header">
            <h2>Inspector Dashboard - <?php echo htmlspecialchars($station_name); ?></h2>
            <div class="header-buttons">
                <a href="officer_list.php" class="officer-list-btn">Officer List</a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success-message"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <!-- Officer Details Table (shown when filtered) -->
        <?php if (!empty($officer_details)): ?>
            <div class="officer-details-table">
                <h3><?= $title ?></h3>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Officer Name</th>
                            <th>Rank</th>
                            <th>Status</th>
                            <?php if ($status_filter == 'weekoff'): ?>
                                <th>Monthly Count</th>
                                <th>Total Count</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $serial = 1; ?>
                        <?php foreach ($officer_details as $officer): ?>
                        <tr>
                            <td><?= $serial++ ?></td>
                            <td><?= htmlspecialchars($officer['name']) ?></td>
                            <td><?= htmlspecialchars($officer['rank']) ?></td>
                            <td class="status-<?= str_replace(' ', '-', strtolower($status_filter)) ?>">
                                <?= ucfirst(str_replace('_', ' ', $status_filter)) ?>
                            </td>
                            <?php if ($status_filter == 'weekoff'): ?>
                                <td><?= $officer['monthly_count'] ?? 0 ?></td>
                                <td><?= $officer['total_count'] ?? 0 ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <a href="dashboard_inspector.php" class="logout-btn">Back to Dashboard</a>
            </div>
        <?php else: ?>
            <!-- Inspector Dashboard Content -->
            <div class="inspector-dashboard">
                <div class="user-info">
                    <p><b>Name: </b><?php echo htmlspecialchars($_SESSION['admin_name']); ?></p>
                    <p><b>Rank: </b><?php echo htmlspecialchars($_SESSION['rank']); ?></p>
                    <p><b>Station: </b><?php echo htmlspecialchars($station_name); ?></p>
                </div>

                <!-- Availability Stats -->
                <div class="availability-stats">
                    <a href="?status=available" class="stat-card-link">
                        <div class="stat-card available">
                            <h3>Available</h3>
                            <div class="stat-value"><?php echo $availability_stats['available'] ?? 0; ?></div>
                        </div>
                    </a>
                    
                    <a href="?status=not_available" class="stat-card-link">
                        <div class="stat-card not-available">
                            <h3>Not Available</h3>
                            <div class="stat-value"><?php echo $availability_stats['not_available'] ?? 0; ?></div>
                        </div>
                    </a>
                    
                    <a href="?status=weekoff" class="stat-card-link">
                        <div class="stat-card weekoff">
                            <h3>Weekoff</h3>
                            <div class="stat-value"><?php echo $weekoff_count; ?></div>
                        </div>
                    </a>
                    
                    <a href="?status=active" class="stat-card-link">
                        <div class="stat-card active">
                            <h3>Active</h3>
                            <div class="stat-value"><?php echo $active_count; ?></div>
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
                
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-header">
                            <i class="fas fa-clock"></i>
                            <h3>Pending Requests</h3>
                        </div>
                        <div class="stat-value"><?php echo $pending_count; ?></div>
                        <a href="manage_requests.php" class="stat-action">Manage Requests</a>
                    </div>
                </div>
                
                <div class="todays-weekoffs">
                    <h3>Today's Approved Weekoffs (<?php echo date('d-M-Y'); ?>)</h3>
                    <?php
                    $todays_list_sql = "SELECT w.*, 
                                       (SELECT COUNT(*) FROM weekoff_info 
                                        WHERE officer_id = w.officer_id 
                                        AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                        AND weekoff_status = 'approved') as monthly_count,
                                       (SELECT COUNT(*) FROM weekoff_info 
                                        WHERE officer_id = w.officer_id 
                                        AND weekoff_status = 'approved') as total_count
                                       FROM weekoff_info w
                                       WHERE w.station_name = '$station_name' 
                                       AND w.date = CURDATE()
                                       AND w.weekoff_status = 'approved'
                                       ORDER BY w.approved_time DESC";
                    $todays_list_result = $conn->query($todays_list_sql);
                    ?>
                    
                    <?php if ($todays_list_result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Officer Name</th>
                                    <th>Rank</th>
                                    <th>Monthly Count</th>
                                    <th>Total Count</th>
                                    <th>Approved On</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $serial = 1; ?>
                                <?php while ($row = $todays_list_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $serial++ ?></td>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['rank']); ?></td>
                                    <td><?= $row['monthly_count'] ?? 0 ?></td>
                                    <td><?= $row['total_count'] ?? 0 ?></td>
                                    <td><?php echo $row['approved_time'] ? date('d-M-Y H:i', strtotime($row['approved_time'])) : 'N/A'; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>No weekoffs approved for today</p>
                    <?php endif; ?>
                </div>
              <div class="quick-actions">
    <a href="view_station_weekoffs.php" class="action-btn">View All Weekoffs</a>
    <a href="od_records.php" class="action-btn">View OD Records</a>
</div>
            </div>
        <?php endif; ?>
        
        <a href="../logout.php" class="logout-btn">Logout</a>
    </div>
</body>



</html>

<?php include '../includes/footer.php'; ?>