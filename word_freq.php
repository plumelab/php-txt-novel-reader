<?php
header('Content-Type: application/json; charset=utf-8');

function clean_book_id(string $bookId): string
{
    $bookId = basename($bookId);
    if (!preg_match('/^book_[A-Za-z0-9_\-]+$/', $bookId)) {
        return '';
    }
    return $bookId;
}

function to_int($value, int $default = 0): int
{
    if (!is_scalar($value)) {
        return $default;
    }
    return intval($value);
}

function clamp_int(int $value, int $min, int $max): int
{
    if ($value < $min) return $min;
    if ($value > $max) return $max;
    return $value;
}

function utf8_len(string $text): int
{
    if ($text === '') return 0;
    if (preg_match_all('/./u', $text, $m) !== false) {
        return count($m[0]);
    }
    return strlen($text);
}

function lower_utf8(string $text): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text, 'UTF-8');
    }
    return strtolower($text);
}

function substr_utf8(string $text, int $start, ?int $length = null): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, $start, $length, 'UTF-8');
    }
    if ($length === null) {
        return substr($text, $start);
    }
    return substr($text, $start, $length);
}

function normalize_title_key(string $str): string
{
    $s = trim($str);
    $s = preg_replace('/[\s\x{3000}]+/u', '', $s);
    $s = preg_replace('/[，。！？：；、“”‘’（）()\[\]【】《》＜＞<>「」『』·—\-]/u', '', $s);
    $s = lower_utf8($s);
    return $s;
}

function strip_duplicated_title(string $rawText, string $title): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $rawText);
    $lines = explode("\n", $text);
    $normTitle = normalize_title_key($title);
    if ($normTitle === '') {
        return $text;
    }

    $checked = 0;
    for ($i = 0; $i < count($lines) && $checked < 4; $i++) {
        if (trim($lines[$i]) === '') continue;
        $checked++;
        $normLine = normalize_title_key($lines[$i]);
        if ($normLine === $normTitle) {
            array_splice($lines, $i, 1);
            $i--;
        }
    }

    while (count($lines) > 0 && trim($lines[0]) === '') {
        array_shift($lines);
    }
    return implode("\n", $lines);
}

function normalize_book_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
        if (is_string($normalized)) {
            $text = $normalized;
        }
    }
    return $text;
}

function get_lang_set(string $langParam): array
{
    $supported = ['ja' => true, 'en' => true];
    $parts = array_filter(array_map('trim', explode(',', strtolower($langParam))));
    if (empty($parts)) {
        return ['ja', 'en'];
    }

    $langs = [];
    foreach ($parts as $p) {
        if (isset($supported[$p])) {
            $langs[] = $p;
        }
    }

    if (empty($langs)) {
        return ['ja', 'en'];
    }

    return array_values(array_unique($langs));
}

function english_stopwords(): array
{
    return [
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'at', 'for', 'from', 'with',
        'by', 'as', 'is', 'am', 'are', 'was', 'were', 'be', 'been', 'being', 'it', 'its',
        'this', 'that', 'these', 'those', 'i', 'you', 'he', 'she', 'we', 'they', 'them',
        'my', 'your', 'his', 'her', 'their', 'our', 'me', 'him', 'us', 'do', 'does', 'did',
        'not', 'but', 'if', 'then', 'so', 'than', 'too', 'very', 'can', 'could', 'will',
        'would', 'should', 'may', 'might', 'just', 'have', 'has', 'had', 'into', 'about',
        'after', 'before', 'over', 'under', 'out', 'up', 'down'
    ];
}

function japanese_stopwords(): array
{
    return [
        'は', 'が', 'を', 'に', 'へ', 'で', 'と', 'も', 'の', 'ね', 'よ', 'ぞ', 'さ', 'か',
        'な', 'だ', 'です', 'ます', 'ない', 'いる', 'ある', 'する', 'した', 'して', 'れる',
        'られる', 'から', 'まで', 'より', 'だけ', 'ほど', 'ので', 'のに', 'そして', 'しかし',
        'それ', 'これ', 'あれ', 'ここ', 'そこ', 'あそこ', 'こと', 'もの', 'さん', 'ちゃん'
    ];
}

function stem_english(string $token): string
{
    $t = strtolower($token);
    $len = strlen($t);
    if ($len >= 6 && substr($t, -3) === 'ing') {
        return substr($t, 0, -3);
    }
    if ($len >= 5 && substr($t, -2) === 'ed') {
        return substr($t, 0, -2);
    }
    if ($len >= 5 && substr($t, -2) === 'es') {
        return substr($t, 0, -2);
    }
    if ($len >= 4 && substr($t, -1) === 's') {
        return substr($t, 0, -1);
    }
    return $t;
}

function tokenize_english(string $text, array $stopSet): array
{
    $tokens = [];
    if (!preg_match_all("/[A-Za-z]+(?:'[A-Za-z]+)?/u", strtolower($text), $m)) {
        return $tokens;
    }

    foreach ($m[0] as $raw) {
        $word = stem_english($raw);
        if (strlen($word) < 3) continue;
        if (isset($stopSet[$word])) continue;
        $tokens[] = $word;
    }
    return $tokens;
}

