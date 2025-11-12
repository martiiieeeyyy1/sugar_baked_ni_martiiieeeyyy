

<?php
// users.php
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

$alert = ""; // for showing bootstrap alert

// ✅ Roles array para consistent sa Add ug Edit modal
$roles = ["Admin", "Cashier"];

// ====== ADD USER ======
if (isset($_POST['add_user'])) {
    $full_name = trim($_POST['full_name']);
    $username  = trim($_POST['username']);
    $email     = trim($_POST['email']);
    $password  = $_POST['password']; // plain text (mas maayo i-hash gamit password_hash)
    $role      = $_POST['role'];

    // Check for duplicate username or email
    $check = $conn->prepare("SELECT user_id FROM users WHERE username=? OR email=?");
    $check->bind_param("ss", $username, $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $alert = "<div class='alert alert-warning alert-dismissible fade show' role='alert'>
                    Username or Email already exists!
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                  </div>";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, password, role, date_created) 
                                VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssss", $full_name, $username, $email, $password, $role);
        if ($stmt->execute()) {
            $alert = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                        User <b>$full_name</b> successfully added!
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                      </div>";
        }
        $stmt->close();
    }
    $check->close();
}

// ====== UPDATE USER ======
if (isset($_POST['update_user'])) {
    $id        = $_POST['user_id'];
    $full_name = trim($_POST['full_name']);
    $username  = trim($_POST['username']);
    $email     = trim($_POST['email']);
    $role      = $_POST['role'];

    // ✅ Get current user data
    $stmt = $conn->prepare("SELECT full_name, username, email, role FROM users WHERE user_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Check if there are any changes
    if (
        $full_name === $current['full_name'] &&
        $username === $current['username'] &&
        $email === $current['email'] &&
        $role === $current['role']
    ) {
        $alert = "<div class='alert alert-info alert-dismissible fade show' role='alert'>
                    No changes detected. User <b>{$current['full_name']}</b> not updated.
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                  </div>";
    } else {
        // Check duplicates except itself
        $check = $conn->prepare("SELECT user_id FROM users WHERE (username=? OR email=?) AND user_id != ?");
        $check->bind_param("ssi", $username, $email, $id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $alert = "<div class='alert alert-warning alert-dismissible fade show' role='alert'>
                        Another user already uses this Username or Email!
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                      </div>";
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, email=?, role=? WHERE user_id=?");
            $stmt->bind_param("ssssi", $full_name, $username, $email, $role, $id);
            if ($stmt->execute()) {
                $alert = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                            User <b>$full_name</b> successfully updated!
                            <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                          </div>";
            }
            $stmt->close();
        }
        $check->close();
    }
}

// ====== DELETE USER ======
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $deletedUser = $result->fetch_assoc()['full_name'] ?? '';
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM users WHERE user_id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $alert = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                    User <b>$deletedUser</b> successfully deleted!
                    <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                  </div>";
    }
    $stmt->close();
}

// ===== PAGINATION =====
$limit = 5; // ✅ Max 5 users per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';

// Total Rows
$totalQuery = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE LOWER(full_name) LIKE CONCAT('%',?,'%') OR LOWER(username) LIKE CONCAT('%',?,'%') OR LOWER(email) LIKE CONCAT('%',?,'%')");
$totalQuery->bind_param("sss", $search, $search, $search);
$totalQuery->execute();
$totalRows = $totalQuery->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);
$totalQuery->close();

