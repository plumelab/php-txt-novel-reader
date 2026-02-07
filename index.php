<?php
session_start();

$baseDir     = __DIR__;
$incomingDir = $baseDir . '/incoming';
$uploadDir   = $baseDir . '/uploads';
$libraryFile = $uploadDir . '/library.json';

// 确保目录存在
if (!is_dir($incomingDir)) mkdir($incomingDir, 0777, true);
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$isAdmin = !empty($_SESSION['admin']);

// -----------------------------
// Library helpers (folders + book mapping)
// -----------------------------
function read_library(string $file): array {
    $default = [
        'version'  => 1,
        'folders'  => [
            ['id' => 'default', 'name' => '未分类', 'created' => time()],
        ],
        'book_map' => [],
    ];

    if (!is_file($file)) return $default;

    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data)) return $default;

    // 最小校验 + 兼容字段缺失
    if (empty($data['folders']) || !is_array($data['folders'])) $data['folders'] = $default['folders'];
    if (empty($data['book_map']) || !is_array($data['book_map'])) $data['book_map'] = [];
    if (empty($data['version'])) $data['version'] = 1;

    // 确保有 default 文件夹
    $hasDefault = false;
    foreach ($data['folders'] as $f) {
        if (($f['id'] ?? '') === 'default') { $hasDefault = true; break; }
    }
    if (!$hasDefault) array_unshift($data['folders'], $default['folders'][0]);

    return $data;
}

function write_library(string $file, array $lib): void {
    $json = json_encode($lib, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($file, $json . "\n", LOCK_EX);
}

function folder_exists(array $lib, string $folderId): bool {
    foreach ($lib['folders'] as $f) {
        if (($f['id'] ?? '') === $folderId) return true;
    }
    return false;
}

function get_folder_name(array $lib, string $folderId): string {
    foreach ($lib['folders'] as $f) {
        if (($f['id'] ?? '') === $folderId) return (string)($f['name'] ?? $folderId);
    }
    return $folderId;
}

function ensure_folder_by_name(array &$lib, string $name): string {
    $name = trim($name);
    if ($name === '') return 'default';

    // 如果已有同名 folder，直接复用
    foreach ($lib['folders'] as $f) {
        if (trim((string)($f['name'] ?? '')) === $name) return (string)$f['id'];
    }

    $id = 'f_' . substr(md5($name . '|' . microtime(true) . '|' . mt_rand()), 0, 8);
    $lib['folders'][] = ['id' => $id, 'name' => $name, 'created' => time()];
    return $id;
}

function safe_trim_fullwidth(string $s): string {
    // 同时去掉半角空白和全角空白（U+3000）
    return preg_replace('/^[\s　]+|[\s　]+$/u', '', $s);
}

function is_book_dir(string $dir): bool {
    return is_dir($dir) && is_file($dir . '/book.txt');
}

function clean_book_id(string $bookId): string {
    $bookId = basename($bookId);
    if (!preg_match('/^book_[A-Za-z0-9_\-]+$/', $bookId)) return '';
    return $bookId;
}

function clean_folder_id(string $folderId): string {
    $folderId = basename($folderId);
    if ($folderId === 'default') return 'default';
    if (!preg_match('/^f_[a-f0-9]{8}$/', $folderId)) return '';
    return $folderId;
}

function remove_empty_dirs(string $root): void {
    if (!is_dir($root)) return;
    $dirs = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $info) {
        if ($info->isDir()) $dirs[] = $info->getPathname();
    }
    foreach ($dirs as $d) {
        // 不删根目录
        if (realpath($d) === realpath($root)) continue;
        @rmdir($d);
    }
}

$lib = read_library($libraryFile);