function split_japanese_tail_particles(string $token): array
{
    $particles = ['でしょう', 'でした', 'ます', 'です', 'ない', 'れる', 'られる', 'から', 'まで', 'だけ', 'ほど', 'ので', 'のに', 'とか', 'など', 'には', 'では', 'には', 'なら', 'たり', 'たり', 'は', 'が', 'を', 'に', 'へ', 'で', 'と', 'も', 'の', 'か', 'な', 'ね', 'よ', 'ぞ', 'さ', 'し', 'て'];
    foreach ($particles as $p) {
        $plen = utf8_len($p);
        if ($plen <= 0) continue;
        if (substr_utf8($token, -$plen, null) === $p && utf8_len($token) > $plen + 1) {
            $base = substr_utf8($token, 0, utf8_len($token) - $plen);
            return [$base, $p];
        }
    }
    return [$token];
}

function tokenize_japanese(string $text, array $stopSet): array
{
    $tokens = [];
    if (!preg_match_all('/[\x{4E00}-\x{9FFF}]+(?:[\x{3040}-\x{309F}]+)?|[\x{30A0}-\x{30FF}ー]+|[\x{3040}-\x{309F}]+/u', $text, $m)) {
        return $tokens;
    }

    foreach ($m[0] as $piece) {
        $subs = split_japanese_tail_particles($piece);
        foreach ($subs as $t) {
            $word = trim($t);
            if ($word === '') continue;
            if (isset($stopSet[$word])) continue;
            if (preg_match('/^[\x{3040}-\x{309F}]+$/u', $word) && utf8_len($word) <= 2) continue;
            if (!preg_match('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $word)) continue;
            $tokens[] = $word;
        }
    }

    return $tokens;
}

$bookId = clean_book_id((string)($_GET['book'] ?? ''));
if ($bookId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_book'], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseDir = __DIR__;
$jsonFile = $baseDir . '/uploads/' . $bookId . '/chapters.json';
if (!is_file($jsonFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'chapters_not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($jsonFile);
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'invalid_chapters_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$total = count($data);
if ($total <= 0) {
    echo json_encode(['ok' => true, 'range' => ['start' => 1, 'end' => 0, 'total' => 0], 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$start = clamp_int(to_int($_GET['start'] ?? 1, 1), 1, $total);
$end = clamp_int(to_int($_GET['end'] ?? $total, $total), 1, $total);
if ($start > $end) {
    $tmp = $start;
    $start = $end;
    $end = $tmp;
}

$minCount = clamp_int(to_int($_GET['minCount'] ?? 2, 2), 1, 50);
$limit = clamp_int(to_int($_GET['limit'] ?? 300, 300), 20, 2000);
$langs = get_lang_set((string)($_GET['lang'] ?? 'ja,en'));

$enStopSet = array_fill_keys(english_stopwords(), true);
$jaStopSet = array_fill_keys(japanese_stopwords(), true);

$bucket = [];
$startIndex = $start - 1;
$endIndex = $end - 1;
$beginAt = microtime(true);

for ($idx = $startIndex; $idx <= $endIndex; $idx++) {
    $chapter = $data[$idx] ?? [];
    if (!is_array($chapter)) continue;

    $title = isset($chapter['title']) ? (string)$chapter['title'] : ('第' . ($idx + 1) . '章');
    $content = isset($chapter['content']) ? (string)$chapter['content'] : '';
    $text = normalize_book_text(strip_duplicated_title($content, $title));

    $seenInChapter = [];

    if (in_array('ja', $langs, true)) {
        $jaTokens = tokenize_japanese($text, $jaStopSet);
        foreach ($jaTokens as $word) {
            $key = 'ja|' . $word;
            if (!isset($bucket[$key])) {
                $bucket[$key] = [
                    'word' => $word,
                    'lang' => 'ja',
                    'count' => 0,
                    'chapters' => []
                ];
            }
            $bucket[$key]['count'] += 1;
            if (!isset($seenInChapter[$key])) {
                $bucket[$key]['chapters'][$idx] = true;
                $seenInChapter[$key] = true;
            }
        }
    }

    if (in_array('en', $langs, true)) {
        $enTokens = tokenize_english($text, $enStopSet);
        foreach ($enTokens as $word) {
            $key = 'en|' . $word;
            if (!isset($bucket[$key])) {
                $bucket[$key] = [
                    'word' => $word,
                    'lang' => 'en',
                    'count' => 0,
                    'chapters' => []
                ];
            }
            $bucket[$key]['count'] += 1;
            if (!isset($seenInChapter[$key])) {
                $bucket[$key]['chapters'][$idx] = true;
                $seenInChapter[$key] = true;
            }
        }
    }
}

$items = [];
foreach ($bucket as $item) {
    $coverage = count($item['chapters']);
    if ($item['count'] < $minCount) continue;
    $items[] = [
        'word' => $item['word'],
        'lang' => $item['lang'],
        'count' => $item['count'],
        'coverage' => $coverage,
    ];
}

usort($items, function ($a, $b) {
    if ($a['count'] !== $b['count']) {
        return $b['count'] <=> $a['count'];
    }
    if ($a['coverage'] !== $b['coverage']) {
        return $b['coverage'] <=> $a['coverage'];
    }
    if ($a['lang'] !== $b['lang']) {
        return strcmp($a['lang'], $b['lang']);
    }
    return strcmp($a['word'], $b['word']);
});

if (count($items) > $limit) {
    $items = array_slice($items, 0, $limit);
}

$elapsedMs = (int)round((microtime(true) - $beginAt) * 1000);

echo json_encode([
    'ok' => true,
    'range' => [
        'start' => $start,
        'end' => $end,
        'total' => $total,
    ],
    'meta' => [
        'chapterCount' => max(0, $end - $start + 1),
        'elapsedMs' => $elapsedMs,
        'tokenizerVersion' => 'wf-v2-server',
        'langs' => $langs,
    ],
    'items' => $items,
], JSON_UNESCAPED_UNICODE);