// Fetch paginated users
$stmt = $conn->prepare("SELECT * FROM users WHERE LOWER(full_name) LIKE CONCAT('%',?,'%') OR LOWER(username) LIKE CONCAT('%',?,'%') OR LOWER(email) LIKE CONCAT('%',?,'%') ORDER BY user_id ASC LIMIT ? OFFSET ?");
$stmt->bind_param("sssii", $search, $search, $search, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sugar Baked - Users</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===== Body ===== */
body {
  font-family: "Poppins", sans-serif;
  background-color: #e6e7eb; /* soft gray */
  margin: 0;
}

/* ===== Sidebar ===== */
.sidebar {
  height: 100vh;
  width: 250px;
  position: fixed;
  top: 0;
  left: 0;
  background: #000000ff; /* dark gray */
  color: #babec5ff;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  box-shadow: 3px 0 12px rgba(0, 0, 0, 0.25);
  transition: all 0.3s ease;
  border-right: 1px solid rgba(255, 255, 255, 0.05);
  padding-top: 10px;
}

/* ===== Sidebar Header ===== */
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
  gap: 14px; /* consistent gap between icon and label */
  padding: 14px 20px;
  color: #cbd5e1;
  text-decoration: none;
  font-size: 15px;
  font-weight: 500;
  border-left: 4px solid transparent;
  transition: all 0.25s ease;
  border-radius: 8px;
  margin: 4px 12px;
  line-height: 1.2; /* helps align text vertically */
}

/* Uniform icon size and position */
.sidebar-nav a i {
  width: 22px;
  text-align: center;
  font-size: 18px;
  opacity: 0.85;
}

/* Hover + Active State */
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

/* ===== Content Area ===== */
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

/* ===== Table cleanup ===== */
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
.products-table td:nth-child(1) { width: 40px; }
.products-table th:nth-child(2),
.products-table td:nth-child(2) { width: 170px; }
.products-table th:nth-child(3),
.products-table td:nth-child(3) { width: 90px; }
.products-table th:nth-child(4),
.products-table td:nth-child(4) { width: 150px; }
.products-table th:nth-child(5),
.products-table td:nth-child(5) { width: 70px; }
.products-table th:nth-child(6),
.products-table td:nth-child(6) { width: 160px; }
.products-table th:nth-child(7),
.products-table td:nth-child(7) { width: 110px; }
.products-table th:nth-child(8),
.products-table td:nth-child(8) { width: 130px; }
</style>

<!-- Sidebar -->
<div class="sidebar">
  <div class="sidebar-header">
    <h4>Sugar Baked</h4>
  </div>
  <div class="sidebar-nav">
    <a href="admin_dashboard.php"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>

    <!-- ✅ Category Module -->
    <a href="categories.php"><i class="fas fa-tags"></i><span>Categories</span></a>

    <a href="products.php"><i class="fas fa-box"></i><span>Products</span></a>
    <a href="inventory.php"><i class="fas fa-warehouse"></i><span>Inventory</span></a>
        <a href="admin_pos.php"><i class="fas fa-cash-register"></i><span>POS</span></a>

    <a href="sales.php"><i class="fas fa-receipt"></i><span>Sales</span></a>
    <a href="users.php" class= active><i class="fas fa-users"></i><span>Users</span></a>
        <a href="reports.php"><i class="fas fa-chart-bar"></i><span>Reports</span></a>


    <a href="#" class="logout" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
      <i class="fas fa-sign-out-alt"></i><span>Logout</span>
    </a>
  </div>
</div>


<div class="content py-3"><!-- py-3 = padding top & bottom --><br>
    <h2 class="mb-4">Users Management</h2>
    <?php echo $alert; ?>

    <!-- ===== Search + Add Button Row ===== -->
    <div class="d-flex flex-wrap align-items-center mb-3 gap-2">
        <!-- Search Form -->
        <form method="GET" class="d-flex gap-2 flex-grow-1">
            <input type="text" name="search" class="form-control" placeholder="Search users..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button class="btn btn-secondary">Search</button>
            <a href="users.php" class="btn btn-outline-secondary">Reset</a>
        </form>

        <!-- Add Button -->
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            Add User
        </button>
    </div>


<div class="card shadow-sm">
  <div class="card-body">
<div class="table-responsive" style="max-height:500px; overflow-y:auto;">
    <table class="table table-bordered table-striped align-middle products-table">
        <thead class="table-dark">
          <tr>
            <th class="text-start">ID</th>
            <th class="text-start">Full Name</th>
            <th class="text-start">Username</th>
            <th class="text-start">Email</th>
            <th class="text-start">Role</th>
            <th class="text-start">Date Created</th>
            <th class="text-start">Actions</th>
          </tr>
        </thead>
        <tbody>
