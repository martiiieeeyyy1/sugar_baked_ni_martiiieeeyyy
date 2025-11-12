<?php
// inventory.php
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

// ===== DELETE INVENTORY RECORD =====
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Get inventory details before deleting
    $getInv = $conn->prepare("SELECT product_id, product_name, stock_added, is_expired, expiration_date FROM inventory WHERE inventory_id=?");
    $getInv->bind_param("i", $id);
    $getInv->execute();
    $invDetails = $getInv->get_result()->fetch_assoc();
    $getInv->close();
    
    if ($invDetails) {
        $product_id = $invDetails['product_id'];
        $stock_to_remove = $invDetails['stock_added'];
        $is_expired = $invDetails['is_expired'];
        $expiration_date = $invDetails['expiration_date'];
        
        // Check if inventory is actually expired (either marked as expired OR past expiration date)
        $now = new DateTime();
        $isActuallyExpired = false;
        
        if ($is_expired == 1) {
            $isActuallyExpired = true;
        } elseif ($expiration_date && strtotime($expiration_date) < time()) {
            $isActuallyExpired = true;
        }
        
        // Only deduct stock if NOT expired
        if (!$isActuallyExpired) {
            // Update product stock (deduct the inventory stock)
            $updateProduct = $conn->prepare("UPDATE products SET stock = stock - ? WHERE product_id = ?");
            $updateProduct->bind_param("ii", $stock_to_remove, $product_id);
            $updateProduct->execute();
            $updateProduct->close();
            
            $_SESSION['alert'] = "<div class='alert alert-success alert-dismissible fade show'>
                                    Inventory record for <b>{$invDetails['product_name']}</b> ({$stock_to_remove} units) has been deleted successfully.
                                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                                  </div>";
        } else {
            // Expired inventory - don't deduct from products table
            $_SESSION['alert'] = "<div class='alert alert-info alert-dismissible fade show'>
                                    Expired inventory record for <b>{$invDetails['product_name']}</b> ({$stock_to_remove} units) has been deleted. 
                                    <small>(Stock not deducted as it was already expired)</small>
                                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                                  </div>";
        }
        
        // Delete the inventory record
        $stmt = $conn->prepare("DELETE FROM inventory WHERE inventory_id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    
    // ===== AUTO-REORDER IDs =====
    function reorderInventoryIDs($conn) {
        // Temporarily disable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        
        $conn->query("SET @num := 0");
        $conn->query("UPDATE inventory SET inventory_id = (@num := @num + 1) ORDER BY inventory_id ASC");
        $next = $conn->query("SELECT MAX(inventory_id) + 1 AS next_id FROM inventory")->fetch_assoc()['next_id'];
        $next = $next ? $next : 1;
        $conn->query("ALTER TABLE inventory AUTO_INCREMENT = $next");
        
        // Re-enable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    }
    reorderInventoryIDs($conn);
    
    // Adjust page if last item deleted
    $limit = 5;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
    $dateFilter = isset($_GET['date']) ? $_GET['date'] : '';
    
    // Build WHERE clause for count
    $where = "WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($search) {
        $where .= " AND (LOWER(product_name) LIKE CONCAT(?, '%') OR LOWER(category) LIKE CONCAT(?, '%'))";
        $params[] = strtolower(trim($search));
        $params[] = strtolower(trim($search));
        $types .= "ss";
    }
    
    if ($dateFilter) {
        $where .= " AND DATE(expiration_date) = ?";
        $params[] = $dateFilter;
        $types .= "s";
    }
    
    $statusFilterSQL = "";
    if ($statusFilter === 'expired') {
        $statusFilterSQL = " AND expiration_date < NOW()";
    } elseif ($statusFilter === 'expiring_soon') {
        $statusFilterSQL = " AND expiration_date >= NOW() AND expiration_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)";
    } elseif ($statusFilter === 'fresh') {
        $statusFilterSQL = " AND expiration_date > DATE_ADD(NOW(), INTERVAL 7 DAY)";
    }
    $where .= $statusFilterSQL;
    
    $countQuery = $conn->prepare("SELECT COUNT(*) AS total FROM inventory $where");
    if (!empty($params)) {
        $countQuery->bind_param($types, ...$params);
    }
    $countQuery->execute();
    $count = $countQuery->get_result()->fetch_assoc()['total'];
    $countQuery->close();
    
    $totalPages = ceil($count / $limit);
    if ($page > $totalPages) $page = max(1, $totalPages);
    
    header("Location: inventory.php?page=$page&search=$search&status=$statusFilter&date=$dateFilter");
    exit();
}

// ===== AUTO-DELETE INVENTORY RECORDS WITH 0 STOCK =====
$conn->query("DELETE FROM inventory WHERE stock_added = 0");

// ===== PAGINATION + SEARCH + FILTERS =====
$limit = 5;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';

// Build WHERE clause (automatically exclude 0 stock)
$where = "WHERE stock_added > 0";
$params = [];
$types = "";

if ($search) {
    $where .= " AND (LOWER(product_name) LIKE CONCAT(?, '%') OR LOWER(category) LIKE CONCAT(?, '%'))";
    $params[] = $search;
    $params[] = $search;
    $types .= "ss";
}

if ($dateFilter) {
    $where .= " AND DATE(expiration_date) = ?";
    $params[] = $dateFilter;
    $types .= "s";
}

// Status filter logic
$statusFilterSQL = "";
if ($statusFilter === 'expired') {
    $statusFilterSQL = " AND expiration_date < NOW()";
} elseif ($statusFilter === 'expiring_soon') {
    $statusFilterSQL = " AND expiration_date >= NOW() AND expiration_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)";
} elseif ($statusFilter === 'fresh') {
    $statusFilterSQL = " AND expiration_date > DATE_ADD(NOW(), INTERVAL 7 DAY)";
}

$where .= $statusFilterSQL;

// Total Rows
$totalQuery = $conn->prepare("SELECT COUNT(*) AS total FROM inventory $where");
if (!empty($params)) {
    $totalQuery->bind_param($types, ...$params);
}
$totalQuery->execute();
$totalRows = $totalQuery->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);
$totalQuery->close();

