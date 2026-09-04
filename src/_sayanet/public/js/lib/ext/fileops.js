const {dom, each} = require('../util');
const server = require('../server');
const event = require('../core/event');
const allsettings = require('../core/settings');
const location = require('../core/location');

const settings = Object.assign({
    enabled: false
}, allsettings.fileops);

let $toolbar;
let $uploadInput;

const canWrite = () => {
    try {
        const cfg = require('../config');
        return cfg.setup && (cfg.setup.CAN_WRITE || cfg.setup.AS_ADMIN);
    } catch (e) { return false; }
};

const refresh = () => location.refresh();

const uploadFiles = files => {
    if (!files || !files.length) return;
    const loc = location.getItem();
    if (!loc) return;
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('href', loc.absHref);
    each(files, f => fd.append('files[]', f));
    // use fetch directly for multipart
    fetch('?', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(res => {
            if (res && res.ok === false) {
                event.pub('notification', `Upload failed: ${res.txt || 'error'}`);
            }
            refresh();
        })
        .catch(() => event.pub('notification', 'Upload failed'));
};

const onUploadClick = () => {
    if (!canWrite()) return;
    $uploadInput[0].click();
};

const onUploadChange = ev => {
    const files = ev.target.files;
    uploadFiles(files);
    ev.target.value = '';
};

const onMkdir = () => {
    if (!canWrite()) return;
    const name = global.window.prompt('New folder name:');
    if (!name) return;
    const loc = location.getItem();
    server.request({action: 'mkdir', href: loc.absHref, name}).then(res => {
        if (res && res.ok === false) event.pub('notification', 'Mkdir failed');
        refresh();
    });
};

const onDelete = () => {
    if (!canWrite()) return;
    const sel = require('./select');
    // try to get selected via event, fallback to prompt
    const loc = location.getItem();
    // ask for confirmation with list
    const hrefs = [];
    dom('#items .item.selected').each(el => {
        if (el._item) hrefs.push(el._item.absHref);
    });
    if (!hrefs.length) {
        if (!global.window.confirm(`Delete ${loc.label}?`)) return;
        hrefs.push(loc.absHref);
    } else if (!global.window.confirm(`Delete ${hrefs.length} items?`)) return;
    server.request({action: 'delete', hrefs}).then(() => refresh());
};

const onRename = () => {
    if (!canWrite()) return;
    const loc = location.getItem();
    const name = global.window.prompt('New name:', loc.label);
    if (!name || name === loc.label) return;
    server.request({action: 'rename', href: loc.absHref, name}).then(() => refresh());
};

const onMove = () => {
    if (!canWrite()) return;
    const dest = global.window.prompt('Move to (href, e.g. /folder/):');
    if (!dest) return;
    const hrefs = [];
    dom('#items .item.selected').each(el => {
        if (el._item) hrefs.push(el._item.absHref);
    });
    const loc = location.getItem();
    if (!hrefs.length) hrefs.push(loc.absHref);
    server.request({action: 'move', hrefs, destHref: dest}).then(() => refresh());
};

const init = () => {
    if (!settings.enabled) return;
    // check if can_write, but still init toolbar for all, hide if not
    const loc = location.getItem();
    if (!loc) return;

    $toolbar = dom('#toolbar');
    if (!$toolbar.length) return;

    // create hidden file input
    $uploadInput = dom('<input type="file" multiple style="display:none"/>').appTo('body');
    $uploadInput.on('change', onUploadChange);

    // toolbar buttons
    const addBtn = (id, label, handler) => {
        const $b = dom(`<div id="${id}" class="tool"><img src="${require('../core/resource').image(id)}" alt="${label}"/><span>${label}</span></div>`);
        $b.on('click', handler).appTo($toolbar);
        return $b;
    };

    // only show if canWrite
    if (canWrite()) {
        addBtn('upload', 'Upload', onUploadClick);
        addBtn('mkdir', 'New folder', onMkdir);
        addBtn('delete', 'Delete', onDelete);
        addBtn('rename', 'Rename', onRename);
        addBtn('move', 'Move', onMove);

        // drag-drop
        const $content = dom('#content');
        if ($content.length) {
            $content.on('dragover', ev => {
                ev.preventDefault();
                $content.addCls('dragover');
            });
            $content.on('dragleave', () => $content.rmCls('dragover'));
            $content.on('drop', ev => {
                ev.preventDefault();
                $content.rmCls('dragover');
                const dt = ev.dataTransfer;
                if (dt && dt.files) uploadFiles(dt.files);
            });
        }
    }

    event.sub('location.changed', () => {
        // re-evaluate canWrite after navigation (role may change)
        if (!canWrite()) {
            dom('#upload,#mkdir,#delete,#rename,#move').hide();
        } else {
            dom('#upload,#mkdir,#delete,#rename,#move').show();
        }
    });
};

init();
