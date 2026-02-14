<?php
// progress.php
// GET  progress.php?book=xxx        读取进度
// POST book=xxx&chapterIndex=0&pageIndex=0   保存进度

header('Content-Type: application/json; charset=utf-8');

$bookId = isset($_REQUEST['book']) ? basename($_REQUEST['book']) : '';
if ($bookId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_book'], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseDir   = __DIR__;
$bookDir   = $baseDir . '/uploads/' . $bookId;
$progressFile = $bookDir . '/progress.json';

if (!is_dir($bookDir)) {
    http_response_code(404);
    echo json_encode(['error' => 'book_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (is_file($progressFile)) {
        $json = file_get_contents($progressFile);
        $data = json_decode($json, true);
        if (is_array($data) && isset($data['chapterIndex'], $data['pageIndex'])) {
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    // 没有进度文件或文件无效 → 默认从 0 章 0 页开始
    echo json_encode([
        'chapterIndex' => 0,
        'pageIndex'    => 0
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $chapterIndex = isset($_POST['chapterIndex']) ? intval($_POST['chapterIndex']) : 0;
    $pageIndex    = isset($_POST['pageIndex']) ? intval($_POST['pageIndex']) : 0;

    $data = [
        'chapterIndex' => max(0, $chapterIndex),
        'pageIndex'    => max(0, $pageIndex),
        'updatedAt'    => time()
    ];

    if (file_put_contents($progressFile, json_encode($data, JSON_UNESCAPED_UNICODE)) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'write_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);