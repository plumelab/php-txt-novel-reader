<?php
$bookId = isset($_GET['book']) ? basename($_GET['book']) : '';
if ($bookId === '') {
    header('Location: index.php');
    exit;
}

$baseDir   = __DIR__;
$bookDir   = $baseDir . '/uploads/' . $bookId;
$bookFile  = $bookDir . '/book.txt';
$titleFile = $bookDir . '/title.txt';
$jsonFile  = $bookDir . '/chapters.json';

if (!is_dir($bookDir) || !file_exists($bookFile)) {
    die('该书不存在');
}

$title = is_file($titleFile) ? trim(file_get_contents($titleFile)) : $bookId;

// 如果还没有章节 JSON，则跳转去解析
if (!file_exists($jsonFile)) {
    header('Location: parse.php?book=' . urlencode($bookId));
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title><?=htmlspecialchars($title)?> - 阅读</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <!-- iOS 添加到主屏幕后全屏 -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="小说阅读器">

    <meta name="theme-color" content="#0b0b0b">
    <link rel="apple-touch-icon" href="assets/icon.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="reader-page">
<div class="app-shell reader-shell">

    <header class="reader-header">
        <a href="index.php" class="header-back" aria-label="返回书库">← 书库</a>
        <div class="reader-title" title="<?=htmlspecialchars($title)?>"><?=htmlspecialchars($title)?></div>
        <button class="btn tiny secondary header-btn" id="btn-settings-open" type="button">设置</button>
    </header>

    <!-- 固定高度的阅读区域，内部不滚动，靠分页翻页 -->
    <main class="reader-main" id="reader-main">
        <div class="reader-main-inner" id="reader-main-inner">
            <div id="chapter-title" class="chapter-title"></div>
            <div id="chapter-content" class="chapter-content"></div>
        </div>

        <!-- 右下角常驻阅读进度 -->
        <div id="reading-progress" class="reading-progress">第 0/0 章 · 第 0/0 页</div>
    </main>

    <footer class="reader-footer">
        <button class="btn secondary nav-btn" onclick="prevPageOrChapter()">上一页</button>

        <div class="reader-footer-middle">
            <div id="footer-progress" class="footer-progress">第 0/0 章 · 第 0/0 页</div>
            <div class="footer-actions">
                <button class="btn tiny secondary" type="button" id="btn-theme-toggle">主题</button>
                <button class="btn tiny secondary" type="button" id="btn-immersive-toggle">沉浸</button>
            </div>
        </div>

        <button class="btn secondary nav-btn" onclick="nextPageOrChapter()">下一页</button>
    </footer>

</div>

<!-- 阅读设置：弹窗（窄屏友好） -->
<div id="settings-modal" class="modal" aria-hidden="true">
    <div class="modal-backdrop" data-close="1"></div>
    <div class="modal-panel">
        <div class="modal-header">
            <div class="modal-title">阅读设置</div>
            <button class="btn tiny secondary" type="button" data-close="1">关闭</button>
        </div>
        <div class="modal-body">
            <div class="settings-tabs">
                <button id="settings-tab-reading" class="btn tiny secondary settings-tab-btn active" type="button" data-tab="reading">阅读设置</button>
                <button id="settings-tab-vocab" class="btn tiny secondary settings-tab-btn" type="button" data-tab="vocab">词频统计</button>
            </div>

            <div id="settings-pane-reading" class="settings-pane active">
                <div class="setting-row">
                    <div class="setting-label">字号</div>
                    <div class="setting-controls">
                        <button class="btn tiny" onclick="smallerFont()">A-</button>
                        <button class="btn tiny" onclick="largerFont()">A+</button>
                    </div>
                </div>

                <div class="setting-row">
                    <div class="setting-label">行距</div>
                    <div class="setting-controls">
                        <button class="btn tiny" onclick="tighterLine()">－</button>
                        <button class="btn tiny" onclick="looserLine()">＋</button>
                    </div>
                </div>

                <div class="setting-row">
                    <div class="setting-label">边距</div>
                    <div class="setting-controls">
                        <button class="btn tiny" onclick="smallerMargin()">－</button>
                        <button class="btn tiny" onclick="largerMargin()">＋</button>
                    </div>
                </div>

                <div class="chapter-picker">
                    <div class="chapter-range-header">
                        <div id="chapter-range-title" class="chapter-range-title">请选择章节范围</div>
                        <button class="btn tiny secondary" type="button" id="chapter-range-back" style="display:none;">返回</button>
                    </div>
                    <div id="chapter-range-list" class="chapter-range-list" role="listbox" aria-label="章节范围"></div>
                    <div id="chapter-list" class="chapter-list" role="listbox" aria-label="章节列表"></div>
                    <div id="chapter-list-empty" class="chapter-list-empty">正在加载章节列表…</div>
                </div>

                <div class="setting-row cache-setting-row">
                    <div class="setting-label">临时缓存</div>
                    <div class="cache-setting-controls">
                        <div class="cache-input-group">
                            <label for="cache-start">起始章</label>
                            <input id="cache-start" class="cache-input" type="number" min="1" step="1" value="1">
                        </div>
                        <div class="cache-input-group">
                            <label for="cache-end">结束章</label>
                            <input id="cache-end" class="cache-input" type="number" min="1" step="1" value="20">
                        </div>
                    </div>
                    <div class="cache-action-row">
                        <button id="btn-cache-range" class="btn tiny primary" type="button" disabled>缓存章节</button>
                        <div id="cache-status" class="cache-status" aria-live="polite"></div>
                    </div>
                </div>

                <div class="setting-hint">
                    <div>📌 操作提示：</div>
                    <ul>
                        <li>点击屏幕左/右 1/3：翻上一页/下一页</li>
                        <li>点击中间 1/3：显示/隐藏上下栏（沉浸模式）</li>
                        <li>左右滑动：翻页（手机/iPad 更顺手）</li>
                        <li>长按选字：会出现“🤖 问AI”按钮，可对选中段落提问</li>
                    </ul>
                </div>
            </div>

            <div id="settings-pane-vocab" class="settings-pane">
                <div class="setting-row cache-setting-row">
                    <div class="setting-label">统计范围（章节区间）</div>
                    <div class="cache-setting-controls">
                        <div class="cache-input-group">
                            <label for="wf-start">起始章</label>
                            <input id="wf-start" class="cache-input" type="number" min="1" step="1" value="1">
                        </div>
                        <div class="cache-input-group">
                            <label for="wf-end">结束章</label>
                            <input id="wf-end" class="cache-input" type="number" min="1" step="1" value="1">
                        </div>
                        <div class="cache-input-group">
                            <label for="wf-min-count">最少出现次数</label>
                            <input id="wf-min-count" class="cache-input" type="number" min="1" max="20" step="1" value="2">
                        </div>
                    </div>
                    <div class="cache-action-row">
                        <label><input id="wf-lang-ja" type="checkbox" checked> 日语</label>
                        <label><input id="wf-lang-en" type="checkbox" checked> 英语</label>
                        <button id="btn-wf-recent5" class="btn tiny secondary" type="button">最近5章</button>
                        <button id="btn-wf-run" class="btn tiny primary" type="button">刷新词频</button>
                    </div>
                    <div id="wf-status" class="cache-status" aria-live="polite"></div>
                </div>

                <div id="wf-results" class="wf-results">
                    <div class="chapter-list-empty show">点击“刷新词频”开始统计最近章节词频。</div>
                </div>

                <div class="setting-hint">
                    <div>📝 说明：</div>
                    <ul>
                        <li>词频统计基于你选择的章节区间进行服务端聚合分析</li>
                        <li>在本页停留属于“学习模式”，不会计入阅读速度/阅读时长</li>
                        <li>日语分词已做停用词与简易词形清洗，英语有停用词与词形归一</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 选中文本后的悬浮操作条 -->
<div id="selection-actions" class="selection-actions" aria-hidden="true">
    <button id="selection-ask-ai" class="btn primary tiny" type="button">🤖 问AI</button>
    <button id="selection-copy" class="btn secondary tiny" type="button">复制</button>
</div>

<!-- AI 对话弹窗 -->
<div id="ai-modal" class="modal modal-ai" aria-hidden="true">
    <div class="modal-backdrop" data-close="1"></div>
    <div class="modal-panel ai-panel">
        <div class="modal-header">
            <div class="modal-title">🤖 AI 阅读助手</div>
            <button class="btn tiny secondary" type="button" data-close="1">关闭</button>
        </div>

        <div class="modal-body ai-body">
            <div class="ai-context">
                <div class="ai-context-label">选中文本（将作为上下文发送）</div>
                <div id="ai-context-preview" class="ai-context-preview"></div>
            </div>

            <div class="ai-quick">
                <button class="btn tiny secondary" type="button" data-ai-template="解释这段话的意思，并用更通俗的话复述。">解释</button>
                <button class="btn tiny secondary" type="button" data-ai-template="帮我总结这段内容的要点（3-5条）。">总结</button>
                <button class="btn tiny secondary" type="button" data-ai-template="你可以帮我翻译一下嘛，大意+你觉得比较有意思的点即可，我在阅读日语轻小说来学习日语。">翻译</button>
            </div>

            <div id="ai-chat-log" class="ai-chat-log"></div>

            <div class="ai-input">
                <textarea id="ai-user-input" rows="2" placeholder="输入你的问题...（上下文保留3轮）"></textarea>
                <div class="ai-actions">
                    <button class="btn secondary" type="button" id="ai-clear">清空</button>
                    <button class="btn primary" type="button" id="ai-send">发送</button>
                </div>
                <div id="ai-error" class="ai-error" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<script>
    const BOOK_ID = "<?=htmlspecialchars($bookId, ENT_QUOTES)?>";
    const BOOK_TITLE = "<?=htmlspecialchars($title, ENT_QUOTES)?>";
</script>
<script src="assets/reader.js"></script>
</body>
</html>
