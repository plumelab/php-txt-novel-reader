<?php
// chapter_list.php?book=xxx  → 返回章节列表（仅标题）

header('Content-Type: application/json; charset=utf-8');

$bookId = isset($_GET['book']) ? basename($_GET['book']) : '';
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
$chapters = [];

for ($i = 0; $i < $total; $i++) {
    $title = isset($data[$i]['title']) ? $data[$i]['title'] : ('第' . ($i + 1) . '章');
    $chapters[] = [
        'index' => $i,
        'title' => $title
    ];
}

echo json_encode([
    'total' => $total,
    'chapters' => $chapters
], JSON_UNESCAPED_UNICODE);