<?php while ($row = $result->fetch_assoc()) { ?>
  <tr>
      <td><?= $row['user_id'] ?></td>
      <td><?= htmlspecialchars($row['full_name']) ?></td>
      <td><?= htmlspecialchars($row['username']) ?></td>
      <td><?= htmlspecialchars($row['email']) ?></td>
      <td>
          <?php 
              if ($row['role'] === 'Admin') {
                  echo "<span class='badge bg-danger'>Admin</span>";
              } else {
                  echo "<span class='badge bg-info'>Cashier</span>";
              }
          ?>
      </td>
      <td><?= date('d-m-Y | h:i A', strtotime($row['date_created'])) ?></td>
      <td class='text-center'>
        <div class='d-flex justify-content-center gap-2'>
          <button class='btn btn-sm btn-warning' data-bs-toggle='modal' data-bs-target='#editUserModal<?= $row['user_id'] ?>'>Edit</button>
          <button class='btn btn-sm btn-danger' data-bs-toggle='modal' data-bs-target='#deleteUserModal<?= $row['user_id'] ?>'>Delete</button>
        </div>
      </td>
  </tr>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal<?= $row['user_id'] ?>" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title">Edit User - <?= htmlspecialchars($row['full_name']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="user_id" value="<?= $row['user_id'] ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name</label>
              <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($row['full_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Username</label>
              <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($row['username']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($row['email']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <?php if($row['role'] === 'Admin'): ?>
                <input type="text" class="form-control" value="Admin" readonly>
                <input type="hidden" name="role" value="Admin">
              <?php else: ?>
                <input type="text" class="form-control" value="Cashier" readonly>
                <input type="hidden" name="role" value="Cashier">
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="update_user" class="btn btn-success">Update</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>



          <!-- Delete User Modal -->
          <div class="modal fade" id="deleteUserModal<?= $row['user_id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                  <h5 class="modal-title">Delete User</h5>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                  Are you sure you want to delete <b><?= htmlspecialchars($row['full_name']) ?></b>?
                </div>
                <div class="modal-footer justify-content-center">
                  <a href="users.php?delete=<?= $row['user_id'] ?>" class="btn btn-danger">Yes, Delete</a>
                  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
              </div>
            </div>
          </div>

      <?php } ?>
      </tbody>
    </table>
  </div>

<!-- Pagination (before table/content) -->
<nav aria-label="Users page navigation" class="mb-3"> <!-- mb-2 para gamay lang spacing -->
  <ul class="pagination justify-content-center flex-wrap my-0">
    <?php
    $queryParams = "&search=" . urlencode($search);

    if ($page > 1) {
        echo "<li class='page-item'>
                <a class='page-link' href='?page=" . ($page - 1) . $queryParams . "'>Previous</a>
              </li>";
    }

    for ($i = 1; $i <= $totalPages; $i++) {
        $active = ($i == $page) ? "active" : "";
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


<!-- ✅ Updated Pagination CSS -->
<style>
.pagination {
  gap: 2px;
  flex-wrap: wrap;
  justify-content: center;
  overflow-x: hidden; /* para mo-fit sa mobile ug table */
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
  background-color: #0066ffff; /* match sa products.php */
  color: #fff;
  font-weight: 600;
  box-shadow: 0 0 6px rgba(13, 110, 253, 0.4);
}
.page-link-smooth:hover {
  transform: translateY(-1px);
}

/* Mobile responsiveness */
@media (max-width: 576px) {
  .page-link {
    padding: 4px 8px;
    font-size: 13px;
  }
}
</style>


      
    </div>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Add User</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name</label>
              <input type="text" name="full_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Username</label>
              <input type="text" name="username" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <input type="text" class="form-control" value="Cashier" readonly>
              <input type="hidden" name="role" value="Cashier">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="add_user" class="btn btn-success">Save</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
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
</body>
</html>
