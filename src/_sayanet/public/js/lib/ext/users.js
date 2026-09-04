const {dom} = require('../util');
const server = require('../server');
const event = require('../core/event');
const allsettings = require('../core/settings');
const config = require('../config');

const settings = Object.assign({
    enabled: true
}, allsettings.users);

let $panel;

const render = users => {
    if (!$panel) return;
    $panel.clr();
    dom('<h3>Users</h3>').appTo($panel);
    const $list = dom('<ul/>').appTo($panel);
    users.forEach(u => {
        const $li = dom(`<li>${u.username} (${u.role}) <button data-user="${u.username}" class="del">delete</button></li>`).appTo($list);
        $li.find('.del').on('click', () => {
            if (!global.window.confirm(`Delete user ${u.username}?`)) return;
            server.request({action: 'user_delete', user: u.username}).then(res => {
                if (res && res.users) render(res.users);
                else refresh();
            });
        });
    });
    const $form = dom('<div><input id="new-user" placeholder="username"/><input id="new-pass" type="password" placeholder="password"/><select id="new-role"><option>viewer</option><option>editor</option><option>admin</option></select><button id="add-user">Add</button></div>').appTo($panel);
    $form.find('#add-user').on('click', () => {
        const user = $form.find('#new-user').val();
        const pass = $form.find('#new-pass').val();
        const role = $form.find('#new-role').val();
        if (!user || !pass) return;
        server.request({action: 'user_create', user, pass, role}).then(res => {
            if (res && res.users) render(res.users);
            else refresh();
        });
    });
};

const refresh = () => {
    if (!config.setup.AS_ADMIN) return;
    server.request({action: 'user_list'}).then(res => {
        if (res && res.users) render(res.users);
    });
};

const init = () => {
    if (!settings.enabled) return;
    // only admin sees panel, and only on info page or main? Add to sidebar
    const $sidebar = dom('#sidebar');
    if (!$sidebar.length) return;
    $panel = dom('<div id="users-panel" class="block" style="display:none"/>').appTo($sidebar);
    event.sub('location.changed', () => {
        // show only if admin
        if (config.setup.AS_ADMIN) {
            $panel.show();
            refresh();
        } else {
            $panel.hide();
        }
    });
    // initial check
    if (config.setup.AS_ADMIN) {
        $panel.show();
        refresh();
    }
};

init();
