<?php
// Clear all buffers and set headers
while (ob_get_level()) ob_end_clean();
ob_start();

session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

// DOMPDF LOADER
$dompdf_available = false;
$autoloader_worked = false;

// Attempt 1: Standard Composer autoload
$composer_autoload = __DIR__.'/../vendor/autoload.php';
if (file_exists($composer_autoload)) {
    try {
        require_once $composer_autoload;
        $autoloader_worked = true;
        $dompdf_available = class_exists('Dompdf\Dompdf');
    } catch (Throwable $e) {
        error_log("Composer autoload failed: ".$e->getMessage());
    }
}

// Attempt 2: Manual DomPDF load if Composer fails
if (!$autoloader_worked) {
    $dompdf_main = __DIR__.'/../vendor/dompdf/dompdf/src/Dompdf.php';
    if (file_exists($dompdf_main)) {
        require_once $dompdf_main;
        require_once __DIR__.'/../vendor/dompdf/dompdf/src/Options.php';
        require_once __DIR__.'/../vendor/dompdf/dompdf/src/Canvas.php';
        $dompdf_available = class_exists('Dompdf\Dompdf');
    }
}

if (!$dompdf_available) {
    error_log("Dompdf could not be loaded");
}

include '../config/db_connection.php';
include '../includes/header.php';

$admin_rank = $_SESSION['rank'];
$sub_division = isset($_SESSION['sub_division']) ? $_SESSION['sub_division'] : null;
$station_name = isset($_SESSION['station_name']) ? $_SESSION['station_name'] : null;

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] == 'pdf' && $dompdf_available) {
    exportWeekoffsToPDF($conn, $admin_rank, $sub_division, $station_name);
    exit();
}

// Get all filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$specific_station = isset($_GET['station']) ? $_GET['station'] : null;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;

// Build query based on admin rank and filters
$where_clauses = [];
$params = [];
$param_types = '';

// Role-based filtering
if ($admin_rank == 'SP') {
    if ($specific_station) {
        $where_clauses[] = "w.station_name = ?";
        $params[] = $conn->real_escape_string($specific_station);
        $param_types .= 's';
    }
} elseif ($admin_rank == 'DSP') {
    if (empty($sub_division)) {
        $sub_division_query = "SELECT sub_division FROM admin_login WHERE id = " . $_SESSION['admin_id'];
        $sub_division_result = $conn->query($sub_division_query);
        if ($sub_division_result->num_rows > 0) {
            $sub_division_row = $sub_division_result->fetch_assoc();
            $sub_division = $sub_division_row['sub_division'];
            $_SESSION['sub_division'] = $sub_division;
        }
    }

    $where_clauses[] = "a.sub_division = ?";
    $params[] = $conn->real_escape_string($sub_division);
    $param_types .= 's';
    
    if ($specific_station) {
        $where_clauses[] = "w.station_name = ?";
        $params[] = $conn->real_escape_string($specific_station);
        $param_types .= 's';
    }
} else { // Inspector
    $where_clauses[] = "w.station_name = ?";
    $params[] = $conn->real_escape_string($station_name);
    $param_types .= 's';
}

// Date filtering
if ($filter == 'today') {
    $where_clauses[] = "w.date = CURDATE()";
} elseif ($filter == 'date_range' && $date_from && $date_to) {
    $where_clauses[] = "w.date BETWEEN ? AND ?";
    $params[] = $conn->real_escape_string($date_from);
    $params[] = $conn->real_escape_string($date_to);
    $param_types .= 'ss';
}

// Status filtering
if ($status_filter != 'all') {
    $where_clauses[] = "w.weekoff_status = ?";
    $params[] = $conn->real_escape_string($status_filter);
    $param_types .= 's';
}

// Build final query
$sql = "SELECT w.*, a.sub_division FROM weekoff_info w
       LEFT JOIN admin_login a ON w.station_name = a.station_name";
       
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql .= " ORDER BY w.date DESC";

// Prepare and execute
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Set page title
$title = "Weekoff Records";
if ($admin_rank == 'SP') {
    $title = $specific_station ? "Weekoffs for " . htmlspecialchars($specific_station) : "All Stations Weekoffs";
} elseif ($admin_rank == 'DSP') {
    $title = $specific_station ? "Weekoffs for " . htmlspecialchars($specific_station) : "All Weekoffs in " . htmlspecialchars($sub_division) . " Sub-Division";
} else {
    if ($filter == 'today') {
        $title = "Today's Weekoffs at " . htmlspecialchars($station_name);
    } else {
        $title = "All Weekoffs at " . htmlspecialchars($station_name);
    }
}

