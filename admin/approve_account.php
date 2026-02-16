<?php
// Admin Interface for Approving Account Requests

// Include database connection file
include_once 'db_connection.php';

// Check if the user is logged in as admin
session_start();
if(!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header('Location: login.php');
    exit;
}

// Fetch account requests from the database
$query = "SELECT * FROM account_requests WHERE status = 'pending'";
$result = mysqli_query($conn, $query);

// Approve account function
if (isset($_POST['approve'])) {
    $request_id = $_POST['request_id'];
    $approve_query = "UPDATE account_requests SET status = 'approved' WHERE id = '$request_id'";
    mysqli_query($conn, $approve_query);
    header('Location: approve_account.php');
}

// Display account requests
?>
<!DOCTYPE html>
<html>
<head>
    <title>Approve Account Requests</title>
</head>
<body>
<h1>Account Requests</h1>
<table>
    <tr>
        <th>ID</th>
        <th>User</th>
        <th>Action</th>
    </tr>
    <?php while($row = mysqli_fetch_assoc($result)) { ?>
    <tr>
        <td><?php echo $row['id']; ?></td>
        <td><?php echo $row['username']; ?></td>
        <td>
            <form method="post">
                <input type="hidden" name="request_id" value="<?php echo $row['id']; ?>">
                <input type="submit" name="approve" value="Approve">
            </form>
        </td>
    </tr>
    <?php } ?>
</table>
</body>
</html>