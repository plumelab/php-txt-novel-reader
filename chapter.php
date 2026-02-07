<?php
// chapter.php?book=xxx&index=0  → 返回该书第 index 章的内容

header('Content-Type: application/json; charset=utf-8');

$bookId = isset($_GET['book']) ? basename($_GET['book']) : '';
$index  = isset($_GET['index']) ? intval($_GET['index']) : 0;

if ($bookId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_book'], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseDir = __DIR__;
$bookDir = $baseDir . '/uploads/' . $bookId;
$jsonFile = $bookDir . '/chapters.json';

if (!is_dir($bookDir) || !is_file($jsonFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode(file_get_contents($jsonFile), true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$total = count($data);
if ($total === 0) {
    echo json_encode(['error' => 'empty', 'total' => 0], JSON_UNESCAPED_UNICODE);
    exit;
}

// 索引安全限制
if ($index < 0) $index = 0;
if ($index >= $total) $index = $total - 1;

$chapter = $data[$index];

echo json_encode([
    'index'   => $index,
    'total'   => $total,
    'title'   => isset($chapter['title']) ? $chapter['title'] : ('第'.($index+1).'章'),
    'content' => isset($chapter['content']) ? $chapter['content'] : '',
], JSON_UNESCAPED_UNICODE);