// -----------------------------
// Admin actions (folder ops / move book / rename book)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_folder') {
        $name = safe_trim_fullwidth((string)($_POST['folder_name'] ?? ''));
        if ($name !== '') {
            ensure_folder_by_name($lib, $name);
            write_library($libraryFile, $lib);
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'rename_folder') {
        $fid = clean_folder_id((string)($_POST['folder_id'] ?? ''));
        $name = safe_trim_fullwidth((string)($_POST['new_name'] ?? ''));
        if ($fid !== '' && $fid !== 'default' && $name !== '') {
            foreach ($lib['folders'] as &$f) {
                if (($f['id'] ?? '') === $fid) {
                    $f['name'] = $name;
                    break;
                }
            }
            unset($f);
            write_library($libraryFile, $lib);
        }
        header('Location: index.php?folder=' . urlencode($fid));
        exit;
    }

    if ($action === 'delete_folder') {
        $fid = clean_folder_id((string)($_POST['folder_id'] ?? ''));
        if ($fid !== '' && $fid !== 'default') {
            // 把该文件夹下的书移动到 default
            foreach ($lib['book_map'] as $bid => $mapped) {
                if ($mapped === $fid) $lib['book_map'][$bid] = 'default';
            }
            // 删除 folder
            $lib['folders'] = array_values(array_filter($lib['folders'], function($f) use ($fid) {
                return (($f['id'] ?? '') !== $fid);
            }));
            write_library($libraryFile, $lib);
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'move_book') {
        $bid = clean_book_id((string)($_POST['book_id'] ?? ''));
        $fid = clean_folder_id((string)($_POST['folder_id'] ?? ''));
        if ($bid !== '' && $fid !== '' && folder_exists($lib, $fid)) {
            $bookPath = $uploadDir . '/' . $bid;
            if (is_book_dir($bookPath)) {
                $lib['book_map'][$bid] = $fid;
                write_library($libraryFile, $lib);
            }
        }
        $redirectFolder = $_GET['folder'] ?? 'all';
        header('Location: index.php?folder=' . urlencode($redirectFolder));
        exit;
    }

    if ($action === 'rename_book') {
        $bid = clean_book_id((string)($_POST['book_id'] ?? ''));
        $newTitle = safe_trim_fullwidth((string)($_POST['new_title'] ?? ''));
        if ($bid !== '' && $newTitle !== '') {
            $bookPath = $uploadDir . '/' . $bid;
            if (is_book_dir($bookPath)) {
                file_put_contents($bookPath . '/title.txt', $newTitle);
            }
        }
        $redirectFolder = $_GET['folder'] ?? 'all';
        header('Location: index.php?folder=' . urlencode($redirectFolder));
        exit;
    }
}

// -----------------------------
// Auto-import incoming/*.txt (supports subfolders -> folder)
// -----------------------------
$imported = 0;
$libChanged = false;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($incomingDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $info) {
    if (!$info->isFile()) continue;
    if (strtolower($info->getExtension()) !== 'txt') continue;

    $filePath = $info->getPathname();
    $filename = $info->getBasename();
    $title    = preg_replace('/\.txt$/i', '', $filename);

    // 相对路径（用于判断 incoming 子目录）
    $rel = str_replace('\\', '/', substr($filePath, strlen($incomingDir)));
    $rel = ltrim($rel, '/');
    $relDir = str_replace('\\', '/', dirname($rel));
    if ($relDir === '.' || $relDir === '/') $relDir = '';

    $folderId = 'default';
    if ($relDir !== '') {
        $folderId = ensure_folder_by_name($lib, $relDir);
        $libChanged = true;
    }

    $bookId   = 'book_' . date('Ymd_His') . '_' . mt_rand(1000, 9999);
    $bookPath = $uploadDir . '/' . $bookId;

    if (!is_dir($bookPath)) {
        mkdir($bookPath, 0777, true);
        // 移动文件
        rename($filePath, $bookPath . '/book.txt');
        // 写入标题
        file_put_contents($bookPath . '/title.txt', $title);
        // 写入文件夹映射
        $lib['book_map'][$bookId] = $folderId;
        $libChanged = true;
        $imported++;
    }
}

// 清理 incoming 里空文件夹
remove_empty_dirs($incomingDir);

