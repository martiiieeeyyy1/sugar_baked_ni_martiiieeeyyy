<?php
// ===================================================
// admin_dashboard.php - Sugar Baked Admin Dashboard
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

// ===== GET ADMIN NAME =====
$admin_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';

// ===== FETCH DASHBOARD STATISTICS (COMPLETED SALES ONLY) =====

// Total Revenue (Completed Sales Only)
$revenueQuery = $conn->query("
    SELECT SUM(price * quantity * 1.12) AS total_revenue 
    FROM sales 
    WHERE status = 'Completed'
");
$totalRevenue = $revenueQuery->fetch_assoc()['total_revenue'] ?? 0;

// Total Completed Sales
$completedSalesQuery = $conn->query("
    SELECT COUNT(*) AS total_sales 
    FROM sales 
    WHERE status = 'Completed'
");
$totalCompletedSales = $completedSalesQuery->fetch_assoc()['total_sales'] ?? 0;

// Total Products
$productsQuery = $conn->query("SELECT COUNT(*) AS total_products FROM products");
$totalProducts = $productsQuery->fetch_assoc()['total_products'] ?? 0;

// Low Stock Products (stock <= 3)
$lowStockQuery = $conn->query("
    SELECT COUNT(*) AS low_stock_count 
    FROM products 
    WHERE stock > 0 AND stock <= 3
");
$lowStockCount = $lowStockQuery->fetch_assoc()['low_stock_count'] ?? 0;

// Out of Stock Products
$outOfStockQuery = $conn->query("
    SELECT COUNT(*) AS out_stock_count 
    FROM products 
    WHERE stock = 0
");
$outOfStockCount = $outOfStockQuery->fetch_assoc()['out_stock_count'] ?? 0;

// Expired Products
$expiredQuery = $conn->query("
    SELECT COUNT(*) AS expired_count 
    FROM inventory 
    WHERE expiration_date < NOW() AND is_expired = 1
");
$expiredCount = $expiredQuery->fetch_assoc()['expired_count'] ?? 0;

// ===== BESTSELLING PRODUCTS (TOP 5 - COMPLETED SALES ONLY) =====
$bestsellingQuery = $conn->query("
    SELECT 
        product_name, 
        SUM(quantity) AS total_sold,
        SUM(price * quantity * 1.12) AS total_revenue
    FROM sales 
    WHERE status = 'Completed'
    GROUP BY product_name 
    ORDER BY total_sold DESC 
    LIMIT 5
");

// ===== RECENT COMPLETED SALES (LAST 5) =====
$recentSalesQuery = $conn->query("
    SELECT 
        product_name, 
        quantity, 
        price, 
        cashier_name, 
        sale_date,
        (price * quantity * 1.12) AS total
    FROM sales 
    WHERE status = 'Completed'
    ORDER BY sale_date DESC 
    LIMIT 5
");

// ===== DAILY SALES CHART DATA (LAST 7 DAYS - COMPLETED ONLY) =====
$dailySalesData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $salesQuery = $conn->query("
        SELECT SUM(price * quantity * 1.12) AS daily_total 
        FROM sales 
        WHERE DATE(sale_date) = '$date' AND status = 'Completed'
    ");
    $dailyTotal = $salesQuery->fetch_assoc()['daily_total'] ?? 0;
    $dailySalesData[] = [
        'date' => date('M d', strtotime($date)),
        'total' => $dailyTotal
    ];
}

// ===== MONTHLY SALES (COMPLETED ONLY) =====
$currentMonth = date('Y-m');
$monthlySalesQuery = $conn->query("
    SELECT SUM(price * quantity * 1.12) AS monthly_total 
    FROM sales 
    WHERE DATE_FORMAT(sale_date, '%Y-%m') = '$currentMonth' AND status = 'Completed'
");
$monthlySales = $monthlySalesQuery->fetch_assoc()['monthly_total'] ?? 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sugar Baked - Admin Dashboard</title>
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

/* Welcome Header Styling */
.welcome-header {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 15px;
  padding: 30px;
  margin-bottom: 30px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
  color: white;
}

.welcome-header h2 {
  font-size: 2rem;
  font-weight: 700;
  margin: 0;
  text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
}

.welcome-header p {
  font-size: 1rem;
  margin: 10px 0 0 0;
  opacity: 0.95;
}

.welcome-header .admin-icon {
  font-size: 3rem;
  opacity: 0.3;
  position: absolute;
  right: 30px;
  top: 20px;
}

@media (max-width: 768px) {
  .welcome-header h2 {
    font-size: 1.5rem;
  }
  .welcome-header .admin-icon {
    font-size: 2rem;
    right: 20px;
  }
}

/* Dashboard Cards */
.stat-card {
  border-radius: 15px;
  padding: 25px;
  color: white;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
  transition: transform 0.3s ease, box-shadow 0.3s ease;
  background: linear-gradient(135deg, var(--card-color-1), var(--card-color-2));
}

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.stat-card .icon {
  font-size: 3rem;
  opacity: 0.3;
  position: absolute;
  right: 20px;
  top: 20px;
}

.stat-card h3 {
  font-size: 2.5rem;
  font-weight: 700;
  margin: 0;
}

.stat-card p {
  font-size: 1rem;
  margin: 5px 0 0 0;
  opacity: 0.9;
}

/* Card Colors */
.card-revenue {
  --card-color-1: #4CAF50;
  --card-color-2: #45a049;
}

.card-sales {
  --card-color-1: #2196F3;
  --card-color-2: #1976D2;
}

.card-products {
  --card-color-1: #FF9800;
  --card-color-2: #F57C00;
}

.card-lowstock {
  --card-color-1: #FFC107;
  --card-color-2: #FFA000;
}

.card-outstock {
  --card-color-1: #F44336;
  --card-color-2: #D32F2F;
}

.card-expired {
  --card-color-1: #9C27B0;
  --card-color-2: #7B1FA2;
}

/* Charts and Tables */
.chart-container {
  background: white;
  border-radius: 15px;
  padding: 25px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
  margin-bottom: 25px;
}

.table-container {
  background: white;
  border-radius: 15px;
  padding: 25px;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
}

.table-container h5 {
  color: #333;
  font-weight: 600;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
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
.rank-other { background: linear-gradient(135deg, #e0e0e0, #bdbdbd); color: #666; }
</style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
  <div class="sidebar-header">
    <h4>Sugar Baked</h4>
  </div>
  <div class="sidebar-nav">
    <a href="admin_dashboard.php" class="active"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="categories.php"><i class="fas fa-tags"></i><span>Categories</span></a>
    <a href="products.php"><i class="fas fa-box"></i><span>Products</span></a>
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

<!-- Main Content -->
<div class="content py-3"><br>
  
  <!-- Welcome Header -->
  <div class="welcome-header position-relative">
    <i class="fas fa-user-shield admin-icon"></i>
    <h2>Welcome, Admin <?php echo htmlspecialchars($admin_name); ?>!</h2>
    <p><i class="fas fa-calendar-day me-2"></i><?php echo date('l, F d, Y | h:i A'); ?></p>
  </div>

  <!-- Statistics Cards -->
  <div class="row g-4 mb-4">
    <!-- Total Revenue Card -->
    <div class="col-md-4">
      <div class="stat-card card-revenue position-relative">
        <i class="fas fa-peso-sign icon"></i>
        <h3>₱ <?php echo number_format($totalRevenue, 2); ?></h3>
        <p>Total Revenue</p>
      </div>
    </div>

    <!-- Total Completed Sales Card -->
    <div class="col-md-4">
      <div class="stat-card card-sales position-relative">
        <i class="fas fa-check-circle icon"></i>
        <h3><?php echo number_format($totalCompletedSales); ?></h3>
        <p>Completed Sales</p>
      </div>
    </div>

    <!-- Total Products Card -->
    <div class="col-md-4">
      <div class="stat-card card-products position-relative">
        <i class="fas fa-box icon"></i>
        <h3><?php echo number_format($totalProducts); ?></h3>
        <p>Total Products</p>
      </div>
    </div>
  </div>

  <!-- Secondary Stats -->
  <div class="row g-4 mb-4">
    <!-- Low Stock Card -->
    <div class="col-md-4">
      <div class="stat-card card-lowstock position-relative">
        <i class="fas fa-exclamation-triangle icon"></i>
        <h3><?php echo number_format($lowStockCount); ?></h3>
        <p>Low Stock</p>
      </div>
    </div>

    <!-- Out of Stock Card -->
    <div class="col-md-4">
      <div class="stat-card card-outstock position-relative">
        <i class="fas fa-times-circle icon"></i>
        <h3><?php echo number_format($outOfStockCount); ?></h3>
        <p>Out of Stock</p>
      </div>
    </div>

    <!-- Expired Products Card -->
    <div class="col-md-4">
      <div class="stat-card card-expired position-relative">
        <i class="fas fa-skull-crossbones icon"></i>
        <h3><?php echo number_format($expiredCount); ?></h3>
        <p>Expired Products</p>
      </div>
    </div>
  </div>

  <!-- Charts Row -->
  <div class="row g-4 mb-4">
    <!-- Daily Sales Chart -->
    <div class="col-lg-8">
      <div class="chart-container">
        <h5><i class="fas fa-chart-line text-primary"></i> Daily Sales (Last 7 Days)</h5>
        <canvas id="dailySalesChart"></canvas>
      </div>
    </div>

    <!-- Monthly Summary -->
    <div class="col-lg-4">
      <div class="chart-container">
        <h5><i class="fas fa-calendar-alt text-success"></i> This Month</h5>
        <div class="text-center mt-4">
          <h2 class="text-success mb-2">₱ <?php echo number_format($monthlySales, 2); ?></h2>
          <p class="text-muted">Total Sales</p>
          <hr>
          <p class="mb-1"><strong><?php echo date('F Y'); ?></strong></p>
        </div>
      </div>
    </div>
  </div>

  <!-- Tables Row -->
  <div class="row g-4">
    <!-- Bestselling Products -->
    <div class="col-lg-6">
      <div class="table-container">
        <h5><i class="fas fa-trophy text-warning"></i> Top 5 Bestselling Products</h5>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th width="60">Rank</th>
                <th>Product Name</th>
                <th class="text-center">Sold</th>
                <th class="text-end">Revenue</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $rank = 1;
              if ($bestsellingQuery->num_rows > 0) {
                  while ($row = $bestsellingQuery->fetch_assoc()) {
                      $rankClass = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : 'rank-other'));
                      echo "<tr>
                              <td><span class='badge-rank $rankClass'>$rank</span></td>
                              <td><strong>{$row['product_name']}</strong></td>
                              <td class='text-center'><span class='badge bg-primary'>{$row['total_sold']}</span></td>
                              <td class='text-end text-success'><strong>₱ " . number_format($row['total_revenue'], 2) . "</strong></td>
                            </tr>";
                      $rank++;
                  }
              } else {
                  echo "<tr><td colspan='4' class='text-center text-muted'>No sales data available</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Recent Sales -->
    <div class="col-lg-6">
      <div class="table-container">
        <h5><i class="fas fa-clock text-info"></i> Recent Completed Sales</h5>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead class="table-light">
              <tr>
                <th>Product</th>
                <th class="text-center">Qty</th>
                <th class="text-end">Total</th>
                <th>Cashier</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              if ($recentSalesQuery->num_rows > 0) {
                  while ($row = $recentSalesQuery->fetch_assoc()) {
                      echo "<tr>
                              <td><strong>{$row['product_name']}</strong><br>
                                  <small class='text-muted'>" . date('M d, h:i A', strtotime($row['sale_date'])) . "</small>
                              </td>
                              <td class='text-center'><span class='badge bg-secondary'>{$row['quantity']}</span></td>
                              <td class='text-end text-success'><strong>₱ " . number_format($row['total'], 2) . "</strong></td>
                              <td><small class='text-muted'>{$row['cashier_name']}</small></td>
                            </tr>";
                  }
              } else {
                  echo "<tr><td colspan='4' class='text-center text-muted'>No recent sales</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
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
// Daily Sales Chart
const dailySalesCtx = document.getElementById('dailySalesChart').getContext('2d');
const dailySalesChart = new Chart(dailySalesCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($dailySalesData, 'date')); ?>,
        datasets: [{
            label: 'Sales (₱)',
            data: <?php echo json_encode(array_column($dailySalesData, 'total')); ?>,
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
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                display: true,
                position: 'top'
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                padding: 12,
                titleFont: { size: 14 },
                bodyFont: { size: 13 },
                callbacks: {
                    label: function(context) {
                        return 'Sales: ₱ ' + context.parsed.y.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₱ ' + value.toLocaleString();
                    }
                }
            }
        }
    }
});
</script>
</body>
</html>