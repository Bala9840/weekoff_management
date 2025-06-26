<?php
session_start();
include 'config/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['officer_login'])) {
        $username = $conn->real_escape_string($_POST['officer_username']);
        $password = $conn->real_escape_string($_POST['officer_password']);
        
        $sql = "SELECT * FROM officer_login WHERE username = '$username'";
        $result = $conn->query($sql);
        
        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            if ($row['password'] === $password) {
                $_SESSION['officer_id'] = $row['id'];
                $_SESSION['officer_name'] = $row['name'];
                $_SESSION['rank'] = $row['rank'];
                $_SESSION['station_name'] = $row['station_name'];
                header("Location: officer/dashboard.php");
                exit();
            } else {
                $officer_error = "Invalid username or password";
            }
        } else {
            $officer_error = "Invalid username or password";
        }
    } elseif (isset($_POST['admin_login'])) {
        $username = $conn->real_escape_string($_POST['admin_username']);
        $password = $conn->real_escape_string($_POST['admin_password']);
        
        $sql = "SELECT * FROM admin_login WHERE username = '$username'";
        $result = $conn->query($sql);
        


if ($result->num_rows == 1) {
    $row = $result->fetch_assoc();
    if ($row['password'] === $password) {
        $_SESSION['admin_id'] = $row['id'];
        $_SESSION['admin_name'] = $row['name'];
        $_SESSION['rank'] = $row['rank'];
        $_SESSION['station_name'] = $row['station_name'];
        
        // Redirect to appropriate dashboard based on rank
        switch($row['rank']) {
            case 'SP':
                header("Location: admin/dashboard_sp.php");
                break;
            case 'DSP':
                header("Location: admin/dashboard_dsp.php");
                break;
            case 'Inspector':
                header("Location: admin/dashboard_inspector.php");
                break;
            default:
                header("Location: admin/dashboard_inspector.php");
        }
        exit();
    } else {
        $admin_error = "Invalid username or password";
    }
}
    }
}
    

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KKI POLICE WEEK OFF</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <header>
        <div class="header-content">
            <img src="../weekoff_management/includes/kk.png" alt="TN Police Logo" class="logo">
            <div class="header-text">
                <h1>கன்னியாகுமரி காவல்</h1>
                <h2>வார ஓய்வு செயலி</h2>
            </div>
        </div>
    </header>

    <main>
        <div class="login-container">
            <div class="login-options">
                <div class="login-box">
                    <div class="login-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h2>Police Login</h2>
                    <?php if (isset($officer_error)): ?>
                        <p class="error-message"><?php echo $officer_error; ?></p>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="input-group">
                            <i class="fas fa-user"></i>
                            <input type="text" name="officer_username" placeholder="Username" required>
                        </div>
                        <div class="input-group">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="officer_password" placeholder="Password" required>
                        </div>
                        <button type="submit" name="officer_login" class="login-btn">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </button>
                    </form>
                </div>

                <div class="login-box">
                    <div class="login-icon">
                        <i class="fas fa-user-cog"></i>
                    </div>
                    <h2>Officer Login</h2>
                    <?php if (isset($admin_error)): ?>
                        <p class="error-message"><?php echo $admin_error; ?></p>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="input-group">
                            <i class="fas fa-user"></i>
                            <input type="text" name="admin_username" placeholder="Username" required>
                        </div>
                        <div class="input-group">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="admin_password" placeholder="Password" required>
                        </div>
                        <button type="submit" name="admin_login" class="login-btn">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> Kanyakumari Police Department. All rights reserved.</p>
    </footer>
</body>
</html>