let totalChapters = 0;        // 总章节数
let currentChapterIndex = 0;  // 当前章节索引（0-based）
let currentPageIndex = 0;     // 当前页索引（0-based）
let currentPages = [];        // 当前章节分页结果（每页 HTML）
let chapterCache = {};        // 缓存：chapterIndex -> { title, text, pages, total }

let readingSettings = {
    fontSize: 17,   // px
    lineHeight: 1.8,
    padding: 14     // px，上下左右内边距
};

let resizeTimer = null;
let uiVisible = false;        // 是否显示上下栏（默认隐藏更沉浸）

// ===== DOM =====
const titleEl         = document.getElementById('chapter-title');
const contentEl       = document.getElementById('chapter-content');
const readerMain      = document.getElementById('reader-main');
const innerEl         = document.getElementById('reader-main-inner');
const chapterRangeListEl = document.getElementById('chapter-range-list');
const chapterRangeTitleEl = document.getElementById('chapter-range-title');
const chapterRangeBackEl = document.getElementById('chapter-range-back');
const chapterListEl   = document.getElementById('chapter-list');
const chapterListEmptyEl = document.getElementById('chapter-list-empty');
const rootEl          = document.documentElement;
const readerShellEl   = document.querySelector('.reader-shell');
const progressBadgeEl = document.getElementById('reading-progress');
const footerProgressEl= document.getElementById('footer-progress');

const settingsModalEl = document.getElementById('settings-modal');
const aiModalEl       = document.getElementById('ai-modal');

const btnSettingsOpen = document.getElementById('btn-settings-open');
const btnThemeToggle  = document.getElementById('btn-theme-toggle');
const btnImmersive    = document.getElementById('btn-immersive-toggle');

const selectionActionsEl = document.getElementById('selection-actions');
const selectionAskAiEl   = document.getElementById('selection-ask-ai');
const selectionCopyEl    = document.getElementById('selection-copy');

const aiContextPreviewEl = document.getElementById('ai-context-preview');
const aiChatLogEl        = document.getElementById('ai-chat-log');
const aiUserInputEl      = document.getElementById('ai-user-input');
const aiSendBtnEl        = document.getElementById('ai-send');
const aiClearBtnEl       = document.getElementById('ai-clear');
const aiErrorEl          = document.getElementById('ai-error');

let chapterList = [];
let currentRangeIndex = null;
let chapterListLoading = false;

const CHAPTERS_PER_RANGE = 50;

// ===== Toast =====
let toastEl = null;
function ensureToast() {
    if (toastEl) return toastEl;
    toastEl = document.createElement('div');
    toastEl.className = 'toast';
    toastEl.textContent = '';
    document.body.appendChild(toastEl);
    return toastEl;
}

function showToast(msg, ms = 1200) {
    const el = ensureToast();
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), ms);
}

/** 工具：HTML 转义 */
function escapeHTML(str) {
    return (str || '').replace(/[&<>"']/g, s => {
        switch (s) {
            case '&': return '&amp;';
            case '<': return '&lt;';
            case '>': return '&gt;';
            case '"': return '&quot;';
            case "'": return '&#39;';
            default: return s;
        }
    });
}

/** 工具：限制数值范围 */
function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
}

function isAnyModalOpen() {
    return !!document.querySelector('.modal.show');
}

function openModal(modalEl) {
    if (!modalEl) return;
    modalEl.classList.add('show');
    modalEl.setAttribute('aria-hidden', 'false');
}

function closeModal(modalEl) {
    if (!modalEl) return;
    modalEl.classList.remove('show');
    modalEl.setAttribute('aria-hidden', 'true');
}

function closeAllModals() {
    document.querySelectorAll('.modal.show').forEach(m => closeModal(m));
}

// 点击遮罩 / 关闭按钮关闭弹窗
document.addEventListener('click', (e) => {
    const closeBtn = e.target.closest('[data-close="1"]');
    if (!closeBtn) return;
    const modal = closeBtn.closest('.modal');
    if (modal) closeModal(modal);
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeAllModals();
        hideSelectionActions();
    }
});

/* =============================
   主题
   ============================= */
let currentTheme = 'dark';
const THEME_KEY = 'novel_reader_theme';

function themeLabel(theme) {
    if (theme === 'paper') return '主题：纸';
    if (theme === 'sepia') return '主题：茶';
    return '主题：夜';
}

function applyTheme() {
    rootEl.setAttribute('data-theme', currentTheme);
    try {
        localStorage.setItem(THEME_KEY, currentTheme);
    } catch (e) {}
    if (btnThemeToggle) btnThemeToggle.textContent = themeLabel(currentTheme);
}

function loadTheme() {
    try {
        const t = localStorage.getItem(THEME_KEY);
        if (t === 'dark' || t === 'paper' || t === 'sepia') {
            currentTheme = t;
        }
    } catch (e) {}
    applyTheme();
}

