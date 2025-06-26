<?php
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['rank'] != 'Inspector') {
    header("Location: ../index.php");
    exit();
}

include '../config/db_connection.php';
include '../includes/header.php';

$status = isset($_GET['status']) ? $_GET['status'] : '';
$station_name = $_SESSION['station_name'];

// Get officers based on status
switch ($status) {
    case 'available':
        $sql = "SELECT a.* FROM availability_info a
               WHERE a.station_name = '$station_name'
               AND a.availability_updated_date = CURDATE()
               AND a.availability_status = 'available'
               ORDER BY a.name";
        $title = "Available Officers";
        break;
        
    case 'not_available':
        $sql = "SELECT a.* FROM availability_info a
               WHERE a.station_name = '$station_name'
               AND a.availability_updated_date = CURDATE()
               AND a.availability_status = 'not available'
               ORDER BY a.name";
        $title = "Not Available Officers";
        break;
        
    case 'weekoff':
        $sql = "SELECT a.* FROM availability_info a
               JOIN weekoff_info w ON a.officer_id = w.officer_id
               WHERE a.station_name = '$station_name'
               AND a.availability_updated_date = CURDATE()
               AND a.availability_status = 'available'
               AND w.date = CURDATE()
               AND w.weekoff_status = 'approved'
               ORDER BY a.name";
        $title = "Officers on Weekoff";
        break;
        
    case 'active':
        $sql = "SELECT a.* FROM availability_info a
               LEFT JOIN weekoff_info w ON a.officer_id = w.officer_id AND w.date = CURDATE() AND w.weekoff_status = 'approved'
               WHERE a.station_name = '$station_name'
               AND a.availability_updated_date = CURDATE()
               AND a.availability_status = 'available'
               AND w.id IS NULL
               ORDER BY a.name";
        $title = "Active Officers";
        break;
        
    default:
        header("Location: dashboard.php");
        exit();
}

$result = $conn->query($sql);
?>

<div class="officers-list">
    <h2><?php echo $title; ?> - <?php echo $station_name; ?></h2>
    <p>Showing officers as of <?php echo date('d-M-Y'); ?></p>
    
    <a href="dashboard.php" class="back-btn">Back to Dashboard</a>
    
    <?php if ($result->num_rows > 0): ?>
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
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $serial++; ?></td>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo htmlspecialchars($row['rank']); ?></td>
                    <td><?php echo htmlspecialchars($row['station_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['sub_division'] ?? 'N/A'); ?></td>
                    <td class="status-<?php echo $status == 'weekoff' ? 'weekoff' : ($status == 'not_available' ? 'not-available' : 'available'); ?>">
                        <?php 
                        echo $status == 'weekoff' ? 'Weekoff' : 
                             ($status == 'not_available' ? 'Not Available' : 'Available'); 
                        ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No officers found in this category</p>
    <?php endif; ?>
</div>

<style>
.officers-list {
    max-width: 1000px;
    margin: 20px auto;
    padding: 20px;
    background: #fff;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
}

.back-btn {
    display: inline-block;
    padding: 10px 20px;
    background: #95a5a6;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    margin-bottom: 20px;
    transition: background 0.2s;
}

.back-btn:hover {
    background: #7f8c8d;
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

.status-weekoff {
    color: #f39c12;
    background-color: #fef5e7;
    padding: 3px 8px;
    border-radius: 3px;
}

@media (max-width: 768px) {
    table {
        display: block;
        overflow-x: auto;
    }
}
</style>

<?php include '../includes/footer.php'; ?>