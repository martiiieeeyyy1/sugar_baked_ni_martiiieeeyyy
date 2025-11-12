<?php
// ===================================================
// categories.php - Category Management Module
// Sugar Baked System
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

// ====== PAGINATION ======
$limit = 5;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// ====== TOTAL COUNT ======
$totalQuery = $conn->query("SELECT COUNT(*) AS total FROM categories");
$totalRows = $totalQuery->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// ====== FETCH CATEGORIES ======
$stmt = $conn->prepare("SELECT * FROM categories ORDER BY category_id ASC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$categories = $stmt->get_result();

// ====== ALERTS ======
$alert = $_SESSION['alert'] ?? '';
unset($_SESSION['alert']);

// ===================================================
// CATEGORY ACTIONS (Add, Update, Delete)
// ===================================================

// -----------------
// ADD CATEGORY
// -----------------
if (isset($_POST['add_category'])) {
    // Auto capitalize first letter of each word
    $category_name = ucwords(strtolower(trim($_POST['category_name'])));

    if (!empty($category_name)) {
        $check = $conn->prepare("
            SELECT category_id 
            FROM categories 
            WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(?)) 
            LIMIT 1
        ");
        $check->bind_param("s", $category_name);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0) {
            $_SESSION['alert'] = "
            <div class='alert alert-warning alert-dismissible fade show shadow-sm'>
               <strong>Duplicate Category:</strong> The category name already exists. Please try a different name.
              <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
        } else {
            $ins = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
            $ins->bind_param("s", $category_name);
            $ins->execute();

            $_SESSION['alert'] = "
            <div class='alert alert-success alert-dismissible fade show shadow-sm'>
               <strong>Success!</strong> The new category <strong>" . htmlspecialchars($category_name) . "</strong> has been added successfully.
              <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
        }
    } else {
        $_SESSION['alert'] = "
        <div class='alert alert-danger alert-dismissible fade show shadow-sm'>
           <strong>Empty Field:</strong> Please enter a category name before submitting.
          <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    }

    header("Location: categories.php");
    exit;
}

// -----------------
// UPDATE CATEGORY
// -----------------
if (isset($_POST['update_category'])) {
    $category_id = intval($_POST['category_id']);
    // Auto capitalize first letter of each word
    $category_name = ucwords(strtolower(trim($_POST['category_name'])));

    if (!empty($category_name)) {
        // Check if existing name is the same
        $check = $conn->prepare("SELECT category_name FROM categories WHERE category_id = ?");
        $check->bind_param("i", $category_id);
        $check->execute();
        $result = $check->get_result()->fetch_assoc();
        $old_name = trim($result['category_name']);

        if (strcasecmp($old_name, $category_name) === 0) {
            // No changes
            $_SESSION['alert'] = "
            <div class='alert alert-warning alert-dismissible fade show shadow-sm'>
               <strong>No Changes Detected:</strong> The category name is still the same. Nothing was updated.
              <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
        } else {
            // Check for duplicates with other categories
            $dupCheck = $conn->prepare("SELECT category_id FROM categories WHERE LOWER(TRIM(category_name)) = LOWER(TRIM(?)) AND category_id != ?");
            $dupCheck->bind_param("si", $category_name, $category_id);
            $dupCheck->execute();
            $dupResult = $dupCheck->get_result();

            if ($dupResult->num_rows > 0) {
                $_SESSION['alert'] = "
                <div class='alert alert-warning alert-dismissible fade show shadow-sm'>
                   <strong>Duplicate Category:</strong> Another category with this name already exists.
                  <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
            } else {
                // Update category
                $stmtUp = $conn->prepare("UPDATE categories SET category_name = ? WHERE category_id = ?");
                $stmtUp->bind_param("si", $category_name, $category_id);
                $stmtUp->execute();

                $_SESSION['alert'] = "
                <div class='alert alert-success alert-dismissible fade show shadow-sm'>
                   <strong>Updated:</strong> Category name has been successfully changed to 
                  <strong>" . htmlspecialchars($category_name) . "</strong>.
                  <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
            }
        }
    } else {
        $_SESSION['alert'] = "
        <div class='alert alert-danger alert-dismissible fade show shadow-sm'>
           <strong>Invalid Input:</strong> Category name cannot be empty.
          <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    }

    header("Location: categories.php?page=" . urlencode($page));
    exit;
}

