(function (global) {
    'use strict';

    const HIRAGANA_RE = /[\u3040-\u309F]/;
    const KATAKANA_RE = /[\u30A0-\u30FF]/;
    const KANJI_RE = /[\u3400-\u4DBF\u4E00-\u9FFF]/;
    const LATIN_RE = /[A-Za-z]/;
    const NUMBER_RE = /[0-9]/;

    const PARTICLES = new Set([
        'は', 'が', 'を', 'に', 'へ', 'で', 'と', 'も', 'や', 'の', 'ね', 'よ',
        'ぞ', 'さ', 'か', 'し', 'て', 'な', 'だ', 'です', 'ます', 'ない', 'いる',
        'ある', 'から', 'まで', 'より', 'だけ', 'ほど', 'とか', 'など'
    ]);

    function charType(ch) {
        if (HIRAGANA_RE.test(ch)) return 'hiragana';
        if (KATAKANA_RE.test(ch)) return 'katakana';
        if (KANJI_RE.test(ch)) return 'kanji';
        if (LATIN_RE.test(ch)) return 'latin';
        if (NUMBER_RE.test(ch)) return 'number';
        return 'other';
    }

    function splitByScript(text) {
        const chunks = [];
        let buf = '';
        let prevType = '';

        for (let i = 0; i < text.length; i++) {
            const ch = text[i];
            const type = charType(ch);
            if (type === 'other') {
                if (buf) {
                    chunks.push(buf);
                    buf = '';
                }
                prevType = '';
                continue;
            }

            if (!buf) {
                buf = ch;
                prevType = type;
                continue;
            }

            const keepSame = (type === prevType) || (prevType === 'kanji' && type === 'hiragana');
            if (keepSame) {
                buf += ch;
            } else {
                chunks.push(buf);
                buf = ch;
            }
            prevType = type;
        }

        if (buf) chunks.push(buf);
        return chunks;
    }

    function splitParticles(token) {
        if (!token) return [];
        if (token.length <= 2) return [token];

        const result = [];
        let start = 0;
        for (let i = 0; i < token.length; i++) {
            const one = token.slice(i, i + 1);
            const two = token.slice(i, i + 2);
            const three = token.slice(i, i + 3);

            let matched = '';
            if (PARTICLES.has(three)) matched = three;
            else if (PARTICLES.has(two)) matched = two;
            else if (PARTICLES.has(one)) matched = one;

            if (matched) {
                if (i > start) {
                    result.push(token.slice(start, i));
                }
                result.push(matched);
                i += matched.length - 1;
                start = i + 1;
            }
        }

        if (start < token.length) {
            result.push(token.slice(start));
        }

        return result.filter(Boolean);
    }

    function TinySegmenter() {}

    TinySegmenter.prototype.segment = function (input) {
        const text = String(input || '');
        const chunks = splitByScript(text);
        const tokens = [];

        for (let i = 0; i < chunks.length; i++) {
            const chunk = chunks[i];
            const type = charType(chunk[0]);

            if (type === 'hiragana' || type === 'kanji') {
                const sliced = splitParticles(chunk);
                for (let j = 0; j < sliced.length; j++) {
                    tokens.push(sliced[j]);
                }
            } else {
                tokens.push(chunk);
            }
        }

        return tokens;
    };

    global.TinySegmenter = TinySegmenter;
})(typeof window !== 'undefined' ? window : this);