// Add filters to title
if ($status_filter != 'all') {
    $title .= " (" . ucfirst($status_filter) . ")";
}
if ($filter == 'date_range' && $date_from && $date_to) {
    $title .= " (From " . date('d-M-Y', strtotime($date_from)) . " to " . date('d-M-Y', strtotime($date_to)) . ")";
} elseif ($filter == 'today') {
    $title .= " (" . date('d-M-Y') . ")";
}

// Determine back URL based on user role
$back_url = "dashboard_" . strtolower($admin_rank) . ".php";
if ($admin_rank == 'Inspector') {
    $back_url = "dashboard_inspector.php";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo $title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
        }

        .weekoff-records {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .filter-options {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
        }

        .status-filters {
            display: flex;
            gap: 10px;
        }

        .status-btn {
            padding: 8px 15px;
            background: #e0e0e0;
            color: #333;
            border-radius: 4px;
            text-decoration: none;
            transition: all 0.3s;
            font-size: 14px;
        }

        .status-btn:hover {
            background: #d0d0d0;
        }

        .status-btn.active {
            background: #3498db;
            color: white;
            font-weight: 500;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .date-range-group {
            background: #f5f5f5;
            padding: 8px 12px;
            border-radius: 4px;
        }

        .apply-btn {
            padding: 6px 12px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .apply-btn:hover {
            background: #2980b9;
        }

        select, input[type="date"] {
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        label {
            font-weight: 500;
            color: #555;
            font-size: 14px;
        }

        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 8px 15px;
            background: #e74c3c;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            transition: background 0.3s;
        }

        .export-btn:hover {
            background: #c0392b;
        }

        .export-disabled {
            color: #999;
            cursor: not-allowed;
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
        }

        .table-responsive {
            overflow-x: auto;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th {
            background-color: #3498db;
            color: white;
            padding: 12px 15px;
            text-align: left;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .status-approved {
            color: #27ae60;
            background-color: #d5f5e3;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
        }

        .status-rejected {
            color: #e74c3c;
            background-color: #fadbd8;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
        }

        .status-pending {
            color: #f39c12;
            background-color: #fef5e7;
            padding: 3px 8px;
            border-radius: 3px;
            display: inline-block;
        }

        .no-records {
            text-align: center;
            padding: 30px;
            color: #777;
        }

        .no-records ul {
            text-align: left;
            max-width: 500px;
            margin: 15px auto;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background-color: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s;
            margin-top: 20px;
        }

        .back-btn:hover {
            background-color: #2980b9;
        }

        @media (max-width: 768px) {
            .header-actions {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-options {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .status-filters {
                flex-wrap: wrap;
            }
            
            .filter-form {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .date-range-group {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="weekoff-records">
        <div class="header-actions">
            <h2><?php echo $title; ?></h2>
            
            <div class="filter-options">
                <!-- Status Toggle Buttons -->
                <div class="status-filters">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'all'])); ?>" 
                       class="status-btn <?php echo $status_filter == 'all' ? 'active' : ''; ?>">
                       View All
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'approved'])); ?>" 
                       class="status-btn <?php echo $status_filter == 'approved' ? 'active' : ''; ?>">
                       Approved
                    </a>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'rejected'])); ?>" 
                       class="status-btn <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>">
                       Rejected
                    </a>
                </div>

                <!-- Date Filter -->
                <form method="GET" class="filter-form">
                    <?php if (isset($_GET['station'])): ?>
                        <input type="hidden" name="station" value="<?php echo htmlspecialchars($_GET['station']); ?>">
                    <?php endif; ?>
                    <?php if (isset($_GET['sub_division'])): ?>
                        <input type="hidden" name="sub_division" value="<?php echo htmlspecialchars($_GET['sub_division']); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    
                    <div class="filter-group">
                        <label for="filter">Date Filter:</label>
                        <select name="filter" id="filter" onchange="this.form.submit()">
                            <option value="all" <?php echo $filter == 'all' ? 'selected' : ''; ?>>All Dates</option>
                            <option value="today" <?php echo $filter == 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="date_range" <?php echo $filter == 'date_range' ? 'selected' : ''; ?>>Date Range</option>
                        </select>
                    </div>
                    
                    <?php if ($filter == 'date_range'): ?>
                    <div class="filter-group date-range-group">
                        <label for="date_from">From:</label>
                        <input type="date" name="date_from" id="date_from" 
                               value="<?php echo isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : ''; ?>">
                        
                        <label for="date_to">To:</label>
                        <input type="date" name="date_to" id="date_to" 
                               value="<?php echo isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : ''; ?>">
                        
                        <button type="submit" class="apply-btn">Apply</button>
                    </div>
                    <?php endif; ?>
                </form>
                
                <?php if ($dompdf_available) : ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" class="export-btn">
                        <i class="fas fa-file-pdf"></i> Export as PDF
                    </a>
                <?php else : ?>
                    <span class="export-disabled" title="PDF export unavailable - DomPDF library not found">
                        <i class="fas fa-file-pdf"></i> PDF Export (Disabled)
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($result->num_rows > 0) : ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Officer Name</th>
                            <th>Rank</th>
                            <th>Station</th>
                            <th>Sub-Division</th>
                            <th>Weekoff Date</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>Requested On</th>
                            <th>Approved On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $result->fetch_assoc()) : ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['rank']); ?></td>
                            <td><?php echo htmlspecialchars($row['station_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['sub_division']); ?></td>
                            <td><?php echo date('d-M-Y', strtotime($row['date'])); ?></td>
                            <td class="status-<?php echo $row['weekoff_status']; ?>">
                                <?php echo ucfirst($row['weekoff_status']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['remarks']); ?></td>
                            <td><?php echo date('d-M-Y H:i', strtotime($row['request_time'])); ?></td>
                            <td><?php echo $row['approved_time'] ? date('d-M-Y H:i', strtotime($row['approved_time'])) : 'N/A'; ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <div class="no-records">
                <p>No weekoff records found matching your filters</p>
                <?php if ($admin_rank == 'DSP') : ?>
                    <p>Try checking if:</p>
                    <ul>
                        <li>Your sub-division has any stations assigned</li>
                        <li>Officers in your sub-division have requested weekoffs</li>
                        <li>There are approved weekoffs in your sub-division</li>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <a href="<?php echo $back_url; ?>" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Auto-submit date range when both dates are selected
        const dateFrom = document.getElementById('date_from');
        const dateTo = document.getElementById('date_to');
        
        if (dateFrom && dateTo) {
            dateFrom.addEventListener('change', function() {
                if (dateTo.value) {
                    document.querySelector('.filter-form').submit();
                }
            });
            
            dateTo.addEventListener('change', function() {
                if (dateFrom.value) {
                    document.querySelector('.filter-form').submit();
                }
            });
        }
    });
    </script>
