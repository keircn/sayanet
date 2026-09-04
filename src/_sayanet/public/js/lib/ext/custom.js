const {each, dom} = require('../util');

let parseMdCache = null;
const getParseMd = async () => {
    if (parseMdCache) return parseMdCache;
    try {
        const mod = await import('marked');
        const m = mod.marked || mod.default || mod;
        parseMdCache = m.parse || m.marked || m;
    } catch (e) {
        // fallback to require for legacy build
        try {
            const marked = require('marked');
            parseMdCache = marked.parse || marked.marked || marked;
        } catch (err) {
            parseMdCache = x => x;
        }
    }
    return parseMdCache;
};
const server = require('../server');
const event = require('../core/event');
const allsettings = require('../core/settings');


const settings = Object.assign({
    enabled: false
}, allsettings.custom);

const update = async (data, key) => {
    const $el = dom(`#content-${key}`);

    if (data && data[key].content) {
        let content = data[key].content;
        if (data[key].type === 'md') {
            const parseMd = await getParseMd();
            content = parseMd(content);
        }
        $el.html(content).show();
    } else {
        $el.hide();
    }
};

const onLocationChanged = item => {
    server.request({action: 'get', custom: item.absHref}).then(response => {
        const data = response && response.custom;
        each(['header', 'footer'], key => update(data, key));
    });
};

const init = () => {
    if (!settings.enabled) {
        return;
    }

    dom('<div id="content-header"></div>').hide().preTo('#content');
    dom('<div id="content-footer"></div>').hide().appTo('#content');

    event.sub('location.changed', onLocationChanged);
};


init();
