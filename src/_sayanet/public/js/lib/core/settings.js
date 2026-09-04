const config = require('../config');

module.exports = Object.assign({}, config.options || {}, {
    publicHref: config.setup && config.setup.PUBLIC_HREF || '/_sayanet/public/',
    rootHref: config.setup && config.setup.ROOT_HREF || '/'
});
