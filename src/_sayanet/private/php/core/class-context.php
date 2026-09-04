<?php

class Context {
    private static $DEFAULT_PASSHASH = 'cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e';
    private static $AS_ADMIN_SESSION_KEY = 'AS_ADMIN';
    private static $USER_SESSION_KEY = 'USER';
    private static $ROLE_SESSION_KEY = 'ROLE';
    private static $L10N_ISO_CODES = array(
        'af', 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'he',
        'hi', 'hr', 'hu', 'id', 'it', 'ja','ko', 'lv', 'nb', 'nl', 'pl',
        'pt-br', 'pt-pt', 'ro', 'ru', 'sk', 'sl', 'sr', 'sv', 'tr', 'uk',
        'zh-cn', 'zh-tw'
    );

    private $session;
    private $request;
    private $setup;
    private $options;
    private $passhash;
    private $users;

    public function __construct($session, $request, $setup) {
        $this->session = $session;
        $this->request = $request;
        $this->setup = $setup;

        $this->options = Json::load($this->setup->get('CONF_PATH') . '/options.json');

        $this->passhash = $this->query_option('passhash', '');
        $this->options['hasCustomPasshash'] = strcasecmp($this->passhash, Context::$DEFAULT_PASSHASH) !== 0;
        // multi-user: users array takes precedence, keep legacy passhash for compat
        $this->users = $this->query_option('users', []);
        if (!is_array($this->users)) {
            $this->users = [];
        }
        // normalize users: ensure each has username, passhash, role
        $normalized = [];
        foreach ($this->users as $u) {
            if (!is_array($u) || empty($u['username']) || empty($u['passhash'])) {
                continue;
            }
            $role = strtolower($u['role'] ?? 'viewer');
            if (!in_array($role, ['admin', 'editor', 'viewer'], true)) {
                $role = 'viewer';
            }
            $normalized[] = [
                'username' => (string)$u['username'],
                'passhash' => (string)$u['passhash'],
                'role' => $role
            ];
        }
        $this->users = $normalized;
        $this->options['hasUsers'] = !empty($this->users);
        // don't leak hashes to client
        unset($this->options['passhash']);
        unset($this->options['users']);
    }

    public function get_session() {
        return $this->session;
    }

    public function get_request() {
        return $this->request;
    }

    public function get_setup() {
        return $this->setup;
    }

    public function get_options() {
        return $this->options;
    }

    public function query_option($keypath = '', $default = null) {
        return Util::array_query($this->options, $keypath, $default);
    }

    public function get_types() {
        return Json::load($this->setup->get('CONF_PATH') . '/types.json');
    }

