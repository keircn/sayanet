<?php

class Context {
    private static $DEFAULT_PASSHASH = 'cf83e1357eefb8bdf1542850d66d8007d620e4050b5715dc83f4a921d36ce9ce47d0d13c5d85f2b0ff8318d2877eec2f63b931bd47417a81a538327af927da3e';
    private static $AS_ADMIN_SESSION_KEY = 'AS_ADMIN';
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

    public function __construct($session, $request, $setup) {
        $this->session = $session;
        $this->request = $request;
        $this->setup = $setup;

        $this->options = Json::load($this->setup->get('CONF_PATH') . '/options.json');

        $this->passhash = $this->query_option('passhash', '');
        $this->options['hasCustomPasshash'] = strcasecmp($this->passhash, Context::$DEFAULT_PASSHASH) !== 0;
        unset($this->options['passhash']);
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
        $isValid = false;
        $hash = (string)$this->passhash;
        // Support modern password_hash (argon2id/bcrypt) and legacy sha512
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$argon2')) {
            $isValid = password_verify((string)$pass, $hash);
            // timing-safe rehash check
            if ($isValid && password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                // could persist new hash, but keep in-memory for now
            }
        } else {
            // legacy sha512 with timing-safe compare
            $computed = hash('sha512', (string)$pass);
            $isValid = hash_equals(strtolower($hash), strtolower($computed));
        }
        if ($isValid) {
            // prevent session fixation
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        }
        $this->session->set(Context::$AS_ADMIN_SESSION_KEY, $isValid);
        return $this->session->get(Context::$AS_ADMIN_SESSION_KEY);
    }

    public function logout_admin() {
        $this->session->set(Context::$AS_ADMIN_SESSION_KEY, false);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        return $this->session->get(Context::$AS_ADMIN_SESSION_KEY);
    }

    /**
     * Helper to generate a secure passhash for options.json
     * Usage: php -r "echo password_hash('yourpassword', PASSWORD_DEFAULT);"
     */
    public static function hash_password($pass) {
        return password_hash((string)$pass, PASSWORD_DEFAULT);
    }

    public function is_admin() {
        return $this->session->get(Context::$AS_ADMIN_SESSION_KEY);
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
