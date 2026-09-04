const {dom, awaitReady} = require('./util');
const config = require('./config');

const name = dom('script[data-module]').attr('data-module');
const query = {
    action: 'get',
    setup: true,
    options: true,
    types: true
};

if (name === 'index') {
    query.theme = true;
    query.langs = true;
} else if (name === 'info') {
    query.refresh = true;
} else {
    throw new Error(`no-main-module: '${name}'`);
}

config._update(query)
    .then(resp => {
        if (!resp || resp.status === 500 || (resp.err && !resp.setup)) {
            const msg = resp && resp.txt ? resp.txt.slice(0, 200) : 'setup failed (500)';
            console.error('sayanet setup failed', resp);
            const hint = document.getElementById('fallback-hints');
            if (hint) hint.innerHTML += `<span style="color:#ee5396;margin-left:16px">setup failed: ${msg} – check php logs</span>`;
            // still try to continue if we have setup
            if (!resp || !resp.setup) throw new Error('no-setup:' + msg);
        }
        return awaitReady();
    })
    .then(() => require(`./main/${name}`))
    .catch(err => {
        console.error(err);
    });
