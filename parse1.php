<?php
// parse.php?book=xxx  → 解析该书 txt 为 chapters.json

$bookId = isset($_GET['book']) ? basename($_GET['book']) : '';
if ($bookId === '') {
    header('Location: index.php');
    exit;
}

$baseDir   = __DIR__;
$bookDir   = $baseDir . '/uploads/' . $bookId;
$bookFile  = $bookDir . '/book.txt';
$jsonFile  = $bookDir . '/chapters.json';

if (!is_file($bookFile)) {
    die('找不到原始 txt 文件');
}

// 读取全文并转为 UTF-8
$content = file_get_contents($bookFile);
$encoding = mb_detect_encoding($content, ['UTF-8','GBK','GB2312','BIG5','ASCII']);
if ($encoding !== 'UTF-8') {
    $content = mb_convert_encoding($content, 'UTF-8', $encoding);
}

/**
 * 分章思路：
 * 1. 用正则找出所有“章节标题”的候选位置，比如：
 *    第1章 xxx、第一章 xxx、第十回 xxx、第三卷 xxx 等
 * 2. 针对“同一章号重复”的情况（例如多次出现‘第一章 xxx’），
 *    只保留首次出现的那一个，其余跳过。
 * 3. 针对“标题在同一行重复出现”的情况（例：第一章 开始 第一章 开始 正文xxx），
 *    截断到第一段标题，后面的重复丢弃。
 */

// ① 找出所有章节标题候选（只匹配到行尾，不跨行）
$pattern = '/(第[一二三四五六七八九十百千万零〇两0-9]+[章节卷回][^\r\n]*)/u';
preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

$heads = [];
$lastChapterKey = null;  // 用来记录上一次的“章号”（比如 “一”、“二”、“10” 等）

foreach ($matches[0] as $m) {
    $fullTitle = $m[0];      // 如： "第一章 开始 第一章 开始 正文xxx"
    $offset    = $m[1];

    // ② 在标题内只保留第一段“第X章/卷/节/回 ...”，避免同一行多次重复
    //    例如 "第一章 开始 第一章 开始 正文xxx"
    //    只保留 "第一章 开始 " 部分
    $cleanTitle = $fullTitle;
    if (preg_match('/第[一二三四五六七八九十百千万零〇两0-9]+[章节卷回][^第\r\n]*/u', $fullTitle, $m2)) {
        $cleanTitle = $m2[0];
    }
    $cleanTitle = trim($cleanTitle);

    // ③ 提取“章号部分”，用来判断是否重复，比如：
    //    "第一章 xxx" → "一"
    //    "第10章 xxx" → "10"
    //    "第十三回"   → "十三"
    $chapterKey = null;
    if (preg_match('/第([一二三四五六七八九十百千万零〇两0-9]+)[章节卷回]/u', $cleanTitle, $mNo)) {
        $chapterKey = $mNo[1]; // 只用中间的“数字部分”作为 key
    }

    // ④ 如果检测到同一个 chapterKey（同一章号）重复出现，则跳过
    //    例如多次出现“第一章 xxx”，只认第一次
    if ($chapterKey !== null && $chapterKey === $lastChapterKey) {
        continue;
    }

    $heads[] = [
        'title' => $cleanTitle,
        'pos'   => $offset,
        'key'   => $chapterKey,
    ];
    $lastChapterKey = $chapterKey;
}

$chapters = [];

// 如果一个标题都没匹配到，就把整本书当成一个章节
if (count($heads) === 0) {
    $chapters[] = [
        'title'   => '正文',
        'content' => $content,
    ];
} else {
    // 有匹配到标题：按标题位置切分正文
    $headCount = count($heads);
    for ($i = 0; $i < $headCount; $i++) {
        $start = $heads[$i]['pos'];
        $end   = ($i + 1 < $headCount) ? $heads[$i + 1]['pos'] : strlen($content);

        // 注意：这里的 content 从“当前标题开始”截到“下一个标题之前”
        $text = substr($content, $start, $end - $start);

        // 再次确保标题在章节内容最开头
        // 避免少数情况下 offset 对不齐（理论上不会，但这样更安全）
        if (mb_strpos($text, $heads[$i]['title']) !== 0) {
            // 如果标题不在段首，则手动拼一下
            $text = $heads[$i]['title'] . "\n" . $text;
        }

        $chapters[] = [
            'title'   => $heads[$i]['title'],
            'content' => $text,
        ];
    }
}

// 写入 JSON
file_put_contents($jsonFile, json_encode($chapters, JSON_UNESCAPED_UNICODE));

// 解析完直接跳回阅读
header('Location: reader.php?book=' . urlencode($bookId));
exit;