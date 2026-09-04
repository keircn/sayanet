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
        try {
            return JSON.parse(text);
        } catch (err) {
            return {err, txt: text};
        }
    } catch (err) {
        // network error or abort
        if (err && err.name === 'AbortError') {
            return {err, txt: ''};
        }
        return {err, txt: String(err)};
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