function cycleTheme() {
    const order = ['dark', 'paper', 'sepia'];
    const idx = order.indexOf(currentTheme);
    currentTheme = order[(idx + 1) % order.length];
    applyTheme();
}

/* =============================
   阅读进度（本地 + 云端）
   ============================= */
function loadLocalProgress() {
    try {
        const key = 'novel_reader_progress_' + BOOK_ID;
        const raw = localStorage.getItem(key);
        if (!raw) return null;
        const data = JSON.parse(raw);
        if (typeof data.chapterIndex === 'number' && typeof data.pageIndex === 'number') {
            return data;
        }
    } catch (e) {
        console.warn('loadLocalProgress error', e);
    }
    return null;
}

function saveLocalProgress() {
    try {
        const key = 'novel_reader_progress_' + BOOK_ID;
        const data = {
            chapterIndex: currentChapterIndex,
            pageIndex: currentPageIndex
        };
        localStorage.setItem(key, JSON.stringify(data));
    } catch (e) {
        console.warn('saveLocalProgress error', e);
    }
}

function loadRemoteProgress() {
    return fetch(`progress.php?book=${encodeURIComponent(BOOK_ID)}&_t=${Date.now()}`)
        .then(r => r.ok ? r.json() : null)
        .catch(err => {
            console.warn('loadRemoteProgress error', err);
            return null;
        });
}

function saveRemoteProgress() {
    const data = new URLSearchParams();
    data.append('book', BOOK_ID);
    data.append('chapterIndex', currentChapterIndex);
    data.append('pageIndex', currentPageIndex);

    fetch('progress.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: data.toString()
    }).catch(err => {
        console.warn('saveRemoteProgress error', err);
    });
}

function saveProgress() {
    saveLocalProgress();
    saveRemoteProgress();
}

/* =============================
   阅读样式设置
   ============================= */
function initDefaultReadingSettingsIfNeeded() {
    // 如果本地已有设置，就不动
    try {
        const key = 'novel_reader_style_global';
        const raw = localStorage.getItem(key);
        if (raw) return;
    } catch (e) {
        return;
    }

    const w = Math.min(window.innerWidth || 0, 1400);
    if (w >= 1100) {
        readingSettings.fontSize = 18;
        readingSettings.lineHeight = 1.85;
        readingSettings.padding = 18;
    } else if (w >= 768) {
        readingSettings.fontSize = 18;
        readingSettings.lineHeight = 1.85;
        readingSettings.padding = 16;
    } else {
        readingSettings.fontSize = 17;
        readingSettings.lineHeight = 1.8;
        readingSettings.padding = 14;
    }
}

function loadReadingSettings() {
    try {
        const key = 'novel_reader_style_global';
        const raw = localStorage.getItem(key);
        if (!raw) return;
        const data = JSON.parse(raw);
        if (typeof data.fontSize === 'number') readingSettings.fontSize = data.fontSize;
        if (typeof data.lineHeight === 'number') readingSettings.lineHeight = data.lineHeight;
        if (typeof data.padding === 'number') readingSettings.padding = data.padding;
    } catch (e) {
        console.warn('loadReadingSettings error', e);
    }
}

function saveReadingSettings() {
    try {
        const key = 'novel_reader_style_global';
        localStorage.setItem(key, JSON.stringify(readingSettings));
    } catch (e) {
        console.warn('saveReadingSettings error', e);
    }
}

function applyReadingSettings() {
    rootEl.style.setProperty('--reader-font-size', readingSettings.fontSize + 'px');
    rootEl.style.setProperty('--reader-line-height', String(readingSettings.lineHeight));
    rootEl.style.setProperty('--reader-padding-v', readingSettings.padding + 'px');
    rootEl.style.setProperty('--reader-padding-h', readingSettings.padding + 'px');
}

function applyReadingSettingsAndRepaginate() {
    applyReadingSettings();
    saveReadingSettings();

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            rePaginateCurrentChapter(true);
        });
    });
}

/* =============================
   分页
   ============================= */
