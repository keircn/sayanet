const {dom} = require('../util');
const store = require('../core/store');

const KEY = 'theme';
const apply = theme => {
    const root = dom('html')[0] || global.window.document.documentElement;
    if (!root) return;
    if (theme === 'light' || theme === 'dark') {
        root.setAttribute('data-theme', theme);
        store.put(KEY, theme);
    } else {
        root.removeAttribute('data-theme');
        store.put(KEY, 'system');
    }
};

const current = () => store.get(KEY) || 'system';

const init = () => {
    const saved = current();
    apply(saved);

    // watch system changes if in system mode
    if (global.window.matchMedia) {
        const mql = global.window.matchMedia('(prefers-color-scheme: light)');
        const onChange = () => {
            if (current() === 'system') apply('system');
        };
        if (mql.addEventListener) mql.addEventListener('change', onChange);
        else if (mql.addListener) mql.addListener(onChange);
    }

    // add toggle to toolbar if enabled
    const $toolbar = dom('#toolbar');
    if ($toolbar.length) {
        const $btn = dom('<div id="theme-toggle" class="tool" title="Toggle theme"><span>◐</span></div>');
        $btn.on('click', () => {
            const cur = current();
            const next = cur === 'dark' ? 'light' : cur === 'light' ? 'system' : 'dark';
            apply(next);
            // also toggle watermark on light for less distraction?
        }).appTo($toolbar);
    }

    // also allow double-click watermark to hide
    const rootEl = dom('#root');
    if (rootEl.length) {
        dom('#root').on('dblclick', () => {
            const cur = rootEl[0].getAttribute('data-watermark');
            if (cur === 'hidden') rootEl[0].removeAttribute('data-watermark');
            else rootEl[0].setAttribute('data-watermark', 'hidden');
        });
    }
};

init();
