<?php
// ===================================================
// cashier_sales.php - Sugar Baked Cashier Sales Records with Status
// ===================================================
// ===== IMPORTANT: Set session name BEFORE session_start() =====
session_name('LASTNALANGKA_CASHIER');
session_start();
include 'db_connect.php';

// Set timezone
date_default_timezone_set('Asia/Manila');

// ===== CHECK IF USER IS LOGGED IN =====
if (!isset($_SESSION['user_id']) || strcasecmp($_SESSION['role'], 'Cashier') !== 0) {
    header("Location: index.php");
    exit();
}

// ===== GET CASHIER INFO =====
$cashier_id = $_SESSION['user_id'];
$cashier_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Cashier';

$alert = ""; // For showing bootstrap alert

// ===== Check if status column exists, if not add it =====
$checkColumn = $conn->query("SHOW COLUMNS FROM sales LIKE 'status'");
if ($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE sales ADD COLUMN status VARCHAR(20) DEFAULT 'Completed' AFTER role");
}

// ===== Show POS success or error alerts =====
if (isset($_SESSION['sale_success'])) {
    $alert = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                {$_SESSION['sale_success']}
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
              </div>";
    unset($_SESSION['sale_success']);
}

if (isset($_SESSION['sale_error'])) {
    $alert = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>
                {$_SESSION['sale_error']}
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
              </div>";
    unset($_SESSION['sale_error']);
}

// ===== Pagination, Search & Date Filter =====
$limit = 5;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
$filter_date = isset($_GET['filter_date']) && $_GET['filter_date'] !== '' ? $_GET['filter_date'] : '';
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';

// ===== Compute date range =====
date_default_timezone_set('Asia/Manila');
$start_date = null;
$end_date = null;

if ($filter_date) {
    $d = DateTime::createFromFormat('Y-m-d', $filter_date);
    if ($d !== false) {
        $start_date = $d->format('Y-m-d') . ' 00:00:00';
        $end_date   = $d->format('Y-m-d') . ' 23:59:59';
    }
}

// ===== Build WHERE clause dynamically (CASHIER SPECIFIC) =====
$where = "WHERE cashier_id = ?";
$params = [$cashier_id];
$types = "i";

// Search
if ($search !== '') {
    $where .= " AND LOWER(product_name) LIKE ?";
    $searchLike = $search . '%';
    $params[] = $searchLike;
    $types .= 's';
}

// Date filter
if ($start_date && $end_date) {
    $where .= " AND (sale_date BETWEEN ? AND ?)";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
}

// Status filter
if ($status_filter !== '') {
    $where .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

// ===== Total rows =====
$totalQuery = $conn->prepare("SELECT COUNT(*) AS total FROM sales $where");
if (!empty($params)) {
    $totalQuery->bind_param($types, ...$params);
}
$totalQuery->execute();
$totalRows = $totalQuery->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);
$totalQuery->close();

// ===== Fetch Sales Data (CASHIER SPECIFIC) =====
$stmt = $conn->prepare("SELECT * FROM sales $where ORDER BY sales_id DESC LIMIT ? OFFSET ?");
$paramsForData = $params;
$typesForData = $types;
$paramsForData[] = $limit;
$paramsForData[] = $offset;
$typesForData .= "ii";
$stmt->bind_param($typesForData, ...$paramsForData);
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
<title>Sugar Baked - My Sales</title>
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
  background: #000000ff;
  color: #babec5ff;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  box-shadow: 3px 0 12px rgba(0, 0, 0, 0.25);
  transition: all 0.3s ease;
  border-right: 1px solid rgba(255, 255, 255, 0.05);
  padding-top: 10px;
}