function paginateChapter(text) {
    if (!contentEl || !readerMain) {
        return [escapeHTML(text).replace(/\n/g, '<br>')];
    }

    const width = contentEl.clientWidth;
    // 给一点点安全余量，减少“最后一行被裁切”的概率
    const height = Math.max(0, contentEl.clientHeight - 2);

    if (width <= 0 || height <= 80) {
        return [escapeHTML(text).replace(/\n/g, '<br>')];
    }

    const temp = document.createElement('div');
    temp.className = 'chapter-content';
    temp.style.position = 'absolute';
    temp.style.visibility = 'hidden';
    temp.style.left = '-9999px';
    temp.style.top = '0';
    temp.style.width = width + 'px';
    temp.style.height = height + 'px';
    temp.style.overflow = 'hidden';

    document.body.appendChild(temp);

    const pages = [];
    let pageWrapper = document.createElement('div');
    pageWrapper.className = 'page-inner';
    temp.appendChild(pageWrapper);

    const paragraphs = String(text || '').split(/\n+/);

    for (let i = 0; i < paragraphs.length; i++) {
        const paraText = paragraphs[i];
        const p = document.createElement('p');
        p.textContent = paraText;
        pageWrapper.appendChild(p);

        if (temp.scrollHeight > temp.clientHeight + 1) {
            pageWrapper.removeChild(p);
            pages.push(pageWrapper.innerHTML);

            temp.removeChild(pageWrapper);
            pageWrapper = document.createElement('div');
            pageWrapper.className = 'page-inner';
            temp.appendChild(pageWrapper);

            pageWrapper.appendChild(p);
        }
    }

    if (pageWrapper.childNodes.length > 0) {
        pages.push(pageWrapper.innerHTML);
    }

    document.body.removeChild(temp);

    if (!pages.length) {
        pages.push('<div class="page-inner"></div>');
    }

    return pages;
}

function rePaginateCurrentChapter(keepPosition) {
    const cache = chapterCache[currentChapterIndex];
    if (!cache || !cache.text) return;

    const oldPages = currentPages;
    const oldPageCount = oldPages ? oldPages.length : 1;
    const ratio = (keepPosition && oldPageCount > 1)
        ? currentPageIndex / oldPageCount
        : 0;

    const newPages = paginateChapter(cache.text);
    cache.pages = newPages;
    currentPages = newPages;

    if (keepPosition && newPages.length > 1) {
        let newIndex = Math.round(ratio * newPages.length);
        if (newIndex >= newPages.length) newIndex = newPages.length - 1;
        if (newIndex < 0) newIndex = 0;
        currentPageIndex = newIndex;
    } else {
        currentPageIndex = 0;
    }

    renderPage(0);
}

/* =============================
   渲染
   ============================= */
function updateProgressUI() {
    const pageCount = currentPages.length || 0;
    const text = `第 ${currentChapterIndex + 1}/${totalChapters || 0} 章 · 第 ${currentPageIndex + 1}/${pageCount || 0} 页`;
    if (progressBadgeEl) progressBadgeEl.textContent = text;
    if (footerProgressEl) footerProgressEl.textContent = text;
}

/**
 * 渲染当前章节当前页
 * direction: 1=下一页/章（从右滑入），-1=上一页/章（从左滑入），0=无动画
 */
function renderPage(direction) {
    hideSelectionActions();

    if (!currentPages.length) {
        if (titleEl) titleEl.textContent = '无内容';
        if (contentEl) contentEl.innerHTML = '';
        updateProgressUI();
        return;
    }

    const cache = chapterCache[currentChapterIndex];
    const title = cache ? cache.title : ('第 ' + (currentChapterIndex + 1) + ' 章');

    // 只在第一页显示标题
    if (titleEl) {
        if (currentPageIndex === 0) {
            titleEl.style.display = 'block';
            titleEl.textContent = title;
        } else {
            titleEl.style.display = 'none';
        }
    }

    if (contentEl) {
        contentEl.innerHTML = '<div class="page-inner">' + currentPages[currentPageIndex] + '</div>';
    }

    updateProgressUI();
    saveProgress();
    updateChapterListSelection();

    // 动画
    if (!innerEl || !direction) return;

    const offset = direction > 0 ? 40 : -40;
    innerEl.style.transition = 'none';
    innerEl.style.transform = `translateX(${offset}px)`;
    innerEl.style.opacity = '0';

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            innerEl.style.transition = 'transform 0.25s ease, opacity 0.25s ease';
            innerEl.style.transform = 'translateX(0)';
            innerEl.style.opacity = '1';
        });
    });
}

/* =============================
   加载章节
   ============================= */
