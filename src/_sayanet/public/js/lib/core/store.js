const store = global.window.localStorage;
const storekey = '_sayanet';
const legacyStorekey = '_h5ai';


const load = () => {
    try {
        if (store[storekey]) {
            return JSON.parse(store[storekey]);
        }
        // migrate legacy key if present
        if (store[legacyStorekey]) {
            const data = JSON.parse(store[legacyStorekey]);
            store[storekey] = JSON.stringify(data);
            return data;
        }
    } catch (e) {/* skip */}
    return {};
};

const save = obj => {
    store[storekey] = JSON.stringify(obj);
};

const put = (key, value) => {
    const obj = load();
    obj[key] = value;
    save(obj);
};

const get = key => load()[key];


module.exports = {
    put,
    get
};
