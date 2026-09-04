const {dom} = require('../util');
const server = require('../server');
const event = require('../core/event');
const config = require('../config');
const allsettings = require('../core/settings');

const settings = Object.assign({
    enabled: true
}, allsettings.auth);

const tplModal =
    `<div id="auth-modal" class="auth-modal hidden">
        <div class="auth-backdrop"></div>
        <div class="auth-dialog" role="dialog" aria-modal="true" aria-labelledby="auth-title">
            <h2 id="auth-title">Sign in</h2>
            <div class="auth-fields">
                <input id="auth-user" type="text" placeholder="username" autocomplete="username"/>
                <input id="auth-pass" type="password" placeholder="password" autocomplete="current-password"/>
            </div>
            <div id="auth-hint" class="hint"></div>
            <div class="auth-actions">
                <button id="auth-cancel" class="btn ghost">Cancel</button>
                <button id="auth-submit" class="btn primary">Login</button>
            </div>
        </div>
    </div>`;

let $btn;
let $modal;

const isAdmin = () => config.setup && config.setup.AS_ADMIN;
const canWrite = () => config.setup && (config.setup.CAN_WRITE || config.setup.AS_ADMIN);
const hasUsers = () => config.options && config.options.hasUsers;

const updateButton = () => {
    if (!$btn) return;
    if (isAdmin()) {
        const label = config.setup.USER ? config.setup.USER + ' (' + config.setup.ROLE + ')' : 'admin';
        $btn.find('.auth-label').text(label);
        $btn.attr('title', 'Logout (' + label + ')');
        $btn.addCls('is-logged-in');
    } else if (canWrite()) {
        const label = config.setup.USER || 'editor';
        $btn.find('.auth-label').text(label);
        $btn.attr('title', 'Logout (' + label + ')');
        $btn.addCls('is-logged-in');
    } else {
        $btn.find('.auth-label').text('Login');
        $btn.attr('title', 'Login as admin');
        $btn.rmCls('is-logged-in');
    }
};

const openModal = () => {
    if (!$modal) return;
    const showUser = hasUsers();
    $modal.find('#auth-user').toggle(showUser);
    if (!showUser) {
        $modal.find('#auth-title').text('Admin login');
        $modal.find('#auth-user').hide();
    } else {
        $modal.find('#auth-title').text('Sign in');
    }
    $modal.rmCls('hidden');
    $modal.find('#auth-hint').text(showUser ? 'Enter your username and password.' : 'Default password is empty. Change it in options.json.');
    setTimeout(() => {
        if (showUser) $modal.find('#auth-user')[0].focus();
        else $modal.find('#auth-pass')[0].focus();
    }, 50);
};

const closeModal = () => {
    if (!$modal) return;
    $modal.addCls('hidden');
    $modal.find('#auth-pass').val('');
};

const doLogin = () => {
    const user = $modal.find('#auth-user').val() || '';
    const pass = $modal.find('#auth-pass').val() || '';
    if (hasUsers() && !user) {
        $modal.find('#auth-hint').text('Username required');
        return;
    }
    const data = {action: 'login', pass};
    if (hasUsers() || user) data.user = user;
    server.request(data).then(res => {
        if (!res || res.ok === false || res.status === 429) {
            const msg = res && res.txt ? res.txt : 'Login failed';
            $modal.find('#auth-hint').text(msg);
            return;
        }
        if (res && res.user !== undefined) {
            global.window.location.reload();
        } else if (res && res.asAdmin === false) {
            $modal.find('#auth-hint').text('Invalid credentials');
        } else {
            global.window.location.reload();
        }
    });
};

const doLogout = () => {
    server.request({action: 'logout'}).then(() => {
        global.window.location.reload();
    });
};

const onAuthClick = () => {
    if (isAdmin() || canWrite()) {
        if (global.window.confirm('Logout?')) doLogout();
        return;
    }
    openModal();
};

const init = () => {
    if (!settings.enabled) return;

    const $toolbar = dom('#toolbar');
    const fallbackToolbar = !dom('#toolbar').length;
    const $anchor = fallbackToolbar ? dom('#topbar') : $toolbar;

    if (!$anchor.length) return;

    $modal = dom(tplModal).appTo('body');
    $modal.find('.auth-backdrop').on('click', closeModal);
    $modal.find('#auth-cancel').on('click', closeModal);
    $modal.find('#auth-submit').on('click', doLogin);
    $modal.find('#auth-pass').on('keydown', ev => {
        if (ev.which === 13) doLogin();
        if (ev.which === 27) closeModal();
    });
    $modal.find('#auth-user').on('keydown', ev => {
        if (ev.which === 13) {
            if (hasUsers() && !$modal.find('#auth-pass').val()) {
                $modal.find('#auth-pass')[0].focus();
            } else doLogin();
        }
        if (ev.which === 27) closeModal();
    });

    global.window.addEventListener('keydown', ev => {
        if (ev.key === 'Escape' && !$modal.hasCls('hidden')) closeModal();
    });

    const btnTpl =
        `<div id="auth-btn" class="tool auth-tool" role="button" tabindex="0" aria-label="Login">
            <span class="auth-label">Login</span>
        </div>`;

    $btn = dom(btnTpl).appTo($toolbar.length ? $toolbar : $anchor);
    $btn.on('click', onAuthClick);
    $btn.on('keydown', ev => {
        if (ev.which === 13 || ev.which === 32) {
            ev.preventDefault();
            onAuthClick();
        }
    });

    updateButton();
    event.sub('location.changed', updateButton);
};

init();
