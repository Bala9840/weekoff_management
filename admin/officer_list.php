<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

// Database connection with error handling
$conn = null;
try {
    include '../config/db_connection.php';
    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    die("Error connecting to database: " . $e->getMessage());
}

include '../includes/header.php';

$admin_rank = $_SESSION['rank'];
$station_name = $_SESSION['station_name'];
$sub_division = $_SESSION['sub_division'] ?? '';

// Get station filter if provided
$station_filter = $_GET['station'] ?? null;

// Status filter
$status_filter = $_GET['status'] ?? 'all';

// Handle POST availability update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['availability'])) {
    foreach ($_POST['availability'] as $officer_id => $status) {
        $officer_id = (int)$officer_id;
        $status = $conn->real_escape_string($status);
        $remarks = isset($_POST['remarks'][$officer_id]) ? $conn->real_escape_string($_POST['remarks'][$officer_id]) : '';
        $to_station = isset($_POST['to_station'][$officer_id]) ? $conn->real_escape_string($_POST['to_station'][$officer_id]) : 'N/A';

        // Get officer details
        $officer_sql = "SELECT name, username, rank, station_name FROM officer_login WHERE id = ?";
        $stmt = $conn->prepare($officer_sql);
        if ($stmt === false) {
            die("Error preparing officer statement: " . $conn->error);
        }
        $stmt->bind_param("i", $officer_id);
        $stmt->execute();
        $officer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Update or insert availability info
        $check_sql = "SELECT id FROM availability_info 
                     WHERE officer_id = ? 
                     AND availability_updated_date = CURDATE()";
        $stmt = $conn->prepare($check_sql);
        if ($stmt === false) {
            die("Error preparing check statement: " . $conn->error);
        }
        $stmt->bind_param("i", $officer_id);
        $stmt->execute();
        $record_exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($record_exists) {
            $update_sql = "UPDATE availability_info SET 
                          availability_status = ?,
                          remarks = ?,
                          to_station = ?,
                          sub_division = ?
                          WHERE officer_id = ? 
                          AND availability_updated_date = CURDATE()";
            $stmt = $conn->prepare($update_sql);
            if ($stmt === false) {
                die("Error preparing update statement: " . $conn->error);
            }
            $stmt->bind_param("ssssi", $status, $remarks, $to_station, $sub_division, $officer_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $insert_sql = "INSERT INTO availability_info 
                          (officer_id, name, username, rank, station_name, sub_division, 
                          availability_status, availability_updated_date, remarks, to_station)
                          VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)";
            $stmt = $conn->prepare($insert_sql);
            if ($stmt === false) {
                die("Error preparing insert statement: " . $conn->error);
            }
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

        // Handle OD records
        if ($status == 'not available' && $remarks == 'OD' && $to_station != 'N/A') {
            // Check if active OD record exists
            $check_od_sql = "SELECT id FROM od_records 
                           WHERE officer_id = ? 
                           AND od_end_date IS NULL";
            $stmt = $conn->prepare($check_od_sql);
            if ($stmt === false) {
                die("Error preparing OD check statement: " . $conn->error);
            }
            $stmt->bind_param("i", $officer_id);
            $stmt->execute();
            $od_exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if (!$od_exists) {
                $insert_od_sql = "INSERT INTO od_records 
                                (officer_id, officer_name, mother_station, od_station, od_start_date)
                                VALUES (?, ?, ?, ?, CURDATE())";
                $stmt = $conn->prepare($insert_od_sql);
                if ($stmt === false) {
                    die("Error preparing OD insert statement: " . $conn->error);
                }
                $stmt->bind_param("isss", 
                    $officer_id,
                    $officer['name'],
                    $officer['station_name'],
                    $to_station);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($status == 'available') {
            // Complete any active OD records
            $complete_od_sql = "UPDATE od_records 
                              SET od_end_date = CURDATE() 
                              WHERE officer_id = ? 
                              AND od_end_date IS NULL";
            $stmt = $conn->prepare($complete_od_sql);
            if ($stmt === false) {
                die("Error preparing OD complete statement: " . $conn->error);
            }
            $stmt->bind_param("i", $officer_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    $_SESSION['success'] = "Availability status updated successfully";
    header("Location: officer_list.php" . ($station_filter ? "?station=" . urlencode($station_filter) : ""));
    exit();
}

// Get all stations for dropdown
$all_stations = [
    'KK', 'Nagercoil Zone', 'Aralvaimozhy(N5)', 'Asaripallam(N4)', 'AWPS NGL', 
    'Boothapandi(N6)', 'CCS', 'Control Room', 'Kottar(N1)', 'Nesamony Nagar(N3)',
    'TIW', 'Traffic Regulation wing', 'Vadasery(N2)', 'Thuckalay Zone', 
    'Awps Marthandam T11', 'Keeriparai T5', 'Kotticode T12', 'Kulasekaram T3',
    'Petchiparai T4', 'Thiruvattar T2', 'Thuckalay T1', 'Traffic Marthandam T15',
    'Traffic Thuckalay T14', 'Marthandam Zone', 'Arukani', 'Arumanai', 
    'Kadayalmoodu', 'Kalliyakavillai', 'Kollencode', 'Marthandam', 'Nithiravilai',
    'Palugal', 'Colachel Zone', 'AWPS Colachel-C10', 'Colachel-C1', 'Eraniel-C2',
    'Karungal-C6', 'Manavalakurichy-C3', 'Mondaicadu-C5', 'Puthukadai-C7',
    'Trafffic PS Colachel-C11', 'Vellichanthai-C4', 'Kanyakumari Zone',
    'Anjugramam PS', 'Eathamozhy PS', 'Kanyakumari PS', 'KK Awps', 'KK Traffic PS',
    'Rajakkamangalam PS', 'STKulam PS', 'Suchindram PS'
];

// Build query to get officers with their current availability status
$conditions = [];
$params = [];
$param_types = '';
$join = "";

if ($admin_rank === 'DSP') {
    $join = "JOIN admin_login al ON o.station_name = al.station_name";
    $conditions[] = "al.sub_division = ?";
    $params[] = $sub_division;
    $param_types .= 's';
} elseif ($admin_rank === 'Inspector') {
    $conditions[] = "o.station_name = ?";
    $params[] = $station_name;
    $param_types .= 's';
}

if ($station_filter) {
    $conditions[] = "o.station_name = ?";
    $params[] = $station_filter;
    $param_types .= 's';
}

$where_clause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$sql = "SELECT 
        o.*, 
        av.availability_status,
        av.sub_division,
        av.remarks,
        av.to_station,
        (
            SELECT COUNT(*) 
            FROM weekoff_info 
            WHERE officer_id = o.id 
            AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
            AND weekoff_status = 'approved'
        ) AS monthly_count,
        (
            SELECT COUNT(*) 
            FROM weekoff_info 
            WHERE officer_id = o.id 
            AND weekoff_status = 'approved'
        ) AS total_count
    FROM officer_login o
    LEFT JOIN availability_info av ON o.id = av.officer_id 
        AND av.availability_updated_date = CURDATE()
    $join
    $where_clause
    ORDER BY o.name";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Error preparing officer list statement: " . $conn->error);
}

if (!empty($params)) {
    if (!$stmt->bind_param($param_types, ...$params)) {
        die("Error binding parameters: " . $stmt->error);
    }
}

if (!$stmt->execute()) {
    die("Error executing statement: " . $stmt->error);
}

$result = $stmt->get_result();
$all_officers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Apply status filter if needed
$filtered_officers = $all_officers;
if ($status_filter !== 'all') {
    $filtered_officers = array_filter($all_officers, function($officer) use ($status_filter) {
        return ($officer['availability_status'] ?? 'available') === $status_filter;
    });
}

$has_officers = count($filtered_officers) > 0;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Officer List</title>
    <style>
        .officer-list-container {
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
        
        .status-filters {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }
        
        .status-filters a {
            padding: 8px 15px;
            border-radius: 4px;
            text-decoration: none;
            background: #e0e0e0;
            color: #333;
        }
        
        .status-filters a.active {
            background: #3498db;
            color: white;
        }
        
        .radio-group {
            display: flex;
            gap: 15px;
        }
        
        .radio-group label {
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }
        
        .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 15px;
        }
        
        .submit-btn {
            padding: 10px 20px;
            background-color: #2ecc71;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .submit-btn:hover {
            background-color: #27ae60;
        }
        
        .back-btn {
            padding: 10px 20px;
            background-color: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .back-btn:hover {
            background-color: #7f8c8d;
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
        }
        
        .status-not-available {
            color: #e74c3c;
        }
        
        select {
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            min-width: 120px;
        }
        
        select:disabled {
            background-color: #f5f5f5;
            color: #999;
        }
        
        @media (max-width: 768px) {
            .radio-group {
                flex-direction: column;
                gap: 5px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .submit-btn, .back-btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="officer-list-container">
        <h2>Officer List - <?= htmlspecialchars($station_filter ? $station_filter : $station_name); ?></h2>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="success-message"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="status-filters">
            <a href="?status=all<?= $station_filter ? '&station=' . urlencode($station_filter) : '' ?>" class="<?= $status_filter == 'all' ? 'active' : '' ?>">All</a>
            <a href="?status=available<?= $station_filter ? '&station=' . urlencode($station_filter) : '' ?>" class="<?= $status_filter == 'available' ? 'active' : '' ?>">Available</a>
            <a href="?status=not available<?= $station_filter ? '&station=' . urlencode($station_filter) : '' ?>" class="<?= $status_filter == 'not available' ? 'active' : '' ?>">Not Available</a>
        </div>

        <form method="POST" onsubmit="return validateForm()">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Rank</th>
                        <th>Station</th>
                        <th>Sub-Division</th>
                        <th>Monthly Weekoffs</th>
                        <th>Total Weekoffs</th>
                        <th>Availability Status</th>
                        <th>Remarks</th>
                        <th>OD To Station</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($has_officers): ?>
                        <?php $serial = 1; ?>
                        <?php foreach ($filtered_officers as $officer): 
                            $current_status = $officer['availability_status'] ?? 'available';
                            $current_remarks = $officer['remarks'] ?? '';
                            $current_to_station = $officer['to_station'] ?? 'N/A';
                        ?>
                            <tr>
                                <td><?= $serial++; ?></td>
                                <td><?= htmlspecialchars($officer['name']); ?></td>
                                <td><?= htmlspecialchars($officer['rank']); ?></td>
                                <td><?= htmlspecialchars($officer['station_name']); ?></td>
                                <td><?= htmlspecialchars($officer['sub_division'] ?? 'N/A'); ?></td>
                                <td><?= $officer['monthly_count']; ?></td>
                                <td><?= $officer['total_count']; ?></td>
                                <td>
                                    <div class="radio-group">
                                        <label>
                                            <input type="radio" name="availability[<?= $officer['id']; ?>]" 
                                                   value="available" <?= $current_status == 'available' ? 'checked' : ''; ?>
                                                   onchange="toggleRemarks(this, <?= $officer['id']; ?>)">
                                            <span class="status-available">Available</span>
                                        </label>
                                        <label>
                                            <input type="radio" name="availability[<?= $officer['id']; ?>]" 
                                                   value="not available" <?= $current_status == 'not available' ? 'checked' : ''; ?>
                                                   onchange="toggleRemarks(this, <?= $officer['id']; ?>)">
                                            <span class="status-not-available">Not Available</span>
                                        </label>
                                    </div>
                                </td>
                                <td>
                                    <select name="remarks[<?= $officer['id']; ?>]" 
                                            id="remarks_<?= $officer['id']; ?>" 
                                            onchange="toggleToStation(this, <?= $officer['id']; ?>)" 
                                            <?= $current_status != 'not available' ? 'disabled' : '' ?>>
                                        <option value="">Select</option>
                                        <option value="OD" <?= $current_remarks == 'OD' ? 'selected' : '' ?>>OD</option>
                                        <option value="EL" <?= $current_remarks == 'EL' ? 'selected' : '' ?>>EL</option>
                                        <option value="CL" <?= $current_remarks == 'CL' ? 'selected' : '' ?>>CL</option>
                                        <option value="ML" <?= $current_remarks == 'ML' ? 'selected' : '' ?>>ML</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="to_station[<?= $officer['id']; ?>]" 
                                            id="to_station_<?= $officer['id']; ?>" 
                                            <?= ($current_status != 'not available' || $current_remarks != 'OD') ? 'disabled' : '' ?>>
                                        <option value="N/A">N/A</option>
                                        <?php foreach ($all_stations as $station): ?>
                                            <?php if ($station != $officer['station_name']): ?>
                                                <option value="<?= htmlspecialchars($station); ?>"
                                                    <?= $current_to_station == $station ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($station); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="10">No officers found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($has_officers): ?>
                <div class="form-actions">
                    <button type="submit" class="submit-btn">Save Changes</button>
                    <a href="dashboard_<?= strtolower($admin_rank) ?>.php" class="back-btn">Back to Dashboard</a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <script>
        function toggleRemarks(radio, officerId) {
            const remarksSelect = document.getElementById(`remarks_${officerId}`);
            const toStationSelect = document.getElementById(`to_station_${officerId}`);
            
            if (radio.value === 'not available') {
                remarksSelect.disabled = false;
                // Reset values when changing to not available
                if (remarksSelect.value === '') {
                    toStationSelect.value = 'N/A';
                    toStationSelect.disabled = true;
                }
            } else {
                remarksSelect.disabled = true;
                toStationSelect.disabled = true;
            }
        }
        
        function toggleToStation(select, officerId) {
            const toStationSelect = document.getElementById(`to_station_${officerId}`);
            
            if (select.value === 'OD') {
                toStationSelect.disabled = false;
            } else {
                toStationSelect.disabled = true;
                toStationSelect.value = 'N/A';
            }
        }
        
        function validateForm() {
            let isValid = true;
            const errorMessages = [];
            
            document.querySelectorAll('input[type="radio"][value="not available"]:checked').forEach(radio => {
                const officerId = radio.name.match(/\[(\d+)\]/)[1];
                const remarksSelect = document.getElementById(`remarks_${officerId}`);
                const toStationSelect = document.getElementById(`to_station_${officerId}`);
                
                if (remarksSelect.value === '') {
                    isValid = false;
                    errorMessages.push(`Please select remarks for ${document.querySelector(`input[name="availability[${officerId}]"][value="not available"]`).parentNode.textContent.trim()}`);
                    remarksSelect.style.borderColor = 'red';
                } else {
                    remarksSelect.style.borderColor = '';
                }
                
                if (remarksSelect.value === 'OD' && toStationSelect.value === 'N/A') {
                    isValid = false;
                    errorMessages.push(`Please select OD station for ${document.querySelector(`input[name="availability[${officerId}]"][value="not available"]`).parentNode.textContent.trim()}`);
                    toStationSelect.style.borderColor = 'red';
                } else {
                    toStationSelect.style.borderColor = '';
                }
            });
            
            if (!isValid) {
                alert("Please fix the following errors:\n\n" + errorMessages.join("\n"));
                return false;
            }
            return true;
        }
        
        // Initialize form state on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[type="radio"]').forEach(radio => {
                const officerId = radio.name.match(/\[(\d+)\]/)[1];
                toggleRemarks(radio, officerId);
            });
            
            document.querySelectorAll('select[name^="remarks"]').forEach(select => {
                const officerId = select.name.match(/\[(\d+)\]/)[1];
                toggleToStation(select, officerId);
            });
        });
    </script>
</body>
</html>

<?php include '../includes/footer.php'; ?>