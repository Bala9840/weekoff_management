<?php
session_start();
if (!isset($_SESSION['officer_id'])) {
    header("Location: ../index.php");
    exit();
}

include '../config/db_connection.php';
include '../includes/header.php';

$officer_id = $_SESSION['officer_id'];
$today = date('Y-m-d');

// Check officer availability
$availability_sql = "SELECT availability_status FROM availability_info 
                    WHERE officer_id = ? 
                    AND availability_updated_date = CURDATE() 
                    ORDER BY created_at DESC LIMIT 1";
$stmt = $conn->prepare($availability_sql);
$stmt->bind_param("i", $officer_id);
$stmt->execute();
$availability_result = $stmt->get_result();
$is_available = $availability_result->num_rows > 0 ? 
    ($availability_result->fetch_assoc()['availability_status'] == 'available') : true;

// Handle weekoff request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_weekoff'])) {
    $request_date = $conn->real_escape_string($_POST['weekoff_date']);
    
    // Validate date is not in the past
    if (strtotime($request_date) < strtotime(date('Y-m-d'))) {
        $_SESSION['error'] = "You cannot request weekoff for past dates";
        header("Location: dashboard.php");
        exit();
    }

    // Check if already requested for this date
    $check_sql = "SELECT id, weekoff_status FROM weekoff_info 
                 WHERE officer_id = ? AND date = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("is", $officer_id, $request_date);
    $stmt->execute();
    $check_result = $stmt->get_result();

    if ($check_result->num_rows > 0) {
        $request = $check_result->fetch_assoc();
        if ($request['weekoff_status'] != 'rejected') {
            $_SESSION['error'] = "You have already requested weekoff for this date";
            header("Location: dashboard.php");
            exit();
        }
    }

    // Check weekly limit (only count approved weekoffs)
    $week_start = date('Y-m-d', strtotime('monday this week', strtotime($request_date)));
    $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($request_date)));
    
    $weekly_sql = "SELECT COUNT(*) as weekly_count FROM weekoff_info 
                  WHERE officer_id = ? 
                  AND date BETWEEN ? AND ?
                  AND weekoff_status = 'approved'";
    $stmt = $conn->prepare($weekly_sql);
    $stmt->bind_param("iss", $officer_id, $week_start, $week_end);
    $stmt->execute();
    $weekly_result = $stmt->get_result();
    $weekly_count = $weekly_result->fetch_assoc()['weekly_count'];
    
    if ($weekly_count >= 1) {
        $_SESSION['error'] = "You already have an approved week off this week (Sunday to Saturday)";
        header("Location: dashboard.php");
        exit();
    }
    
    // Get current month count
    $start_of_month = date('Y-m-01', strtotime($request_date));
    $month_count_sql = "SELECT COUNT(*) as monthly_count FROM weekoff_info 
                       WHERE officer_id = ? 
                       AND date >= ? 
                       AND weekoff_status = 'approved'";
    $stmt = $conn->prepare($month_count_sql);
    $stmt->bind_param("is", $officer_id, $start_of_month);
    $stmt->execute();
    $month_count_result = $stmt->get_result();
    $monthly_count = $month_count_result->fetch_assoc()['monthly_count'];

    // Get total count
    $total_sql = "SELECT COUNT(*) as total_count FROM weekoff_info 
                 WHERE officer_id = ? 
                 AND weekoff_status = 'approved'";
    $stmt = $conn->prepare($total_sql);
    $stmt->bind_param("i", $officer_id);
    $stmt->execute();
    $total_result = $stmt->get_result();
    $total_count = $total_result->fetch_assoc()['total_count'];

    // Get officer details
    $officer_sql = "SELECT name, username, rank, station_name FROM officer_login WHERE id = ?";
    $stmt = $conn->prepare($officer_sql);
    $stmt->bind_param("i", $officer_id);
    $stmt->execute();
    $officer_result = $stmt->get_result();
    $officer = $officer_result->fetch_assoc();

    // Insert weekoff request
    $insert_sql = "INSERT INTO weekoff_info 
                 (officer_id, name, username, rank, station_name, date, request_time, 
                  weekoff_status, monthly_count, total_count) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), 'pending', ?, ?)";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param("isssssii",
        $officer_id,
        $officer['name'],
        $officer['username'],
        $officer['rank'],
        $officer['station_name'],
        $request_date,
        $monthly_count,
        $total_count
    );

    if ($stmt->execute()) {
        $_SESSION['success'] = "Weekoff request submitted successfully for " . date('d-M-Y', strtotime($request_date)) . ". Waiting for approval.";
    } else {
        $_SESSION['error'] = "Error submitting request: " . $conn->error;
    }

    header("Location: dashboard.php");
    exit();
}