function loadChapterFromServer(chapterIndex, pageToOpen = 0, direction = 0) {
    if (chapterIndex < 0) chapterIndex = 0;
    if (totalChapters && chapterIndex >= totalChapters) {
        chapterIndex = totalChapters - 1;
    }

    // 缓存命中
    if (chapterCache[chapterIndex]) {
        const cache = chapterCache[chapterIndex];
        totalChapters = cache.total || totalChapters;
        currentChapterIndex = chapterIndex;
        currentPages = cache.pages || [];
        if (pageToOpen >= currentPages.length) pageToOpen = currentPages.length - 1;
        currentPageIndex = Math.max(0, pageToOpen);
        renderPage(direction);
        return;
    }

    // 显示加载中
    if (titleEl) titleEl.textContent = '加载中...';
    if (contentEl) contentEl.innerHTML = '';
    if (progressBadgeEl) progressBadgeEl.textContent = '';
    if (footerProgressEl) footerProgressEl.textContent = '';

    fetch(`chapter.php?book=${encodeURIComponent(BOOK_ID)}&index=${chapterIndex}&_t=${Date.now()}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                if (titleEl) titleEl.textContent = '加载失败';
                if (contentEl) contentEl.textContent = '无法加载章节：' + data.error;
                return;
            }

            totalChapters = data.total || 0;
            currentChapterIndex = (typeof data.index === 'number') ? data.index : chapterIndex;

            const rawText = data.content || '';
            const pages = paginateChapter(rawText);

            chapterCache[currentChapterIndex] = {
                title: data.title || ('第 ' + (currentChapterIndex + 1) + ' 章'),
                text: rawText,
                pages: pages,
                total: totalChapters
            };

            currentPages = pages;
            if (pageToOpen >= currentPages.length) pageToOpen = currentPages.length - 1;
            currentPageIndex = Math.max(0, pageToOpen);

            renderPage(direction);
        })
        .catch(err => {
            console.error(err);
            if (titleEl) titleEl.textContent = '加载失败';
            if (contentEl) contentEl.textContent = '网络或服务器错误，请稍后重试。';
        });
}

/* =============================
   章节列表
   ============================= */
function setChapterListEmpty(message, show) {
    if (!chapterListEmptyEl) return;
    if (typeof message === 'string') {
        chapterListEmptyEl.textContent = message;
    }
    chapterListEmptyEl.classList.toggle('show', !!show);
}

function formatChapterLabel(item) {
    const idx = item.index + 1;
    const title = item.title || ('第 ' + idx + ' 章');
    return { idx, title };
}

function updateChapterListSelection() {
    if (!chapterListEl) return;
    const items = chapterListEl.querySelectorAll('.chapter-item');
    items.forEach(btn => {
        const idx = parseInt(btn.dataset.index || '0', 10);
        btn.classList.toggle('active', idx === currentChapterIndex);
    });
    if (chapterRangeListEl) {
        const rangeItems = chapterRangeListEl.querySelectorAll('.chapter-range-item');
        const currentRange = getRangeForChapterIndex(currentChapterIndex);
        rangeItems.forEach(btn => {
            const idx = parseInt(btn.dataset.range || '0', 10);
            btn.classList.toggle('active', idx === currentRange);
        });
    }
}

function setChapterRangeMode(showRanges) {
    if (chapterRangeListEl) {
        chapterRangeListEl.style.display = showRanges ? 'flex' : 'none';
    }
    if (chapterListEl) {
        chapterListEl.style.display = showRanges ? 'none' : 'flex';
    }
    if (chapterRangeBackEl) {
        chapterRangeBackEl.style.display = showRanges ? 'none' : 'inline-flex';
    }
}

function getRangeCount() {
    if (!chapterList.length) return 0;
    return Math.ceil(chapterList.length / CHAPTERS_PER_RANGE);
}

function getRangeForChapterIndex(index) {
    if (index < 0) return 0;
    return Math.floor(index / CHAPTERS_PER_RANGE);
}

function getRangeLabel(rangeIndex) {
    const start = rangeIndex * CHAPTERS_PER_RANGE + 1;
    const end = Math.min(chapterList.length, (rangeIndex + 1) * CHAPTERS_PER_RANGE);
    return `第 ${start}-${end} 章`;
}

function renderChapterRanges() {
    if (!chapterRangeListEl) return;
    chapterRangeListEl.innerHTML = '';

    if (!chapterList.length) {
        const emptyText = chapterListLoading ? '正在加载章节列表…' : '暂无章节';
        setChapterListEmpty(emptyText, true);
        return;
    }

    const rangeCount = getRangeCount();
    if (!rangeCount) {
        setChapterListEmpty('暂无章节', true);
        return;
    }

    setChapterListEmpty('', false);
    for (let i = 0; i < rangeCount; i++) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'chapter-range-item';
        btn.dataset.range = String(i);
        btn.textContent = getRangeLabel(i);
        if (i === getRangeForChapterIndex(currentChapterIndex)) {
            btn.classList.add('active');
        }
        btn.addEventListener('click', () => {
            openChapterRange(i);
        });
        chapterRangeListEl.appendChild(btn);
    }
    setChapterRangeMode(true);
    if (chapterRangeTitleEl) {
        chapterRangeTitleEl.textContent = '请选择章节范围';
    }
}

function openChapterRange(rangeIndex) {
    currentRangeIndex = rangeIndex;
    renderChapterList();
    setChapterRangeMode(false);
    if (chapterRangeTitleEl) {
        chapterRangeTitleEl.textContent = getRangeLabel(rangeIndex);
    }
}

function renderChapterList() {
    if (!chapterListEl) return;
    chapterListEl.innerHTML = '';

    if (!chapterList.length) {
        setChapterListEmpty('暂无章节', true);
        return;
    }

    const rangeIndex = (typeof currentRangeIndex === 'number') ? currentRangeIndex : 0;
    const start = rangeIndex * CHAPTERS_PER_RANGE;
    const end = Math.min(chapterList.length, (rangeIndex + 1) * CHAPTERS_PER_RANGE);
    const filtered = chapterList.slice(start, end);

    if (!filtered.length) {
        setChapterListEmpty('该范围暂无章节', true);
        return;
    }

    setChapterListEmpty('', false);
    filtered.forEach(item => {
        const label = formatChapterLabel(item);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'chapter-item';
        btn.dataset.index = String(item.index);
        btn.innerHTML = `<span class="chapter-item-index">#${label.idx}</span><span class="chapter-item-title">${escapeHTML(label.title)}</span>`;
        btn.addEventListener('click', () => {
            loadChapterFromServer(item.index, 0, 0);
            closeModal(settingsModalEl);
        });
        if (item.index === currentChapterIndex) {
            btn.classList.add('active');
        }
        chapterListEl.appendChild(btn);
    });
}

