<?php
// Start the appropriate session based on role
if (isset($_GET['role']) && $_GET['role'] === 'cashier') {
    session_name('LASTNALANGKA_CASHIER');
} else {
    session_name('LASTNALANGKA_ADMIN');
}

session_start();
session_unset();
session_destroy();

header("Location: index.php");
exit();
?>