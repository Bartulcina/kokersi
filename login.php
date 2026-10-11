<?php

session_start();
include "db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    $stmt = $mysqli->prepare(
        "SELECT id, username, password, is_admin
         FROM users
         WHERE username = ?"
    );

    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {

        if (password_verify($password, $user["password"])) {

            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["username"] = $user["username"];
            $_SESSION["is_admin"] = $user["is_admin"];

            header("Location: index.php");
            exit;
        }
    }

    $message = "Wrong username or password";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

<h1>Login</h1>
<?php include "navbar.php"; ?>

<p><?php echo htmlspecialchars($message); ?></p>


<form method="post">
    <input type="text" name="username" placeholder="Username">

    <br>

    <input type="password" name="password" placeholder="Password">

    <br>

    <button type="submit">Login</button>
</form>

</body>
</html>