function loadChapterList() {
    if (!chapterListEl) return Promise.resolve();
    chapterListLoading = true;
    setChapterListEmpty('正在加载章节列表…', true);

    return fetch(`chapter_list.php?book=${encodeURIComponent(BOOK_ID)}&_t=${Date.now()}`)
        .then(r => r.ok ? r.json() : null)
        .then(data => {
            if (!data || !Array.isArray(data.chapters)) {
                throw new Error('invalid_list');
            }
            chapterList = data.chapters.map(ch => ({
                index: typeof ch.index === 'number' ? ch.index : 0,
                title: ch.title || ''
            }));
            if (data.total) totalChapters = data.total;
            chapterListLoading = false;
            renderChapterRanges();
        })
        .catch(err => {
            console.warn('loadChapterList error', err);
            chapterListLoading = false;
            setChapterListEmpty('章节列表加载失败，请稍后再试。', true);
        });
}

/* =============================
   翻页 / 跳转
   ============================= */
function prevPageOrChapter() {
    if (!currentPages.length) return;

    if (currentPageIndex > 0) {
        currentPageIndex--;
        renderPage(-1);
    } else if (currentChapterIndex > 0) {
        const targetChapter = currentChapterIndex - 1;
        const cached = chapterCache[targetChapter];
        if (cached) {
            loadChapterFromServer(targetChapter, cached.pages.length - 1, -1);
        } else {
            loadChapterFromServer(targetChapter, 999999, -1);
        }
    }
}

function nextPageOrChapter() {
    if (!currentPages.length) return;

    if (currentPageIndex < currentPages.length - 1) {
        currentPageIndex++;
        renderPage(1);
    } else if (currentChapterIndex < totalChapters - 1) {
        const targetChapter = currentChapterIndex + 1;
        loadChapterFromServer(targetChapter, 0, 1);
    }
}

/* =============================
   UI 显示/隐藏（沉浸模式）
   ============================= */
function updateImmersiveButton() {
    if (!btnImmersive) return;
    btnImmersive.textContent = uiVisible ? '沉浸' : '显示';
}

function setUIVisible(visible, repaginate = true) {
    uiVisible = visible;
    if (!readerShellEl) return;

    if (visible) {
        readerShellEl.classList.remove('chrome-hidden');
    } else {
        readerShellEl.classList.add('chrome-hidden');
    }

    updateImmersiveButton();

    // ⚠️ 现在隐藏上下栏会释放空间，因此需要重新分页才能真正“顶满屏”
    if (repaginate) {
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                rePaginateCurrentChapter(true);
            });
        });
    }
}

/* 点击阅读区：左 1/3 上一页，右 1/3 下一页，中间 1/3 显示/隐藏 UI */
function setupTapFlip() {
    if (!readerMain) return;
    readerMain.addEventListener('click', function (e) {
        if (isAnyModalOpen()) return;

        const sel = window.getSelection && window.getSelection().toString();
        if (sel && sel.trim().length > 0) return;

        const rect = readerMain.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const w = rect.width;

        if (x < w / 3) {
            prevPageOrChapter();
        } else if (x > (2 * w / 3)) {
            nextPageOrChapter();
        } else {
            setUIVisible(!uiVisible);
        }
    });
}

