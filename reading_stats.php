<?php
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>阅读记录</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <link rel="apple-touch-icon" href="assets/icon.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="stats-page">
<div class="app-shell stats-shell">
    <header class="app-header">
        <a href="index.php" class="header-back">← 书库</a>
        <div class="app-title">📈 阅读记录</div>
        <div class="stats-range-switch" id="range-switch">
            <button type="button" class="range-btn active" data-days="7">7天</button>
            <button type="button" class="range-btn" data-days="30">30天</button>
            <button type="button" class="range-btn" data-days="90">90天</button>
        </div>
    </header>

    <main class="app-content stats-content">
        <section id="stats-overview" class="stats-overview"></section>

        <section class="stats-grid-2">
            <article class="stats-panel">
                <h3>阅读趋势（字符）</h3>
                <div id="daily-chart" class="daily-chart"></div>
            </article>
            <article class="stats-panel">
                <h3>时段热力（24小时）</h3>
                <div id="hourly-heatmap" class="hourly-heatmap"></div>
            </article>
        </section>

        <section class="stats-grid-2">
            <article class="stats-panel">
                <h3>书籍阅读排行</h3>
                <div id="top-books" class="top-books"></div>
            </article>
            <article class="stats-panel">
                <h3>最近阅读会话</h3>
                <div id="recent-sessions" class="recent-sessions"></div>
            </article>
        </section>
    </main>
</div>

<script>
const overviewEl = document.getElementById('stats-overview');
const dailyChartEl = document.getElementById('daily-chart');
const hourlyHeatmapEl = document.getElementById('hourly-heatmap');
const topBooksEl = document.getElementById('top-books');
const recentSessionsEl = document.getElementById('recent-sessions');
const rangeSwitchEl = document.getElementById('range-switch');

let currentDays = 7;

function escapeHTML(str) {
    return String(str || '').replace(/[&<>"']/g, (s) => {
        if (s === '&') return '&amp;';
        if (s === '<') return '&lt;';
        if (s === '>') return '&gt;';
        if (s === '"') return '&quot;';
        return '&#39;';
    });
}

function formatDuration(seconds) {
    const sec = Math.max(0, Number(seconds || 0));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    if (h > 0) return `${h}h ${m}m`;
    return `${m}m`;
}

function formatNumber(num) {
    return Number(num || 0).toLocaleString('zh-CN');
}

function formatDateTime(ts) {
    const d = new Date((Number(ts) || 0) * 1000);
    if (Number.isNaN(d.getTime())) return '-';
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hh = String(d.getHours()).padStart(2, '0');
    const mm = String(d.getMinutes()).padStart(2, '0');
    return `${y}-${m}-${day} ${hh}:${mm}`;
}

function renderOverview(overview) {
    const items = [
        { label: '总会话数', value: formatNumber(overview.sessions) },
        { label: '总阅读时长', value: formatDuration(overview.activeSec) },
        { label: '总阅读字符', value: formatNumber(overview.charsRead) },
        { label: '平均速度', value: `${formatNumber(overview.avgSpeedCpm)} 字符/分钟` },
        { label: '今日阅读字符', value: formatNumber(overview.todayChars) },
        { label: '近7天阅读字符', value: formatNumber(overview.day7Chars) }
    ];

    overviewEl.innerHTML = items.map(item => `
        <div class="kpi-card">
            <div class="kpi-label">${escapeHTML(item.label)}</div>
            <div class="kpi-value">${escapeHTML(item.value)}</div>
        </div>
    `).join('');
}

function renderDailyChart(daily) {
    if (!Array.isArray(daily) || daily.length === 0) {
        dailyChartEl.innerHTML = '<div class="stats-empty">暂无数据</div>';
        return;
    }

    const maxChars = Math.max(1, ...daily.map(d => Number(d.charsRead || 0)));
    const recent = daily.slice(-Math.min(daily.length, 30));

    dailyChartEl.innerHTML = recent.map(item => {
        const chars = Number(item.charsRead || 0);
        const sec = Number(item.activeSec || 0);
        const ratio = Math.max(0.04, chars / maxChars);
        const height = Math.round(ratio * 100);
        const day = String(item.date || '').slice(5);
        return `
            <div class="daily-bar-wrap" title="${escapeHTML(item.date || '')}\n${formatNumber(chars)} 字符\n${formatDuration(sec)}">
                <div class="daily-bar" style="height:${height}%"></div>
                <div class="daily-bar-label">${escapeHTML(day)}</div>
            </div>
        `;
    }).join('');
}

