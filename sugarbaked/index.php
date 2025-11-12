<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sugar Baked Login</title>
  <link rel="stylesheet" href="css/style.css">

  <!-- Google Icons (for eye icon) -->
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

  <style>
    /* You can move this to style.css if you want */
    .password-container {
      position: relative;
      width: 100%;
    }

    .password-container input {
      width: 93.5%;
      padding-right: 0px; /* space for icon */
    }

    .toggle-password {
      position: absolute;
      right: 15px;
      top: 35%;
      transform: translateY(-57%);
      cursor: pointer;
      user-select: none;
      color: #555;
    }

    .toggle-password:hover {
      color: #000;
    }
  </style>
</head>
<body>
  <div class="glass-container">
    <h2>Login</h2>
    
    <form action="login.php" method="POST">
      <input type="text" name="username" placeholder="Username" required>

      <div class="password-container">
        <input type="password" id="passwordField" name="password" placeholder="Password" required>
        <span class="material-icons toggle-password" id="togglePassword">visibility_off</span>
      </div>  
      
      <button type="submit">Login</button>
    </form>
  </div>

  <script>
    const passwordField = document.getElementById("passwordField");
    const togglePassword = document.getElementById("togglePassword");

    togglePassword.addEventListener("click", () => {
      // If password is hidden, show it
      if (passwordField.type === "password") {
        passwordField.type = "text";
        togglePassword.textContent = "visibility"; // Change icon
      } else {
        passwordField.type = "password";
        togglePassword.textContent = "visibility_off"; // Change icon
      }
    });
  </script>

</body>
</html>