/* 触摸滑动翻页（手机/iPad） */
function setupSwipeFlip() {
    if (!readerMain) return;

    let sx = 0, sy = 0, st = 0;

    readerMain.addEventListener('touchstart', (e) => {
        if (!e.touches || e.touches.length !== 1) return;
        if (isAnyModalOpen()) return;

        const t = e.touches[0];
        sx = t.clientX;
        sy = t.clientY;
        st = Date.now();
    }, { passive: true });

    readerMain.addEventListener('touchend', (e) => {
        if (isAnyModalOpen()) return;

        const sel = window.getSelection && window.getSelection().toString();
        if (sel && sel.trim().length > 0) return;

        const dt = Date.now() - st;
        if (dt > 800) return;

        const t = (e.changedTouches && e.changedTouches[0]) ? e.changedTouches[0] : null;
        if (!t) return;

        const dx = t.clientX - sx;
        const dy = t.clientY - sy;

        if (Math.abs(dx) < 55) return;
        if (Math.abs(dx) < Math.abs(dy) * 1.4) return;

        if (dx < 0) nextPageOrChapter();
        else prevPageOrChapter();
    }, { passive: true });
}

/* 键盘翻页（电脑） */
function setupKeyboardFlip() {
    document.addEventListener('keydown', (e) => {
        if (isAnyModalOpen()) return;

        const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea') return;

        if (e.key === 'ArrowLeft') {
            prevPageOrChapter();
        } else if (e.key === 'ArrowRight') {
            nextPageOrChapter();
        }
    });
}

/* =============================
   选中文字：浮条 + 复制 + 问AI
   ============================= */
let selectionRaf = 0;
let activeSelectionText = '';

// ✅ Safari 兼容：缓存最后一次有效选区（点按钮时选区可能瞬间被清空）
let lastSelectionText = '';
let lastSelectionAt = 0;

function getLiveSelectionText() {
    try {
        const sel = window.getSelection && window.getSelection();
        const t = sel ? String(sel.toString() || '').trim() : '';
        return t;
    } catch (e) {
        return '';
    }
}

function getSelectionTextSafe() {
    // 优先读实时选区；读不到则用缓存（Safari 点按钮时常清空选区）
    const live = getLiveSelectionText();
    if (live) {
        lastSelectionText = live;
        lastSelectionAt = Date.now();
        return live;
    }
    return String(lastSelectionText || '').trim();
}

function clearSelection() {
    try {
        const sel = window.getSelection && window.getSelection();
        if (sel && sel.removeAllRanges) sel.removeAllRanges();
    } catch (e) {}
}

function hideSelectionActions() {
    if (!selectionActionsEl) return;
    selectionActionsEl.classList.remove('show');
    selectionActionsEl.setAttribute('aria-hidden', 'true');
}

function isSelectionInContent() {
    const sel = window.getSelection && window.getSelection();
    if (!sel || sel.rangeCount === 0) return false;
    const range = sel.getRangeAt(0);
    const node = range.commonAncestorContainer;
    const el = (node.nodeType === 1) ? node : node.parentElement;
    if (!el) return false;
    return !!(contentEl && contentEl.contains(el));
}

function updateSelectionActions() {
    if (!selectionActionsEl) return;
    if (isAnyModalOpen()) {
        hideSelectionActions();
        return;
    }

    const sel = window.getSelection && window.getSelection();
    const text = sel ? String(sel.toString() || '').trim() : '';

    if (!text || text.length === 0) {
        hideSelectionActions();
        return;
    }

    if (!isSelectionInContent()) {
        hideSelectionActions();
        return;
    }

    // ✅ 有效选区时立刻缓存（Safari 点击按钮会清空 selection）
    lastSelectionText = text;
    lastSelectionAt = Date.now();

    let rect = null;
    try {
        const range = sel.getRangeAt(0);
        rect = range.getBoundingClientRect();
        if ((!rect || (rect.width === 0 && rect.height === 0)) && range.getClientRects) {
            const rs = range.getClientRects();
            if (rs && rs.length) rect = rs[0];
        }
    } catch (e) {
        rect = null;
    }

    if (!rect) {
        hideSelectionActions();
        return;
    }

    const cx = rect.left + rect.width / 2;
    let top = rect.top - 44;
    if (top < 10) top = rect.bottom + 10;

    const x = clamp(cx, 70, window.innerWidth - 70);

    selectionActionsEl.style.left = x + 'px';
    selectionActionsEl.style.top = top + 'px';
    selectionActionsEl.classList.add('show');
    selectionActionsEl.setAttribute('aria-hidden', 'false');
}

document.addEventListener('selectionchange', () => {
    if (selectionRaf) cancelAnimationFrame(selectionRaf);
    selectionRaf = requestAnimationFrame(updateSelectionActions);
});

async function copyText(text) {
    const t = String(text || '').trim();
    if (!t) return;

    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(t);
            showToast('已复制 ✅');
            return;
        }
    } catch (e) {}

    // fallback
    try {
        const ta = document.createElement('textarea');
        ta.value = t;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        ta.style.top = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        showToast(ok ? '已复制 ✅' : '复制失败');
    } catch (e) {
        showToast('复制失败');
    }
}

