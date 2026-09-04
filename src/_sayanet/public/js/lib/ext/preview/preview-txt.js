const {keys, dom} = require('../../util');

let parseMdCache = null;
let lolightCache = null;
const getParseMd = async () => {
    if (parseMdCache) return parseMdCache;
    try {
        const mod = await import('marked');
        const m = mod.marked || mod.default || mod;
        parseMdCache = m.parse || m.marked || m;
    } catch (e) {
        try {
            const marked = require('marked');
            parseMdCache = marked.parse || marked.marked || marked;
        } catch (err) {
            parseMdCache = x => x;
        }
    }
    return parseMdCache;
};
const getLolight = async () => {
    if (lolightCache) return lolightCache;
    try {
        const mod = await import('lolight');
        lolightCache = mod.default || mod;
    } catch (e) {
        try { lolightCache = require('lolight'); } catch (err) { lolightCache = {el: () => {}}; }
    }
    return lolightCache;
};
const allsettings = require('../../core/settings');
const preview = require('./preview');

const win = global.window;
const settings = Object.assign({
    enabled: false,
    styles: {}
}, allsettings['preview-txt']);
const preTpl = '<pre id="pv-content-txt"></pre>';
const divTpl = '<div id="pv-content-txt"></div>';

const updateGui = () => {
    const el = dom('#pv-content-txt')[0];
    if (!el) {
        return;
    }

    const container = dom('#pv-container')[0];
    el.style.height = container.offsetHeight + 'px';

    preview.setLabels([
        preview.item.label,
        preview.item.size + ' bytes'
    ]);
};

const requestTextContent = async href => {
    try {
        const res = await fetch(href);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return await res.text();
    } catch (err) {
        throw String(err);
    }
};

const load = item => {
    return requestTextContent(item.absHref)
        .catch(err => '[request failed] ' + err)
        .then(async content => {
            const style = settings.styles[item.type];

            if (style === 1) {
                return dom(preTpl).text(content);
            } else if (style === 2) {
                const parseMd = await getParseMd();
                return dom(divTpl).html(parseMd(content));
            } else if (style === 3) {
                const $code = dom('<code></code>').text(content);
                win.setTimeout(async () => {
                    const lolight = await getLolight();
                    lolight.el($code[0]);
                }, content.length < 20000 ? 0 : 500);
                return dom(preTpl).app($code);
            }

            return dom(divTpl).text(content);
        });
};

const init = () => {
    if (settings.enabled) {
        preview.register(keys(settings.styles), load, updateGui);
    }
};

init();
