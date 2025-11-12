<?php
// ===================================================
// reports.php - Sugar Baked Comprehensive Reports
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

date_default_timezone_set('Asia/Manila');

// ===== DATE FILTER HANDLING =====
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Convert to datetime for SQL
$startDateTime = $startDate . ' 00:00:00';
$endDateTime = $endDate . ' 23:59:59';

// ===== SALES REPORTS (COMPLETED ONLY) =====
$salesRevenueQuery = $conn->query("
    SELECT 
        SUM(price * quantity * 1.12) AS total_revenue,
        SUM(price * quantity) AS subtotal,
        SUM(price * quantity * 0.12) AS total_vat,
        COUNT(*) AS total_sales
    FROM sales 
    WHERE status = 'Completed' 
    AND sale_date BETWEEN '$startDateTime' AND '$endDateTime'
");
$salesData = $salesRevenueQuery->fetch_assoc();
$totalRevenue = $salesData['total_revenue'] ?? 0;
$subtotal = $salesData['subtotal'] ?? 0;
$totalVAT = $salesData['total_vat'] ?? 0;
$totalSales = $salesData['total_sales'] ?? 0;

// Voided Sales Count
$voidedQuery = $conn->query("
    SELECT COUNT(*) AS voided_count 
    FROM sales 
    WHERE status = 'Voided' 
    AND sale_date BETWEEN '$startDateTime' AND '$endDateTime'
");
$voidedCount = $voidedQuery->fetch_assoc()['voided_count'] ?? 0;

// ===== TOP SELLING PRODUCTS =====
$topProductsQuery = $conn->query("
    SELECT 
        product_name, 
        SUM(quantity) AS total_sold,
        SUM(price * quantity * 1.12) AS revenue
    FROM sales 
    WHERE status = 'Completed'
    AND sale_date BETWEEN '$startDateTime' AND '$endDateTime'
    GROUP BY product_name 
    ORDER BY total_sold DESC 
    LIMIT 5
");

// ===== SALES BY CATEGORY =====
$categoryQuery = $conn->query("
    SELECT 
        p.category,
        SUM(s.quantity) AS total_sold,
        SUM(s.price * s.quantity * 1.12) AS revenue
    FROM sales s
    JOIN products p ON s.product_name = p.product_name
    WHERE s.status = 'Completed'
    AND s.sale_date BETWEEN '$startDateTime' AND '$endDateTime'
    GROUP BY p.category
    ORDER BY revenue DESC
");

// ===== CASHIER PERFORMANCE =====
$cashierQuery = $conn->query("
    SELECT 
        cashier_name,
        COUNT(*) AS total_transactions,
        SUM(price * quantity * 1.12) AS total_revenue
    FROM sales 
    WHERE status = 'Completed'
    AND sale_date BETWEEN '$startDateTime' AND '$endDateTime'
    GROUP BY cashier_name
    ORDER BY total_revenue DESC
");

// ===== INVENTORY STATUS =====
$inventoryQuery = $conn->query("
    SELECT 
        COUNT(*) AS total_products,
        SUM(CASE WHEN stock > 3 THEN 1 ELSE 0 END) AS available,
        SUM(CASE WHEN stock > 0 AND stock <= 3 THEN 1 ELSE 0 END) AS low_stock,
        SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) AS out_of_stock
    FROM products
");
$inventoryData = $inventoryQuery->fetch_assoc();

// ===== EXPIRED PRODUCTS =====
$expiredQuery = $conn->query("
    SELECT 
        product_name,
        category,
        stock_added,
        expiration_date,
        date_added
    FROM inventory
    WHERE is_expired = 1
    AND date_added BETWEEN '$startDateTime' AND '$endDateTime'
    ORDER BY expiration_date DESC
    LIMIT 10
");

// ===== LOW STOCK PRODUCTS =====
$lowStockQuery = $conn->query("
    SELECT 
        product_name,
        category,
        stock,
        status
    FROM products
    WHERE stock > 0 AND stock <= 3
    ORDER BY stock ASC
    LIMIT 10
");

// ===== DAILY SALES TREND =====
$dailyTrendQuery = $conn->query("
    SELECT 
        DATE(sale_date) AS sale_day,
        COUNT(*) AS transactions,
        SUM(price * quantity * 1.12) AS revenue
    FROM sales
    WHERE status = 'Completed'
    AND sale_date BETWEEN '$startDateTime' AND '$endDateTime'
    GROUP BY DATE(sale_date)
    ORDER BY sale_day ASC
");

$dailyTrend = [];
while ($row = $dailyTrendQuery->fetch_assoc()) {
    $dailyTrend[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sugar Baked - Reports</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body {
  font-family: "Poppins", sans-serif;
  background-color: #e6e7eb;
  margin: 0;
}

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
  min-height: 100vh;
  transition: margin-left 0.3s ease;
}

@media (max-width: 768px) {
  .content {
    margin-left: 80px;
  }
}

/* Report Cards */
.report-card {
  background: white;
  border-radius: 15px;
  padding: 25px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
  margin-bottom: 25px;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.report-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
}

.report-card h5 {
  color: #333;
  font-weight: 600;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
  border-bottom: 2px solid #e0e0e0;
  padding-bottom: 10px;
}

.stat-box {
  background: linear-gradient(135deg, var(--box-color-1), var(--box-color-2));
  border-radius: 12px;
  padding: 20px;
  color: white;
  text-align: center;
  height: 100%;
}

.stat-box h3 {
  font-size: 2rem;
  font-weight: 700;
  margin: 10px 0;
}

.stat-box p {
  margin: 0;
  opacity: 0.9;
  font-size: 0.9rem;
}

.stat-revenue { --box-color-1: #4CAF50; --box-color-2: #45a049; }
.stat-sales { --box-color-1: #2196F3; --box-color-2: #1976D2; }
.stat-vat { --box-color-1: #FF9800; --box-color-2: #F57C00; }
.stat-voided { --box-color-1: #F44336; --box-color-2: #D32F2F; }

.date-filter-box {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 15px;
  padding: 25px;
  color: white;
  box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
}

.date-filter-box label {
  font-weight: 500;
  margin-bottom: 8px;
  display: block;
}

.badge-rank {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  font-weight: bold;
  font-size: 14px;
}

.rank-1 { background: linear-gradient(135deg, #FFD700, #FFA500); color: white; }
.rank-2 { background: linear-gradient(135deg, #C0C0C0, #A8A8A8); color: white; }
.rank-3 { background: linear-gradient(135deg, #CD7F32, #B87333); color: white; }

.progress-bar-custom {
  height: 25px;
  border-radius: 10px;
  font-weight: 600;
  font-size: 0.9rem;
}

@media print {
  .sidebar, .no-print {
    display: none !important;
  }
  .content {
    margin-left: 0;
  }
  .report-card {
    page-break-inside: avoid;
  }
}
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar no-print">
  <div class="sidebar-header">
    <h4>Sugar Baked</h4>
  </div>
  <div class="sidebar-nav">
    <a href="admin_dashboard.php"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="categories.php"><i class="fas fa-tags"></i><span>Categories</span></a>
    <a href="products.php"><i class="fas fa-box"></i><span>Products</span></a>
    <a href="inventory.php"><i class="fas fa-warehouse"></i><span>Inventory</span></a>
    <a href="admin_pos.php"><i class="fas fa-cash-register"></i><span>POS</span></a>
    <a href="sales.php"><i class="fas fa-receipt"></i><span>Sales</span></a>
    <a href="users.php"><i class="fas fa-users"></i><span>Users</span></a>
    <a href="reports.php" class="active"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
    <a href="#" class="logout" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
      <i class="fas fa-sign-out-alt"></i><span>Logout</span>
    </a>
  </div>
</div>

<!-- Main Content -->
<div class="content py-3"><br>
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i></i> Comprehensive Reports</h2>
    <button class="btn btn-success no-print" onclick="window.print()">
      <i class="fas fa-print"></i> Print Report
    </button>
  </div>

  <!-- Date Filter -->
  <div class="date-filter-box mb-4 no-print">
    <form method="GET" class="row g-3 align-items-end">
      <div class="col-md-4">
        <label>Start Date</label>
        <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>" required>
      </div>
      <div class="col-md-4">
        <label>End Date</label>
        <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>" required>
      </div>
      <div class="col-md-4">
        <button type="submit" class="btn btn-light w-100">
          <i class="fas fa-filter"></i> Apply Filter
        </button>
      </div>
    </form>
    <div class="text-center mt-3">
      <small>Showing reports from <strong><?php echo date('M d, Y', strtotime($startDate)); ?></strong> to <strong><?php echo date('M d, Y', strtotime($endDate)); ?></strong></small>
    </div>
  </div>

  <!-- Sales Summary -->
  <div class="report-card">
    <h5><i class="fas fa-dollar-sign text-success"></i> Sales Summary</h5>
    <div class="row g-3">
      <div class="col-md-3">
        <div class="stat-box stat-revenue">
          <i class="fas fa-coins fa-2x mb-2"></i>
          <p>Total Revenue</p>
          <h3>₱ <?php echo number_format($totalRevenue, 2); ?></h3>
        </div>
      </div>
      <div class="col-md-3">
        <div class="stat-box stat-sales">
          <i class="fas fa-receipt fa-2x mb-2"></i>
          <p>Completed Sales</p>
          <h3><?php echo number_format($totalSales); ?></h3>
        </div>
      </div>
      <div class="col-md-3">
        <div class="stat-box stat-vat">
          <i class="fas fa-percent fa-2x mb-2"></i>
          <p>Total VAT (12%)</p>
          <h3>₱ <?php echo number_format($totalVAT, 2); ?></h3>
        </div>
      </div>
      <div class="col-md-3">
        <div class="stat-box stat-voided">
          <i class="fas fa-ban fa-2x mb-2"></i>
          <p>Voided Sales</p>
          <h3><?php echo number_format($voidedCount); ?></h3>
        </div>
      </div>
    </div>
  </div>

  <!-- Charts Row -->
  <div class="row g-4 mb-4">
    <!-- Daily Sales Trend -->
    <div class="col-lg-8">
      <div class="report-card">
        <h5><i class="fas fa-chart-area text-info"></i> Daily Sales Trend</h5>
        <canvas id="dailyTrendChart"></canvas>
      </div>
    </div>

    <!-- Sales by Category -->
    <div class="col-lg-4">
      <div class="report-card">
        <h5><i class="fas fa-chart-pie text-warning"></i> Sales by Category</h5>
        <canvas id="categoryChart"></canvas>
      </div>
    </div>
  </div>

  <!-- Top Products & Cashier Performance -->
  <div class="row g-4 mb-4">
    <!-- Top 10 Selling Products -->
    <div class="col-lg-6">
      <div class="report-card">
        <h5><i class="fas fa-trophy text-warning"></i> Top 5 Bestselling Products</h5>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th width="60">Rank</th>
                <th>Product</th>
                <th class="text-center">Quantity</th>
                <th class="text-end">Revenue</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $rank = 1;
              if ($topProductsQuery->num_rows > 0) {
                  while ($row = $topProductsQuery->fetch_assoc()) {
                      $rankClass = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : ''));
                      echo "<tr>
                              <td>" . ($rankClass ? "<span class='badge-rank $rankClass'>$rank</span>" : "<span class='badge bg-secondary'>$rank</span>") . "</td>
                              <td><strong>{$row['product_name']}</strong></td>
                              <td class='text-center'><span class='badge bg-primary'>{$row['total_sold']}</span></td>
                              <td class='text-end text-success'><strong>₱ " . number_format($row['revenue'], 2) . "</strong></td>
                            </tr>";
                      $rank++;
                  }
              } else {
                  echo "<tr><td colspan='4' class='text-center text-muted'>No sales data</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Cashier Performance -->
    <div class="col-lg-6">
      <div class="report-card">
        <h5><i class="fas fa-user-tie text-primary"></i> Cashier Performance</h5>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th>Cashier Name</th>
                <th class="text-center">Transactions</th>
                <th class="text-end">Revenue</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              if ($cashierQuery->num_rows > 0) {
                  while ($row = $cashierQuery->fetch_assoc()) {
                      echo "<tr>
                              <td><strong>{$row['cashier_name']}</strong></td>
                              <td class='text-center'><span class='badge bg-info'>{$row['total_transactions']}</span></td>
                              <td class='text-end text-success'><strong>₱ " . number_format($row['total_revenue'], 2) . "</strong></td>
                            </tr>";
                  }
              } else {
                  echo "<tr><td colspan='3' class='text-center text-muted'>No data available</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Inventory Status -->
  <div class="row g-4 mb-4">
    <div class="col-lg-12">
      <div class="report-card">
        <h5><i class="fas fa-boxes text-danger"></i> Inventory Status Overview</h5>
        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <div class="text-center p-3 bg-light rounded">
              <h4 class="text-primary"><?php echo $inventoryData['total_products']; ?></h4>
              <p class="mb-0">Total Products</p>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center p-3 bg-light rounded">
              <h4 class="text-success"><?php echo $inventoryData['available']; ?></h4>
              <p class="mb-0">Available</p>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center p-3 bg-light rounded">
              <h4 class="text-warning"><?php echo $inventoryData['low_stock']; ?></h4>
              <p class="mb-0">Low Stock</p>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center p-3 bg-light rounded">
              <h4 class="text-danger"><?php echo $inventoryData['out_of_stock']; ?></h4>
              <p class="mb-0">Out of Stock</p>
            </div>
          </div>
        </div>

        <div class="progress" style="height: 30px;">
          <?php
          $total = $inventoryData['total_products'];
          if ($total > 0) {
              $availablePct = ($inventoryData['available'] / $total) * 100;
              $lowStockPct = ($inventoryData['low_stock'] / $total) * 100;
              $outPct = ($inventoryData['out_of_stock'] / $total) * 100;
              
              echo "<div class='progress-bar bg-success' style='width: {$availablePct}%'>" . round($availablePct) . "%</div>";
              echo "<div class='progress-bar bg-warning' style='width: {$lowStockPct}%'>" . round($lowStockPct) . "%</div>";
              echo "<div class='progress-bar bg-danger' style='width: {$outPct}%'>" . round($outPct) . "%</div>";
          }
          ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Low Stock & Expired Products -->
  <div class="row g-4 mb-4">
    <!-- Low Stock Products -->
    <div class="col-lg-6">
      <div class="report-card">
        <h5><i class="fas fa-exclamation-triangle text-warning"></i> Low Stock Products</h5>
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead class="table-light">
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th class="text-center">Stock</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              if ($lowStockQuery->num_rows > 0) {
                  while ($row = $lowStockQuery->fetch_assoc()) {
                      echo "<tr>
                              <td><strong>{$row['product_name']}</strong></td>
                              <td><span class='badge bg-secondary'>{$row['category']}</span></td>
                              <td class='text-center'><span class='badge bg-warning text-dark'>{$row['stock']}</span></td>
                              <td><span class='badge bg-warning text-dark'>{$row['status']}</span></td>
                            </tr>";
                  }
              } else {
                  echo "<tr><td colspan='4' class='text-center text-muted'>No low stock products</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Expired Products -->
    <div class="col-lg-6">
      <div class="report-card">
        <h5><i class="fas fa-skull-crossbones text-danger"></i> Recently Expired Products</h5>
        <div class="table-responsive">
          <table class="table table-sm table-hover">
            <thead class="table-light">
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th class="text-center">Quantity</th>
                <th>Expired On</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              if ($expiredQuery->num_rows > 0) {
                  while ($row = $expiredQuery->fetch_assoc()) {
                      echo "<tr>
                              <td><strong>{$row['product_name']}</strong></td>
                              <td><span class='badge bg-secondary'>{$row['category']}</span></td>
                              <td class='text-center'><span class='badge bg-danger'>{$row['stock_added']}</span></td>
                              <td><small>" . date('M d, Y', strtotime($row['expiration_date'])) . "</small></td>
                            </tr>";
                  }
              } else {
                  echo "<tr><td colspan='4' class='text-center text-muted'>No expired products in this period</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Category Sales Details -->
  <div class="report-card">
    <h5><i class="fas fa-layer-group text-info"></i> Sales by Category </h5>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-dark">
          <tr>
            <th>Category</th>
            <th class="text-center">Units Sold</th>
            <th class="text-end">Revenue</th>
            <th>Performance</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $categoryQuery->data_seek(0);
          $maxRevenue = 0;
          $categories = [];
          while ($row = $categoryQuery->fetch_assoc()) {
              $categories[] = $row;
              if ($row['revenue'] > $maxRevenue) {
                  $maxRevenue = $row['revenue'];
              }
          }

          foreach ($categories as $row) {
              $percentage = $maxRevenue > 0 ? ($row['revenue'] / $maxRevenue) * 100 : 0;
              echo "<tr>
                      <td><strong>{$row['category']}</strong></td>
                      <td class='text-center'><span class='badge bg-primary'>{$row['total_sold']}</span></td>
                      <td class='text-end text-success'><strong>₱ " . number_format($row['revenue'], 2) . "</strong></td>
                      <td>
                        <div class='progress' style='height: 20px;'>
                          <div class='progress-bar bg-info progress-bar-striped progress-bar-animated' 
                               style='width: {$percentage}%'>
                            " . round($percentage) . "%
                          </div>
                        </div>
                      </td>
                    </tr>";
          }
          
          if (empty($categories)) {
              echo "<tr><td colspan='4' class='text-center text-muted'>No category data available</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Logout Modal -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Confirm Logout</h5>
      </div>
      <div class="modal-body text-center">
        <p>Are you sure you want to log out?</p>
      </div>
      <div class="modal-footer justify-content-center">
        <a href="logout.php" class="btn btn-danger">Yes, Logout</a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Daily Sales Trend Chart
const dailyTrendCtx = document.getElementById('dailyTrendChart').getContext('2d');
const dailyTrendData = <?php echo json_encode($dailyTrend); ?>;

const dailyTrendChart = new Chart(dailyTrendCtx, {
    type: 'line',
    data: {
        labels: dailyTrendData.map(d => {
            const date = new Date(d.sale_day);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }),
        datasets: [{
            label: 'Daily Revenue (₱)',
            data: dailyTrendData.map(d => parseFloat(d.revenue)),
            borderColor: '#4CAF50',
            backgroundColor: 'rgba(76, 175, 80, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointRadius: 5,
            pointBackgroundColor: '#4CAF50',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointHoverRadius: 7
        }, {
            label: 'Transactions',
            data: dailyTrendData.map(d => parseInt(d.transactions)),
            borderColor: '#2196F3',
            backgroundColor: 'rgba(33, 150, 243, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointRadius: 5,
            pointBackgroundColor: '#2196F3',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointHoverRadius: 7,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: {
            mode: 'index',
            intersect: false
        },
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    font: { size: 12, weight: 'bold' },
                    usePointStyle: true,
                    padding: 15
                }
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12,
                titleFont: { size: 14 },
                bodyFont: { size: 13 },
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.datasetIndex === 0) {
                            label += '₱ ' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        } else {
                            label += context.parsed.y + ' sales';
                        }
                        return label;
                    }
                }
            }
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₱ ' + value.toLocaleString();
                    }
                },
                title: {
                    display: true,
                    text: 'Revenue (₱)',
                    font: { size: 12, weight: 'bold' }
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                beginAtZero: true,
                grid: {
                    drawOnChartArea: false
                },
                ticks: {
                    stepSize: 1
                },
                title: {
                    display: true,
                    text: 'Transactions',
                    font: { size: 12, weight: 'bold' }
                }
            }
        }
    }
});

// Category Pie Chart
const categoryCtx = document.getElementById('categoryChart').getContext('2d');
<?php
$categoryQuery->data_seek(0);
$categoryNames = [];
$categoryRevenues = [];
$categoryColors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF'];
$colorIndex = 0;
while ($row = $categoryQuery->fetch_assoc()) {
    $categoryNames[] = $row['category'];
    $categoryRevenues[] = $row['revenue'];
}
?>

const categoryChart = new Chart(categoryCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($categoryNames); ?>,
        datasets: [{
            data: <?php echo json_encode($categoryRevenues); ?>,
            backgroundColor: [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: true,
                position: 'bottom',
                labels: {
                    font: { size: 11 },
                    padding: 10,
                    usePointStyle: true
                }
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12,
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed || 0;
                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                        const percentage = ((value / total) * 100).toFixed(1);
                        return label + ': ₱' + value.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' (' + percentage + '%)';
                    }
                }
            }
        }
    }
});
</script>
</body>
</html>