// Fetch latest count for display
$start_of_month = date('Y-m-01');
$count_sql = "SELECT 
               (SELECT COUNT(*) FROM weekoff_info WHERE officer_id = ? AND date >= ? AND weekoff_status = 'approved') AS monthly_count,
               (SELECT COUNT(*) FROM weekoff_info WHERE officer_id = ? AND weekoff_status = 'approved') AS total_count";
$stmt = $conn->prepare($count_sql);
$stmt->bind_param("isi", $officer_id, $start_of_month, $officer_id);
$stmt->execute();
$count_result = $stmt->get_result();
$counts = $count_result->fetch_assoc();
$monthly_count = $counts['monthly_count'];
$total_count = $counts['total_count'];

// Today's request check
$today_request_sql = "SELECT id, weekoff_status FROM weekoff_info 
                     WHERE officer_id = ? AND date = CURDATE()";
$stmt = $conn->prepare($today_request_sql);
$stmt->bind_param("i", $officer_id);
$stmt->execute();
$today_request_result = $stmt->get_result();
$has_requested_today = $today_request_result->num_rows > 0;
$today_request_status = $has_requested_today ? $today_request_result->fetch_assoc()['weekoff_status'] : null;

// Get pending requests
$pending_sql = "SELECT * FROM weekoff_info 
               WHERE officer_id = ? 
               AND weekoff_status = 'pending' 
               ORDER BY date DESC";
$stmt = $conn->prepare($pending_sql);
$stmt->bind_param("i", $officer_id);
$stmt->execute();
$pending_result = $stmt->get_result();

// Get last 5 approved/rejected requests
$status_sql = "SELECT * FROM weekoff_info 
              WHERE officer_id = ? 
              AND weekoff_status IN ('approved', 'rejected') 
              ORDER BY date DESC LIMIT 5";
$stmt = $conn->prepare($status_sql);
$stmt->bind_param("i", $officer_id);
$stmt->execute();
$status_result = $stmt->get_result();

// Get station count
$station_name = $_SESSION['station_name'];
$station_sql = "SELECT COUNT(*) as station_count FROM weekoff_info 
               WHERE station_name = ? 
               AND date = CURDATE() 
               AND weekoff_status = 'approved'";
