<?php
// products.php
// ===== IMPORTANT: Set session name BEFORE session_start() =====
session_name('LASTNALANGKA_ADMIN');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db_connect.php';

// Set timezone
date_default_timezone_set('Asia/Manila');

// ===== CHECK IF USER IS LOGGED IN =====
if (!isset($_SESSION['user_id']) || strcasecmp($_SESSION['role'], 'Admin') !== 0) {
    header("Location: index.php");
    exit();
}

$alert = "";

// ====== CATEGORIES from DB ======
$categories = [];
$resCats = $conn->query("SELECT category_name FROM categories ORDER BY category_name ASC");
while ($c = $resCats->fetch_assoc()) {
    $categories[] = $c['category_name'];
}

// ===== FUNCTION: Auto status based on stock =====
function getStatus($stock) {
    if ($stock <= 0) return "Out of Stock";
    if ($stock <= 3) return "Low Stock";
    return "Available";
}

// ===== AUTO-DEDUCT EXPIRED STOCK FROM PRODUCTS =====
// Check if is_expired column exists, if not add it
$checkColumn = $conn->query("SHOW COLUMNS FROM inventory LIKE 'is_expired'");
if ($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE inventory ADD COLUMN is_expired TINYINT(1) DEFAULT 0 AFTER expiration_date");
}

$today = date('Y-m-d H:i:s');