// -----------------
// DELETE CATEGORY
// -----------------
if (isset($_POST['delete_category_id'])) {
    $id = intval($_POST['delete_category_id']);

    $check = $conn->prepare("SELECT COUNT(*) AS count FROM products WHERE category = (SELECT category_name FROM categories WHERE category_id = ?)");
    $check->bind_param("i", $id);
    $check->execute();
    $count = $check->get_result()->fetch_assoc()['count'];

    if ($count > 0) {
        $_SESSION['alert'] = "
        <div class='alert alert-warning alert-dismissible fade show shadow-sm'>
           <strong>Action Denied:</strong> This category cannot be deleted because it's still linked to existing products.
          <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    } else {
        $del = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
        $del->bind_param("i", $id);
        $del->execute();

        $_SESSION['alert'] = "
        <div class='alert alert-success alert-dismissible fade show shadow-sm'>
           <strong>Deleted:</strong> The category has been removed successfully.
          <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
    }

    header("Location: categories.php?page=" . urlencode($page));
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sugar Baked - Categories</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
  transition: margin-left 0.3s ease;
  min-height: 100vh;
}

@media (max-width: 768px) {
  .content {
    margin-left: 80px;
  }
}

.category-table {
  table-layout: fixed;
  width: 100%;
}
.category-table th:nth-child(1), .category-table td:nth-child(1) { width: 80px; }
.category-table th:nth-child(2), .category-table td:nth-child(2) { width: auto; }
.category-table th:nth-child(3), .category-table td:nth-child(3) { width: 200px; }

.page-link {
  border-radius: 8px;
  margin: 1px;
  padding: 6px 12px;
  font-weight: 500;
  font-size: 14px;
  color: #333;
  background: #fff;
  border: 1px solid #dee2e6;
  transition: 0.2s;
}
.page-link:hover {
  background: #f8f9fa;
  color: #000;
}
.page-item.active .page-link {
  background: #0066ff;
  color: #fff;
  font-weight: 600;
  box-shadow: 0 0 6px rgba(0,110,253,0.4);
}
</style>
</head>
<body>

<div class="sidebar">
  <div class="sidebar-header">
    <h4>Sugar Baked</h4>
  </div>
  <div class="sidebar-nav">
    <a href="admin_dashboard.php"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
    <a href="categories.php" class="active"><i class="fas fa-tags"></i><span>Categories</span></a>
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

<div class="content py-3"><br>
    <h2 class="mb-4">Categories Management</h2>

  <?= $alert; ?>

  <div class="d-flex justify-content-start mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
      <i></i> Add Category
    </button>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <table class="table table-bordered table-striped mb-0 text-center category-table">
        <thead class="table-dark">
          <tr>
            <th>ID</th>
            <th>Category Name</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php 
        if ($categories->num_rows > 0): 
            $rowNumber = $offset + 1; // Auto-reordering number
            while ($row = $categories->fetch_assoc()): 
        ?>
          <tr>
            <td><?= $rowNumber ?></td>
            <td><?= htmlspecialchars($row['category_name']) ?></td>
            <td>
              <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['category_id'] ?>">Edit</button>
              <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $row['category_id'] ?>">Delete</button>
            </td>
          </tr>

          <!-- Edit Modal -->
          <div class="modal fade" id="editModal<?= $row['category_id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <form method="POST">
                  <div class="modal-header bg-warning">
                    <h5 class="modal-title">Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <input type="hidden" name="category_id" value="<?= $row['category_id'] ?>">
                    <input type="text" name="category_name" class="form-control"
                           value="<?= htmlspecialchars($row['category_name']) ?>" required>
                  </div>
                  <div class="modal-footer">
                    <button type="submit" name="update_category" class="btn btn-success">Update</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <!-- Delete Modal -->
          <div class="modal fade" id="deleteModal<?= $row['category_id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <form method="POST">
                  <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Delete Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <p>Are you sure you want to delete <strong><?= htmlspecialchars($row['category_name']) ?></strong>?</p>
                    <input type="hidden" name="delete_category_id" value="<?= $row['category_id'] ?>">
                  </div>
                  <div class="modal-footer">
                    <button type="submit" class="btn btn-danger">Delete</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                  </div>
                </form>
              </div>
            </div>
          </div>

        <?php 
            $rowNumber++;
            endwhile; 
        else: 
        ?>
          <tr><td colspan="3" class="text-muted">No categories found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <!-- Pagination -->
      <nav aria-label="Category page navigation" class="text-center mt-3">
        <ul class="pagination justify-content-center flex-wrap">
          <?php
          if ($page > 1) {
              echo "<li class='page-item'>
                      <a class='page-link' href='?page=" . ($page - 1) . "'>Previous</a>
                    </li>";
          }

          for ($i = 1; $i <= $totalPages; $i++) {
              $active = $i == $page ? "active" : "";
              echo "<li class='page-item $active'>
                      <a class='page-link' href='?page=$i'>$i</a>
                    </li>";
          }

          if ($page < $totalPages) {
              echo "<li class='page-item'>
                      <a class='page-link' href='?page=" . ($page + 1) . "'>Next</a>
                    </li>";
          }
          ?>
        </ul>
      </nav>

    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header bg-primary text-white">
          <h5>Add Category</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="text" name="category_name" class="form-control" placeholder="Enter category name" required>
        </div>
        <div class="modal-footer">
          <button type="submit" name="add_category" class="btn btn-success">Save</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
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
</body>
</html> 