const {each, dom} = require('./util');

const request = async (data, {signal} = {}) => {
    try {
        const res = await fetch('?', {
            method: 'POST',
            headers: {'Content-Type': 'application/json;charset=utf-8'},
            body: JSON.stringify(data),
            signal
        });
        const text = await res.text();
        let json;
        try {
            json = JSON.parse(text);
        } catch (err) {
            return {err, txt: text, status: res.status};
        }
        if (res.status === 429) {
            const retry = res.headers.get('Retry-After') || '30';
            // show notification if available
            try {
                const event = require('../core/event');
                event.pub('notification', `Rate limited, retry after ${retry}s`);
            } catch (e) { /* ignore missing event */ }
            json.retryAfter = retry;
            json.status = 429;
        }
        json.status = json.status || res.status;
        return json;
    } catch (err) {
        if (err && err.name === 'AbortError') {
            return {err, txt: '', status: 0};
        }
        return {err, txt: String(err), status: 0};
    }
};

const formRequest = data => {
    const $form = dom('<form method="post" action="?" style="display:none;"/>');

    each(data, (val, key) => {
        dom('<input type="hidden"/>')
            .attr('name', key)
            .attr('value', val)
            .appTo($form);
    });

    $form.appTo('body');
    $form[0].submit();
    $form.rm();
};

module.exports = {
    request,
    formRequest
};
