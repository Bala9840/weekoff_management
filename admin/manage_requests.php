<?php
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['rank'] != 'Inspector') {
    header("Location: ../index.php");
    exit();
}

// Define the root path
define('ROOT_PATH', dirname(__DIR__));

// Include database configuration
require_once ROOT_PATH . '/config/db_connection.php';

// Check if connection is established
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$station_name = $_SESSION['station_name'];

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['approve'])) {
        $request_id = $conn->real_escape_string($_POST['request_id']);
        $request_date = date('Y-m-d'); // Current date for the approval
        
        // Get officer_id and request date from the request
        $officer_sql = "SELECT officer_id, date FROM weekoff_info WHERE id = $request_id";
        $officer_result = $conn->query($officer_sql);
        
        if (!$officer_result) {
            $_SESSION['error'] = "Error getting officer information: " . $conn->error;
            header("Location: manage_requests.php");
            exit();
        }
        
        $officer_row = $officer_result->fetch_assoc();
        $officer_id = $officer_row['officer_id'];
        $weekoff_date = $officer_row['date'];
        
        // Get current month's first day
        $current_month_start = date('Y-m-01', strtotime($weekoff_date));
        
        // Get the most recent approved record for this officer to check counts
        $count_sql = "SELECT total_count, monthly_count, date 
                     FROM weekoff_info 
                     WHERE officer_id = $officer_id 
                     AND weekoff_status = 'approved'
                     ORDER BY date DESC LIMIT 1";
        $count_result = $conn->query($count_sql);
        
        if ($count_result->num_rows > 0) {
            $count_row = $count_result->fetch_assoc();
            $previous_date = $count_row['date'];
            $previous_month_start = date('Y-m-01', strtotime($previous_date));
            
            // Check if previous approval was in the same month
            if ($previous_month_start == $current_month_start) {
                // Same month - increment both counts
                $total_count = $count_row['total_count'] + 1;
                $monthly_count = $count_row['monthly_count'] + 1;
            } else {
                // New month - increment total, reset monthly
                $total_count = $count_row['total_count'] + 1;
                $monthly_count = 1;
            }
        } else {
            // First approval for this officer
            $total_count = 1;
            $monthly_count = 1;
        }
        
        // Update the request with approved status and counts
        $sql = "UPDATE weekoff_info 
               SET weekoff_status = 'approved', 
                   approved_time = NOW(), 
                   status = 'weekoff',
                   total_count = $total_count,
                   monthly_count = $monthly_count 
               WHERE id = $request_id 
               AND station_name = '$station_name'";
        
        if ($conn->query($sql)) {
            $_SESSION['success'] = "Request approved successfully";
        } else {
            $_SESSION['error'] = "Error approving request: " . $conn->error;
        }
    } 
    elseif (isset($_POST['reject'])) {
        $request_id = $conn->real_escape_string($_POST['request_id']);
        $remarks = $conn->real_escape_string($_POST['remarks']);
        
        $sql = "UPDATE weekoff_info 
               SET weekoff_status = 'rejected', 
                   approved_time = NOW(), 
                   remarks = '$remarks' 
               WHERE id = $request_id 
               AND station_name = '$station_name'";
        
        if ($conn->query($sql)) {
            $_SESSION['success'] = "Request rejected successfully";
        } else {
            $_SESSION['error'] = "Error rejecting request: " . $conn->error;
        }
    }
    header("Location: manage_requests.php");
    exit();
}

// Get pending requests for the inspector's station
$sql = "SELECT w.* FROM weekoff_info w
       LEFT JOIN availability_info a ON w.officer_id = a.officer_id 
           AND a.availability_updated_date = CURDATE()
       WHERE (w.station_name = '$station_name' OR a.to_station = '$station_name')
       AND w.weekoff_status = 'pending' 
       ORDER BY w.request_time ASC";
$result = $conn->query($sql);

// Check if query was successful
if ($result === false) {
    die("Error executing query: " . $conn->error);
}

// Determine the correct dashboard file based on user rank
$dashboard_file = '';
if ($_SESSION['rank'] == 'SP') {
    $dashboard_file = 'dashboard_sp.php';
} elseif ($_SESSION['rank'] == 'Inspector') {
    $dashboard_file = 'dashboard_inspector.php';
} elseif ($_SESSION['rank'] == 'DSP') {
    $dashboard_file = 'dashboard_dsp.php';
}

// Include header
include ROOT_PATH . '/includes/header.php';
?>


<div class="manage-requests">
      <a href="<?php echo htmlspecialchars($dashboard_file); ?>" class="back-btn">Back to Dashboard</a>
      <br>
      <br>
    <h2>Manage Week Off Requests - <?php echo htmlspecialchars($station_name); ?></h2>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="success-message"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error-message"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    
    <?php if ($result->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Officer Name</th>
                    <th>Rank</th>
                    <th>Request Date</th>
                    <th>Requested On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo htmlspecialchars($row['rank']); ?></td>
                    <td><?php echo date('d-M-Y', strtotime($row['date'])); ?></td>
                    <td><?php echo date('d-M-Y H:i', strtotime($row['request_time'])); ?></td>
                    <td class="actions">
                        <form method="POST" class="action-form">
                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($row['id']); ?>">
                            <button type="submit" name="approve" class="approve-btn">Approve</button>
                        </form>
                        <form method="POST" class="action-form">
                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($row['id']); ?>">
                            <input type="text" name="remarks" placeholder="Rejection reason" required>
                            <button type="submit" name="reject" class="reject-btn">Reject</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No pending requests</p>
    <?php endif; ?>
    
    <a href="<?php echo htmlspecialchars($dashboard_file); ?>" class="back-btn">Back to Dashboard</a>
</div>

<?php
// Include footer
include ROOT_PATH . '/includes/footer.php';
?>