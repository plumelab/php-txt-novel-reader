<?php
header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$logFile = $uploadDir . '/reading_logs.json';

$qualityFilter = [
    'minActiveSec' => 120,
    'minCharsRead' => 500,
    'maxSpeedCpm' => 1800,
    'minSecPerPage' => 2.0,
];

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

function clean_book_id(string $bookId): string
{
    $bookId = basename($bookId);
    if (!preg_match('/^book_[A-Za-z0-9_\-]+$/', $bookId)) {
        return '';
    }
    return $bookId;
}

function read_logs(string $file): array
{
    $default = ['version' => 1, 'sessions' => []];
    if (!is_file($file)) {
        return $default;
    }

    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $default;
    }

    if (!isset($data['version']) || !is_int($data['version'])) {
        $data['version'] = 1;
    }

    if (!isset($data['sessions']) || !is_array($data['sessions'])) {
        $data['sessions'] = [];
    }

    return $data;
}

function write_logs(string $file, array $data): bool
{
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        return false;
    }

    return file_put_contents($file, $encoded . "\n", LOCK_EX) !== false;
}

function get_book_title(string $baseDir, string $bookId): string
{
    $titleFile = $baseDir . '/uploads/' . $bookId . '/title.txt';
    if (!is_file($titleFile)) {
        return $bookId;
    }

    $title = trim((string)file_get_contents($titleFile));
    return $title !== '' ? $title : $bookId;
}

function to_non_negative_int($value): int
{
    return max(0, intval($value));
}

function format_day(int $timestamp): string
{
    return date('Y-m-d', $timestamp);
}

function get_session_reject_reason(int $activeSec, int $charsRead, int $pagesRead, float $speedCpm, array $filter): string
{
    if ($activeSec < intval($filter['minActiveSec'] ?? 0)) {
        return 'too_short';
    }

    if ($charsRead < intval($filter['minCharsRead'] ?? 0)) {
        return 'too_little_chars';
    }

    if ($speedCpm > floatval($filter['maxSpeedCpm'] ?? 999999)) {
        return 'speed_too_high';
    }

    if ($pagesRead > 0) {
        $secPerPage = $activeSec / $pagesRead;
        if ($secPerPage < floatval($filter['minSecPerPage'] ?? 0)) {
            return 'flip_too_fast';
        }
    }

    return '';
}

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
if ($action === '') {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
}
if ($action === '') {
    $action = 'summary';
}