</body>
</html>

<?php
function exportWeekoffsToPDF($conn, $admin_rank, $sub_division, $station_name) {
    try {
        // Clear all buffered output
        while (ob_get_level()) ob_end_clean();
        
        // Set proper headers first
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="weekoff_report_'.date('Y-m-d').'.pdf"');

        // Get all current filter parameters
        $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
        $status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
        $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : null;
        $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : null;
        $specific_station = isset($_GET['station']) ? $_GET['station'] : null;

        // Build query with the same filters as the current view
        $where_clauses = [];
        $params = [];
        $param_types = '';

        // Role-based filtering
        if ($admin_rank == 'SP') {
            if ($specific_station) {
                $where_clauses[] = "w.station_name = ?";
                $params[] = $conn->real_escape_string($specific_station);
                $param_types .= 's';
            }
        } elseif ($admin_rank == 'DSP') {
            $where_clauses[] = "a.sub_division = ?";
            $params[] = $conn->real_escape_string($sub_division);
            $param_types .= 's';
            
            if ($specific_station) {
                $where_clauses[] = "w.station_name = ?";
                $params[] = $conn->real_escape_string($specific_station);
                $param_types .= 's';
            }
        } else { // Inspector
            $where_clauses[] = "w.station_name = ?";
            $params[] = $conn->real_escape_string($station_name);
            $param_types .= 's';
        }

        // Date filtering
        if ($filter == 'today') {
            $where_clauses[] = "w.date = CURDATE()";
        } elseif ($filter == 'date_range' && $date_from && $date_to) {
            $where_clauses[] = "w.date BETWEEN ? AND ?";
            $params[] = $conn->real_escape_string($date_from);
            $params[] = $conn->real_escape_string($date_to);
            $param_types .= 'ss';
        }

        // Status filtering
        if ($status_filter != 'all') {
            $where_clauses[] = "w.weekoff_status = ?";
            $params[] = $conn->real_escape_string($status_filter);
            $param_types .= 's';

        }

        $sql = "SELECT w.name, w.rank, w.station_name, a.sub_division, w.date, w.weekoff_status, w.remarks, w.request_time, w.approved_time 
               FROM weekoff_info w
               LEFT JOIN admin_login a ON w.station_name = a.station_name";
               
        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(" AND ", $where_clauses);
        }

        $sql .= " ORDER BY w.date DESC";

        // Prepare and execute the query
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        // Generate HTML
        $html = generatePdfHtml($result, $admin_rank, $sub_division, $station_name, $filter, $status_filter, $date_from, $date_to);

        // Generate PDF
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => true,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'Helvetica',
            'tempDir' => sys_get_temp_dir().'/dompdf'
        ]);
        
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        // Direct output
        $dompdf->stream("weekoff_report_".date('Y-m-d').".pdf", [
            'Attachment' => 1  // Force download instead of preview
        ]);
        
        exit;

    } catch (Exception $e) {
        // Clean any output
        while (ob_get_level()) ob_end_clean();
        
        // Log error
        error_log("PDF Generation Error: ".$e->getMessage());
        
        // Display user-friendly message
        die("PDF generation failed. Error: ".$e->getMessage());
    }
}


