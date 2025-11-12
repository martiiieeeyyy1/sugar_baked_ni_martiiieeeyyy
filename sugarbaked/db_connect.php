<?php
// db_connect.php
$host = "localhost";   // XAMPP host
$user = "root";        // XAMPP default user
$pass = "";            // XAMPP default password (blank)
$db   = "sugarbaked_db";

// Connect using MySQLi
$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
