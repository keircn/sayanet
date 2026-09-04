const {dom} = require('../util');
const config = require('../config');
const server = require('../server');
const resource = require('../core/resource');


const testsTpl =
        '<ul id="tests"></ul>';
const testTpl =
        `<li class="test">
            <span class="label"></span>
            <span class="result"></span>
            <div class="info"></div>
        </li>`;
const loginTpl =
        `<div id="login-wrapper">
            <input id="user" type="text" placeholder="username" style="display:none"/>
            <input id="pass" type="password" placeholder="password"/>
            <span id="login">login</span>
            <span id="logout">logout</span>
            <div id="hint">
                The preset password is the empty string, just click login.
                Change it in '_sayanet/private/conf/options.json' (legacy: '_h5ai/private/conf/options.json').
            </div>
            <div id="current-user" style="display:none"></div>
        </div>`;
const supportTpl =
        `<div id="support">
            Show your support with a donation!
            <div class="paypal">
                <form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
                    <input type="hidden" name="cmd" value="_s-xclick"/>
                    <input type="hidden" name="hosted_button_id" value="8WSPKWT7YBTSQ"/>
                    <input type="image" src="${resource.image('paypal')}" name="submit" alt="PayPal"/>
                </form>
            </div>
        </div>`;
const setup = config.setup;


const addTest = (label, info, passed, result) => {
    const $test = dom(testTpl).appTo('#tests');
    $test.find('.label').text(label);
    $test.find('.result')
        .addCls(passed ? 'passed' : 'failed')
        .text(result ? result : passed ? 'yes' : 'no');
    $test.find('.info').html(info);
};

const addTests = () => {
    if (!setup.AS_ADMIN) {
        return;
    }

    dom(testsTpl).appTo('#content');

    addTest(
        'sayanet version', 'Only green if this is an official sayanet release',
        (/^\d+\.\d+\.\d+$/).test(setup.VERSION), setup.VERSION
    );

    addTest(
        'Index file found', 'Add <code>' + setup.INDEX_HREF + '</code> to your index file list',
        setup.INDEX_HREF
    );

    addTest(
        'Options parsable', 'File <code>options.json</code> is readable and syntax is correct',
        config.options !== null
    );

    addTest(
        'Types parsable', 'File <code>types.json</code> is readable and syntax is correct',
        config.types !== null
    );

    addTest(
        'Server software', 'Server is one of apache, lighttpd, nginx or cherokee',
        setup.HAS_SERVER, setup.SERVER_NAME + ' ' + setup.SERVER_VERSION
    );

    addTest(
        'PHP version', 'PHP version &gt;= ' + setup.MIN_PHP_VERSION,
        true, setup.PHP_VERSION
    );

    addTest(
        'PHP arch', '64-bit required to correctly display file/folder sizes &gt; ~2GB',
        setup.PHP_ARCH === '64-bit', setup.PHP_ARCH
    );

    addTest(
        'Public Cache directory', 'Web server has write access',
        setup.HAS_WRITABLE_CACHE_PUB
    );

    addTest(
        'Private Cache directory', 'Web server has write access',
        setup.HAS_WRITABLE_CACHE_PRV
    );

    addTest(
        'Image thumbs', 'PHP GD extension with JPEG support available',
        setup.HAS_PHP_JPEG
    );

    addTest(
        'Use EXIF thumbs', 'PHP EXIF extension available',
        setup.HAS_PHP_EXIF
    );

    addTest(
        'Movie thumbs', 'Command line program <code>avconv</code> or <code>ffmpeg</code> available',
        setup.HAS_CMD_AVCONV || setup.HAS_CMD_FFMPEG
    );

    addTest(
        'PDF thumbs', 'Command line program <code>convert</code> or <code>gm</code> available',
        setup.HAS_CMD_CONVERT || setup.HAS_CMD_GM
    );

    addTest(
        'Shell tar', 'Command line program <code>tar</code> available',
        setup.HAS_CMD_TAR
    );

    addTest(
        'Shell zip', 'Command line program <code>zip</code> available',
        setup.HAS_CMD_ZIP
    );

    addTest(
        'Shell du', 'Command line program <code>du</code> available',
        setup.HAS_CMD_DU
    );
};

const reload = () => {
    global.window.location.reload();
};

const onLogin = () => {
    const user = dom('#user').val() || '';
    const pass = dom('#pass').val() || '';
    const data = {action: 'login', pass};
    // only send user if hasUsers or user field visible
    if (config.options.hasUsers || user) {
        data.user = user;
    }
    server.request(data).then(res => {
        if (res && res.ok === false) {
            dom('#hint').text('Login failed').show();
            return;
        }
        reload();
    });
};

const onLogout = () => {
    server.request({
        action: 'logout'
    }).then(reload);
};

const onKeydown = ev => {
    if (ev.which === 13) {
        onLogin();
    }
};

const addSupport = () => {
    dom(supportTpl).appTo('#content');
};

const addLogin = () => {
    dom(loginTpl).appTo('#content');

    // handle multi-user: show username field if hasUsers
    const hasUsers = config.options.hasUsers;
    if (hasUsers) {
        dom('#user').show();
        if (!setup.AS_ADMIN) {
            dom('#user').on('keydown', onKeydown);
        }
    }

    // show current user if logged in
    if (setup.USER) {
        dom('#current-user').text(`Logged in as ${setup.USER} (${setup.ROLE || 'viewer'})`).show();
    } else if (setup.AS_ADMIN) {
        dom('#current-user').text('Logged in as admin').show();
    }

    if (setup.AS_ADMIN) {
        dom('#pass').rm();
        dom('#user').rm();
        dom('#login').rm();
        dom('#logout').on('click', onLogout);
    } else {
        dom('#pass').on('keydown', onKeydown)[0].focus();
        dom('#login').on('click', onLogin);
        dom('#logout').rm();
    }
    if (config.options.hasCustomPasshash && !hasUsers) {
        dom('#hint').rm();
    }
    if (hasUsers) {
        dom('#hint').text('Enter username and password. Ask admin to create account via options.json or admin panel.');
    }
};

const init = () => {
    addSupport();
    addLogin();
    addTests();
};


init();