    public function login_admin($pass) {
        // backwards compat: single pass without username uses legacy passhash or first admin user
        $user = $this->request->query('user', null);
        if ($user !== null && $user !== '') {
            return $this->login($user, $pass);
        }
        // legacy path: try users array first if exists, else passhash
        if (!empty($this->users)) {
            // try to find admin user that matches pass (for legacy clients sending only pass)
            foreach ($this->users as $u) {
                if ($this->verify_hash((string)$pass, $u['passhash'])) {
                    return $this->set_logged_in($u['username'], $u['role']);
                }
            }
            return $this->set_logged_in(null, null, false);
        }
        $isValid = $this->verify_hash((string)$pass, (string)$this->passhash);
        if ($isValid) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        }
        $this->session->set(Context::$AS_ADMIN_SESSION_KEY, $isValid);
        $this->session->set(Context::$USER_SESSION_KEY, $isValid ? 'admin' : null);
        $this->session->set(Context::$ROLE_SESSION_KEY, $isValid ? 'admin' : null);
        return $this->session->get(Context::$AS_ADMIN_SESSION_KEY);
    }

    public function login($user, $pass) {
        $user = trim((string)$user);
        $pass = (string)$pass;
        if ($user === '' || $pass === '') {
            return $this->set_logged_in(null, null, false);
        }
        // check users array
        if (!empty($this->users)) {
            foreach ($this->users as $u) {
                if (strcasecmp($u['username'], $user) === 0 && $this->verify_hash($pass, $u['passhash'])) {
                    return $this->set_logged_in($u['username'], $u['role']);
                }
            }
            return $this->set_logged_in(null, null, false);
        }
        // fallback to legacy passhash: username must be admin or empty
        if (strcasecmp($user, 'admin') === 0 || $user === '') {
            $isValid = $this->verify_hash($pass, (string)$this->passhash);
            if ($isValid) {
                return $this->set_logged_in('admin', 'admin');
            }
        }
        return $this->set_logged_in(null, null, false);
    }

    private function verify_hash($pass, $hash) {
        $hash = (string)$hash;
        if ($hash === '') {
            return false;
        }
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2')) {
            return password_verify((string)$pass, $hash);
        }
        $computed = hash('sha512', (string)$pass);
        return hash_equals(strtolower($hash), strtolower($computed));
    }

    private function set_logged_in($username, $role, $isValid = true) {
        if ($isValid) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $this->session->set(Context::$AS_ADMIN_SESSION_KEY, $role === 'admin');
            $this->session->set(Context::$USER_SESSION_KEY, $username);
            $this->session->set(Context::$ROLE_SESSION_KEY, $role);
            return true;
        }
        $this->session->set(Context::$AS_ADMIN_SESSION_KEY, false);
        $this->session->set(Context::$USER_SESSION_KEY, null);
        $this->session->set(Context::$ROLE_SESSION_KEY, null);
        return false;
    }

    public function logout_admin() {
        $this->session->set(Context::$AS_ADMIN_SESSION_KEY, false);
        $this->session->set(Context::$USER_SESSION_KEY, null);
        $this->session->set(Context::$ROLE_SESSION_KEY, null);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        return false;
    }

    /**
     * Helper to generate a secure passhash for options.json
     * Usage: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
     */
    public static function hash_password($pass) {
        return password_hash((string)$pass, PASSWORD_DEFAULT);
    }

    public function is_admin() {
        return $this->session->get(Context::$AS_ADMIN_SESSION_KEY, false) === true;
    }

    public function get_current_user() {
        return $this->session->get(Context::$USER_SESSION_KEY, null);
    }

    public function get_current_role() {
        return $this->session->get(Context::$ROLE_SESSION_KEY, null) ?? 'viewer';
    }

    public function can_write() {
        $role = $this->get_current_role();
        if ($this->is_admin()) {
            return true;
        }
        return in_array($role, ['admin', 'editor'], true);
    }

    public function can_admin() {
        return $this->is_admin();
    }

    public function get_users_sanitized() {
        $out = [];
        foreach ($this->users as $u) {
            $out[] = ['username' => $u['username'], 'role' => $u['role']];
        }
        return $out;
    }

    public function create_user($username, $pass, $role = 'viewer') {
        if (!$this->is_admin()) {
            return false;
        }
        $username = trim((string)$username);
        if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]{3,20}$/', $username)) {
            return false;
        }
        $role = strtolower($role);
        if (!in_array($role, ['admin', 'editor', 'viewer'], true)) {
            $role = 'viewer';
        }
        foreach ($this->users as $u) {
            if (strcasecmp($u['username'], $username) === 0) {
                return false;
            }
        }
        $hash = self::hash_password($pass);
        $this->users[] = ['username' => $username, 'passhash' => $hash, 'role' => $role];
        return $this->persist_users();
    }

    public function delete_user($username) {
        if (!$this->is_admin()) {
            return false;
        }
        $username = trim((string)$username);
        $found = false;
        $new = [];
        foreach ($this->users as $u) {
            if (strcasecmp($u['username'], $username) === 0) {
                $found = true;
                continue;
            }
            $new[] = $u;
        }
        if (!$found) {
            return false;
        }
        // prevent deleting last admin
        $hasAdmin = false;
        foreach ($new as $u) {
            if ($u['role'] === 'admin') {
                $hasAdmin = true;
                break;
            }
        }
        if (!$hasAdmin && !empty($new)) {
            // ensure at least one admin remains, if original had admin
            $origHasAdmin = false;
            foreach ($this->users as $u) {
                if ($u['role'] === 'admin') {
                    $origHasAdmin = true;
                    break;
                }
            }
            if ($origHasAdmin) {
                return false;
            }
        }
        $this->users = $new;
        return $this->persist_users();
    }

    public function update_user($username, $pass = null, $role = null) {
        if (!$this->is_admin()) {
            return false;
        }
        $username = trim((string)$username);
        foreach ($this->users as &$u) {
            if (strcasecmp($u['username'], $username) === 0) {
                if ($pass !== null && $pass !== '') {
                    $u['passhash'] = self::hash_password($pass);
                }
                if ($role !== null) {
                    $role = strtolower($role);
                    if (in_array($role, ['admin', 'editor', 'viewer'], true)) {
                        $u['role'] = $role;
                    }
                }
                return $this->persist_users();
            }
        }
        return false;
    }

    private function persist_users() {
        $path = $this->setup->get('CONF_PATH') . '/options.json';
        $data = Json::load($path);
        $data['users'] = $this->users;
        // keep legacy passhash for compat but don't overwrite if users exist
        $ok = Json::save($path, $data);
        if ($ok) {
            $this->options['hasUsers'] = !empty($this->users);
        }
        return $ok;
    }

    public function is_api_request() {
        return strtolower($this->setup->get('REQUEST_METHOD')) === 'post';
    }

    public function is_info_request() {
        return Util::starts_with($this->setup->get('REQUEST_HREF') . '/', $this->setup->get('PUBLIC_HREF'));
    }

    public function is_text_browser() {
        return preg_match('/curl|links|lynx|w3m/i', $this->setup->get('HTTP_USER_AGENT')) === 1;
    }

    public function is_fallback_mode() {
        return $this->query_option('view.fallbackMode', false) || $this->is_text_browser();
    }

    public function to_href($path, $trailing_slash = true) {
        $rel_path = substr($path, strlen($this->setup->get('ROOT_PATH')));
        $parts = explode('/', $rel_path);
        $encoded_parts = [];
        foreach ($parts as $part) {
            if ($part != '') {
                $encoded_parts[] = rawurlencode($part);
            }
        }

        return Util::normalize_path($this->setup->get('ROOT_HREF') . implode('/', $encoded_parts), $trailing_slash);
    }

    public function to_path($href) {
        $rel_href = substr($href, strlen($this->setup->get('ROOT_HREF')));
        return Util::normalize_path($this->setup->get('ROOT_PATH') . '/' . rawurldecode($rel_href));
    }

    public function is_hidden($name) {
        // always hide
        if ($name === '.' || $name === '..') {
            return true;
        }

        foreach ($this->query_option('view.hidden', []) as $re) {
            $re = Util::wrap_pattern($re);
            if (preg_match($re, $name)) {
                return true;
            }
        }

        return false;
    }

    public function read_dir($path) {
        $names = [];
        if (is_dir($path)) {
            foreach (scandir($path) as $name) {
                if (
                    $this->is_hidden($name)
                    || $this->is_hidden($this->to_href($path) . $name)
                    || (!is_readable($path . '/' . $name) && $this->query_option('view.hideIf403', false))
                ) {
                    continue;
                }
                $names[] = $name;
            }
        }
        return $names;
    }

    public function is_managed_href($href) {
        return $this->is_managed_path($this->to_path($href));
    }

    public function is_managed_path($path) {
        if (!is_dir($path) || strpos($path, '../') !== false || strpos($path, '/..') !== false || $path === '..') {
            return false;
        }

        if (strpos($path, $this->setup->get('PUBLIC_PATH')) === 0) {
            return false;
        }

        if (strpos($path, $this->setup->get('PRIVATE_PATH')) === 0) {
            return false;
        }

        foreach ($this->query_option('view.unmanaged', []) as $name) {
            if (file_exists($path . '/' . $name)) {
                return false;
            }
        }

        while ($path !== $this->setup->get('ROOT_PATH')) {
            if (@is_dir($path . '/_sayanet/private/conf') || @is_dir($path . '/_h5ai/private/conf')) {
                return false;
            }
            $parent_path = Util::normalize_path(dirname($path));
            if ($parent_path === $path) {
                return false;
            }
            $path = $parent_path;
        }
        return true;
    }

    public function get_current_path() {
        $current_href = Util::normalize_path($this->setup->get('REQUEST_HREF'), true);
        $current_path = $this->to_path($current_href);

        if (!is_dir($current_path)) {
            $current_path = Util::normalize_path(dirname($current_path), false);
        }

        return $current_path;
    }

    public function get_items($href, $what) {
        if (!$this->is_managed_href($href)) {
            return [];
        }

        $cache = [];
        $folder = Item::get($this, $this->to_path($href), $cache);

        // add content of subfolders
        if ($what >= 2 && $folder !== null) {
            foreach ($folder->get_content($cache) as $item) {
                $item->get_content($cache);
            }
            $folder = $folder->get_parent($cache);
        }

        // add content of this folder and all parent folders
        while ($what >= 1 && $folder !== null) {
            $folder->get_content($cache);
            $folder = $folder->get_parent($cache);
        }

        uasort($cache, ['Item', 'cmp']);
        $result = [];
        foreach ($cache as $p => $item) {
            $result[] = $item->to_json_object();
        }

        return $result;
    }

    public function get_langs() {
        $langs = [];
        $l10n_path = $this->setup->get('CONF_PATH') . '/l10n';
        if (is_dir($l10n_path)) {
            if ($dir = opendir($l10n_path)) {
                while (($file = readdir($dir)) !== false) {
                    if (Util::ends_with($file, '.json')) {
                        $translations = Json::load($l10n_path . '/' . $file);
                        $langs[basename($file, '.json')] = $translations['lang'];
                    }
                }
                closedir($dir);
            }
        }
        ksort($langs);
        return $langs;
    }

    public function get_l10n($iso_codes) {
        $results = [];

        foreach ($iso_codes as $iso_code) {
            if (!in_array($iso_code, Context::$L10N_ISO_CODES)) {
                continue;
            }

            $file = $this->setup->get('CONF_PATH') . '/l10n/' . $iso_code . '.json';
            $results[$iso_code] = Json::load($file);
            $results[$iso_code]['isoCode'] = $iso_code;
        }

        return $results;
    }

    public function get_thumbs($requests) {
        $hrefs = [];

        foreach ($requests as $req) {
            $thumb = new Thumb($this);
            $hrefs[] = $thumb->thumb($req['type'], $req['href'], $req['width'], $req['height']);
        }

        return $hrefs;
    }

    private function prefix_x_head_href($href) {
        if (preg_match('@^(https?://|/)@i', $href)) {
            return $href;
        }

        return $this->setup->get('PUBLIC_HREF') . 'ext/' . $href;
    }

    private function get_fonts_html() {
        $fonts = $this->query_option('view.fonts', []);
        $fonts_mono = $this->query_option('view.fontsMono', []);

        $html = '<style class="x-head">';

        if (sizeof($fonts) > 0) {
            $html .= '#root,input,select{font-family:"' . implode('","', $fonts) . '"!important}';
        }

        if (sizeof($fonts_mono) > 0) {
            $html .= 'pre,code{font-family:"' . implode('","', $fonts_mono) . '"!important}';
        }

        $html .= '</style>';

        return $html;
    }

    public function get_x_head_html() {
        $scripts = $this->query_option('resources.scripts', []);
        $styles = $this->query_option('resources.styles', []);

        $html = '';

        foreach ($styles as $href) {
            $safe = htmlspecialchars($this->prefix_x_head_href($href), ENT_QUOTES, 'UTF-8');
            $html .= '<link rel="stylesheet" href="' . $safe . '" class="x-head">';
        }

        foreach ($scripts as $href) {
            $safe = htmlspecialchars($this->prefix_x_head_href($href), ENT_QUOTES, 'UTF-8');
            $html .= '<script src="' . $safe . '" class="x-head"></script>';
        }

        $html .= $this->get_fonts_html();

        return $html;
    }
}
