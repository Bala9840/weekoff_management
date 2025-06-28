<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

include '../config/db_connection.php';
include '../includes/header.php';

$station_name = $_SESSION['station_name'];
$sub_division = $_SESSION['sub_division'] ?? '';

// Handle OD completion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['complete_od'])) {
    $od_id = (int)$_POST['od_id'];
    
    // Get OD record
    $od_sql = "SELECT * FROM od_records WHERE id = ?";
    $stmt = $conn->prepare($od_sql);
    $stmt->bind_param("i", $od_id);
    $stmt->execute();
    $od_record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($od_record) {
        // Update availability to available
        $update_sql = "UPDATE availability_info 
                      SET availability_status = 'available',
                          remarks = '',
                          to_station = 'N/A'
                      WHERE officer_id = ?
                      AND availability_updated_date = CURDATE()";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("i", $od_record['officer_id']);
        $stmt->execute();
        $stmt->close();

        // Mark OD as completed
        $complete_sql = "UPDATE od_records 
                        SET od_end_date = CURDATE()
                        WHERE id = ?";
        $stmt = $conn->prepare($complete_sql);
        $stmt->bind_param("i", $od_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['success'] = "OD completed successfully. Officer is now available at mother station.";
    } else {
        $_SESSION['error'] = "OD record not found";
    }
    
    header("Location: od_records.php");
    exit();
}

// Get active OD records - Updated query with weekoff counts
$od_sql = "SELECT o.*, a.availability_status, 
                  COALESCE(w.monthly_count, 0) AS monthly_count,
                  COALESCE(w.total_count, 0) AS total_count
          FROM od_records o
          LEFT JOIN availability_info a ON o.officer_id = a.officer_id 
              AND a.availability_updated_date = CURDATE()
          LEFT JOIN (
              SELECT officer_id, 
                     SUM(CASE WHEN date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND weekoff_status = 'approved' THEN 1 ELSE 0 END) AS monthly_count,
                     SUM(CASE WHEN weekoff_status = 'approved' THEN 1 ELSE 0 END) AS total_count
              FROM weekoff_info
              GROUP BY officer_id
          ) w ON o.officer_id = w.officer_id
          WHERE (o.mother_station = ? OR o.od_station = ?)
          AND o.od_end_date IS NULL
          ORDER BY o.od_start_date DESC";

$stmt = $conn->prepare($od_sql);
$stmt->bind_param("ss", $station_name, $station_name);
$stmt->execute();
$od_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get completed OD records (last 30 days) with weekoff counts
$completed_sql = "SELECT o.*, 
                         COALESCE(w.monthly_count, 0) AS monthly_count,
                         COALESCE(w.total_count, 0) AS total_count
                 FROM od_records o
                 LEFT JOIN (
                     SELECT officer_id, 
                            SUM(CASE WHEN date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND weekoff_status = 'approved' THEN 1 ELSE 0 END) AS monthly_count,
                            SUM(CASE WHEN weekoff_status = 'approved' THEN 1 ELSE 0 END) AS total_count
                     FROM weekoff_info
                     GROUP BY officer_id
                 ) w ON o.officer_id = w.officer_id
                 WHERE (o.mother_station = ? OR o.od_station = ?)
                 AND o.od_end_date IS NOT NULL
                 AND o.od_end_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY o.od_end_date DESC";
$stmt = $conn->prepare($completed_sql);
$stmt->bind_param("ss", $station_name, $station_name);
$stmt->execute();
$completed_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>On Duty Records</title>
    <style>
        .od-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .success-message {
            background-color: #d5f5e3;
            color: #27ae60;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .error-message {
            background-color: #fadbd8;
            color: #e74c3c;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .od-tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        
        .od-tab {
            padding: 10px 20px;
            cursor: pointer;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-bottom: none;
            margin-right: 5px;
            border-radius: 5px 5px 0 0;
        }
        
        .od-tab.active {
            background: #3498db;
            color: white;
        }
        
        .od-content {
            display: none;
        }
        
        .od-content.active {
            display: block;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
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
        
        .status-active {
            color: #27ae60;
        }
        
        .status-completed {
            color: #7f8c8d;
        }
        
        .complete-btn {
            padding: 5px 10px;
            background: #2ecc71;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
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
        
        .count-cell {
            text-align: center;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="od-container">
        <h2>On Duty Records - <?= htmlspecialchars($station_name) ?></h2>

        <a href="dashboard_inspector.php" class="back-btn">Back to Dashboard</a>
        <br>
        <br>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success-message"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <div class="od-tabs">
            <div class="od-tab active" onclick="showTab('active')">Active OD</div>
            <div class="od-tab" onclick="showTab('completed')">Completed OD</div>
        </div>
        
        <div id="active-tab" class="od-content active">
            <h3>Active On Duty Records</h3>
            
            <?php if (!empty($od_records)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Officer Name</th>
                            <th>Mother Station</th>
                            <th>OD Station</th>
                            <th>Start Date</th>
                            <th>Monthly Count</th>
                            <th>Total Count</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($od_records as $record): ?>
                        <tr>
                            <td><?= htmlspecialchars($record['officer_name']) ?></td>
                            <td><?= htmlspecialchars($record['mother_station']) ?></td>
                            <td><?= htmlspecialchars($record['od_station']) ?></td>
                            <td><?= date('d-M-Y', strtotime($record['od_start_date'])) ?></td>
                            <td class="count-cell"><?= $record['monthly_count'] ?></td>
                            <td class="count-cell"><?= $record['total_count'] ?></td>
                            <td class="status-active">Active</td>
                            <td>
                                <?php if ($record['mother_station'] == $station_name): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="od_id" value="<?= $record['id'] ?>">
                                        <button type="submit" name="complete_od" class="complete-btn">Complete OD</button>
                                    </form>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No active OD records found</p>
            <?php endif; ?>
        </div>
        
        <div id="completed-tab" class="od-content">
            <h3>Completed On Duty Records (Last 30 Days)</h3>
            
            <?php if (!empty($completed_records)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Officer Name</th>
                            <th>Mother Station</th>
                            <th>OD Station</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Monthly Count</th>
                            <th>Total Count</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($completed_records as $record): ?>
                        <tr>
                            <td><?= htmlspecialchars($record['officer_name']) ?></td>
                            <td><?= htmlspecialchars($record['mother_station']) ?></td>
                            <td><?= htmlspecialchars($record['od_station']) ?></td>
                            <td><?= date('d-M-Y', strtotime($record['od_start_date'])) ?></td>
                            <td><?= date('d-M-Y', strtotime($record['od_end_date'])) ?></td>
                            <td class="count-cell"><?= $record['monthly_count'] ?></td>
                            <td class="count-cell"><?= $record['total_count'] ?></td>
                            <td class="status-completed">Completed</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No completed OD records found</p>
            <?php endif; ?>
        </div>
        
        <a href="dashboard_inspector.php" class="back-btn">Back to Dashboard</a>
    </div>
    
    <script>
        function showTab(tabId) {
            document.querySelectorAll('.od-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.od-content').forEach(content => {
                content.classList.remove('active');
            });
            
            document.querySelector(`.od-tab[onclick="showTab('${tabId}')"]`).classList.add('active');
            document.getElementById(`${tabId}-tab`).classList.add('active');
        }
    </script>
</body>
</html>

<?php include '../includes/footer.php'; ?>