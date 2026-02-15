<?php
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

// Navigation menu
$navigation = '<nav><ul>';
$navigation .= '<li><a href="dashboard.php">Dashboard</a></li>';
$navigation .= '<li><a href="config-builder.php">Config Builder</a></li>';
$navigation .= '<li><a href="logout.php">Logout</a></li>';
$navigation .= '</ul></nav>';

// Dashboard content
$content = '<h1>Dashboard</h1>';
$content .= '<div class="dashboard-cards">';
$content .= '<div class="card">';
$content .= '<h2>Devices</h2>';
$content .= '<p>Manage your devices here.</p>';
$content .= '</div>';
$content .= '<div class="card">';
$content .= '<h2>Config Builder</h2>';
$content .= '<p>Build your configurations here.</p>';
$content .= '</div>';
$content .= '</div>';

// Output the final page
include('header.php');
echo $navigation;
echo $content;
include('footer.php');
?>