// Fetch Data
$stmt = $conn->prepare("SELECT * FROM inventory $where ORDER BY inventory_id ASC LIMIT ? OFFSET ?");
$params[] = $limit;
$params[] = $offset;
$types .= "ii";
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

if (isset($_SESSION['alert'])) {
    $alert = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sugar Baked - Inventory</title>
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

/* ===== Table ===== */
.inventory-table {
  table-layout: fixed;
  width: 100%;
}
.inventory-table th:nth-child(1), .inventory-table td:nth-child(1) { width: 50px; }
.inventory-table th:nth-child(2), .inventory-table td:nth-child(2) { width: 190px; }
.inventory-table th:nth-child(3), .inventory-table td:nth-child(3) { width: 120px; }
.inventory-table th:nth-child(4), .inventory-table td:nth-child(4) { width: 120px; }
.inventory-table th:nth-child(5), .inventory-table td:nth-child(5) { width: 180px; }
.inventory-table th:nth-child(6), .inventory-table td:nth-child(6) { width: 180px; }
.inventory-table th:nth-child(7), .inventory-table td:nth-child(7) { width: 80px; }
.inventory-table td, .inventory-table th {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ===== Expiration Badges ===== */
.badge-expired {
  background-color: #dc3545 !important;
  color: white;
}
.badge-expiring-soon {
  background-color: #ffc107 !important;
  color: #000;
}
.badge-fresh {
  background-color: #28a745 !important;
  color: white;
}

/* ===== Expired Row Styling ===== */
.expired-row {
  background-color: #ffe0e0 !important;
}
.expired-row td {
  color: #dc3545;
  font-weight: 500;
}

/* ===== Filter Buttons ===== */
.filter-btn-group .btn {
  border-radius: 20px;
  padding: 8px 20px;
  font-size: 14px;
  transition: all 0.3s ease;
}

.filter-btn-group .btn.active {
  transform: scale(1.05);
  box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

/* ===== Pagination Styles ===== */
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
  transform: translateY(-1px);
}
.page-item.active .page-link {
  background-color: #0066ff;
  color: #fff;
  font-weight: 600;
  box-shadow: 0 0 6px rgba(13, 110, 253, 0.4);
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
    <a href="admin_dashboard.php"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="categories.php"><i class="fas fa-tags"></i><span>Categories</span></a>
    <a href="products.php"><i class="fas fa-box"></i><span>Products</span></a>
    <a href="inventory.php" class="active"><i class="fas fa-warehouse"></i><span>Inventory</span></a>
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
    <h2 class="mb-4">Inventory & Expiration Records</h2>
    <?php echo $alert; ?>

    <!-- ===== Filter Buttons ===== -->
    <div class="mb-3 filter-btn-group d-flex flex-wrap gap-2">
        <a href="?status=all<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $dateFilter ? '&date='.$dateFilter : ''; ?>" 
           class="btn <?php echo $statusFilter == '' || $statusFilter == 'all' ? 'btn-primary active' : 'btn-outline-primary'; ?>">
            <i class="fas fa-list"></i> All Products
        </a>
        <a href="?status=fresh<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $dateFilter ? '&date='.$dateFilter : ''; ?>" 
           class="btn <?php echo $statusFilter == 'fresh' ? 'btn-success active' : 'btn-outline-success'; ?>">
            <i class="fas fa-check-circle"></i> Fresh
        </a>
        <a href="?status=expiring_soon<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $dateFilter ? '&date='.$dateFilter : ''; ?>" 
           class="btn <?php echo $statusFilter == 'expiring_soon' ? 'btn-warning active' : 'btn-outline-warning'; ?>">
            <i class="fas fa-exclamation-triangle"></i> Expiring Soon
        </a>
        <a href="?status=expired<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $dateFilter ? '&date='.$dateFilter : ''; ?>" 
           class="btn <?php echo $statusFilter == 'expired' ? 'btn-danger active' : 'btn-outline-danger'; ?>">
            <i class="fas fa-times-circle"></i> Expired
        </a>
    </div>

    <!-- ===== Search Row ===== -->
    <form method="GET" class="d-flex align-items-center mb-3 gap-2">
        <input type="text" name="search" class="form-control" placeholder="Search by product or category..." 
               value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; min-width: 200px;">
        <input type="date" name="date" class="form-control" placeholder="Filter by expiration date" 
               value="<?php echo htmlspecialchars($dateFilter); ?>" style="width: 180px;">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
        <button class="btn btn-secondary" style="white-space: nowrap;">Search</button>
        <a href="inventory.php" class="btn btn-outline-secondary" style="white-space: nowrap;">Reset</a>
    </form>

    <div class="card shadow-sm">
        <div class="card-body">
            <table class="table table-bordered table-striped mb-0 inventory-table">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Stock Added</th>
                        <th>Expiration Date</th>
                        <th>Date Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>

<?php
if ($result->num_rows == 0) {
    echo "<tr><td colspan='7' class='text-center text-muted'>No inventory records found.</td></tr>";
}

$rowNumber = $offset + 1;

while($row = $result->fetch_assoc()) {
    $expirationDate = $row['expiration_date'] ? date("M d, Y h:i A", strtotime($row['expiration_date'])) : "N/A";
    
    // Check expiration status with hours
    $expirationBadge = "N/A";
    $isExpired = false;
    $rowClass = "";
    
    if ($row['expiration_date']) {
        $now = new DateTime();
        $expiry = new DateTime($row['expiration_date']);
        $interval = $now->diff($expiry);
        
        // Calculate total hours
        $totalHours = ($interval->days * 24) + $interval->h;
        $minutes = $interval->i;
        
        if ($expiry < $now) {
            // Expired
            $expirationBadge = "<span class='badge badge-expired'><i class='fas fa-skull-crossbones'></i> Expired</span>";
            $isExpired = true;
            $rowClass = "expired-row";
        } elseif ($totalHours < 24) {
            // Less than 24 hours
            if ($totalHours > 0) {
                $expirationBadge = "<span class='badge badge-expiring-soon'><i class='fas fa-clock'></i> Expires in $totalHours hour" . ($totalHours > 1 ? 's' : '') . "</span>";
            } else {
                $expirationBadge = "<span class='badge badge-expiring-soon'><i class='fas fa-clock'></i> Expires in $minutes minute" . ($minutes > 1 ? 's' : '') . "</span>";
            }
        } elseif ($interval->days <= 7) {
            // Within 7 days
            $days = $interval->days;
            $expirationBadge = "<span class='badge badge-expiring-soon'><i class='fas fa-hourglass-half'></i> Expires in $days day" . ($days > 1 ? 's' : '') . "</span>";
        } else {
            // Fresh (more than 7 days)
            $expirationBadge = "<span class='badge badge-fresh'><i class='fas fa-calendar-check'></i> $expirationDate</span>";
        }
    }

    echo "<tr class='$rowClass'>
        <td>{$rowNumber}</td>
        <td>{$row['product_name']}</td>
        <td>{$row['category']}</td>
        <td>{$row['stock_added']}</td>
        <td>$expirationBadge</td>
        <td>".date("d-m-Y | h:i A", strtotime($row['date_added']))."</td>
        <td>";
    
    echo "<button class='btn btn-sm btn-danger' data-bs-toggle='modal' data-bs-target='#deleteInventoryModal{$row['inventory_id']}'>Delete</button>";
    
    echo "</td>
    </tr>";
    
    $rowNumber++;

    // Delete Modal
    echo "
    <div class='modal fade' id='deleteInventoryModal{$row['inventory_id']}' tabindex='-1'>
    <div class='modal-dialog modal-dialog-centered'>
    <div class='modal-content'>
    <div class='modal-header bg-danger text-white'>
    <h5>Confirm Delete</h5>
    </div>
    <div class='modal-body'>
    <p>Are you sure you want to delete this inventory record for <b>{$row['product_name']}</b>?</p>
    <p class='text-warning'><i class='fas fa-exclamation-triangle'></i> <small>This will remove <b>{$row['stock_added']} units</b> from the product stock.</small></p>
    </div>
    <div class='modal-footer'>
    <a href='inventory.php?delete={$row['inventory_id']}&page=$page&status=$statusFilter&search=$search&date=$dateFilter' class='btn btn-danger'>Yes, Delete</a>
    <button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Cancel</button>
    </div>
    </div></div></div>";
}

$stmt->close();
?>

                </tbody>
            </table>

            <!-- Pagination -->
            <nav aria-label="Inventory page navigation" class="text-center mt-3">
              <ul class="pagination justify-content-center flex-wrap my-3">
                <?php
                $queryParams = "&search=" . urlencode($search) . "&status=" . urlencode($statusFilter) . "&date=" . urlencode($dateFilter);

                if ($page > 1) {
                    echo "<li class='page-item'>
                            <a class='page-link' href='?page=" . ($page - 1) . $queryParams . "'>Previous</a>
                          </li>";
                }

                for ($i = 1; $i <= $totalPages; $i++) {
                    $active = $i == $page ? "active" : "";
                    echo "<li class='page-item $active'>
                            <a class='page-link' href='?page=$i$queryParams'>$i</a>
                          </li>";
                }

                if ($page < $totalPages) {
                    echo "<li class='page-item'>
                            <a class='page-link' href='?page=" . ($page + 1) . $queryParams . "'>Next</a>
                          </li>";
                }
                ?>
              </ul>
            </nav>

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