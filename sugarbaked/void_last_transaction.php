<?php
// void_last_transaction.php
session_start();
include 'db_connect.php';

header('Content-Type: application/json');

if (empty($_SESSION['last_cart'])) {
    echo json_encode(['status'=>'fail','message'=>'No last transaction found!']);
    exit();
}

$last_cart = json_decode($_SESSION['last_cart'], true);

$success = true;
$message = "";

foreach ($last_cart as $item) {
    // Restore stock
    $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE product_name=?");
    $stmt->bind_param("is", $item['qty'], $item['name']);
    if (!$stmt->execute()) $success = false;
    $stmt->close();

    // Delete last sale(s)
    $stmt = $conn->prepare("
        DELETE FROM sales 
        WHERE product_name=? 
        AND sale_date=(SELECT MAX(sale_date) FROM sales WHERE product_name=?)
        LIMIT 1
    ");
    $stmt->bind_param("ss", $item['name'], $item['name']);
    if (!$stmt->execute()) $success = false;
    $stmt->close();
}

// Clear last transaction from session
if ($success) {
    unset($_SESSION['last_cart']);
    unset($_SESSION['last_subtotal']);
    unset($_SESSION['last_vat']);
    unset($_SESSION['last_total']);
    unset($_SESSION['last_cash']);
    unset($_SESSION['last_change']);
    unset($_SESSION['last_cashier_name']);
    unset($_SESSION['last_role']);
    $message = "Last transaction voided successfully!";
} else {
    $message = "Failed to void the last transaction!";
}

echo json_encode([
    'status' => $success ? 'success' : 'fail',
    'message' => $message
]);