if ($action === 'append') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $bookId = clean_book_id((string)($_POST['book'] ?? ''));
    if ($bookId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_book'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sessionId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['id'] ?? ''));
    if ($sessionId === '') {
        $sessionId = 'sess_' . time() . '_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 10);
    }

    $startAt = to_non_negative_int($_POST['startAt'] ?? 0);
    $endAt = to_non_negative_int($_POST['endAt'] ?? 0);
    $activeSec = to_non_negative_int($_POST['activeSec'] ?? 0);
    $pagesRead = to_non_negative_int($_POST['pagesRead'] ?? 0);
    $charsRead = to_non_negative_int($_POST['charsRead'] ?? 0);

    $chapterFrom = to_non_negative_int($_POST['chapterFrom'] ?? 0);
    $pageFrom = to_non_negative_int($_POST['pageFrom'] ?? 0);
    $chapterTo = to_non_negative_int($_POST['chapterTo'] ?? 0);
    $pageTo = to_non_negative_int($_POST['pageTo'] ?? 0);

    if ($startAt === 0) {
        $startAt = time();
    }
    if ($endAt === 0) {
        $endAt = max($startAt, time());
    }
    if ($endAt < $startAt) {
        $endAt = $startAt;
    }

    if ($activeSec <= 0 || $charsRead <= 0) {
        echo json_encode(['ok' => true, 'ignored' => true, 'reason' => 'empty'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $bookTitle = get_book_title($baseDir, $bookId);
    $speedCpm = $activeSec > 0 ? round(($charsRead * 60) / $activeSec, 2) : 0;
    $rejectReason = get_session_reject_reason($activeSec, $charsRead, $pagesRead, $speedCpm, $qualityFilter);
    if ($rejectReason !== '') {
        echo json_encode(['ok' => true, 'ignored' => true, 'reason' => $rejectReason], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = [
        'id' => $sessionId,
        'bookId' => $bookId,
        'bookTitle' => $bookTitle,
        'startAt' => $startAt,
        'endAt' => $endAt,
        'activeSec' => $activeSec,
        'pagesRead' => $pagesRead,
        'charsRead' => $charsRead,
        'speedCpm' => $speedCpm,
        'chapterFrom' => $chapterFrom,
        'pageFrom' => $pageFrom,
        'chapterTo' => $chapterTo,
        'pageTo' => $pageTo,
        'updatedAt' => time(),
    ];

    $logs = read_logs($logFile);
    $updated = false;
    foreach ($logs['sessions'] as $idx => $session) {
        if ((string)($session['id'] ?? '') === $sessionId) {
            $payload['createdAt'] = to_non_negative_int($session['createdAt'] ?? $session['updatedAt'] ?? time());
            $logs['sessions'][$idx] = $payload;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $payload['createdAt'] = time();
        $logs['sessions'][] = $payload;
    }

    if (!write_logs($logFile, $logs)) {
        http_response_code(500);
        echo json_encode(['error' => 'write_failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'id' => $sessionId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'summary') {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_action'], JSON_UNESCAPED_UNICODE);
    exit;
}

$days = isset($_GET['days']) ? intval($_GET['days']) : 30;
$days = max(1, min(365, $days));

$logs = read_logs($logFile);
$allSessions = $logs['sessions'];
$now = time();
$startTs = strtotime('-' . ($days - 1) . ' days midnight');
if ($startTs === false) {
    $startTs = $now - ($days - 1) * 86400;
}

$dailyMap = [];
for ($i = 0; $i < $days; $i++) {
    $dayTs = strtotime('+' . $i . ' days', $startTs);
    if ($dayTs === false) {
        $dayTs = $startTs + $i * 86400;
    }
    $day = format_day($dayTs);
    $dailyMap[$day] = [
        'date' => $day,
        'charsRead' => 0,
        'activeSec' => 0,
        'sessions' => 0,
    ];
}

$hourly = [];
for ($h = 0; $h < 24; $h++) {
    $hourly[$h] = ['hour' => $h, 'charsRead' => 0, 'activeSec' => 0, 'sessions' => 0];
}

$bookAgg = [];
$recentSessions = [];
$totalChars = 0;
$totalActiveSec = 0;
$totalSessions = 0;

foreach ($allSessions as $session) {
    if (!is_array($session)) {
        continue;
    }

    $endAt = to_non_negative_int($session['endAt'] ?? 0);
    if ($endAt <= 0 || $endAt < $startTs || $endAt > $now + 86400) {
        continue;
    }

    $charsRead = to_non_negative_int($session['charsRead'] ?? 0);
    $activeSec = to_non_negative_int($session['activeSec'] ?? 0);
    $pagesRead = to_non_negative_int($session['pagesRead'] ?? 0);
    if ($charsRead <= 0 || $activeSec <= 0) {
        continue;
    }

    $speedCpm = $activeSec > 0 ? round(($charsRead * 60) / $activeSec, 2) : 0;
    $rejectReason = get_session_reject_reason($activeSec, $charsRead, $pagesRead, $speedCpm, $qualityFilter);
    if ($rejectReason !== '') {
        continue;
    }

    $bookId = clean_book_id((string)($session['bookId'] ?? ''));
    if ($bookId === '') {
        $bookId = 'book_unknown';
    }
    $bookTitle = trim((string)($session['bookTitle'] ?? ''));
    if ($bookTitle === '') {
        $bookTitle = $bookId;
    }

    $day = format_day($endAt);
    if (isset($dailyMap[$day])) {
        $dailyMap[$day]['charsRead'] += $charsRead;
        $dailyMap[$day]['activeSec'] += $activeSec;
        $dailyMap[$day]['sessions'] += 1;
    }

    $hour = intval(date('G', $endAt));
    if (!isset($hourly[$hour])) {
        $hour = 0;
    }
    $hourly[$hour]['charsRead'] += $charsRead;
    $hourly[$hour]['activeSec'] += $activeSec;
    $hourly[$hour]['sessions'] += 1;

    if (!isset($bookAgg[$bookId])) {
        $bookAgg[$bookId] = [
            'bookId' => $bookId,
            'bookTitle' => $bookTitle,
            'charsRead' => 0,
            'activeSec' => 0,
            'sessions' => 0,
            'speedCpm' => 0,
        ];
    }
    $bookAgg[$bookId]['charsRead'] += $charsRead;
    $bookAgg[$bookId]['activeSec'] += $activeSec;
    $bookAgg[$bookId]['sessions'] += 1;

    $recentSessions[] = [
        'id' => (string)($session['id'] ?? ''),
        'bookId' => $bookId,
        'bookTitle' => $bookTitle,
        'startAt' => to_non_negative_int($session['startAt'] ?? $endAt),
        'endAt' => $endAt,
        'activeSec' => $activeSec,
        'pagesRead' => $pagesRead,
        'charsRead' => $charsRead,
        'speedCpm' => $speedCpm,
        'chapterFrom' => to_non_negative_int($session['chapterFrom'] ?? 0),
        'pageFrom' => to_non_negative_int($session['pageFrom'] ?? 0),
        'chapterTo' => to_non_negative_int($session['chapterTo'] ?? 0),
        'pageTo' => to_non_negative_int($session['pageTo'] ?? 0),
    ];

    $totalChars += $charsRead;
    $totalActiveSec += $activeSec;
    $totalSessions += 1;
}

foreach ($bookAgg as $bookId => $row) {
    $bookAgg[$bookId]['speedCpm'] = $row['activeSec'] > 0 ? round(($row['charsRead'] * 60) / $row['activeSec'], 2) : 0;
}

$topBooks = array_values($bookAgg);
usort($topBooks, function ($a, $b) {
    return $b['charsRead'] <=> $a['charsRead'];
});
$topBooks = array_slice($topBooks, 0, 12);

usort($recentSessions, function ($a, $b) {
    return $b['endAt'] <=> $a['endAt'];
});
$recentSessions = array_slice($recentSessions, 0, 80);

$daily = array_values($dailyMap);
$todayKey = format_day($now);
$todayChars = isset($dailyMap[$todayKey]) ? $dailyMap[$todayKey]['charsRead'] : 0;

$day7Chars = 0;
foreach (array_slice($daily, -7) as $item) {
    $day7Chars += to_non_negative_int($item['charsRead'] ?? 0);
}

echo json_encode([
    'ok' => true,
    'range' => [
        'days' => $days,
        'startAt' => $startTs,
        'endAt' => $now,
    ],
    'overview' => [
        'sessions' => $totalSessions,
        'activeSec' => $totalActiveSec,
        'charsRead' => $totalChars,
        'avgSpeedCpm' => $totalActiveSec > 0 ? round(($totalChars * 60) / $totalActiveSec, 2) : 0,
        'todayChars' => $todayChars,
        'day7Chars' => $day7Chars,
    ],
    'daily' => $daily,
    'hourly' => array_values($hourly),
    'topBooks' => $topBooks,
    'recentSessions' => $recentSessions,
], JSON_UNESCAPED_UNICODE);