function generatePdfHtml($result, $admin_rank, $sub_division, $station_name, $filter = 'all', $status_filter = 'all', $date_from = null, $date_to = null) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <style>
            body { font-family: Helvetica, Arial, sans-serif; margin: 1cm; }
            h1 { color: #2c3e50; text-align: center; margin-bottom: 0.5cm; }
            h2 { color: #3498db; text-align: center; margin-top: 0; }
            .filter-info { text-align: center; margin-bottom: 1cm; font-size: 12pt; color: #555; }
            table { width: 100%; border-collapse: collapse; margin-top: 1cm; }
            th { background-color: #3498db; color: white; padding: 8px; text-align: left; }
            td { padding: 6px; border: 1px solid #ddd; }
            .status-approved { color: #27ae60; }
            .status-rejected { color: #e74c3c; }
            .status-pending { color: #f39c12; }
            .footer { margin-top: 1cm; text-align: center; font-size: 10pt; color: #777; }
            .remarks { max-width: 200px; word-wrap: break-word; }
        </style>
    </head>
    <body>
        <h1>Tamil Nadu Police Weekoff Report</h1>
        <h2><?php echo getPdfTitle($admin_rank, $sub_division, $station_name); ?></h2>
        
        <div class="filter-info">
            <?php if ($status_filter != 'all'): ?>
                Showing: <?php echo ucfirst($status_filter); ?> weekoffs<br>
            <?php endif; ?>
            <?php if ($filter == 'today'): ?>
                Date: <?php echo date('d-M-Y'); ?>
            <?php elseif ($filter == 'date_range' && $date_from && $date_to): ?>
                Date Range: <?php echo date('d-M-Y', strtotime($date_from)); ?> to <?php echo date('d-M-Y', strtotime($date_to)); ?>
            <?php endif; ?>
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
                    <th>Status</th>
                    <th>Remarks</th>
                    <th>Requested On</th>
                    <th>Approved On</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['rank']) ?></td>
                    <td><?= htmlspecialchars($row['station_name']) ?></td>
                    <td><?= htmlspecialchars($row['sub_division']) ?></td>
                    <td><?= date('d-M-Y', strtotime($row['date'])) ?></td>
                    <td class="status-<?= $row['weekoff_status'] ?>">
                        <?= ucfirst($row['weekoff_status']) ?>
                    </td>
                    <td class="remarks" align="center">
                        <?= ($row['weekoff_status'] == 'approved') ? '-----' : htmlspecialchars($row['remarks']) ?>
                    </td>
                    <td><?= date('d-M-Y H:i', strtotime($row['request_time'])) ?></td>
                    <td><?= $row['approved_time'] ? date('d-M-Y H:i', strtotime($row['approved_time'])) : 'N/A' ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p>No weekoff records found matching the current filters</p>
        <?php endif; ?>
        
        <div class="footer">
            Generated on: <?= date('d-M-Y H:i:s') ?> | Weekoff Management System
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

function getPdfTitle($admin_rank, $sub_division, $station_name) {
    if ($admin_rank == 'SP') return "All Weekoffs - System Wide";
    if ($admin_rank == 'DSP') return "Weekoffs in $sub_division Sub-Division";
    return "Weekoffs at $station_name";
}

include '../includes/footer.php';
?>