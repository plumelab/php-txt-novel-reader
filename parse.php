<?php
// parse.php?book=xxx
// GET：展示预览 + 输入目录起止行号（可空）
// POST：
//   - 若输入目录行号：只按目录逐条匹配正文标题（序号+标题优先），并生成“序章”
//   - 若不输入：按通用分章逻辑从头到尾识别章节
// 成功写入 chapters.json 后跳转 reader.php

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

// ===== 可调参数 =====
$PREVIEW_LINES = 260;              // 预览多少行（用于你标目录行号）
$MAX_CHARS_PER_CHAPTER = 5000;     // 单章超过多少“字”(去空白字符数)就拆分
$TITLE_SNIPPET_MIN = 1;            // 目录标题用于兜底片段的最小长度
$TITLE_SNIPPET_MAX = 18;           // 目录标题用于兜底片段的最大长度

// ------------------------- 读取全文并转为 UTF-8 -------------------------
function load_book_content_utf8(string $bookFile): string {
    $content = file_get_contents($bookFile);
    $encoding = mb_detect_encoding($content, ['UTF-8','GBK','GB2312','BIG5','ASCII'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    return $content;
}

// ------------------------- 基础工具 -------------------------
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_spaces(string $s): string {
    // trim() 不会去掉全角空格，所以这里用正则把半角/全角空白一起裁掉
    $s = preg_replace('/^[ \t　]+|[ \t　]+$/u', '', $s);
    $s = preg_replace('/[ \t　]+/u', ' ', $s);
    return $s;
}

function count_letters_no_space(string $s): int {
    $x = preg_replace('/\s+/u', '', $s);
    return mb_strlen($x, 'UTF-8');
}

function build_line_index(string $content): array {
    $lines = explode("\n", $content);
    $idx = [];
    $pos = 0;
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        $idx[] = ['text' => $line, 'pos' => $pos];
        $pos += strlen($line) + 1;
    }
    return $idx;
}

function end_pos_of_line(string $content, array $lineRec): int {
    $len = strlen($content);
    $end = $lineRec['pos'] + strlen($lineRec['text']) + 1;
    return min($end, $len);
}

function is_blank_line(string $line): bool {
    return normalize_spaces($line) === '';
}

function is_separator_line(string $line): bool {
    $t = trim($line);
    if ($t === '') return false;
    return (bool)preg_match('/^[\-＝=－ー—―_~～·・•\*＊\.．…‥]+$/u', $t);
}

// ------------------------- 目录项解析 -------------------------

function is_toc_header_line(string $line): bool {
    $c = preg_replace('/[ \t　]+/u', '', trim($line));
    $lower = strtolower($c);
    return ($c === '目次' || $c === '目录' || $c === '目錄' || $lower === 'contents' || $lower === 'tableofcontents');
}

/**
 * 目录项：必须“序号 + 分隔符 + 标题”
 * 关键点：避免把 “○六月七日…” 这种项目符号当章节
 */
function parse_toc_entry(string $line): ?array {
    $raw = trim($line);
    if ($raw === '' || is_separator_line($raw) || is_toc_header_line($raw)) return null;

    // 常见特殊章节（后记等）也作为“章节”处理
    // 注意：不要用 \b（word boundary），对日文/中文词可能不生效
    if (preg_match('/^[ \t　]*(プロローグ|エピローグ|序章|終章|前書き|あとがき|後書き|後記|后记|番外|外伝|外传|附录|附錄|Prologue|Epilogue|Afterword|Preface).*$/iu', $raw)) {
        return ['type'=>'special', 'seq'=>null, 'title'=>normalize_spaces($raw), 'raw'=>normalize_spaces($raw)];
    }

    // 日文：第0話 第１話 等
    if (preg_match('/^[ \t　]*第\s*([0-9０-９]+)\s*(話|章|節|回|部)\s*(.*)$/u', $raw, $m)) {
        $seq = '第' . normalize_spaces($m[1]) . $m[2];
        $title = normalize_spaces($m[3]);
        $full = trim($seq . ($title !== '' ? ' ' . $title : ''));
        return ['type'=>'jp', 'seq'=>$seq, 'title'=>$title, 'raw'=>normalize_spaces($full)];
    }

    // 日文：最終話
    if (preg_match('/^[ \t　]*最終\s*(話|章|節|回|部)\s*(.*)$/u', $raw, $m)) {
        $seq = '最終' . $m[1];
        $title = normalize_spaces($m[2]);
        $full = trim($seq . ($title !== '' ? ' ' . $title : ''));
        return ['type'=>'jp_last', 'seq'=>$seq, 'title'=>$title, 'raw'=>normalize_spaces($full)];
    }

    // 中文：第X章
    if (preg_match('/^[ \t　]*第([一二三四五六七八九十百千万零〇两0-9０-９]+)([章节卷回部幕集话])\s*(.*)$/u', $raw, $m)) {
        $seq = '第' . $m[1] . $m[2];
        $title = normalize_spaces($m[3]);
        $full = trim($seq . ($title !== '' ? ' ' . $title : ''));
        return ['type'=>'cn', 'seq'=>$seq, 'title'=>$title, 'raw'=>normalize_spaces($full)];
    }

    // ✅ 你重点的这种： 一　标题 / 二 标题 / 〇 标题
    // 强制要求：序号后必须有“空白/全角空白/、.．:：-”等分隔符，再跟标题
    if (preg_match('/^[ \t　]*([〇○零一二三四五六七八九十百千万]{1,6})(?:[ \t　]+|[、.．:：・\-–—―]+[ \t　]*)(\S.*)$/u', $raw, $m)) {
        $seq = $m[1];
        $title = normalize_spaces($m[2]);
        $full = $seq . ' ' . $title;
        return ['type'=>'han', 'seq'=>$seq, 'title'=>$title, 'raw'=>normalize_spaces($full)];
    }

    // 数字序号： 1 标题 / 1. 标题
    if (preg_match('/^[ \t　]*([0-9０-９]{1,4})(?:[ \t　]+|[.．、:：)\]][ \t　]*)(\S.*)$/u', $raw, $m)) {
        $seq = $m[1];
        $title = normalize_spaces($m[2]);
        $full = $seq . ' ' . $title;
        return ['type'=>'num', 'seq'=>$seq, 'title'=>$title, 'raw'=>normalize_spaces($full)];
    }

    // 轻小说/文库常见：一章　标题 / 二話「标题」/ 3章 标题（不带“第”）
    // 要点：必须带“章/話/節/回/部/幕”等单位，避免把“○xxxx”项目符号当章节
    if (preg_match('/^[ \t　]*([〇○零一二三四五六七八九十百千万]{1,6}|[0-9０-９]{1,4})\s*(章|話|節|回|部|幕)\s*(\S.*)$/u', $raw, $m)) {
        $seq = normalize_spaces($m[1]) . $m[2];
        $title = normalize_spaces($m[3]);
        $full = trim($seq . ($title !== '' ? ' ' . $title : ''));
        return ['type'=>'jp_unit', 'seq'=>$seq, 'title'=>$title, 'raw'=>normalize_spaces($full)];
    }


    // 轻小说常见：～１敗目～　标题（或 ~1敗目~）
    if (preg_match('/^[ \t　]*[～~]\s*([0-9０-９]+)\s*敗目\s*[～~]\s*(\S.*)$/u', $raw, $m)) {
        $seq = '～' . normalize_spaces($m[1]) . '敗目～';
        $title = normalize_spaces($m[2]);
        $full = $seq . ' ' . $title;
        return ['type'=>'wave', 'seq'=>$seq, 'title'=>$title, 'raw'=>normalize_spaces($full)];
    }

    // 轻小说常见：Intermission　标题（幕間）
    if (preg_match('/^[ \t　]*(Intermission)\s*(?:[ \t　]+|[、.．:：・\-–—―]+[ \t　]*)(\S.*)$/iu', $raw, $m)) {
        $seq = 'Intermission';
        $title = normalize_spaces($m[2]);
        $full = $seq . ' ' . $title;
        return ['type'=>'intermission', 'seq'=>$seq, 'title'=>$title, 'raw'=>normalize_spaces($full)];
    }

    return null;
}

/**
 * 目录区提取条目（去重，保持顺序）
 */
function extract_toc_entries(array $lineIndex, int $startIdx, int $endIdx): array {
    $entries = [];
    $seen = [];
    for ($i = $startIdx; $i <= $endIdx; $i++) {
        $line = $lineIndex[$i]['text'] ?? '';
        $e = parse_toc_entry($line);
        if (!$e) continue;
        $key = ($e['seq'] ?? '') . '|' . ($e['title'] ?? '') . '|' . ($e['raw'] ?? '');
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;
        $entries[] = $e;
    }
    return $entries;
}


/**
 * 手动输入目录条目（每行一个）
 * - 能解析成标准目录项就按标准处理
 * - 解析不了就当作“整行标题”，用于全文精确匹配
 */
function parse_manual_entries_text(string $text): array {
    $lines = preg_split("/\r\n|\r|\n/", $text);
    $entries = [];
    $seen = [];
    foreach ($lines as $ln) {
        $ln = normalize_spaces($ln);
        if ($ln === '') continue;

        $e = parse_toc_entry($ln);
        if (!$e) {
            $e = ['type'=>'manual_raw', 'seq'=>null, 'title'=>'', 'raw'=>normalize_spaces($ln)];
        }

        $key = ($e['type'] ?? '') . '|' . ($e['seq'] ?? '') . '|' . ($e['title'] ?? '') . '|' . ($e['raw'] ?? '');
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;
        $entries[] = $e;
    }
    return $entries;
}


// ------------------------- 正文匹配：按目录逐条找章节标题 -------------------------

function flex_quote_spaces(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $q = preg_quote($s, '/');
    // 让标题内部空白更宽容
    $q = preg_replace('/\\\\\s+/u', '[ \t　]+', $q);
    return $q;
}

/**
 * 构建匹配 regex：
 * 1) strict：seq + 空白 + title
 * 2) relax ：seq + 任意分隔符 + title
 * 3) seqOnly：seq + 分隔符 + 任意后续（仍要求分隔符，避免 ○六月七日…）
 */

function build_regex_for_entry(array $entry, string $mode): ?string {
    $seq = $entry['seq'] ?? null;
    $title = $entry['title'] ?? '';

    // special：直接整行匹配
    if (($entry['type'] ?? '') === 'special') {
        $kw = flex_quote_spaces($entry['raw'] ?? '');
        if ($kw === '') return null;
        return '/^[ \t　]*' . $kw . '[ \t　]*$/mu';
    }

    
    // 手动输入的条目：按整行标题匹配（忽略行首缩进，空白更宽容）
    if (($entry['type'] ?? '') === 'manual_raw') {
        $kw = flex_quote_spaces($entry['raw'] ?? '');
        if ($kw === '') return null;
        return '/^[ \t　]*' . $kw . '[ \t　]*$/mu';
    }

if (!$seq) return null;

    $seqQ = flex_quote_spaces($seq);
    $titleQ = flex_quote_spaces($title);

    // 通用分隔符集合（空白 or 常见标点）
    $sepRelax = '(?:[ \t　]+|[、.．:：・\-–—―]+[ \t　]*)';

    // ⭐ 针对「第０話」「第十章」「最終話」这类：允许 0 个空白直接接「 」
    $type = $entry['type'] ?? '';
    $isNoSepStyle = in_array($type, ['jp','jp_last','cn','jp_unit'], true);
    $sepStrict = $isNoSepStyle ? '[ \t　]*' : '[ \t　]+'; // 关键变化：jp/cn 用 * 而不是 +

    if ($mode === 'strict') {
        if ($titleQ === '') return null;
        return '/^[ \t　]*' . $seqQ . $sepStrict . $titleQ . '[ \t　]*$/mu';
    }

    if ($mode === 'relax') {
        if ($titleQ === '') return null;
        // jp/cn：允许 0 空白 或 常见分隔符
        $sep = $isNoSepStyle ? '(?:[ \t　]*|' . $sepRelax . ')' : $sepRelax;
        return '/^[ \t　]*' . $seqQ . $sep . $titleQ . '[ \t　]*$/mu';
    }

    if ($mode === 'seqOnly') {
        // seq-only 仍然要“像标题”：必须有后续文本
        // jp/cn：允许 0 空白直接跟内容；其他：仍要求 relax 分隔符
        if ($isNoSepStyle) {
            return '/^[ \t　]*' . $seqQ . '[ \t　]*\S.*$/mu';
        }
        return '/^[ \t　]*' . $seqQ . $sepRelax . '\S.*$/mu';
    }

    return null;
}

/**
 * 从 $startPos 起，在正文中找某一条目录对应的标题行
 * - 只在 startPos 之后找（保持章节顺序）
 */
function find_heading_pos(string $content, int $startPos, array $entry): ?array {
    $len = strlen($content);
    $startPos = max(0, min($startPos, $len));

    // 从下一行开始更稳
    $nl = strpos($content, "\n", $startPos);
    if ($nl !== false && $nl + 1 < $len) $startPos = $nl + 1;

    $tail = substr($content, $startPos);

    foreach (['strict', 'relax', 'seqOnly'] as $mode) {
        $re = build_regex_for_entry($entry, $mode);
        if (!$re) continue;

        if (preg_match($re, $tail, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $startPos + $m[0][1];
            $titleLine = normalize_spaces($m[0][0]);
            return ['pos'=>$pos, 'title'=>$titleLine, 'mode'=>$mode];
        }
    }

    return null;
}

/**
 * 按目录顺序逐条匹配正文标题：
 * - 必须全部匹配成功，否则返回失败信息（避免生成乱章）
 */
function find_all_headings_by_toc(string $content, int $bodyStartPos, array $tocEntries): array {
    $heads = [];
    $missing = [];

    $curPos = $bodyStartPos;

    foreach ($tocEntries as $idx => $e) {
        $found = find_heading_pos($content, $curPos, $e);
        if (!$found) {
            $missing[] = $e['raw'] ?? (($e['seq'] ?? '') . ' ' . ($e['title'] ?? ''));
            continue;
        }

        $heads[] = [
            'pos'   => $found['pos'],
            'title' => $found['title'],
            'mode'  => $found['mode'],
            'toc'   => $e['raw'] ?? '',
        ];

        // 下一条从本条之后找（顺序严格）
        $curPos = $found['pos'] + 1;
    }

    return ['heads'=>$heads, 'missing'=>$missing];
}

// ------------------------- 普通分章：通用标题检测（不依赖目录） -------------------------

function is_title_candidate_line(string $line): bool {
    $t = trim($line);
    if ($t === '' || is_separator_line($t) || is_toc_header_line($t)) return false;
    if (mb_strlen($t, 'UTF-8') > 140) return false;

    // 强：第X章/话
    if (preg_match('/^[ \t　]*第[一二三四五六七八九十百千万零〇两0-9０-９]+[章节卷回部幕集话][^\n]*$/u', $t)) return true;
    if (preg_match('/^[ \t　]*第\s*[0-9０-９]+\s*(話|章|節|回|部)[^\n]*$/u', $t)) return true;
    if (preg_match('/^[ \t　]*最終\s*(話|章|節|回|部)[^\n]*$/u', $t)) return true;

    // 特殊章节
    if (preg_match('/^[ \t　]*(プロローグ|エピローグ|序章|終章|前書き|あとがき|後書き|後記|后记|番外|外伝|外传|附录|附錄|Prologue|Epilogue|Afterword|Preface).*$/iu', $t)) return true;

    // 不带“第”的 〇章 / 一章 / 3章 这类
    if (preg_match('/^[ \t　]*([〇○零一二三四五六七八九十百千万]{1,6}|[0-9０-９]{1,4})\s*(章|話|節|回|部|幕)\s*\S.+$/u', $t)) return true;

    // ～１敗目～ 标题
    if (preg_match('/^[ \t　]*[～~]\s*[0-9０-９]+\s*敗目\s*[～~]\s*\S.+$/u', $t)) return true;

    // Intermission 标题
    if (preg_match('/^[ \t　]*Intermission\s*(?:[ \t　]+|[、.．:：・\-–—―]+[ \t　]*)\S.+$/iu', $t)) return true;

    // 序号型：必须有分隔符（避免 ○六月七日…）
    if (preg_match('/^[ \t　]*[〇○零一二三四五六七八九十百千万]{1,6}(?:[ \t　]+|[、.．:：・\-–—―]+[ \t　]*)\S.+$/u', $t)) return true;
    if (preg_match('/^[ \t　]*[0-9０-９]{1,4}(?:[ \t　]+|[.．、:：)\]][ \t　]*)\S.+$/u', $t)) return true;

    return false;
}

function detect_heads_generic(array $lineIndex, int $startPos): array {
    $heads = [];
    $n = count($lineIndex);
    if ($n === 0) return $heads;

    $startIdx = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($lineIndex[$i]['pos'] >= $startPos) { $startIdx = $i; break; }
    }

    $lastPos = -1;
    for ($i = $startIdx; $i < $n; $i++) {
        $line = $lineIndex[$i]['text'];
        if (!is_title_candidate_line($line)) continue;

        // 上下文增强：前后空行更像标题
        $prevBlank = ($i > 0 && is_blank_line($lineIndex[$i - 1]['text']));
        $nextBlank = ($i + 1 < $n && is_blank_line($lineIndex[$i + 1]['text']));
        if (!($prevBlank || $nextBlank)) continue;

        $pos = $lineIndex[$i]['pos'];
        if ($lastPos >= 0 && ($pos - $lastPos) < 5) continue;

        $heads[] = ['pos'=>$pos, 'title'=>normalize_spaces(trim($line))];
        $lastPos = $pos;
    }

    return $heads;
}

// ------------------------- 切章 + 序章 + 超长拆分 -------------------------

function build_chapters_with_prologue(string $content, array $heads, bool $forcePrologue): array {
    $len = strlen($content);

    if (count($heads) === 0) {
        return [[ 'title' => '正文', 'content' => $content ]];
    }

    usort($heads, fn($a,$b) => $a['pos'] <=> $b['pos']);

    $chapters = [];

    $firstPos = $heads[0]['pos'];
    if ($forcePrologue && $firstPos > 0) {
        $pre = substr($content, 0, $firstPos);
        if (trim($pre) !== '') {
            $chapters[] = ['title' => '序章', 'content' => $pre];
        }
    }

    for ($i = 0; $i < count($heads); $i++) {
        $start = $heads[$i]['pos'];
        $end   = ($i + 1 < count($heads)) ? $heads[$i + 1]['pos'] : $len;
        if ($end <= $start) continue;

        $text = substr($content, $start, $end - $start);
        $title = $heads[$i]['title'] ?? '章节';

        // 确保标题在章首
        $lines = explode("\n", $text);
        $firstNonEmpty = null;
        foreach ($lines as $ln) {
            $t = trim($ln);
            if ($t !== '') { $firstNonEmpty = normalize_spaces($t); break; }
        }
        if ($firstNonEmpty === null || normalize_spaces($firstNonEmpty) !== normalize_spaces($title)) {
            $text = $title . "\n" . $text;
        }

        $chapters[] = ['title' => $title, 'content' => $text];
    }

    return $chapters;
}

function split_big_block_by_lines(string $block, int $maxChars): array {
    $block = str_replace(["\r\n", "\r"], "\n", $block);
    $lines = explode("\n", $block);

    $out = [];
    $cur = '';

    foreach ($lines as $ln) {
        if (count_letters_no_space($ln) > $maxChars) {
            if ($cur !== '') { $out[] = $cur; $cur = ''; }
            $len = mb_strlen($ln, 'UTF-8');
            for ($start = 0; $start < $len; $start += $maxChars) {
                $out[] = mb_substr($ln, $start, $maxChars, 'UTF-8');
            }
            continue;
        }

        $candidate = ($cur === '') ? $ln : ($cur . "\n" . $ln);
        if (count_letters_no_space($candidate) <= $maxChars) $cur = $candidate;
        else { if ($cur !== '') $out[] = $cur; $cur = $ln; }
    }

    if ($cur !== '') $out[] = $cur;
    return $out;
}

function split_text_into_chunks(string $text, int $maxChars): array {
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    $chunks = [];
    $current = '';

    $paras = preg_split("/\n{2,}/", $text);
    foreach ($paras as $para) {
        $para = rtrim($para, "\n");
        if ($para === '') continue;

        $candidate = ($current === '') ? $para : ($current . "\n\n" . $para);
        if (count_letters_no_space($candidate) <= $maxChars) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') { $chunks[] = $current; $current = ''; }

        if (count_letters_no_space($para) > $maxChars) {
            foreach (split_big_block_by_lines($para, $maxChars) as $p) {
                if ($current === '') $current = $p;
                else { $chunks[] = $current; $current = $p; }
            }
        } else {
            $current = $para;
        }
    }

    if ($current !== '') $chunks[] = $current;
    if (!count($chunks)) $chunks[] = $text;

    return $chunks;
}

function split_oversized_chapters(array $chapters, int $maxChars): array {
    $out = [];
    foreach ($chapters as $ch) {
        $title = $ch['title'] ?? '正文';
        $text  = $ch['content'] ?? '';

        if (count_letters_no_space($text) <= $maxChars) {
            $out[] = $ch;
            continue;
        }

        $parts = split_text_into_chunks($text, $maxChars);
        if (count($parts) <= 1) { $out[] = $ch; continue; }

        for ($i = 0; $i < count($parts); $i++) {
            $out[] = [
                'title'   => $title . '（' . ($i + 1) . '）',
                'content' => $parts[$i],
            ];
        }
    }
    return $out;
}

// ------------------------- GET：渲染预览页面 -------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $content = load_book_content_utf8($bookFile);
    $lines = explode("\n", $content);

    $showN = min($PREVIEW_LINES, count($lines));
    $preview = '';
    for ($i = 0; $i < $showN; $i++) {
        $no = str_pad((string)($i + 1), 4, ' ', STR_PAD_LEFT);
        $preview .= $no . " | " . $lines[$i] . "\n";
    }

    $action = 'parse.php?book=' . urlencode($bookId);

    echo '<!doctype html><html lang="zh"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>手动标记目录区域 - ' . h($bookId) . '</title>';
    echo '<style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"Noto Sans CJK SC","Noto Sans JP",sans-serif;margin:20px;background:#fafafa}
        .box{background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px;max-width:1100px}
        textarea{width:100%;height:60vh;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;line-height:1.35;white-space:pre}
        .row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin:12px 0}
        label{display:block;font-size:13px;color:#333}
        input[type=number]{width:160px;padding:8px;border:1px solid #ccc;border-radius:8px}
        .btn{padding:10px 14px;border-radius:10px;border:1px solid #333;background:#111;color:#fff;cursor:pointer}
        .btn.secondary{background:#fff;color:#111;border-color:#bbb}
        .hint{font-size:13px;color:#555;margin-top:6px}
        .warn{font-size:13px;color:#a33;margin-top:8px}
    </style></head><body>';

    echo '<div class="box">';
    echo '<h2 style="margin:0 0 10px 0;">手动标记目录区域（可选）</h2>';
    echo '<div class="hint">✅ 填目录行号：仅按“目录里每一条（序号+标题）”逐条去正文匹配，并生成“序章”。<br>✅ 不填：按通用分章逻辑从头到尾识别章节。</div>';

    echo '<form method="post" action="' . h($action) . '">';
    echo '<div class="row">';
    echo '<div><label>目录开始行（可空）<br><input type="number" name="toc_start_line" min="1" placeholder="例如 3"></label></div>';
    echo '<div><label>目录结束行（可空）<br><input type="number" name="toc_end_line" min="1" placeholder="例如 40"></label></div>';
    echo '<div style="flex:1"></div>';
    echo '<button class="btn" type="submit" name="mode" value="run">生成章节并进入阅读</button>';
    echo '<button class="btn secondary" type="submit" name="mode" value="auto">跳过目录，自动分章</button>';
    echo '</div>';

    echo '<textarea readonly>' . h($preview) . '</textarea>';
    echo '<div class="hint">预览显示前 ' . (int)$showN . ' 行；目录更靠后可调大 $PREVIEW_LINES。</div>';
    echo '<div class="warn">建议：目录区覆盖“目次/目录 + 所有目录项”，但不要包含正文第一章标题那一行。</div>';

    echo '</form></div></body></html>';
    exit;
}

// ------------------------- POST：执行分章 -------------------------
$content = load_book_content_utf8($bookFile);
$lineIndex = build_line_index($content);
$totalLines = count($lineIndex);

$tocStartLine = isset($_POST['toc_start_line']) ? (int)$_POST['toc_start_line'] : 0;
$tocEndLine   = isset($_POST['toc_end_line']) ? (int)$_POST['toc_end_line'] : 0;

$mode = isset($_POST['mode']) ? $_POST['mode'] : 'run';

if ($mode === 'auto') {
    $tocStartLine = 0;
    $tocEndLine = 0;
}

$chapters = [];

if ($tocStartLine > 0 && $tocEndLine > 0 && $tocStartLine <= $tocEndLine
    && $tocStartLine <= $totalLines && $tocEndLine <= $totalLines) {

    // 目录模式（严格按目录逐条匹配正文标题）
    $startIdx = $tocStartLine - 1;
    $endIdx   = $tocEndLine - 1;
    $tocEndPos = end_pos_of_line($content, $lineIndex[$endIdx]);

    $tocEntriesAuto = extract_toc_entries($lineIndex, $startIdx, $endIdx);

    // ✅ 无论自动提取结果如何，都先让你确认/编辑章节条目（避免误判导致乱切）
    if ($mode === 'run') {
        $prefillLines = [];
        if (count($tocEntriesAuto) > 0) {
            foreach ($tocEntriesAuto as $e) {
                $prefillLines[] = $e['raw'] ?? trim(($e['seq'] ?? '') . ' ' . ($e['title'] ?? ''));
            }
        } else {
            // 如果一条都提取不到，就把目录区里“非空且不是目次/分隔线”的行全部塞进去，方便你删改
            for ($i = $startIdx; $i <= $endIdx; $i++) {
                $t = trim($lineIndex[$i]['text'] ?? '');
                if ($t === '') continue;
                if (is_toc_header_line($t) || is_separator_line($t)) continue;
                $prefillLines[] = $t;
            }
        }

        $prefill = implode("\n", $prefillLines);
        $cntAuto = count($tocEntriesAuto);

        echo '<!doctype html><html lang="zh"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>确认章节条目 - ' . h($bookId) . '</title>';
        echo '<style>
            body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,"Noto Sans CJK SC","Noto Sans JP",sans-serif;margin:20px;background:#fafafa}
            .box{background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px;max-width:1100px}
            textarea{width:100%;height:50vh;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;line-height:1.35;white-space:pre}
            .row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin:12px 0}
            .btn{padding:10px 14px;border-radius:10px;border:1px solid #333;background:#111;color:#fff;cursor:pointer}
            .btn.secondary{background:#fff;color:#111;border-color:#bbb}
            .hint{font-size:13px;color:#555;margin-top:6px}
        </style></head><body>';

        echo '<div class="box">';
        echo '<h2 style="margin:0 0 10px 0;">确认 / 编辑目录章节条目</h2>';
        echo '<div class="hint">已自动识别 <b>' . (int)$cntAuto . '</b> 条（仅供参考）。你可以在下方增删改：<br>✅ 每行一个条目（例如：<code>序章　…</code>、<code>一章　…</code>、<code>第１話「…」</code>、<code>～１敗目～　…</code>、<code>Intermission　…</code>、<code>あとがき</code>）。<br>✅ 行首缩进/全角空格会自动忽略，空白多少不敏感。</div>';

        echo '<form method="post" action="parse.php?book=' . h($bookId) . '">';
        echo '<input type="hidden" name="toc_start_line" value="' . (int)$tocStartLine . '">';
        echo '<input type="hidden" name="toc_end_line" value="' . (int)$tocEndLine . '">';
        echo '<div class="row">';
        echo '<button class="btn" type="submit" name="mode" value="manual_entries">确认并分章 → 进入阅读</button>';
        echo '<button class="btn secondary" type="submit" name="mode" value="auto">跳过目录，自动分章</button>';
        echo '<a class="btn secondary" style="text-decoration:none;line-height:20px" href="parse.php?book=' . h($bookId) . '">返回重新选择</a>';
        echo '</div>';
        echo '<textarea name="manual_entries" placeholder="每行一个章节条目...">' . h($prefill) . '</textarea>';
        echo '</form></div></body></html>';
        exit;
    }

    // 确认页提交后：按你输入的条目切章
    if ($mode === 'manual_entries') {
        $tocEntries = parse_manual_entries_text($_POST['manual_entries'] ?? '');
        if (count($tocEntries) < 1) {
            echo '<!doctype html><meta charset="utf-8"><div style="font-family:system-ui;padding:20px;max-width:900px">';
            echo '<h3>章节条目为空</h3>';
            echo '<p>请至少输入 1 行章节条目（每行一个）。</p>';
            echo '<p><a href="parse.php?book=' . h($bookId) . '">返回</a></p>';
            echo '</div>';
            exit;
        }
    } else {
        // 理论上不会走到这里（因为 run 会先进入确认页），但保留兜底
        $tocEntries = $tocEntriesAuto;
    }
// 按目录顺序逐条匹配正文标题：必须全部命中，否则不生成
    $result = find_all_headings_by_toc($content, $tocEndPos, $tocEntries);
    $heads = $result['heads'];
    $missing = $result['missing'];

    if (count($missing) > 0) {
        echo '<!doctype html><meta charset="utf-8"><div style="font-family:system-ui;padding:20px;max-width:900px">';
        echo '<h3>目录匹配失败：有章节在正文中没找到（为避免乱切，本次未生成章节）</h3>';
        echo '<p>未匹配到的目录条目：</p><ul>';
        foreach ($missing as $m) {
            echo '<li><code>' . h($m) . '</code></li>';
        }
        echo '</ul>';
        echo '<p>建议：确认“目录结束行”不要包含正文第一章标题；或目录范围是否过大/过小。</p>';
        echo '<p><a href="parse.php?book=' . h($bookId) . '">返回重新选择</a> ｜ ';
        echo '<a href="parse.php?book=' . h($bookId) . '&skip=1">（可选）返回后直接点“跳过目录，自动分章”</a></p>';
        echo '</div>';
        exit;
    }

    // ✅ 第一章之前全部变“序章”
    $chapters = build_chapters_with_prologue($content, $heads, true);

} else {
    // 普通模式（不选目录）：通用分章从头到尾
    $heads = detect_heads_generic($lineIndex, 0);

    // 普通模式：仍然把第一章之前并入第一章（你之前的习惯），不强制序章
    // 但你如果也想普通模式也生成序章，把 false 改 true 即可。
    $chapters = build_chapters_with_prologue($content, $heads, false);

    if (count($heads) === 0) {
        $chapters = [[ 'title' => '正文', 'content' => $content ]];
    }
}

// 超长章节拆分
$chapters = split_oversized_chapters($chapters, $MAX_CHARS_PER_CHAPTER);

// 写入 JSON
file_put_contents($jsonFile, json_encode($chapters, JSON_UNESCAPED_UNICODE));

// 跳回阅读
header('Location: reader.php?book=' . urlencode($bookId));
exit;