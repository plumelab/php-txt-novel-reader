<?php
session_start();
if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

$bookId = isset($_GET['book']) ? basename($_GET['book']) : '';
if ($bookId === '') {
    header('Location: index.php');
    exit;
}

$baseDir = __DIR__;
$bookDir = $baseDir . '/uploads/' . $bookId;

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.','..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

if (is_dir($bookDir)) {
    rrmdir($bookDir);
}

header('Location: index.php');