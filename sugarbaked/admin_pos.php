<?php
// ===================================================
// admin_pos.php - Sugar Baked POS (Admin) with 12% VAT & Void Feature
// ===================================================

// ===== IMPORTANT: Set session name BEFORE session_start() =====
session_name('LASTNALANGKA_ADMIN');
session_start();
include 'db_connect.php';

// Set timezone
date_default_timezone_set('Asia/Manila');

// ===== CHECK IF USER IS LOGGED IN =====
if (!isset($_SESSION['user_id']) || strcasecmp($_SESSION['role'], 'Admin') !== 0) {
    header("Location: index.php");
    exit();
}

// ====== Role Check (Admin only) ======
if (!isset($_SESSION['role']) || strcasecmp(trim($_SESSION['role']), 'Admin') !== 0) {
    header("Location: index.php");
    exit();
}

$alert = "";
$search   = trim($_GET['search'] ?? "");
$category = trim($_GET['category'] ?? "");

// ====== CATEGORIES FROM DB ======
$categories = [];
$res = $conn->query("SELECT category_name FROM categories ORDER BY category_id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row['category_name'];
    }
}

// Default category kung wala pa naka-select
if (empty($category)) {
    $category = $categories[0] ?? "Coffee";
}

// ====== FETCH PRODUCTS ======
if (!empty($search)) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE stock>0 AND product_name LIKE CONCAT('%', ?, '%') ORDER BY product_name ASC");
    $stmt->bind_param("s", $search);
} else {
    $stmt = $conn->prepare("SELECT * FROM products WHERE stock>0 AND category=? ORDER BY product_name ASC");
    $stmt->bind_param("s", $category);
}
$stmt->execute();
$products = $stmt->get_result();
$stmt->close();

