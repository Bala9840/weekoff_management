<?php
$host = "localhost";
$username = "root";
$password = "4321";
$database = "wk";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>