function renderHourlyHeatmap(hourly) {
    if (!Array.isArray(hourly) || hourly.length === 0) {
        hourlyHeatmapEl.innerHTML = '<div class="stats-empty">暂无数据</div>';
        return;
    }

    const maxChars = Math.max(1, ...hourly.map(h => Number(h.charsRead || 0)));
    hourlyHeatmapEl.innerHTML = hourly.map(item => {
        const hour = Number(item.hour || 0);
        const chars = Number(item.charsRead || 0);
        const sessions = Number(item.sessions || 0);
        const ratio = chars / maxChars;
        const alpha = (0.10 + ratio * 0.90).toFixed(3);
        return `
            <div class="hour-cell" style="background: rgba(59,130,246,${alpha});" title="${String(hour).padStart(2, '0')}:00\n${formatNumber(chars)} 字符\n${sessions} 次会话">
                <span class="hour-label">${String(hour).padStart(2, '0')}</span>
                <span class="hour-value">${formatNumber(chars)}</span>
            </div>
        `;
    }).join('');
}

function renderTopBooks(topBooks) {
    if (!Array.isArray(topBooks) || topBooks.length === 0) {
        topBooksEl.innerHTML = '<div class="stats-empty">暂无数据</div>';
        return;
    }

    const maxChars = Math.max(1, ...topBooks.map(b => Number(b.charsRead || 0)));
    topBooksEl.innerHTML = topBooks.slice(0, 10).map((item, index) => {
        const chars = Number(item.charsRead || 0);
        const ratio = Math.max(0.04, chars / maxChars);
        const width = Math.round(ratio * 100);
        return `
            <div class="book-rank-item">
                <div class="book-rank-head">
                    <span class="book-rank-title">#${index + 1} ${escapeHTML(item.bookTitle || item.bookId || '未知书籍')}</span>
                    <span class="book-rank-meta">${formatNumber(chars)} 字符 · ${formatDuration(item.activeSec || 0)}</span>
                </div>
                <div class="book-rank-bar-bg"><div class="book-rank-bar" style="width:${width}%"></div></div>
            </div>
        `;
    }).join('');
}

function renderRecentSessions(sessions) {
    if (!Array.isArray(sessions) || sessions.length === 0) {
        recentSessionsEl.innerHTML = '<div class="stats-empty">暂无数据</div>';
        return;
    }

    const rows = sessions.slice(0, 20).map(item => {
        const chapterRange = `第 ${Number(item.chapterFrom || 0) + 1} 章 / ${Number(item.pageFrom || 0) + 1} 页 → 第 ${Number(item.chapterTo || 0) + 1} 章 / ${Number(item.pageTo || 0) + 1} 页`;
        return `
            <tr>
                <td>${escapeHTML(formatDateTime(item.endAt))}</td>
                <td>${escapeHTML(item.bookTitle || item.bookId || '-')}</td>
                <td>${escapeHTML(formatDuration(item.activeSec))}</td>
                <td>${escapeHTML(formatNumber(item.charsRead))}</td>
                <td>${escapeHTML(formatNumber(item.speedCpm))}</td>
                <td>${escapeHTML(chapterRange)}</td>
            </tr>
        `;
    }).join('');

    recentSessionsEl.innerHTML = `
        <div class="sessions-table-wrap">
            <table class="sessions-table">
                <thead>
                    <tr>
                        <th>时间</th>
                        <th>书籍</th>
                        <th>时长</th>
                        <th>字符</th>
                        <th>速度</th>
                        <th>范围</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>
    `;
}

function renderEmptyState(msg) {
    const html = `<div class="stats-empty-large">${escapeHTML(msg)}</div>`;
    overviewEl.innerHTML = html;
    dailyChartEl.innerHTML = html;
    hourlyHeatmapEl.innerHTML = html;
    topBooksEl.innerHTML = html;
    recentSessionsEl.innerHTML = html;
}

async function loadSummary() {
    try {
        const resp = await fetch(`reading_log.php?action=summary&days=${currentDays}&_t=${Date.now()}`);
        const data = await resp.json();
        if (!resp.ok || !data || data.ok !== true) {
            throw new Error((data && data.error) ? data.error : 'load_failed');
        }

        const overview = data.overview || {};
        const hasData = Number(overview.sessions || 0) > 0;
        if (!hasData) {
            renderEmptyState('暂无阅读记录，先去阅读几页后再来看趋势。');
            return;
        }

        renderOverview(overview);
        renderDailyChart(data.daily || []);
        renderHourlyHeatmap(data.hourly || []);
        renderTopBooks(data.topBooks || []);
        renderRecentSessions(data.recentSessions || []);
    } catch (err) {
        renderEmptyState('加载失败，请稍后重试。');
        console.warn('loadSummary error', err);
    }
}

if (rangeSwitchEl) {
    rangeSwitchEl.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-days]');
        if (!btn) return;
        const days = parseInt(btn.getAttribute('data-days') || '30', 10);
        if (Number.isNaN(days)) return;
        currentDays = days;

        rangeSwitchEl.querySelectorAll('.range-btn').forEach(item => {
            item.classList.toggle('active', item === btn);
        });

        loadSummary();
    });
}

loadSummary();
</script>
</body>
</html>