// -----------------------------
// Scan uploads + cleanup mapping
// -----------------------------
$books = [];
$existingBookIds = [];
foreach (glob($uploadDir . '/*', GLOB_ONLYDIR) as $dir) {
    if (!is_book_dir($dir)) continue;
    $id = basename($dir);
    $existingBookIds[$id] = true;

    $titleFile = $dir . '/title.txt';
    $title = is_file($titleFile) ? safe_trim_fullwidth(file_get_contents($titleFile)) : $id;
    if ($title === '') $title = $id;

    $mtime = is_file($dir . '/book.txt') ? filemtime($dir . '/book.txt') : filemtime($dir);

    $folderId = $lib['book_map'][$id] ?? 'default';
    if ($folderId === '' || !folder_exists($lib, $folderId)) $folderId = 'default';

    // 如果缺少映射，自动补 default
    if (!isset($lib['book_map'][$id])) {
        $lib['book_map'][$id] = $folderId;
        $libChanged = true;
    }

    $books[] = [
        'id'        => $id,
        'title'     => $title,
        'mtime'     => $mtime,
        'folder_id' => $folderId,
        'folder'    => get_folder_name($lib, $folderId),
    ];
}

// 移除 book_map 中不存在的书
foreach (array_keys($lib['book_map']) as $bid) {
    if (!isset($existingBookIds[$bid])) {
        unset($lib['book_map'][$bid]);
        $libChanged = true;
    }
}

if ($libChanged) write_library($libraryFile, $lib);

// 最新的在前
usort($books, function($a, $b) {
    return $b['mtime'] <=> $a['mtime'];
});

// 当前筛选文件夹
$currentFolder = isset($_GET['folder']) ? clean_folder_id((string)$_GET['folder']) : 'all';
if ($currentFolder === '') $currentFolder = 'all';

