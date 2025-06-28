<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

include '../config/db_connection.php';

// Get admin details
$admin_rank = $_SESSION['rank'];
$station_name = $_SESSION['station_name'];
$sub_division = isset($_SESSION['sub_division']) ? $_SESSION['sub_division'] : null;

// Check if filtering by sub-division (for DSP)
$filter_sub_division = isset($_GET['sub_division']) ? $_GET['sub_division'] : null;

// Date range for upcoming weekoffs (next 7 days, excluding today)
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));

// Build query based on admin rank
$where_clauses = [
    "w.date BETWEEN '$today' AND '$next_week'", 
    "w.date != '$today'", // Exclude today's date
    "w.weekoff_status = 'approved'"
];
$params = [];
$param_types = '';

if ($admin_rank == 'DSP' || $filter_sub_division) {
    // If filtering by sub-division or DSP viewing
    $sub_div = $filter_sub_division ?: $sub_division;
    $where_clauses[] = "w.station_name IN (
        SELECT station_name FROM admin_login 
        WHERE sub_division = ? AND rank = 'Inspector'
    )";
    $params[] = $sub_div;
    $param_types .= 's';
} elseif ($admin_rank == 'Inspector') {
    $where_clauses[] = "w.station_name = '$station_name'";
}
// SP will see all upcoming weekoffs

$sql = "SELECT w.*, a.sub_division FROM weekoff_info w
       LEFT JOIN admin_login a ON w.station_name = a.station_name
       WHERE " . implode(" AND ", $where_clauses) . "
       ORDER BY w.date ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$title = "Upcoming Weekoffs";
if ($admin_rank == 'DSP' || $filter_sub_division) {
    $title .= " in " . htmlspecialchars($filter_sub_division ?: $sub_division) . " Sub-Division";
} elseif ($admin_rank == 'Inspector') {
    $title .= " at " . htmlspecialchars($station_name);
}

// Include header
include '../includes/header.php';
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo $title; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .upcoming-weekoffs {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background-color: #3498db;
            color: white;
        }
        
        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }
        
        .back-btn:hover {
            background: #7f8c8d;
        }
        
        .date-range {
            font-size: 1.1em;
            color: #555;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="upcoming-weekoffs">
        <div class="header-actions">
            <h2><?php echo $title; ?></h2>
            <span class="date-range">
                <?php 
                $tomorrow = date('d-M-Y', strtotime('+1 day'));
                $seven_days_later = date('d-M-Y', strtotime('+7 days'));
                echo $tomorrow . " to " . $seven_days_later; 
                ?>
            </span>
        </div>
        
        <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Officer Name</th>
                        <th>Rank</th>
                        <th>Station</th>
                        <th>Sub-Division</th>
                        <th>Weekoff Date</th>
                        <th>Approved On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td><?php echo htmlspecialchars($row['rank']); ?></td>
                        <td><?php echo htmlspecialchars($row['station_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['sub_division']); ?></td>
                        <td><?php echo date('d-M-Y', strtotime($row['date'])); ?></td>
                        <td><?php echo date('d-M-Y H:i', strtotime($row['approved_time'])); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No upcoming weekoffs from <?php echo $tomorrow; ?> to <?php echo $seven_days_later; ?></p>
        <?php endif; ?>
        
        <a href="dashboard_<?php echo strtolower($admin_rank); ?>.php" class="back-btn">Back to Dashboard</a>
    </div>
</body>
</html>

<?php include '../includes/footer.php'; ?>