// ====== PROCESS CHECKOUT WITH 12% VAT (FIXED) ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cart_data'])) {
    $cart_items = json_decode($_POST['cart_data'], true);
    $cash       = floatval($_POST['cash'] ?? 0);
    $subtotal   = 0;

    foreach ($cart_items as $item) {
        $subtotal += $item['price'] * $item['qty'];
    }

    $vat = $subtotal * 0.12;
    $grand_total = $subtotal + $vat;
    $change = $cash - $grand_total;

    if ($change < 0) {
        $alert = "<div class='alert alert-danger'>❌ Kulang ang cash!</div>";
    } else {
        $cashier_id   = $_SESSION['user_id'];
        $cashier_name = $_SESSION['username'];
        $role         = $_SESSION['role'];
        $sale_date    = date("Y-m-d H:i:s");
        $success      = true;

        // ===== FIX: Track deducted inventory for each cart item =====
        $cart_index = 0;
        foreach ($cart_items as &$item) {  // Use reference (&) to modify original array
            $stmt = $conn->prepare("SELECT stock FROM products WHERE product_id=?");
            $stmt->bind_param("i", $item['id']);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$result || $item['qty'] > $result['stock']) {
                $success = false;
                $alert = "<div class='alert alert-danger'>⚠️ Not enough stock for {$item['name']}!</div>";
                break;
            }

            // ===== IMPROVED FIFO DEDUCTION FROM INVENTORY TABLE =====
            $remaining_qty = $item['qty'];
            $deducted_inventory = []; // Track which inventory records were deducted
            
            // Check if is_expired column exists in inventory table
            $checkColumn = $conn->query("SHOW COLUMNS FROM inventory LIKE 'is_expired'");
            $hasExpiredColumn = ($checkColumn && $checkColumn->num_rows > 0);
            
            // Build query based on column existence
            if ($hasExpiredColumn) {
                $inv_sql = "
                    SELECT inventory_id, stock_added, expiration_date 
                    FROM inventory 
                    WHERE product_id = ? 
                    AND stock_added > 0 
                    AND is_expired = 0
                    AND (expiration_date IS NULL OR expiration_date > NOW())
                    ORDER BY date_added ASC, inventory_id ASC
                ";
            } else {
                $inv_sql = "
                    SELECT inventory_id, stock_added, expiration_date 
                    FROM inventory 
                    WHERE product_id = ? 
                    AND stock_added > 0 
                    AND (expiration_date IS NULL OR expiration_date > NOW())
                    ORDER BY date_added ASC, inventory_id ASC
                ";
            }
            
            // Get oldest NON-EXPIRED inventory records first (FIFO)
            $inv_query = $conn->prepare($inv_sql);
            if (!$inv_query) {
                $success = false;
                $alert = "<div class='alert alert-danger'>⚠️ Database error: " . $conn->error . "</div>";
                break;
            }
            
            $inv_query->bind_param("i", $item['id']);
            $inv_query->execute();
            $inv_result = $inv_query->get_result();
            
            if (!$inv_result) {
                $success = false;
                $alert = "<div class='alert alert-danger'>⚠️ Error fetching inventory for {$item['name']}!</div>";
                $inv_query->close();
                break;
            }
            
            while (($inv_row = $inv_result->fetch_assoc()) && $remaining_qty > 0) {
                $inventory_id = $inv_row['inventory_id'];
                $available_stock = $inv_row['stock_added'];
                $expiration_date = $inv_row['expiration_date'];
                
                if ($available_stock >= $remaining_qty) {
                    // This inventory record has enough stock
                    $new_inv_stock = $available_stock - $remaining_qty;
                    $update_inv = $conn->prepare("UPDATE inventory SET stock_added = ? WHERE inventory_id = ?");
                    $update_inv->bind_param("ii", $new_inv_stock, $inventory_id);
                    $update_inv->execute();
                    $update_inv->close();
                    
                    // Track deduction WITH expiration date
                    $deducted_inventory[] = [
                        'qty' => $remaining_qty,
                        'expiration_date' => $expiration_date
                    ];
                    
                    $remaining_qty = 0;
                } else {
                    // Use all stock from this record and continue to next
                    $update_inv = $conn->prepare("UPDATE inventory SET stock_added = 0 WHERE inventory_id = ?");
                    $update_inv->bind_param("i", $inventory_id);
                    $update_inv->execute();
                    $update_inv->close();
                    
                    // Track deduction WITH expiration date
                    $deducted_inventory[] = [
                        'qty' => $available_stock,
                        'expiration_date' => $expiration_date
                    ];
                    
                    $remaining_qty -= $available_stock;
                }
            }
            $inv_query->close();
            
            // Check if we successfully deducted all required quantity
            if ($remaining_qty > 0) {
                $success = false;
                $alert = "<div class='alert alert-danger'>⚠️ Not enough valid inventory for {$item['name']}!</div>";
                break;
            }
            
            // ===== FIX: Store deducted inventory info correctly =====
            $item['deducted_inventory'] = $deducted_inventory;

            // Insert into sales table
            $stmt = $conn->prepare("
                INSERT INTO sales 
                (cashier_id, product_name, quantity, price, total_price, sale_date, cashier_name, vat, role, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Completed')
            ");
            $total = $item['price'] * $item['qty'];
            $item_vat = $total * 0.12;
            $stmt->bind_param("isiddssds",
                $cashier_id, $item['name'], $item['qty'], $item['price'],
                $total, $sale_date, $cashier_name, $item_vat, $role
            );
            $stmt->execute();
            $stmt->close();

            // Update products table (deduct from total stock)
            $new_stock = $result['stock'] - $item['qty'];
            $stmt2 = $conn->prepare("UPDATE products SET stock=? WHERE product_id=?");
            $stmt2->bind_param("ii", $new_stock, $item['id']);
            $stmt2->execute();
            $stmt2->close();
            
            $cart_index++;
        }
        unset($item); // Break reference after loop

        if ($success) {
            // ====== STORE LAST TRANSACTION FOR VOID (with inventory details) ======
            $_SESSION['last_cart']          = json_encode($cart_items);
            $_SESSION['last_subtotal']      = $subtotal;
            $_SESSION['last_vat']           = $vat;
            $_SESSION['last_total']         = $grand_total;
            $_SESSION['last_cash']          = $cash;
            $_SESSION['last_change']        = $change;
            $_SESSION['last_cashier_name']  = $cashier_name;
            $_SESSION['last_role']          = $role;

            // ===== REDIRECT WITH SUCCESS FLAG ======
            session_write_close();
            header("Location: ".$_SERVER['PHP_SELF']."?success=1");
            exit();
        }
    }
}