$filteredBooks = $books;
if ($currentFolder !== 'all') {
    $filteredBooks = array_values(array_filter($books, function($b) use ($currentFolder) {
        return ($b['folder_id'] === $currentFolder);
    }));
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>书库</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <meta name="apple-mobile-web-app-title" content="小说阅读器">
    <meta name="theme-color" content="#111111">
    <link rel="apple-touch-icon" href="assets/icon.png">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* =========================================================
           Folder UI + Buttons (ALL NEW) — FORCE OVERRIDE
           黑/灰背景，白/灰字；全部 !important
           ========================================================= */

        :root{
          --ui-bg: #0f0f10;
          --ui-bg2:#161618;
          --ui-bg3:#1f1f22;
          --ui-border: rgba(255,255,255,.14);
          --ui-border2: rgba(255,255,255,.22);
          --ui-text: #f2f2f2;
          --ui-muted: rgba(242,242,242,.78);
          --ui-muted2: rgba(242,242,242,.62);
          --ui-danger:#b93434;
          --ui-danger2:#d14949;
        }

        /* folder chips */
        .folder-bar{
          display:flex !important;
          gap:10px !important;
          flex-wrap:wrap !important;
          margin:12px 0 !important;
          color: var(--ui-text) !important;
        }
        .folder-chip{
          display:inline-flex !important;
          align-items:center !important;
          gap:8px !important;
          padding:9px 12px !important;
          border-radius:999px !important;
          border:1px solid var(--ui-border) !important;
          background: var(--ui-bg2) !important;
          color: var(--ui-text) !important;
          text-decoration:none !important;
          font-weight:650 !important;
          letter-spacing:.2px !important;
          line-height:1 !important;
          user-select:none !important;
        }
        .folder-chip::before{
          content:"📁" !important;
          font-size:14px !important;
          opacity:.9 !important;
        }
        .folder-chip:hover{
          background: var(--ui-bg3) !important;
          border-color: var(--ui-border2) !important;
        }
        .folder-chip.active{
          background: #000 !important;
          border-color: rgba(255,255,255,.32) !important;
        }
        .folder-chip.active::before{
          content:"📂" !important;
        }

        /* folder admin card */
        .folder-admin{
          margin:14px 0 !important;
          padding:12px !important;
          border-radius:14px !important;
          background: var(--ui-bg) !important;
          color: var(--ui-text) !important;
          border: 1px solid var(--ui-border) !important;
        }

        .inline-form{
          display:flex !important;
          gap:10px !important;
          flex-wrap:wrap !important;
          align-items:center !important;
        }

        /* inputs / selects */
        .inline-form input,
        .inline-form select{
          padding:9px 11px !important;
          border-radius:12px !important;
          border:1px solid var(--ui-border) !important;
          background: var(--ui-bg2) !important;
          color: var(--ui-text) !important;
          outline:none !important;
        }
        .inline-form input::placeholder{
          color: var(--ui-muted2) !important;
        }
        .inline-form input:focus,
        .inline-form select:focus{
          border-color: rgba(255,255,255,.36) !important;
        }

        .admin-row{
          display:flex !important;
          gap:10px !important;
          flex-wrap:wrap !important;
          align-items:center !important;
          margin-top:10px !important;
        }

        /* mini buttons (admin actions) */
        .mini-btn{
          display:inline-flex !important;
          align-items:center !important;
          justify-content:center !important;
          padding:9px 12px !important;
          border-radius:12px !important;
          border:1px solid var(--ui-border) !important;
          background: var(--ui-bg2) !important;
          color: var(--ui-text) !important;
          cursor:pointer !important;
          font-weight:650 !important;
          text-decoration:none !important;
          line-height:1 !important;
          user-select:none !important;
        }
        .mini-btn:hover{
          background: var(--ui-bg3) !important;
          border-color: var(--ui-border2) !important;
        }
        .mini-btn:active{
          transform: translateY(1px) !important;
        }
        .mini-btn.danger{
          background: rgba(185,52,52,.22) !important;
          border-color: rgba(217,73,73,.55) !important;
          color: #ffdede !important;
        }
        .mini-btn.danger:hover{
          background: rgba(185,52,52,.30) !important;
          border-color: rgba(217,73,73,.75) !important;
        }

        /* book card sub text */
        .book-folder{
          font-size:12px !important;
          color: var(--ui-muted) !important;
          margin-top:4px !important;
        }

        /* main buttons (read/delete) */
        .btn{
          display:inline-flex !important;
          align-items:center !important;
          justify-content:center !important;
          padding:10px 14px !important;
          border-radius:12px !important;
          border:1px solid var(--ui-border) !important;
          background: var(--ui-bg2) !important;
          color: var(--ui-text) !important;
          text-decoration:none !important;
          font-weight:700 !important;
          line-height:1 !important;
          cursor:pointer !important;
          user-select:none !important;
        }
        .btn:hover{
          background: var(--ui-bg3) !important;
          border-color: var(--ui-border2) !important;
        }
        .btn:active{
          transform: translateY(1px) !important;
        }

        .btn.primary{
          background: #000 !important;
          border-color: rgba(255,255,255,.28) !important;
          color: #fff !important;
        }
        .btn.primary:hover{
          background: #111 !important;
        }

        .btn.danger{
          background: rgba(185,52,52,.22) !important;
          border-color: rgba(217,73,73,.55) !important;
          color: #ffdede !important;
        }
        .btn.danger:hover{
          background: rgba(185,52,52,.30) !important;
          border-color: rgba(217,73,73,.75) !important;
        }
    </style>
</head>
<body class="bookshelf-page">
<div class="app-shell">

    <header class="app-header">
        <div class="app-title">📚 书库</div>
        <div class="app-header-right">
            <?php if ($isAdmin): ?>
                <a href="logout.php" class="header-link">退出</a>
            <?php else: ?>
                <a href="login.php" class="header-link">管理员</a>
            <?php endif; ?>
        </div>
    </header>

    <main class="app-content">
        <section class="hint-card">
            <div>💡 将 <code>.txt</code> 文件放入服务器的 <code>incoming/</code> 目录，刷新本页面即可自动导入。</div>
            <?php if ($imported > 0): ?>
                <div style="margin-top:8px;">✅ 本次已导入：<?=$imported?> 本</div>
            <?php endif; ?>
        </section>

        <!-- Folder tabs -->
        <nav class="folder-bar">
            <a class="folder-chip <?=$currentFolder==='all'?'active':''?>" href="index.php">全部（<?=count($books)?>）</a>
            <?php foreach ($lib['folders'] as $f):
                $fid = (string)($f['id'] ?? '');
                $fname = (string)($f['name'] ?? $fid);
                if ($fid === '') continue;
                $countInFolder = 0;
                foreach ($books as $b) { if ($b['folder_id'] === $fid) $countInFolder++; }
            ?>
                <a class="folder-chip <?=$currentFolder===$fid?'active':''?>" href="index.php?folder=<?=urlencode($fid)?>">
                    <?=htmlspecialchars($fname)?>（<?=$countInFolder?>）
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($isAdmin): ?>
            <section class="folder-admin">
                <div style="font-weight:700;margin-bottom:10px;">🗂️ 文件夹管理</div>

                <form class="inline-form" method="post" action="index.php">
                    <input type="hidden" name="action" value="create_folder">
                    <input type="text" name="folder_name" placeholder="新建文件夹名称，例如：轻小说" required>
                    <button class="mini-btn" type="submit">新建</button>
                </form>

                <?php if ($currentFolder !== 'all' && $currentFolder !== 'default' && folder_exists($lib, $currentFolder)): ?>
                    <div class="admin-row">
                        <form class="inline-form" method="post" action="index.php?folder=<?=urlencode($currentFolder)?>">
                            <input type="hidden" name="action" value="rename_folder">
                            <input type="hidden" name="folder_id" value="<?=htmlspecialchars($currentFolder)?>">
                            <input type="text" name="new_name" value="<?=htmlspecialchars(get_folder_name($lib, $currentFolder))?>" required>
                            <button class="mini-btn" type="submit">重命名</button>
                        </form>

                        <form class="inline-form" method="post" action="index.php">
                            <input type="hidden" name="action" value="delete_folder">
                            <input type="hidden" name="folder_id" value="<?=htmlspecialchars($currentFolder)?>">
                            <button class="mini-btn danger" type="submit" onclick="return confirm('确定删除该文件夹吗？该文件夹下的书将移动到「未分类」。')">删除文件夹</button>
                        </form>
                    </div>
                <?php endif; ?>

                <div style="margin-top:10px;font-size:12px;color:rgba(242,242,242,.62);">提示：删除文件夹不会删除书籍，只会把书移动到「未分类」。</div>
            </section>
        <?php endif; ?>

        <?php if (empty($filteredBooks)): ?>
            <p>当前列表为空。<?php if ($currentFolder !== 'all'): ?>试试切换到其它文件夹或「全部」。<?php else: ?>请先上传 txt 到 <code>incoming/</code>。<?php endif; ?></p>
        <?php else: ?>
            <section class="book-list">
                <?php foreach ($filteredBooks as $book): ?>
                    <article class="book-card">
                        <div class="book-info">
                            <div class="book-title"><?=htmlspecialchars($book['title'])?></div>
                            <div class="book-folder">📁 <?=htmlspecialchars($book['folder'])?></div>
                            <div class="book-meta">上传日期：<?=date('Y-m-d H:i', $book['mtime'])?></div>

                            <?php if ($isAdmin): ?>
                                <div class="admin-row">
                                    <form class="inline-form" method="post" action="index.php?folder=<?=urlencode($currentFolder)?>">
                                        <input type="hidden" name="action" value="move_book">
                                        <input type="hidden" name="book_id" value="<?=htmlspecialchars($book['id'])?>">
                                        <select name="folder_id" onchange="this.form.submit()">
                                            <?php foreach ($lib['folders'] as $f):
                                                $fid = (string)($f['id'] ?? '');
                                                $fname = (string)($f['name'] ?? $fid);
                                                if ($fid === '') continue;
                                            ?>
                                                <option value="<?=htmlspecialchars($fid)?>" <?=$fid===$book['folder_id']?'selected':''?>><?=htmlspecialchars($fname)?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <noscript><button class="mini-btn" type="submit">移动</button></noscript>
                                    </form>

                                    <form class="inline-form" method="post" action="index.php?folder=<?=urlencode($currentFolder)?>">
                                        <input type="hidden" name="action" value="rename_book">
                                        <input type="hidden" name="book_id" value="<?=htmlspecialchars($book['id'])?>">
                                        <input type="text" name="new_title" value="<?=htmlspecialchars($book['title'])?>" style="min-width:220px" required>
                                        <button class="mini-btn" type="submit">改名</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="book-actions">
                            <a class="btn primary" href="reader.php?book=<?=urlencode($book['id'])?>">阅读</a>
                            <?php if ($isAdmin): ?>
                                <a class="btn danger" href="delete.php?book=<?=urlencode($book['id'])?>" onclick="return confirm('确定删除这本书吗？')">删除</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </main>

</div>
</body>
</html>