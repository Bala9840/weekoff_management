<?php
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['rank'] != 'SP') {
    header("Location: ../index.php");
    exit();
}

include '../config/db_connection.php';
include '../includes/header.php';

// Get date range filter
$date_filter = $_GET['date_filter'] ?? 'today';
$from_date = $_GET['from_date'] ?? date('Y-m-d');
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// Validate and set dates
if ($date_filter == 'custom' && !empty($_GET['from_date'])) {
    $from_date = $_GET['from_date'];
    $to_date = $_GET['to_date'] ?? $from_date;
} else {
    $date_filter = 'today';
    $from_date = $to_date = date('Y-m-d');
}

// Get status filter
$status_filter = $_GET['status'] ?? 'all';

// Build query based on status filter
switch ($status_filter) {
    case 'available':
        $sql = "SELECT * FROM availability_info 
               WHERE availability_status = 'available'
               AND availability_updated_date BETWEEN ? AND ?
               ORDER BY availability_updated_date DESC";
        $title = "Available Officers";
        break;
        
    case 'not_available':
        $sql = "SELECT * FROM availability_info 
               WHERE availability_status = 'not available'
               AND availability_updated_date BETWEEN ? AND ?
               ORDER BY availability_updated_date DESC";
        $title = "Not Available Officers";
        break;
        
    default:
        $sql = "SELECT * FROM availability_info 
               WHERE availability_updated_date BETWEEN ? AND ?
               ORDER BY availability_updated_date DESC";
        $title = "All Availability Records";
        break;
}

$stmt = $conn->prepare($sql);
$availability_records = [];
if ($stmt) {
    $stmt->bind_param("ss", $from_date, $to_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $availability_records = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Availability Records</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .availability-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .status-filters {
            display: flex;
            gap: 10px;
        }
        
        .status-btn {
            padding: 8px 15px;
            background: #e0e0e0;
            color: #333;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .status-btn:hover {
            background: #d0d0d0;
        }
        
        .status-btn.active {
            background: #3498db;
            color: white;
            font-weight: 500;
        }
        
        .date-filter {
            background: #f5f5f5;
            padding: 10px 15px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .date-range {
            font-weight: 600;
            color: #555;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background-color: #3498db;
            color: white;
            font-weight: 600;
        }
        
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        tr:hover {
            background-color: #f1f1f1;
        }
        
        .status-available {
            color: #27ae60;
            background-color: #d5f5e3;
            padding: 3px 8px;
            border-radius: 3px;
        }
        
        .status-not-available {
            color: #e74c3c;
            background-color: #fadbd8;
            padding: 3px 8px;
            border-radius: 3px;
        }
        
        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
            transition: background 0.3s;
        }
        
        .back-btn:hover {
            background: #7f8c8d;
        }
        
        @media (max-width: 768px) {
            .header-actions {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .status-filters {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <div class="availability-container">
        <div class="header-actions">
            <h2><?php echo $title; ?></h2>
            
            <div class="status-filters">
                <a href="?status=all&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                   class="status-btn <?= $status_filter == 'all' ? 'active' : '' ?>">View All</a>
                <a href="?status=available&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                   class="status-btn <?= $status_filter == 'available' ? 'active' : '' ?>">Available</a>
                <a href="?status=not_available&date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
                   class="status-btn <?= $status_filter == 'not_available' ? 'active' : '' ?>">Not Available</a>
            </div>
        </div>
        
        <div class="date-filter">
            <span class="date-range">
                <?= $date_filter == 'today' ? 'Today' : date('d M Y', strtotime($from_date)) . ' to ' . date('d M Y', strtotime($to_date)) ?>
            </span>
            <a href="dashboard_sp.php?date_filter=<?= $date_filter ?>&from_date=<?= $from_date ?>&to_date=<?= $to_date ?>" 
               class="back-btn">Back to Dashboard</a>
        </div>
        
        <?php if (!empty($availability_records)): ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Officer Name</th>
                        <th>Rank</th>
                        <th>Station</th>
                        <th>Sub-Division</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $serial = 1; ?>
                    <?php foreach ($availability_records as $record): ?>
                    <tr>
                        <td><?= $serial++ ?></td>
                        <td><?= htmlspecialchars($record['name']) ?></td>
                        <td><?= htmlspecialchars($record['rank']) ?></td>
                        <td><?= htmlspecialchars($record['station_name']) ?></td>
                        <td><?= htmlspecialchars($record['sub_division'] ?? 'N/A') ?></td>
                        <td class="status-<?= $record['availability_status'] == 'available' ? 'available' : 'not-available' ?>">
                            <?= ucfirst($record['availability_status']) ?>
                        </td>
                        <td><?= date('d-M-Y', strtotime($record['availability_updated_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No availability records found for the selected filters</p>
        <?php endif; ?>
    </div>
</body>
</html>

<?php include '../includes/footer.php'; ?>