/* =============================
   AI 对话
   ============================= */
let aiHistory = []; // [{user, assistant}]，只保留 3 轮
let aiWaiting = false;

function escapeForText(str) {
    return String(str || '').replace(/\r\n/g, '\n');
}

function appendAiMessage(role, text) {
    if (!aiChatLogEl) return;

    const msg = document.createElement('div');
    msg.className = 'ai-msg ' + (role === 'user' ? 'user' : 'assistant');

    const bubble = document.createElement('div');
    bubble.className = 'ai-bubble';
    bubble.textContent = text;

    msg.appendChild(bubble);
    aiChatLogEl.appendChild(msg);

    aiChatLogEl.scrollTop = aiChatLogEl.scrollHeight;
}

function renderAiHistory() {
    if (!aiChatLogEl) return;
    aiChatLogEl.innerHTML = '';
    aiHistory.forEach(turn => {
        appendAiMessage('user', turn.user);
        appendAiMessage('assistant', turn.assistant);
    });
}

function setAiError(msg) {
    if (!aiErrorEl) return;
    if (!msg) {
        aiErrorEl.style.display = 'none';
        aiErrorEl.textContent = '';
        return;
    }
    aiErrorEl.style.display = 'block';
    aiErrorEl.textContent = msg;
}

function openAiModalWithSelection(selectionText) {
    activeSelectionText = String(selectionText || '').trim();
    if (aiContextPreviewEl) aiContextPreviewEl.textContent = activeSelectionText || '（未选中内容）';

    setAiError('');
    renderAiHistory();
    openModal(aiModalEl);

    if (aiUserInputEl) {
        setTimeout(() => {
            aiUserInputEl.focus();
        }, 0);
    }
}

async function sendAiQuestion(prompt) {
    const q = String(prompt || '').trim();
    if (!q) return;
    if (aiWaiting) return;

    setAiError('');
    aiWaiting = true;
    if (aiSendBtnEl) aiSendBtnEl.disabled = true;

    appendAiMessage('user', q);
    appendAiMessage('assistant', '…');

    // 只保留 3 轮作为上下文
    const historyToSend = aiHistory.slice(-3);

    try {
        const resp = await fetch('ai.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json;charset=UTF-8' },
            body: JSON.stringify({
                book: BOOK_ID,
                prompt: q,
                selected: activeSelectionText,
                history: historyToSend
            })
        });

        const data = await resp.json().catch(() => null);
        if (!resp.ok || !data) {
            throw new Error('网络或服务器错误');
        }

        if (!data.ok) {
            throw new Error(data.error || '请求失败');
        }

        const answer = String(data.answer || '').trim() || '（未返回内容）';

        // 替换最后一个“…”
        if (aiChatLogEl) {
            const nodes = aiChatLogEl.querySelectorAll('.ai-msg.assistant .ai-bubble');
            const last = nodes[nodes.length - 1];
            if (last && last.textContent === '…') {
                last.textContent = answer;
            } else {
                appendAiMessage('assistant', answer);
            }
        }

        aiHistory.push({ user: q, assistant: answer });
        aiHistory = aiHistory.slice(-3);

        if (aiUserInputEl) aiUserInputEl.value = '';
    } catch (e) {
        console.warn(e);
        setAiError('❌ ' + (e && e.message ? e.message : '请求失败'));
        showToast('AI 请求失败');

        // 把“…”改成失败提示
        if (aiChatLogEl) {
            const nodes = aiChatLogEl.querySelectorAll('.ai-msg.assistant .ai-bubble');
            const last = nodes[nodes.length - 1];
            if (last && last.textContent === '…') {
                last.textContent = '（请求失败）';
            }
        }
    } finally {
        aiWaiting = false;
        if (aiSendBtnEl) aiSendBtnEl.disabled = false;
    }
}

/* =============================
   初始化
   ============================= */
function initReader() {
    loadTheme();

    initDefaultReadingSettingsIfNeeded();
    loadReadingSettings();
    applyReadingSettings();

    loadChapterList();

    // 默认先隐藏 UI，更沉浸
    setUIVisible(false, false);

    loadRemoteProgress().then(progress => {
        if (progress && typeof progress.chapterIndex === 'number') {
            currentChapterIndex = progress.chapterIndex;
            currentPageIndex = progress.pageIndex || 0;
        } else {
            const local = loadLocalProgress();
            if (local) {
                currentChapterIndex = local.chapterIndex;
                currentPageIndex = local.pageIndex;
            } else {
                currentChapterIndex = 0;
                currentPageIndex = 0;
            }
        }
        loadChapterFromServer(currentChapterIndex, currentPageIndex, 0);
    });
}

// 窗口尺寸变化：重新分页当前章节（保持大致位置）
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
        rePaginateCurrentChapter(true);
    }, 250);
});

