 
<?php
session_start();
if (!isset($_SESSION['officer_id'])) {
    header("Location: ../index.php");
    exit();
}

include '../config/db_connection.php';
include '../includes/header.php';

$officer_id = $_SESSION['officer_id'];

// Get filter from URL if set
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Build query based on filter
switch ($filter) {
    case 'approved':
        $sql = "SELECT * FROM weekoff_info 
                WHERE officer_id = $officer_id 
                AND weekoff_status = 'approved'
                ORDER BY date DESC";
        $title = "Your Approved Weekoffs";
        break;
    case 'pending':
        $sql = "SELECT * FROM weekoff_info 
                WHERE officer_id = $officer_id 
                AND weekoff_status = 'pending'
                ORDER BY date DESC";
        $title = "Your Pending Requests";
        break;
    case 'rejected':
        $sql = "SELECT * FROM weekoff_info 
                WHERE officer_id = $officer_id 
                AND weekoff_status = 'rejected'
                ORDER BY date DESC";
        $title = "Your Rejected Requests";
        break;
    default:
        $sql = "SELECT * FROM weekoff_info 
                WHERE officer_id = $officer_id 
                ORDER BY date DESC";
        $title = "All Your Weekoff Records";
        break;
}

$result = $conn->query($sql);
?>

<div class="weekoff-records">
    <h2><?php echo $title; ?></h2>
    
    <div class="filters">
        <a href="?filter=all" class="<?php echo $filter == 'all' ? 'active' : ''; ?>">All Records</a>
        <a href="?filter=approved" class="<?php echo $filter == 'approved' ? 'active' : ''; ?>">Approved</a>
        <a href="?filter=pending" class="<?php echo $filter == 'pending' ? 'active' : ''; ?>">Pending</a>
        <a href="?filter=rejected" class="<?php echo $filter == 'rejected' ? 'active' : ''; ?>">Rejected</a>
    </div>
    
    <?php if ($result->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Requested On</th>
                    <th>Processed On</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('d-M-Y', strtotime($row['date'])); ?></td>
                    <td class="status-<?php echo $row['weekoff_status']; ?>">
                        <?php echo ucfirst($row['weekoff_status']); ?>
                    </td>
                    <td><?php echo date('d-M-Y H:i', strtotime($row['request_time'])); ?></td>
                    <td>
                        <?php echo isset($row['approved_time']) ? date('d-M-Y H:i', strtotime($row['approved_time'])) : 'N/A'; ?>
                    </td>
                    <td><?php echo $row['remarks'] ?? 'N/A'; ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No records found</p>
    <?php endif; ?>
    
    <a href="dashboard.php" class="back-btn">Back to Dashboard</a>
</div>

<?php include '../includes/footer.php'; ?>