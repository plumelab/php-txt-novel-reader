<?php
// ai.php
// 选中文字 + 提示词 -> 转发给 OpenAI API（Responses API）
// 要求：PHP 环境，无流式，简单保留三轮上下文（由前端传 history 过来）

header('Content-Type: application/json; charset=utf-8');

// 可选：支持放一个 config.php（不强制）
// 里面可以写：<?php define('OPENAI_API_KEY','sk-...');
if (file_exists(__DIR__ . '/config.php')) {
    // 防止 config.php 输出内容
    require_once __DIR__ . '/config.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = null;

if ($raw) {
    $payload = json_decode($raw, true);
}

if (!is_array($payload)) {
    // 兼容 application/x-www-form-urlencoded
    $payload = $_POST;
}

$prompt = isset($payload['prompt']) ? trim((string)$payload['prompt']) : '';
$selected = isset($payload['selected']) ? trim((string)$payload['selected']) : '';
$history = isset($payload['history']) && is_array($payload['history']) ? $payload['history'] : [];

if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'prompt 不能为空']);
    exit;
}

// 简单保留三轮
if (count($history) > 3) {
    $history = array_slice($history, -3);
}

// 读取 API Key（优先环境变量，其次 config.php 里的常量）
$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey && defined('OPENAI_API_KEY')) {
    $apiKey = OPENAI_API_KEY;
}

if (!$apiKey) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => '未配置 OPENAI_API_KEY。请在环境变量中设置 OPENAI_API_KEY，或创建 config.php 并 define(\'OPENAI_API_KEY\',\'...\')。'
    ]);
    exit;
}

$model = 'gpt-5-mini-2025-08-07';
if (isset($payload['model']) && is_string($payload['model']) && trim($payload['model']) !== '') {
    $model = trim($payload['model']);
}

// 组装输入：选中文本 + 历史三轮 + 当前问题
$historyText = '';
foreach ($history as $turn) {
    if (!is_array($turn)) continue;
    $u = isset($turn['user']) ? trim((string)$turn['user']) : '';
    $a = isset($turn['assistant']) ? trim((string)$turn['assistant']) : '';
    if ($u !== '') $historyText .= "用户：{$u}\n";
    if ($a !== '') $historyText .= "助手：{$a}\n";
}

$inputParts = [];
if ($selected !== '') {
    $inputParts[] = "【选中文本】\n" . $selected;
}
if ($historyText !== '') {
    $inputParts[] = "【对话历史（最多3轮）】\n" . trim($historyText);
}
$inputParts[] = "【我的问题】\n" . $prompt;

$input = implode("\n\n", $inputParts);

$reqBody = [
    'model' => $model,
    // instructions 作为系统提示
    'instructions' => "你是一个中文电子书阅读助手。请结合用户选中的原文回答问题。\n\n要求：\n- 尽量用中文简短表述，表达清晰、分点更好\n- 如需引用原文，尽量只引用短句（不必整段复制）\n- 如果信息不足，可以说明缺失并提出1-2个追问\n",
    'input' => $input,
    // 更省一点推理成本（可删掉）
    'reasoning' => [ 'effort' => 'minimal' ],
    // 不存储（更隐私），不影响正常使用
    'store' => false
];

$ch = curl_init('https://api.openai.com/v1/responses');
if (!$ch) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'curl 初始化失败']);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($reqBody, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
]);

$resBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($resBody === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '请求 OpenAI 失败：' . $curlErr]);
    exit;
}

$data = json_decode($resBody, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'OpenAI 返回非 JSON：' . substr($resBody, 0, 200)]);
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    $msg = 'OpenAI 请求失败';
    if (isset($data['error']['message'])) {
        $msg .= '：' . $data['error']['message'];
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// 从 Responses API 结构里提取 output_text
$answer = '';
if (isset($data['output']) && is_array($data['output'])) {
    foreach ($data['output'] as $item) {
        if (!is_array($item)) continue;
        if (($item['type'] ?? '') !== 'message') continue;
        if (($item['role'] ?? '') !== 'assistant') continue;
        if (!isset($item['content']) || !is_array($item['content'])) continue;
        foreach ($item['content'] as $c) {
            if (!is_array($c)) continue;
            if (($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
                $answer .= (string)$c['text'];
            }
        }
    }
}

$answer = trim($answer);

if ($answer === '') {
    $answer = '（未解析到文本输出）';
}

echo json_encode([
    'ok' => true,
    'answer' => $answer,
    'id' => isset($data['id']) ? $data['id'] : null
], JSON_UNESCAPED_UNICODE);