// ====== VOID LAST TRANSACTION (RESTORE WITH EXPIRATION DATES) ======
if (isset($_POST['void_last'])) {
    if (!empty($_SESSION['last_cart'])) {
        $last_cart = json_decode($_SESSION['last_cart'], true);
        $voided_products = [];

        foreach ($last_cart as $item) {
            // Get product_id and category from product_name
            $get_id = $conn->prepare("SELECT product_id, category FROM products WHERE product_name=?");
            $get_id->bind_param("s", $item['name']);
            $get_id->execute();
            $product_result = $get_id->get_result()->fetch_assoc();
            $get_id->close();
            
            if ($product_result) {
                $product_id = $product_result['product_id'];
                $category = $product_result['category'];
                
                // Restore stock to products table
                $stmt = $conn->prepare("UPDATE products SET stock=stock+? WHERE product_id=?");
                $stmt->bind_param("ii", $item['qty'], $product_id);
                $stmt->execute();
                $stmt->close();

                // ===== RESTORE INVENTORY WITH ORIGINAL EXPIRATION DATES =====
                if (isset($item['deducted_inventory']) && is_array($item['deducted_inventory']) && count($item['deducted_inventory']) > 0) {
                    // Restore with tracked expiration dates
                    foreach ($item['deducted_inventory'] as $deducted) {
                        $restore_qty = $deducted['qty'];
                        $restore_exp = $deducted['expiration_date'];
                        
                        // Check if expiration date exists and is valid
                        if (!empty($restore_exp) && $restore_exp !== null) {
                            $inv_stmt = $conn->prepare("
                                INSERT INTO inventory (product_id, product_name, category, stock_added, expiration_date, is_expired, date_added) 
                                VALUES (?, ?, ?, ?, ?, 0, NOW())
                            ");
                            $inv_stmt->bind_param("issis", $product_id, $item['name'], $category, $restore_qty, $restore_exp);
                        } else {
                            // No expiration date - insert NULL
                            $inv_stmt = $conn->prepare("
                                INSERT INTO inventory (product_id, product_name, category, stock_added, expiration_date, is_expired, date_added) 
                                VALUES (?, ?, ?, ?, NULL, 0, NOW())
                            ");
                            $inv_stmt->bind_param("issi", $product_id, $item['name'], $category, $restore_qty);
                        }
                        
                        $inv_stmt->execute();
                        $inv_stmt->close();
                    }
                } else {
                    // Fallback: Create single inventory entry without expiration
                    $inv_stmt = $conn->prepare("
                        INSERT INTO inventory (product_id, product_name, category, stock_added, expiration_date, is_expired, date_added) 
                        VALUES (?, ?, ?, ?, NULL, 0, NOW())
                    ");
                    $inv_stmt->bind_param("issi", $product_id, $item['name'], $category, $item['qty']);
                    $inv_stmt->execute();
                    $inv_stmt->close();
                }
            }

            // Mark as voided in sales
            $stmt = $conn->prepare("UPDATE sales SET status='Voided' WHERE product_name=? AND sale_date=(SELECT MAX(sale_date) FROM (SELECT sale_date FROM sales WHERE product_name=? AND status='Completed') AS temp)");
            $stmt->bind_param("ss", $item['name'], $item['name']);
            $stmt->execute();
            $stmt->close();

            // Collect voided product info
            $voided_products[] = $item['name'] . " (x{$item['qty']})";
        }

        // Clear session last transaction
        unset($_SESSION['last_cart'], $_SESSION['last_subtotal'], $_SESSION['last_vat'], $_SESSION['last_total'], $_SESSION['last_cash'], $_SESSION['last_change'], $_SESSION['last_cashier_name'], $_SESSION['last_role']);

        // Set void success flag with product details
        $voided_list = implode(", ", $voided_products);
        $_SESSION['void_success'] = "Voided successfully: {$voided_list}";

        header("Location: ".$_SERVER['PHP_SELF']."?void_success=1");
        exit();
    } else {
        $_SESSION['void_error'] = "No last transaction found!";
        header("Location: ".$_SERVER['PHP_SELF']."?void_error=1");
        exit();
    }
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sugar Baked POS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
body {
  font-family: "Poppins", sans-serif;
  background-color: #e6e7eb;
  margin: 0;
}
/* ===== Sidebar ===== */
.sidebar {
  height: 100vh;
  width: 250px;
  position: fixed;
  top: 0;
  left: 0;
  background: #000;
  color: #babec5;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  box-shadow: 3px 0 12px rgba(0,0,0,0.25);
  transition: all 0.3s ease;
  border-right: 1px solid rgba(255,255,255,0.05);
  padding-top: 10px;
}

.sidebar-header {
  text-align: center;
  padding: 28px 0;
  border-bottom: 1px solid rgba(255,255,255,0.08);
}

.sidebar-header h4 {
  font-size: 1.5rem;
  font-weight: 700;
  color: #f3f4f6;
  letter-spacing: 0.5px;
  margin: 0;
}

/* ===== Navigation ===== */
.sidebar-nav {
  display: flex;
  flex-direction: column;
  padding-top: 30px;
}

/* Align icon + text perfectly */
.sidebar-nav a {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 14px;
  padding: 14px 20px;
  color: #cbd5e1;
  text-decoration: none;
  font-size: 15px;
  font-weight: 500;
  border-left: 4px solid transparent;
  transition: all 0.25s ease;
  border-radius: 8px;
  margin: 4px 12px;
  line-height: 1.2;
}

/* Uniform icon size and position */
.sidebar-nav a i {
  width: 22px;
  text-align: center;
  font-size: 18px;
  opacity: 0.85;
}

.sidebar-nav a:hover {
  background: rgba(59,130,246,0.08);
  color: #fff;
  transform: translateX(3px);
}

.sidebar-nav a.active {
  background: #0d6efd;
  color: #fff;
  font-weight: 600;
}

/* Logout button */
.sidebar-nav a.logout {
  padding: 14px 20px;
  background: #cd2c2c;
  color: #fff;
  border-left: none;
  border-radius: 8px;
  text-align: center;
}

.sidebar-nav a.logout:hover {
  background: #b91c1c;
}

/* ===== Responsive ===== */
@media(max-width:768px){
  .sidebar{
    width:70px;
  }
  .sidebar-header h4,
  .sidebar-nav a span{
    display:none;
  }
  .sidebar:hover{
    width:230px;
  }
  .sidebar:hover .sidebar-header h4,
  .sidebar:hover .sidebar-nav a span{
    display:inline;
  }
}

.content{ margin-left:250px; padding:25px; min-height:100vh; }

.pos-container { display:grid; grid-template-columns: 2fr 1fr; gap:30px; }
.table th { background:#f1f5f9; }
.receipt { background:#fff; border-radius:10px; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,0.1); }

@media print {
  .sidebar, .alert, .btn, .cash-input, .actions { display:none !important; }
  .content { margin:0; }
}

/* ===== HEADER ===== */
  .receipt-header {
    text-align: center;
    margin-bottom: 20px;
  }

  .receipt-header img {
    width: 50px;
    height: 50px;
    margin-bottom: 5px;
  }

  .receipt-header h1 {
    font-size: 24px;
    font-weight: 600;
    margin: 0;
  }

  .receipt-header p {
    margin: 0;
    font-size: 14px;
  }

  /* ===== TABLE ===== */
  table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
  }

  th, td {
    border-bottom: 1px solid #ccc;
    padding: 8px;
    text-align: center;
  }

  th {
    background-color: #f8f8f8;
    font-weight: 600;
  }

  /* ===== TOTAL SECTION ===== */
  .summary {
    margin-top: 20px;
    text-align: right;
    width: 100%;
  }

  .summary td {
    padding: 6px 8px;
  }

  .summary td.label {
    font-weight: 600;
    text-align: right;
  }

  /* ===== FOOTER ===== */
  .footer {
    text-align: center;
    margin-top: 40px;
    font-size: 14px;
  }

  .footer span {
    color: #ff4d6d;
  }

  @media print {
    body {
      margin: 0;
      padding: 20px;
    }
  }

</style>
</head>

<body>
<!-- Sidebar -->
<div class="sidebar">
  <div class="sidebar-header">
    <h4>Sugar Baked</h4>
  </div>
  <div class="sidebar-nav">
    <a href="admin_dashboard.php" ><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="categories.php"><i class="fas fa-tags"></i><span>Categories</span></a>
    <a href="products.php"><i class="fas fa-box"></i><span>Products</span></a>
    <a href="inventory.php"><i class="fas fa-warehouse"></i><span>Inventory</span></a>
    <a href="admin_pos.php"class="active"><i class="fas fa-cash-register"></i><span>POS</span></a>
    <a href="sales.php"><i class="fas fa-receipt"></i><span>Sales</span></a>
    <a href="users.php"><i class="fas fa-users"></i><span>Users</span></a>
    <a href="reports.php"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
    <a href="#" class="logout" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
      <i class="fas fa-sign-out-alt"></i><span>Logout</span>
    </a>
  </div>
</div>

<div class="content py-3"><br>
  <h2 class="mb-4">Point of Sale</h2>
  <?php echo $alert; ?>

<!-- ===== Search + Category ===== -->
<form method="GET" class="d-flex align-items-center mb-4 flex-wrap justify-content-between" style="gap:10px;">
  <input type="text" name="search" class="form-control" placeholder="Search Product..." 
         value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" style="flex:1; min-width:220px;">
  <select name="category" class="form-select" style="width:220px;" id="categorySelect">
    <?php foreach ($categories as $cat): ?>
      <option value="<?= htmlspecialchars($cat) ?>" <?= $category==$cat?'selected':'' ?>><?= $cat ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary">Search</button>
  <button type="button" class="btn btn-outline-secondary" onclick="resetCart()">Reset</button>
</form>

<div class="pos-container">
  <!-- ===== PRODUCT LIST ===== -->
  <div>
    <div class="card shadow-sm">
      <div class="card-header bg-primary text-white">Available Products</div>
      <div class="card-body table-responsive">
        <table class="table table-hover align-middle" id="productTable">
          <thead><tr><th>Product</th><th>Price</th><th>Stock</th><th>Action</th></tr></thead>
          <tbody>
            <?php if ($products->num_rows > 0): ?>
              <?php while($row=$products->fetch_assoc()): ?>
              <tr id="productRow<?= $row['product_id'] ?>">
                <td><?= htmlspecialchars($row['product_name']) ?></td>
                <td>₱<?= number_format($row['price'],2) ?></td>
                <td id="stock<?= $row['product_id'] ?>"><?= $row['stock'] ?></td>
                <td>
                  <button class="btn btn-sm btn-success" onclick="addToCart(<?= $row['product_id'] ?>,'<?= htmlspecialchars($row['product_name']) ?>',<?= $row['price'] ?>)">
                    <i class="fas fa-plus"></i></button>
                  <button class="btn btn-sm btn-danger" onclick="removeFromCart(<?= $row['product_id'] ?>)">
                     <i class="fas fa-arrow-left"></i></button>
                </td>
              </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="4" class="text-center text-muted">No products found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ===== CART + CHECKOUT ===== -->
  <div class="receipt">
    <h5 class="mb-3"><i></i> Cart</h5>
    <form id="posForm" method="POST" action="admin_pos.php">
      <table class="table table-bordered" id="cartTable">
        <thead><tr><th>Product</th><th>Quantity</th><th>Total</th></tr></thead>
        <tbody></tbody>
      </table>

      <label><b>Total:</b></label>
      <input type="text" id="grandTotal" class="form-control mb-2" readonly>

      <label><b>Cash:</b></label>
      <input type="number" step="0.01" name="cash" class="form-control mb-2 cash-input" oninput="updateChange()" required id="cashInput">

      <label><b>Change:</b></label>
      <input type="text" id="change" class="form-control mb-3" readonly>

      <input type="hidden" name="cart_data" id="cartData">
      <div class="d-grid">
        <button type="button" class="btn btn-primary py-3 fs-5 fw-semibold" onclick="openConfirmModal()">Checkout</button>
        <button type="button" class="btn btn-danger mt-2 fw-semibold" onclick="voidLastTransaction()">Void Last Transaction</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== CONFIRM CHECKOUT MODAL ===== -->
<div class="modal fade" id="confirmCheckoutModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center border-0 shadow">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title w-100"><i></i> Confirm Checkout</h5>
      </div>
      <div class="modal-body">
        <p class="fs-6">Are you sure you want to checkout this transaction?</p>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-primary px-4" id="confirmCheckoutYes">Yes</button>
        <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== SUCCESS MODAL ===== -->
<div class="modal fade" id="successModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title w-100"><i></i> Transaction Complete</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Transaction completed.</p>
        <p class="text-muted">Click below to print the official receipt.</p>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-success" onclick="printReceipt()">Print Receipt</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== VOID LAST TRANSACTION MODAL ===== -->
<div class="modal fade" id="voidTransactionModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title w-100"><i></i> Void Last Transaction</h5>
      </div>
      <div class="modal-body">
        <p class="fs-6">Are you sure you want to void the last transaction?</p>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-danger" id="confirmVoidBtn">Yes, Void</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== VOID SUCCESS MODAL ===== -->
<div class="modal fade" id="voidSuccessModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i></i>Void Successful</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <p class="mb-0 fs-6" id="voidSuccessMessage"></p>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-success px-4" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== VOID ERROR MODAL ===== -->
<div class="modal fade" id="voidErrorModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i></i>Void Failed</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <p class="mb-0 fs-6" id="voidErrorMessage"></p>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-danger px-4" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== ERROR MODAL (Empty Cart / Insufficient Cash) ===== -->
<div class="modal fade" id="errorModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title"><i></i>Warning</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <p class="mb-0 fs-6" id="errorMessage"></p>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-warning px-4" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- Logout Modal -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2"></i>Confirm Logout</h5>
      </div>
      <div class="modal-body text-center">
        <p class="mb-0 fs-6 text-secondary">Are you sure you want to log out?</p>
      </div>
      <div class="modal-footer justify-content-center">
        <a href="logout.php" class="btn btn-danger px-4">Yes, Logout</a>
        <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>

// ==================== CART LOGIC ====================
let cart = [];

function addToCart(id, name, price) {
  const stockCell = document.getElementById("stock" + id);
  let currentStock = parseInt(stockCell.innerText);
  if (currentStock <= 0) {
    showErrorModal("Out of stock: " + name);
    return;
  }
  stockCell.innerText = currentStock - 1;
  let existing = cart.find(i => i.id == id);
  if (existing) existing.qty++;
  else cart.push({ id, name, price, qty: 1 });
  renderCart();
}

function removeFromCart(id) {
  const stockCell = document.getElementById("stock" + id);
  let existing = cart.find(i => i.id == id);
  if (!existing) return;

  existing.qty--;
  stockCell.innerText = parseInt(stockCell.innerText) + 1;

  if (existing.qty <= 0) {
    cart = cart.filter(item => item.id !== id);
  }

  renderCart();
}

function updateQty(index, qty) {
  qty = parseInt(qty);
  let item = cart[index];
  const stockCell = document.getElementById("stock" + item.id);
  const diff = qty - item.qty;

  if (diff > 0) {
    let currentStock = parseInt(stockCell.innerText);
    if (currentStock < diff) {
      showErrorModal("Not enough stock!");
      renderCart();
      return;
    }
    stockCell.innerText = currentStock - diff;
  } else {
    stockCell.innerText = parseInt(stockCell.innerText) + Math.abs(diff);
  }

  item.qty = qty;
  if (item.qty <= 0) {
    cart.splice(index, 1);
  }

  renderCart();
}

function renderCart() {
  const tbody = document.querySelector('#cartTable tbody');
  tbody.innerHTML = "";
  let subtotal = 0;

  cart.forEach((item, i) => {
    const subtotalItem = item.price * item.qty;
    subtotal += subtotalItem;
    tbody.innerHTML += `
      <tr>
        <td>${item.name}</td>
        <td><input type="number" value="${item.qty}" min="1" class="form-control form-control-sm" onchange="updateQty(${i}, this.value)"></td>
        <td>₱${subtotalItem.toFixed(2)}</td>
      </tr>`;
  });

  const vat = subtotal * 0.12;
  const total = subtotal + vat;

  document.getElementById('grandTotal').value = "₱" + total.toFixed(2);
  document.getElementById('cartData').value = JSON.stringify(cart);
  updateChange();
}

function updateChange() {
  const total = parseFloat(document.getElementById("grandTotal").value.replace("₱", "")) || 0;
  const cash = parseFloat(document.querySelector("input[name='cash']").value) || 0;
  const change = cash - total;
  document.getElementById("change").value = change >= 0 ? "₱" + change.toFixed(2) : "₱0.00";
}

// ==================== PRINT RECEIPT ====================
function printReceipt() {
  let cartData = JSON.parse(localStorage.getItem('lastReceiptCart') || '[]');
  const total = localStorage.getItem('lastReceiptTotal') || "0";
  const cash = localStorage.getItem('lastReceiptCash') || "0";
  const change = localStorage.getItem('lastReceiptChange') || "0";
  const cashierName = localStorage.getItem('lastReceiptCashier') || "Unknown";
  const role = localStorage.getItem('lastReceiptRole') || "";
  const now = new Date().toLocaleString("en-PH",{timeZone:"Asia/Manila"});

  const win = window.open('', '_blank', 'width=400,height=600');
  win.document.write(`
  <html>
  <head>
  <title>Official Receipt</title>
  <style>
    @page { size: 58mm auto; margin: 0; }
    body {
      width: 58mm;
      font-family: 'Courier New', monospace;
      font-size: 11px;
      margin: 0;
      padding: 5px;
    }
    .center { text-align: center; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 2px 0; text-align: left; }
    hr { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
  </style>
  </head>
  <body>
    <div class="center">
      <h3>Sugar Baked</h3>
      <p>Official Receipt</p>
      <p>${now}</p>
      <hr>
    </div>
    <table>
      ${cartData.map(i => `
        <tr>
          <td>${i.name} x${i.qty}</td>
          <td style="text-align:right;">₱${(i.price * i.qty).toFixed(2)}</td>
        </tr>`).join('')}
    </table>
    <hr>
    <table>
      <tr><td>Handled By:</td><td style="text-align:right;">${cashierName} (${role})</td></tr>
      <tr><td>VAT (12%):</td><td style="text-align:right;">₱${(parseFloat(total)/1.12*0.12).toFixed(2)}</td></tr>
      <tr><td>Cash:</td><td style="text-align:right;">₱${parseFloat(cash).toFixed(2)}</td></tr>
      <tr><td>Change:</td><td style="text-align:right;">₱${parseFloat(change).toFixed(2)}</td></tr>
      <tr><td><b>Total:</b></td><td style="text-align:right;"><b>₱${parseFloat(total).toFixed(2)}</b></td></tr>
    </table>
    <hr>
    <div class="center">
      <p>Thank you for your purchase!</p>
      <p>~ Have a sweet day! ~</p>
    </div>
    <script>window.print();<\/script>
  </body>
  </html>`);
}

// ===== RESET CART FUNCTION =====
function resetCart() {
  cart.forEach(item => {
    const stockCell = document.getElementById("stock" + item.id);
    if (stockCell) {
      stockCell.innerText = parseInt(stockCell.innerText) + item.qty;
    }
  });

  cart = [];
  localStorage.removeItem('savedCart');
  localStorage.removeItem('lastReceiptCart');
  localStorage.removeItem('lastReceiptTotal');
  localStorage.removeItem('lastReceiptCash');
  localStorage.removeItem('lastReceiptChange');

  document.getElementById('grandTotal').value = "";
  document.querySelector('input[name="cash"]').value = "";
  document.getElementById('change').value = "";

  renderCart();
}

// ====== CATEGORY CHANGE FIX ======
document.getElementById('categorySelect').addEventListener('change', function() {
  localStorage.setItem('savedCart', JSON.stringify(cart));

  const category = this.value;
  const search = document.querySelector('input[name="search"]').value;
  const url = `admin_pos.php?category=${encodeURIComponent(category)}&search=${encodeURIComponent(search)}`;

  window.location.href = url;
});

// ===== RESTORE CART FROM LOCALSTORAGE ON PAGE LOAD =====
window.addEventListener('load', () => {
  const savedCart = localStorage.getItem('savedCart');
  if (savedCart) {
    try {
      cart = JSON.parse(savedCart);
      
      cart.forEach(item => {
        const stockCell = document.getElementById("stock" + item.id);
        if (stockCell) {
          const currentStock = parseInt(stockCell.innerText);
          stockCell.innerText = Math.max(0, currentStock - item.qty);
        }
      });
      
      renderCart();
    } catch (e) {
      console.error("Error loading saved cart:", e);
    }
  }

  // Check if checkout was successful
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('success') === '1') {
    const cartData      = <?php echo json_encode($_SESSION['last_cart'] ?? '[]'); ?>;
    const total         = "<?php echo $_SESSION['last_total'] ?? 0; ?>";
    const cash          = "<?php echo $_SESSION['last_cash'] ?? 0; ?>";
    const change        = "<?php echo $_SESSION['last_change'] ?? 0; ?>";
    const cashierName   = "<?php echo $_SESSION['last_cashier_name'] ?? 'Unknown'; ?>";
    const role          = "<?php echo $_SESSION['last_role'] ?? ''; ?>";

    localStorage.setItem('lastReceiptCart', cartData);
    localStorage.setItem('lastReceiptTotal', total);
    localStorage.setItem('lastReceiptCash', cash);
    localStorage.setItem('lastReceiptChange', change);
    localStorage.setItem('lastReceiptCashier', cashierName);
    localStorage.setItem('lastReceiptRole', role);

    localStorage.removeItem('savedCart');
    cart = [];

    const successModal = new bootstrap.Modal(document.getElementById('successModal'));
    successModal.show();
  }

  // Check if void was successful
  if (urlParams.get('void_success') === '1') {
    const voidMessage = "<?php echo $_SESSION['void_success'] ?? 'Voided successfully!'; ?>";
    document.getElementById('voidSuccessMessage').innerText = voidMessage;
    const voidSuccessModal = new bootstrap.Modal(document.getElementById('voidSuccessModal'));
    voidSuccessModal.show();
    <?php unset($_SESSION['void_success']); ?>
  }

  // Check if void failed
  if (urlParams.get('void_error') === '1') {
    const voidError = "<?php echo $_SESSION['void_error'] ?? 'No last transaction found!'; ?>";
    document.getElementById('voidErrorMessage').innerText = voidError;
    const voidErrorModal = new bootstrap.Modal(document.getElementById('voidErrorModal'));
    voidErrorModal.show();
    <?php unset($_SESSION['void_error']); ?>
  }
});

// ===== OPEN CONFIRM CHECKOUT MODAL =====
function openConfirmModal() {
  const total = parseFloat(document.getElementById("grandTotal").value.replace("₱","")) || 0;
  const cash = parseFloat(document.querySelector("input[name='cash']").value) || 0;

  if(cart.length === 0){ 
    showErrorModal("The cart is empty!");
    return; 
  }
  
  if(cash < total){ 
    showErrorModal("Insufficient cash!");
    return; 
  }

  const modal = new bootstrap.Modal(document.getElementById('confirmCheckoutModal'));
  modal.show();
}

// ===== SHOW ERROR MODAL =====
function showErrorModal(message) {
  document.getElementById('errorMessage').innerText = message;
  const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
  errorModal.show();
}

// ===== HANDLE CONFIRM CHECKOUT YES BUTTON =====
document.getElementById('confirmCheckoutYes').addEventListener('click', () => {
  const modal = bootstrap.Modal.getInstance(document.getElementById('confirmCheckoutModal'));
  modal.hide();

  document.getElementById('cartData').value = JSON.stringify(cart);
  document.getElementById('posForm').submit();
});

// ===== OPEN VOID MODAL =====
function voidLastTransaction() {
  const voidModal = new bootstrap.Modal(document.getElementById('voidTransactionModal'));
  voidModal.show();
}

// ===== HANDLE CONFIRM VOID BUTTON =====
document.getElementById('confirmVoidBtn').addEventListener('click', () => {
  const voidModal = bootstrap.Modal.getInstance(document.getElementById('voidTransactionModal'));
  voidModal.hide();

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'admin_pos.php';

  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = 'void_last';
  input.value = '1';
  form.appendChild(input);

  document.body.appendChild(form);
  form.submit();
});

</script>
</body>
</html>