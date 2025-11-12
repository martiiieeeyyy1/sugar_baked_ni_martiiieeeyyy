<?php
include 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // 1️⃣ Pangitaon ang user by username
    $stmt = $conn->prepare("SELECT user_id, full_name, username, password, role FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows === 1) {
        $user = $res->fetch_assoc();

        $stored = $user['password'];
        $ok = false;

        // 2️⃣ Verify kung hashed (password_hash) or plain text
        if (password_get_info($stored)['algo'] !== 0) {
            if (password_verify($password, $stored)) $ok = true;
        } 
        if (!$ok && $stored === $password) {
            $ok = true;
        }

        if ($ok) {
            // 3️⃣ Pili ug session name base sa role
            if (strcasecmp($user['role'], 'Admin') === 0) {
                session_name('LASTNALANGKA_ADMIN');
            } elseif (strcasecmp($user['role'], 'Cashier') === 0) {
                session_name('LASTNALANGKA_CASHIER');
            } else {
                echo "<script>alert('Unauthorized Role'); window.location.href='index.php';</script>";
                exit;
            }

            // 4️⃣ Start session gamit ang custom name
            session_start();
            session_regenerate_id(true); // security best practice

            // 5️⃣ Store session variables
            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            // 6️⃣ Redirect based on role
            if (strcasecmp($user['role'], 'Admin') === 0) {
                header("Location: admin_dashboard.php");
            } elseif (strcasecmp($user['role'], 'Cashier') === 0) {
                header("Location: cashier_dashboard.php");
            }
            exit;
        } else {
            echo "<script>alert('Wrong Password'); window.location.href='index.php';</script>";
            exit;
        }
    } else {
        echo "<script>alert('User not found'); window.location.href='index.php';</script>";
        exit;
    }
}

$conn->close();
?>