$stmt = $conn->prepare($station_sql);
$stmt->bind_param("s", $station_name);
$stmt->execute();
$station_result = $stmt->get_result();
$station_count = $station_result->fetch_assoc()['station_count'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Officer Dashboard</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            color: #333;
        }
        
        .dashboard {
            max-width: 1000px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        
        h2 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        
        .user-info {
            background: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #3498db;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .user-info p {
            margin: 8px 0;
            font-size: 1.05rem;
        }
        
        .user-info b {
            display: inline-block;
            width: 150px;
        }
        
        .weekoff-request {
            margin-bottom: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .request-form {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .form-group input[type="date"] {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .request-btn {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            transition: background 0.2s;
            height: 40px;
            align-self: flex-end;
        }
        
        .request-btn:hover {
            background: #2980b9;
        }
        
        .request-btn:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }
        
        .alert {
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
        }
        
        .alert.not-available {
            background-color: #fadbd8;
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }
        
        .alert.info {
            background-color: #fef5e7;
            color: #f39c12;
            border-left: 4px solid #f39c12;
        }
        
        .status-available {
            color: #27ae60;
            font-weight: bold;
        }
        
        .status-not-available {
            color: #e74c3c;
            font-weight: bold;
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
        
        .status-approved {
            color: #27ae60;
            background-color: #d5f5e3;
            padding: 3px 8px;
            border-radius: 3px;
        }
        
        .status-rejected {
            color: #e74c3c;
            background-color: #fadbd8;
            padding: 3px 8px;
            border-radius: 3px;
        }
        
        .status-pending {
            color: #f39c12;
            background-color: #fef5e7;
            padding: 3px 8px;
            border-radius: 3px;
        }
        
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
        
        @media (max-width: 768px) {
            .user-info b {
                width: 120px;
            }
            
            .request-form {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .request-btn {
                width: 100%;
                align-self: stretch;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <h2>Officer Dashboard</h2>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success-message"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <div class="user-info">
            <p><b>Name: </b><?php echo htmlspecialchars($_SESSION['officer_name']); ?></p>
            <p><b>Rank: </b><?php echo htmlspecialchars($_SESSION['rank']); ?></p>
            <p><b>Station: </b><?php echo htmlspecialchars($_SESSION['station_name']); ?></p>
            <p><b>Monthly Weekoffs: </b><?php echo $monthly_count; ?></p>
            <p><b>Total Weekoffs: </b><?php echo $total_count; ?></p>
            <p><b>Availability Status: </b>
                <?php if ($is_available): ?>
                    <span class="status-available">Available</span>
                <?php else: ?>
                    <span class="status-not-available">Not Available</span>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="weekoff-request">
            <h3>Request Week Off</h3>
            <form action="dashboard.php" method="POST" class="request-form">
                <div class="form-group">
                    <label for="weekoff_date">Select Date:</label>
                    <input type="date" name="weekoff_date" id="weekoff_date" 
                           min="<?php echo date('Y-m-d'); ?>" 
                           value="<?php echo date('Y-m-d'); ?>"
                           required>
                </div>
                
                <?php if ($is_available): ?>
                    <?php if (!$has_requested_today || $today_request_status == 'rejected'): ?>
                        <button type="submit" name="request_weekoff" class="request-btn">
                            Request Weekoff
                        </button>
                        <?php if ($today_request_status == 'rejected'): ?>
                            <p class="alert info">Your previous request was rejected. You can request again.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <button type="button" class="request-btn" disabled>
                            Request Weekoff
                        </button>
                        <p class="alert info">You have already requested weekoff for today</p>
                    <?php endif; ?>
                <?php else: ?>
                    <button type="button" class="request-btn" disabled>
                        Request Weekoff
                    </button>
                    <p class="alert not-available">You're marked as Not Available and cannot request weekoff</p>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="pending-requests">
            <h3>Pending Requests</h3>
            <?php if ($pending_result->num_rows > 0): ?>
                <table>
                    <tr>
                        <th>Date</th>
                        <th>Request Time</th>
                        <th>Status</th>
                    </tr>
                    <?php while ($row = $pending_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('d-M-Y', strtotime($row['date'])); ?></td>
                        <td><?php echo date('d-M-Y H:i', strtotime($row['request_time'])); ?></td>
                        <td class="status-pending"><?php echo ucfirst($row['weekoff_status']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            <?php else: ?>
                <p>No pending requests</p>
            <?php endif; ?>
        </div>
        
        <div class="recent-status">
            <h3>Recent Requests Status</h3>
            <?php if ($status_result->num_rows > 0): ?>
                <table>
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Remarks</th>
                        <th>Processed On</th>
                    </tr>
                    <?php while ($row = $status_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('d-M-Y', strtotime($row['date'])); ?></td>
                        <td class="status-<?php echo $row['weekoff_status']; ?>">
                            <?php echo ucfirst($row['weekoff_status']); ?>
                        </td>
                        <td><?php echo $row['remarks'] ?? 'N/A'; ?></td>
                        <td><?php echo isset($row['approved_time']) ? date('d-M-Y H:i', strtotime($row['approved_time'])) : 'N/A'; ?></td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            <?php else: ?>
                <p>No recent requests</p>
            <?php endif; ?>
        </div>
        
        <form action="../logout.php" method="POST">
            <button type="submit" class="logout-btn">Logout</button>
        </form>
    </div>

    <script>
        // Set minimum date to today and prevent past dates
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.getElementById('weekoff_date');
            
            dateInput.addEventListener('change', function() {
                const selectedDate = new Date(this.value);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                
                if (selectedDate < today) {
                    alert('You cannot request weekoff for past dates');
                    this.value = today.toISOString().split('T')[0];
                }
            });
        });
    </script>
</body>
</html>

<?php include '../includes/footer.php'; ?>