// ===== IMPROVED EXPIRED STOCK HANDLING (REAL FIFO DEDUCTION) =====
// Process each product that has expired inventory
$expiredProductsQuery = $conn->query("
    SELECT DISTINCT product_id 
    FROM inventory 
    WHERE expiration_date IS NOT NULL 
    AND expiration_date < '$today'
    AND is_expired = 0
    AND stock_added > 0
");

while ($prod = $expiredProductsQuery->fetch_assoc()) {
    $product_id = $prod['product_id'];
    
    // Get current product stock
    $productQuery = $conn->prepare("SELECT stock FROM products WHERE product_id = ?");
    $productQuery->bind_param("i", $product_id);
    $productQuery->execute();
    $productResult = $productQuery->get_result()->fetch_assoc();
    $productQuery->close();
    
    if (!$productResult) continue;
    
    $current_product_stock = $productResult['stock'];
    $total_deduction = 0;
    
    // Get all expired inventory records for this product (FIFO order)
    $expiredInvQuery = $conn->prepare("
        SELECT inventory_id, stock_added 
        FROM inventory 
        WHERE product_id = ? 
        AND expiration_date < ? 
        AND is_expired = 0 
        AND stock_added > 0
        ORDER BY date_added ASC
    ");
    $expiredInvQuery->bind_param("is", $product_id, $today);
    $expiredInvQuery->execute();
    $expiredInvResult = $expiredInvQuery->get_result();
    
    // Process each expired inventory record
    while ($inv = $expiredInvResult->fetch_assoc()) {
        $inventory_id = $inv['inventory_id'];
        $stock_to_expire = $inv['stock_added'];
        
        // How much can we actually deduct from this inventory?
        // (can't deduct more than what's in products table)
        $deduct_amount = min($stock_to_expire, $current_product_stock);
        
        if ($deduct_amount > 0) {
            // Deduct from this inventory record
            $new_inv_stock = $stock_to_expire - $deduct_amount;
            $updateInvStmt = $conn->prepare("UPDATE inventory SET stock_added = ? WHERE inventory_id = ?");
            $updateInvStmt->bind_param("ii", $new_inv_stock, $inventory_id);
            $updateInvStmt->execute();
            $updateInvStmt->close();
            
            // Track total deduction for products table
            $total_deduction += $deduct_amount;
            $current_product_stock -= $deduct_amount;
        }
        
        // Mark as expired (even if stock_added is not 0, it's still expired)
        $markStmt = $conn->prepare("UPDATE inventory SET is_expired = 1 WHERE inventory_id = ?");
        $markStmt->bind_param("i", $inventory_id);
        $markStmt->execute();
        $markStmt->close();
    }
    $expiredInvQuery->close();
    
    // Finally, deduct total from products table
    if ($total_deduction > 0) {
        $deductProductStmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE product_id = ?");
        $deductProductStmt->bind_param("ii", $total_deduction, $product_id);
        $deductProductStmt->execute();
        $deductProductStmt->close();
    }
}

// ===== AUTO-UPDATE STATUS =====
$updateStatusStmt = $conn->prepare("UPDATE products SET status=? WHERE product_id=?");
$result = $conn->query("SELECT product_id, stock FROM products");
while ($row = $result->fetch_assoc()) {
    $status = getStatus(intval($row['stock']));
    $updateStatusStmt->bind_param("si", $status, $row['product_id']);
    $updateStatusStmt->execute();
}
$updateStatusStmt->close();

// ===== COUNT TOTAL PRODUCTS =====
$totalProducts = $conn->query("SELECT COUNT(*) AS total FROM products")->fetch_assoc()['total'];

// ===== ADD PRODUCT =====
if (isset($_POST['add_product'])) {
    if ($totalProducts >= 500) {
        $alert = "<div class='alert alert-danger alert-dismissible fade show'>
                    Product limit reached! (500 max)
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                  </div>";
    } else {
        $name = ucwords(strtolower(trim($_POST['product_name'])));
        $category = $_POST['category'] ?: $categories[0];
        $price = floatval($_POST['price']);
        $stock = intval($_POST['stock']);
        $expiration_date = $_POST['expiration_date'] ?: NULL;
        $status = getStatus($stock);

        // Duplicate check
        $check = $conn->prepare("SELECT product_id FROM products WHERE product_name=? AND category=?");
        $check->bind_param("ss", $name, $category);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $alert = "<div class='alert alert-warning alert-dismissible fade show'>
                        Duplicate Product: <b>$name</b> already exists in <b>$category</b>!
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                      </div>";
        } else {
            $stmt = $conn->prepare("INSERT INTO products (product_name, category, price, stock, status, date_added) 
                                    VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssdis", $name, $category, $price, $stock, $status);
            if ($stmt->execute()) {
                // Add to inventory
                if ($stock > 0) {
                    $product_id = $stmt->insert_id;
                    $inv_stmt = $conn->prepare("INSERT INTO inventory (product_id, product_name, category, stock_added, expiration_date, is_expired, date_added) 
                                                VALUES (?, ?, ?, ?, ?, 0, NOW())");
                    $inv_stmt->bind_param("issis", $product_id, $name, $category, $stock, $expiration_date);
                    $inv_stmt->execute();
                    $inv_stmt->close();
                }
                
                $alert = "<div class='alert alert-success alert-dismissible fade show'>
                            Product <b>$name</b> has been added successfully!
                            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                          </div>";
            }
            $stmt->close();
        }
        $check->close();
    }
}

// ===== UPDATE PRODUCT =====
if (isset($_POST['update_product'])) {
    $id = $_POST['product_id'];
    $name = ucwords(strtolower(trim($_POST['product_name'])));
    $category = trim($_POST['category']);
    $price = floatval($_POST['price']);
    $addedStock = intval($_POST['stock']);
    $expiration_date = $_POST['expiration_date'] ?: NULL;

    // Get current stock
    $stmtCur = $conn->prepare("SELECT stock FROM products WHERE product_id=?");
    $stmtCur->bind_param("i", $id);
    $stmtCur->execute();
    $cur_stock = $stmtCur->get_result()->fetch_assoc()['stock'] ?? 0;
    $stmtCur->close();

    $newStock = $cur_stock + $addedStock;
    $status = getStatus($newStock);

    // Check duplicate
    $check = $conn->prepare("SELECT product_id FROM products WHERE product_name=? AND category=? AND product_id!=?");
    $check->bind_param("ssi", $name, $category, $id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $alert = "<div class='alert alert-warning alert-dismissible fade show'>
                    Another product with the same name exists in <b>$category</b>!
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                  </div>";
    } else {
        // Update product
        $stmt = $conn->prepare("UPDATE products 
                                SET product_name=?, category=?, price=?, stock=?, status=?, date_added=NOW() 
                                WHERE product_id=?");
        $stmt->bind_param("ssdisi", $name, $category, $price, $newStock, $status, $id);
        if ($stmt->execute()) {
            // Add to inventory if stock was added
            if ($addedStock > 0) {
                $inv_stmt = $conn->prepare("INSERT INTO inventory (product_id, product_name, category, stock_added, expiration_date, is_expired, date_added) 
                                            VALUES (?, ?, ?, ?, ?, 0, NOW())");
                $inv_stmt->bind_param("issis", $id, $name, $category, $addedStock, $expiration_date);
                $inv_stmt->execute();
                $inv_stmt->close();
            }
            
            $alert = "<div class='alert alert-success alert-dismissible fade show'>
                        Product <b>$name</b> has been updated successfully!
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                      </div>";
        }
        $stmt->close();
    }
    $check->close();
}

// ===== DELETE PRODUCT =====
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("SELECT product_name, category FROM products WHERE product_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $deletedProduct = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ===== FIRST: Delete related inventory records =====
    $deleteInv = $conn->prepare("DELETE FROM inventory WHERE product_id=?");
    $deleteInv->bind_param("i", $id);
    $deleteInv->execute();
    $deleteInv->close();

    // ===== SECOND: Delete the product =====
    $stmt = $conn->prepare("DELETE FROM products WHERE product_id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['alert'] = "<div class='alert alert-success alert-dismissible fade show'>
                                Product <b>{$deletedProduct['product_name']}</b> from <b>{$deletedProduct['category']}</b> has been deleted successfully.
                                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                              </div>";
    }
    $stmt->close();

    // Reorder IDs
    function reorderProductIDs($conn) {
        // Temporarily disable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        
        $conn->query("SET @num := 0");
        $conn->query("UPDATE products SET product_id = (@num := @num + 1) ORDER BY product_id ASC");
        $next = $conn->query("SELECT MAX(product_id) + 1 AS next_id FROM products")->fetch_assoc()['next_id'];
        $next = $next ? $next : 1;
        $conn->query("ALTER TABLE products AUTO_INCREMENT = $next");
        
        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    }
    reorderProductIDs($conn);

    // Adjust page if last item deleted
    $limit = 5;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $count = $conn->query("SELECT COUNT(*) AS total FROM products")->fetch_assoc()['total'];
    $totalPages = ceil($count / $limit);
    if ($page > $totalPages) $page = max(1, $totalPages);
    header("Location: products.php?page=$page");
    exit();
}

// ===== PAGINATION + SEARCH =====
$limit = 5;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
$where = "WHERE (LOWER(product_name) LIKE CONCAT(?, '%') OR LOWER(category) LIKE CONCAT(?, '%'))";
$params = [$search, $search];
$types = "ss";

// Total Rows
$totalQuery = $conn->prepare("SELECT COUNT(*) AS total FROM products $where");
$totalQuery->bind_param($types, ...$params);
$totalQuery->execute();
$totalRows = $totalQuery->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);
$totalQuery->close();

// Fetch Data
$stmt = $conn->prepare("SELECT * FROM products $where ORDER BY product_id ASC LIMIT ? OFFSET ?");
$params[] = $limit;
$params[] = $offset;
$types .= "ii";
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

if (isset($_SESSION['alert'])) {
    $alert = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

// First-added category
$firstCategoryResult = $conn->query("SELECT category_name FROM categories ORDER BY category_id ASC LIMIT 1");
$firstCategory = $firstCategoryResult->fetch_assoc()['category_name'] ?? '';

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sugar Baked - Products</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===== Body ===== */
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
  margin: 0;
  letter-spacing: 0.5px;
}

/* ===== Navigation ===== */
.sidebar-nav {
  display: flex;
  flex-direction: column;
  padding-top: 30px;
}

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
@media (max-width:768px){
  .sidebar{
    width:70px;
    padding-top:5px;
  }
  .sidebar-header h4, .sidebar-nav a span{
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

/* ===== Content ===== */
.content{
  margin-left:250px;
  padding:25px;
  background:#f2f3f5;
  box-shadow:0 2px 10px rgba(0,0,0,0.08);
  min-height:100vh;
  transition: margin-left 0.3s ease;
}

@media(max-width:768px){
  .content{
    margin-left:80px;
  }
}

/* ===== Table & Pagination ===== */
.products-table td, .products-table th{
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.products-table{table-layout:fixed;width:100%;}
.products-table th:nth-child(1),.products-table td:nth-child(1){width:50px;}
.products-table th:nth-child(2),.products-table td:nth-child(2){width:190px;}
.products-table th:nth-child(3),.products-table td:nth-child(3){width:120px;}
.products-table th:nth-child(4),.products-table td:nth-child(4){width:100px;}
.products-table th:nth-child(5),.products-table td:nth-child(5){width:70px;}
.products-table th:nth-child(6),.products-table td:nth-child(6){width:110px;}
.products-table th:nth-child(7),.products-table td:nth-child(7){width:180px;}
.products-table th:nth-child(8),.products-table td:nth-child(8){width:130px;}
.products-table th, .products-table td{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

.page-link{border-radius:8px;margin:1px;padding:6px 12px;font-weight:500;font-size:14px;color:#333;background:#fff;border:1px solid #dee2e6;transition:0.2s;}
.page-link:hover{background:#f8f9fa;color:#000;}
.page-item.active .page-link{background:#0066ff;color:#fff;font-weight:600;box-shadow:0 0 6px rgba(0,110,253,0.4);}
.stock-dot{position:absolute;top:3.5px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;}
.pagination-legend{font-size:14px;color:#555;display:flex;justify-content:center;align-items:center;gap:10px;flex-wrap:wrap;}
.legend-item{display:inline-flex;align-items:center;}
.legend-dot{width:6px;height:6px;border-radius:50%;display:inline-block;margin-right:5px;box-shadow:0 0 3px rgba(0,0,0,0.2);}
.page-link-smooth:hover{transform:translateY(-1px);}
</style>

</head>
<body>
<!-- Sidebar -->
<div class="sidebar">
  <div class="sidebar-header">
    <h4>Sugar Baked</h4>
  </div>
  <div class="sidebar-nav">
    <a href="admin_dashboard.php"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="categories.php"><i class="fas fa-tags"></i><span>Categories</span></a>
    <a href="products.php" class="active"><i class="fas fa-box"></i><span>Products</span></a>
    <a href="inventory.php"><i class="fas fa-warehouse"></i><span>Inventory</span></a>
    <a href="admin_pos.php"><i class="fas fa-cash-register"></i><span>POS</span></a>
    <a href="sales.php"><i class="fas fa-receipt"></i><span>Sales</span></a>
    <a href="users.php"><i class="fas fa-users"></i><span>Users</span></a>
    <a href="reports.php"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
    <a href="#" class="logout" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
      <i class="fas fa-sign-out-alt"></i><span>Logout</span>
    </a>
  </div>
</div>

<div class="content py-3"><br>
    <h2 class="mb-4">Product & Stock Management</h2>
    <?php echo $alert; ?>

    <!-- ===== Search + Add Button Row ===== -->
    <div class="d-flex flex-wrap align-items-center mb-3 gap-2">
        <form method="GET" class="d-flex gap-2 flex-grow-1">
            <input type="text" name="search" class="form-control" placeholder="Search Product..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button class="btn btn-secondary">Search</button>
            <a href="products.php" class="btn btn-outline-secondary">Reset</a>
        </form>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
            Add Product
        </button>
    </div>

    <div class="card shadow-sm py-0">
        <div class="card-body" id="productTable">
        <table class="table table-bordered table-striped mb-0 products-table">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Date Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>

<?php
if ($result->num_rows == 0) {
    echo "<tr><td colspan='8' class='text-center text-muted'>No products found.</td></tr>";
}

while($row = $result->fetch_assoc()) {
    $statusBadge = $row['status']=='Available' 
        ? "<span class='badge bg-success'>Available</span>" 
        : ($row['status']=='Low Stock' 
            ? "<span class='badge bg-warning text-dark'>Low Stock</span>" 
            : "<span class='badge bg-danger'>Out of Stock</span>");
    
    $formattedPrice = "₱ " . number_format($row['price'], 2);

    echo "<tr>
        <td>{$row['product_id']}</td>
        <td>{$row['product_name']}</td>
        <td>{$row['category']}</td>
        <td>$formattedPrice</td>
        <td>{$row['stock']}</td>
        <td>$statusBadge</td>
        <td>".date("d-m-Y | h:i A", strtotime($row['date_added']))."</td>
        <td>
            <div class='d-flex gap-2'>
            <button class='btn btn-sm btn-warning' data-bs-toggle='modal' data-bs-target='#editProductModal{$row['product_id']}'>Edit</button>
            <button class='btn btn-sm btn-danger' data-bs-toggle='modal' data-bs-target='#deleteProductModal{$row['product_id']}'>Delete</button>
            </div>
        </td>
    </tr>";

    // Edit Modal
    echo "
    <div class='modal fade' id='editProductModal{$row['product_id']}' tabindex='-1'>
    <div class='modal-dialog modal-dialog-centered'>
    <div class='modal-content'>
    <form method='POST'>
    <div class='modal-header bg-warning text-dark'>
    <h5>Edit Product</h5>
    </div>
    <div class='modal-body'>
    <input type='hidden' name='product_id' value='{$row['product_id']}'>
    <div class='mb-2'>
    <label>Name</label>
    <input type='text' name='product_name' class='form-control' value='{$row['product_name']}' required>
    </div>
    <div class='mb-2'>
    <label>Category</label>
    <select name='category' class='form-select'>";
        foreach($categories as $cat){
            $selected = $cat==$row['category']?'selected':''; 
            echo "<option value='$cat' $selected>$cat</option>";
        }
    echo "</select>
    </div>
    <div class='mb-2'>
    <label>Price</label>
    <div class='input-group'>
        <span class='input-group-text'>₱</span>
        <input type='number' step='0.01' name='price' class='form-control' value='{$row['price']}' required>
    </div>
    </div>
    <div class='mb-2'>
    <label>Add Stock</label>
    <input type='number' name='stock' class='form-control' value='0' min='0' required>
    <small class='text-muted'>Current stock: {$row['stock']}</small>
    </div>
    <div class='mb-2'>
    <label>Expiration Date</label>
    <input type='datetime-local' name='expiration_date' class='form-control'>
    </div>
    </div>
    <div class='modal-footer'>
    <button type='submit' name='update_product' class='btn btn-success'>Update</button>
    <button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
    </div>
    </form>
    </div></div></div>";

    // Delete Modal
    echo "
    <div class='modal fade' id='deleteProductModal{$row['product_id']}' tabindex='-1'>
    <div class='modal-dialog modal-dialog-centered'>
    <div class='modal-content'>
    <div class='modal-header bg-danger text-white'>
    <h5>Confirm Delete</h5>
    </div>
    <div class='modal-body'>
    Are you sure you want to delete <b>{$row['product_name']}</b> from <b>{$row['category']}</b>?
    <p class='text-muted mt-2'><small>Note: All related inventory records will also be deleted.</small></p>
    </div>
    <div class='modal-footer'>
    <a href='products.php?delete={$row['product_id']}&page=$page' class='btn btn-danger'>Yes, Delete</a>
    <button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
    </div>
    </div></div></div>";
}

$stmt->close();
?>

</tbody>
</table>

<!-- Pagination -->
<nav aria-label="Product page navigation" class="text-center mt-3">
  <ul class="pagination justify-content-center flex-wrap my-3">
    <?php
    $queryParams = "&search=" . urlencode($search);

    // ======= Function: Check if a page has low or out of stock =======
    function getStockStatusByPage($conn, $pageNum, $limit, $search = "") {
        $offset = ($pageNum - 1) * $limit;
        $searchQuery = "";
        if (!empty($search)) {
            $safeSearch = mysqli_real_escape_string($conn, $search);
            $searchQuery = "WHERE product_name LIKE '%$safeSearch%'";
        }

        $sql = "SELECT stock FROM products $searchQuery LIMIT $limit OFFSET $offset";
        $result = mysqli_query($conn, $sql);

        if (!$result || mysqli_num_rows($result) == 0) {
            return ['low' => false, 'out' => false];
        }

        $hasLow = false;
        $hasOut = false;

        while ($row = mysqli_fetch_assoc($result)) {
            $stock = (int)$row['stock'];
            if ($stock === 0) $hasOut = true;
            elseif ($stock > 0 && $stock <= 3) $hasLow = true;
        }

        return ['low' => $hasLow, 'out' => $hasOut];
    }

    // ======= Check all pages first (for legend visibility) =======
    $pageHasIndicators = false;
    $pageStatuses = [];
    for ($i = 1; $i <= $totalPages; $i++) {
        $status = getStockStatusByPage($conn, $i, $limit, $search);
        $pageStatuses[$i] = $status;
        if ($status['low'] || $status['out']) {
            $pageHasIndicators = true;
        }
    }

    // ======= Previous Button =======
    if ($page > 1) {
        echo "<li class='page-item'>
                <a class='page-link page-link-smooth' href='?page=" . ($page - 1) . $queryParams . "'>Previous</a>
              </li>";
    }

    // ======= Page Numbers + Smart Indicators =======
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i == $page ? "active" : "";
        $status = $pageStatuses[$i];
        $indicator = "";

        if ($pageHasIndicators) {
            if ($status['out']) {
                $indicator = "<span class='stock-dot bg-danger pulse'></span>";
            } elseif ($status['low']) {
                $indicator = "<span class='stock-dot bg-warning glow'></span>";
            }
        }

        echo "
        <li class='page-item $active position-relative'>
            <a class='page-link page-link-smooth' href='?page=$i$queryParams'>
                $indicator
                $i
            </a>
        </li>";
    }

    // ======= Next Button =======
    if ($page < $totalPages) {
        echo "<li class='page-item'>
                <a class='page-link page-link-smooth' href='?page=" . ($page + 1) . $queryParams . "'>Next</a>
              </li>";
    }
    ?>
  </ul>

  <!-- ===== Legend (always visible) ===== -->
  <div class="pagination-legend text-center mt-2">
    <span class="legend-item me-3">
      <span class="legend-dot bg-danger"></span> Out of Stock
    </span>
    <span class="legend-item">
      <span class="legend-dot bg-warning"></span> Low Stock
    </span>
  </div>
</nav>

<!-- ===== CSS (Compact, Responsive, Fit without Scroll) ===== -->
<style>
.pagination {
  gap: 2px;
  flex-wrap: wrap;
  justify-content: center;
  overflow-x: hidden;
  max-width: 100%;
}
.page-link {
  position: relative;
  border-radius: 8px;
  margin: 1px;
  padding: 6px 12px;
  font-weight: 500;
  font-size: 14px;
  color: #333;
  background-color: #fff;
  border: 1px solid #dee2e6;
  transition: all 0.2s ease-in-out;
  white-space: nowrap;
}
.page-link:hover {
  background-color: #f8f9fa;
  border-color: #bfc3c8;
  color: #000;
}
.page-item.active .page-link {
  background-color: #0066ffff;
  color: #fff;
  font-weight: 600;
  box-shadow: 0 0 6px rgba(13, 110, 253, 0.4);
}

/* Stock indicator */
.stock-dot {
  position: absolute;
  top: 3.5px;
  left: 50%;
  transform: translateX(-50%);
  width: 5px;
  height: 5px;
  border-radius: 50%;
}

/* Legend (fit + centered) */
.pagination-legend {
  font-size: 14px;
  color: #555;
  display: flex;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  text-align: center;
  overflow: hidden;
}
.legend-item {
  display: inline-flex;
  align-items: center;
}
.legend-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  display: inline-block;
  margin-right: 5px;
  box-shadow: 0 0 3px rgba(0,0,0,0.2);
}

/* Smooth hover animation */
.page-link-smooth:hover {
  transform: translateY(-1px);
}
</style>
</div>
</div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Add Product</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Product Name</label>
            <input type="text" name="product_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Category</label>
            <select name="category" class="form-select" required>
              <?php
              $categories = $conn->query("SELECT * FROM categories ORDER BY category_id ASC");
              while($cat = $categories->fetch_assoc()) {
                  $selected = ($cat['category_name'] === $firstCategory) ? "selected" : "";
                  echo "<option value='{$cat['category_name']}' $selected>{$cat['category_name']}</option>";
              }
              ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Price</label>
            <div class="input-group">
              <span class="input-group-text">₱</span>
              <input type="number" step="0.01" name="price" class="form-control" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Stock</label>
            <input type="number" name="stock" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Expiration Date (Optional)</label>
            <input type="datetime-local" name="expiration_date" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="add_product" class="btn btn-success">Add Product</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ===== Logout Confirmation Modal ===== -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="logoutConfirmLabel"><i class="fas fa-sign-out-alt me-2"></i> Confirm Logout</h5>
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
</body>
</html>