/* ====== 样式调整按钮对应的函数 ====== */
function smallerFont() {
    readingSettings.fontSize = clamp(readingSettings.fontSize - 1, 12, 30);
    applyReadingSettingsAndRepaginate();
}

function largerFont() {
    readingSettings.fontSize = clamp(readingSettings.fontSize + 1, 12, 30);
    applyReadingSettingsAndRepaginate();
}

function tighterLine() {
    readingSettings.lineHeight = clamp(Math.round((readingSettings.lineHeight - 0.1) * 10) / 10, 1.2, 2.6);
    applyReadingSettingsAndRepaginate();
}

function looserLine() {
    readingSettings.lineHeight = clamp(Math.round((readingSettings.lineHeight + 0.1) * 10) / 10, 1.2, 2.6);
    applyReadingSettingsAndRepaginate();
}

function smallerMargin() {
    readingSettings.padding = clamp(readingSettings.padding - 2, 4, 48);
    applyReadingSettingsAndRepaginate();
}

function largerMargin() {
    readingSettings.padding = clamp(readingSettings.padding + 2, 4, 48);
    applyReadingSettingsAndRepaginate();
}

/* 暴露给 HTML 按钮 */
window.prevPageOrChapter = prevPageOrChapter;
window.nextPageOrChapter = nextPageOrChapter;

window.smallerFont = smallerFont;
window.largerFont = largerFont;
window.tighterLine = tighterLine;
window.looserLine = looserLine;
window.smallerMargin = smallerMargin;
window.largerMargin = largerMargin;

// ====== 绑定按钮 ======
if (btnSettingsOpen) {
    btnSettingsOpen.addEventListener('click', (e) => {
        e.preventDefault();
        renderChapterRanges();
        updateChapterListSelection();
        openModal(settingsModalEl);
    });
}

if (chapterRangeBackEl) {
    chapterRangeBackEl.addEventListener('click', (e) => {
        e.preventDefault();
        currentRangeIndex = null;
        renderChapterRanges();
        updateChapterListSelection();
    });
}

if (btnThemeToggle) {
    btnThemeToggle.addEventListener('click', (e) => {
        e.preventDefault();
        cycleTheme();
        showToast(themeLabel(currentTheme));
    });
}

if (btnImmersive) {
    btnImmersive.addEventListener('click', (e) => {
        e.preventDefault();
        setUIVisible(!uiVisible);
    });
}

// ✅ Safari：用 pointerdown/touchstart 抢先拿到选区，避免 click 时选区被系统清空
function onCopySelection(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const t = getSelectionTextSafe();
    if (!t) return;
    copyText(t);
    hideSelectionActions();
}

function onAskAiSelection(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const t = getSelectionTextSafe();
    if (!t) return;
    openAiModalWithSelection(t);
    hideSelectionActions();
    // 不强制清空选择，让用户还能继续复制/查看；但关闭浮条避免挡住
}

if (selectionCopyEl) {
    selectionCopyEl.addEventListener('pointerdown', onCopySelection, { passive: false });
    selectionCopyEl.addEventListener('touchstart', onCopySelection, { passive: false }); // iOS 兜底
    selectionCopyEl.addEventListener('click', onCopySelection);
}

if (selectionAskAiEl) {
    selectionAskAiEl.addEventListener('pointerdown', onAskAiSelection, { passive: false });
    selectionAskAiEl.addEventListener('touchstart', onAskAiSelection, { passive: false }); // iOS 兜底
    selectionAskAiEl.addEventListener('click', onAskAiSelection);
}

// AI 发送
if (aiSendBtnEl) {
    aiSendBtnEl.addEventListener('click', (e) => {
        e.preventDefault();
        sendAiQuestion(aiUserInputEl ? aiUserInputEl.value : '');
    });
}

if (aiUserInputEl) {
    aiUserInputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendAiQuestion(aiUserInputEl.value);
        }
    });
}

if (aiClearBtnEl) {
    aiClearBtnEl.addEventListener('click', (e) => {
        e.preventDefault();
        aiHistory = [];
        renderAiHistory();
        setAiError('');
        showToast('已清空');
    });
}

// AI 模板按钮
document.querySelectorAll('[data-ai-template]').forEach(btn => {
    btn.addEventListener('click', (e) => {
        const t = e.currentTarget.getAttribute('data-ai-template') || '';
        if (aiUserInputEl) {
            aiUserInputEl.value = t;
            aiUserInputEl.focus();
        }
    });
});

// 防止在弹窗打开时选中文字导致浮条乱跑
document.addEventListener('pointerdown', (e) => {
    const inModal = e.target.closest('.modal');
    if (inModal) hideSelectionActions();
});

setupTapFlip();
setupSwipeFlip();
setupKeyboardFlip();
initReader();
