<?php
session_start();
if (!isset($_SESSION['officer_id'])) {
    header("Location: ../index.php");
    exit();
}

include '../config/db_connection.php';

// First check availability
$availability_sql = "SELECT availability_status FROM availability_info 
                    WHERE officer_id = ? 
                    AND availability_updated_date = CURDATE() 
                    ORDER BY created_at DESC LIMIT 1";
$stmt = $conn->prepare($availability_sql);
$stmt->bind_param("i", $_SESSION['officer_id']);
$stmt->execute();
$availability_result = $stmt->get_result();
$is_available = $availability_result->num_rows > 0 ? 
    ($availability_result->fetch_assoc()['availability_status'] == 'available') : true;

if (!$is_available) {
    $_SESSION['error'] = "You're marked as Not Available and cannot request weekoff";
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $officer_id = $_SESSION['officer_id'];
    $date = $conn->real_escape_string($_POST['weekoff_date']);
    $current_week = date('W');
    $current_year = date('Y');

    // Validate date is not in the past
    if (strtotime($date) < strtotime(date('Y-m-d'))) {
        $_SESSION['error'] = "You cannot request weekoff for past dates";
        header("Location: dashboard.php");
        exit();
    }

    // Check if already requested for this date
    $sql = "SELECT id FROM weekoff_info WHERE officer_id = $officer_id AND date = '$date'";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $_SESSION['error'] = "You have already requested weekoff for this date";
        header("Location: dashboard.php");
        exit();
    }
    
    // Check weekly limit (only count approved weekoffs)
    $weekly_sql = "SELECT COUNT(*) as weekly_count FROM weekoff_info 
                  WHERE officer_id = $officer_id 
                  AND YEARWEEK(date, 1) = YEARWEEK('$date', 1)
                  AND weekoff_status = 'approved'";
    $weekly_result = $conn->query($weekly_sql);
    $weekly_count = $weekly_result->fetch_assoc()['weekly_count'];
    
    if ($weekly_count >= 1) {
        $_SESSION['error'] = "You already have an approved week off this week (Sunday to Saturday)";
        header("Location: dashboard.php");
        exit();
    }
    
    // Get total weekoff count
    $total_sql = "SELECT total_count FROM weekoff_info 
                 WHERE officer_id = $officer_id 
                 AND weekoff_status = 'approved'
                 ORDER BY date DESC LIMIT 1";
    $total_result = $conn->query($total_sql);
    $total_count = $total_result->num_rows > 0 ? 
        $total_result->fetch_assoc()['total_count'] : 0;
    
    // Get officer details
    $officer_sql = "SELECT name, username, rank, station_name FROM officer_login WHERE id = $officer_id";
    $officer_result = $conn->query($officer_sql);
    $officer = $officer_result->fetch_assoc();
    
    // Insert request with updated counts
    $insert_sql = "INSERT INTO weekoff_info 
                  (officer_id, name, username, rank, station_name, date, 
                  request_time, weekoff_status, total_count) 
                  VALUES 
                  ($officer_id, '{$officer['name']}', '{$officer['username']}', '{$officer['rank']}', 
                  '{$officer['station_name']}', '$date', NOW(), 'pending', $total_count)";
    
    if ($conn->query($insert_sql)) {
        $_SESSION['success'] = "Weekoff request submitted successfully. Waiting for approval.";
    } else {
        $_SESSION['error'] = "Error submitting request: " . $conn->error;
    }
    
    header("Location: dashboard.php");
    exit();
} else {
    header("Location: dashboard.php");
    exit();
}
?>