.sidebar-header {
  text-align: center;
  padding: 28px 0;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.sidebar-header h4 {
  font-size: 1.5rem;
  font-weight: 700;
  color: #f3f4f6;
  letter-spacing: 0.5px;
  margin: 0;
}

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
  background: rgba(59, 130, 246, 0.08);
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

@media (max-width: 768px) {
  .sidebar {
    width: 70px;
    padding-top: 5px;
  }
  .sidebar-header h4,
  .sidebar-nav a span {
    display: none;
  }
  .sidebar:hover {
    width: 230px;
  }
  .sidebar:hover .sidebar-header h4,
  .sidebar:hover .sidebar-nav a span {
    display: inline;
  }
}

.content {
  margin-left: 250px;
  padding: 25px;
  background: #f2f3f5;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
  transition: margin-left 0.3s ease;
  min-height: 100vh;
}

@media (max-width: 768px) {
  .content {
    margin-left: 80px;
  }
}

.products-table td, 
.products-table th {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.products-table {
  table-layout: fixed;
  width: 100%;
}

.products-table th:nth-child(1),
.products-table td:nth-child(1) { width: 50px; }
.products-table th:nth-child(2),
.products-table td:nth-child(2) { width: 200px; }
.products-table th:nth-child(3),
.products-table td:nth-child(3) { width: 90px; }
.products-table th:nth-child(4),
.products-table td:nth-child(4) { width: 90px; }
.products-table th:nth-child(5),
.products-table td:nth-child(5) { width: 110px; }
.products-table th:nth-child(6),
.products-table td:nth-child(6) { width: 90px; }
.products-table th:nth-child(7),
.products-table td:nth-child(7) { width: 180px; }
.products-table th:nth-child(8),
.products-table td:nth-child(8) { width: 70px; }

.products-table th, 
.products-table td {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ===== Status Filter Buttons ===== */
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

/* ===== Pagination ===== */
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
  background-color: #0066ff;
  color: #fff;
  font-weight: 600;
  box-shadow: 0 0 6px rgba(13, 110, 253, 0.4);
}
.page-link-smooth:hover {
  transform: translateY(-1px);
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
    <a href="cashier_dashboard.php"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="pos.php"><i class="fas fa-cash-register"></i><span>POS</span></a>
    <a href="cashier_sales.php" class="active"><i class="fas fa-receipt"></i><span>My Sales</span></a>
    <a href="#" class="logout" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
      <i class="fas fa-sign-out-alt"></i><span>Logout</span>
    </a>
  </div>
</div>

<div class="content py-3"><br>
  <h2 class="mb-4">My Sales Records</h2>

  <?php echo $alert; ?>

  <!-- ===== Status Filter Buttons ===== -->
  <div class="mb-3 filter-btn-group d-flex flex-wrap gap-2">
    <a href="?status_filter=<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $filter_date ? '&filter_date='.$filter_date : ''; ?>" 
       class="btn <?php echo $status_filter == '' ? 'btn-primary active' : 'btn-outline-primary'; ?>">
        <i class="fas fa-list"></i> All My Sales
    </a>
    <a href="?status_filter=Completed<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $filter_date ? '&filter_date='.$filter_date : ''; ?>" 
       class="btn <?php echo $status_filter == 'Completed' ? 'btn-success active' : 'btn-outline-success'; ?>">
        <i class="fas fa-check-circle"></i> Completed
    </a>
    <a href="?status_filter=Voided<?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $filter_date ? '&filter_date='.$filter_date : ''; ?>" 
       class="btn <?php echo $status_filter == 'Voided' ? 'btn-danger active' : 'btn-outline-danger'; ?>">
        <i class="fas fa-times-circle"></i> Voided
    </a>
  </div>

  <!-- ===== Search Form ===== -->
  <form method="GET" class="mb-3 d-flex align-items-center" style="gap:10px;">
    <input type="text" name="search" class="form-control" placeholder="Search product..." style="flex:1;"
      value="<?php echo htmlspecialchars($search); ?>">
    <input type="date" name="filter_date" class="form-control" style="width:180px;"
      value="<?php echo htmlspecialchars($filter_date); ?>">
    <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
    <button type="submit" class="btn btn-secondary" style="white-space:nowrap;">Search</button>
    <a href="cashier_sales.php" class="btn btn-outline-secondary" style="white-space:nowrap;">Reset</a>
  </form>

 <div class="card shadow-sm">
  <div class="card-body">
    <table class="table table-bordered table-striped align-middle products-table">
      <thead class="table-dark">
        <tr>
          <th>ID</th>
          <th>Product</th>
          <th>Quantity</th>
          <th>Price</th>
          <th>Total</th>
          <th>Status</th>
          <th>Date</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
<?php
$counter = $offset + 1;
$vatRate = 0.12;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $displayId = $counter++;
        $dbId = $row['sales_id'];

        $pname = htmlspecialchars($row['product_name']);
        $qty = (int)$row['quantity'];
        $price = (float)$row['price'];
        $subtotal = $price * $qty;
        $vat = $subtotal * $vatRate;
        $totalWithVAT = $subtotal + $vat;

        $formattedPrice = number_format($price, 2);
        $formattedTotal = number_format($totalWithVAT, 2);

        $formattedDate = (new DateTime($row['sale_date']))->format('d-m-Y | h:i A');
        
        $status = $row['status'] ?? 'Completed';
        $statusBadge = $status == 'Completed' 
            ? "<span class='badge bg-success'>Completed</span>" 
            : "<span class='badge bg-danger'>Voided</span>";

        echo "<tr>
          <td>$displayId</td>
          <td>$pname</td>
          <td>$qty</td>
          <td>₱ $formattedPrice</td>
          <td>₱ $formattedTotal</td>
          <td>$statusBadge</td>
          <td>$formattedDate</td>
          <td>
            <button class='btn btn-sm btn-info' data-bs-toggle='modal' data-bs-target='#viewSaleModal$dbId'>View</button>
          </td>
        </tr>";

        // View Modal
        echo "<div class='modal fade' id='viewSaleModal$dbId' tabindex='-1'>
                <div class='modal-dialog modal-dialog-centered'>
                  <div class='modal-content'>
                    <div class='modal-header bg-info text-white'>
                      <h5 class='modal-title'>Sale Details</h5>
                    </div>
                    <div class='modal-body'>
                      <p><b>Product:</b> $pname</p>
                      <p><b>Quantity:</b> $qty</p>
                      <p><b>Price:</b> ₱ $formattedPrice</p>
                      <p><b>Subtotal:</b> ₱ " . number_format($subtotal, 2) . "</p>
                      <p><b>VAT (12%):</b> ₱ " . number_format($vat, 2) . "</p>
                      <p><b>Total:</b> ₱ $formattedTotal</p>
                      <p><b>Status:</b> $statusBadge</p>
                      <p><b>Date:</b> $formattedDate</p>
                    </div>
                    <div class='modal-footer'>
                      <button class='btn btn-secondary' data-bs-dismiss='modal'>Close</button>
                    </div>
                  </div>
                </div>
              </div>";
    }
} else {
    echo "<tr><td colspan='8' class='text-center'>No records found.</td></tr>";
}

$stmt->close();
?>
        </tbody>
      </table>

    <!-- Pagination -->
    <nav aria-label="Sales page navigation" class="text-center mt-3">
      <ul class="pagination justify-content-center flex-wrap my-3">
        <?php
        $queryParams = "";
        if (!empty($search)) {
            $queryParams .= "&search=" . urlencode($search);
        }
        if (!empty($filter_date)) {
            $queryParams .= "&filter_date=" . urlencode($filter_date);
        }
        if (!empty($status_filter)) {
            $queryParams .= "&status_filter=" . urlencode($status_filter);
        }

        if ($page > 1) {
            echo "<li class='page-item'>
                    <a class='page-link page-link-smooth' href='?page=" . ($page - 1) . $queryParams . "'>Previous</a>
                  </li>";
        }

        for ($i = 1; $i <= $totalPages; $i++) {
            $active = $i == $page ? "active" : "";
            echo "<li class='page-item $active'>
                    <a class='page-link page-link-smooth' href='?page=$i$queryParams'>$i</a>
                  </li>";
        }

        if ($page < $totalPages) {
            echo "<li class='page-item'>
                    <a class='page-link page-link-smooth' href='?page=" . ($page + 1) . $queryParams . "'>Next</a>
                  </li>";
        }
        ?>
      </ul>
    </nav>

    </div>
  </div>
</div>

<!-- Logout Modal -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-danger text-white"><h5 class="modal-title">Confirm Logout</h5></div>
      <div class="modal-body text-center"><p class="text-secondary">Are you sure you want to log out?</p></div>
      <div class="modal-footer justify-content-center">
        <a href="logout.php" class="btn btn-danger px-4">Yes, Logout</a>
        <button class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>