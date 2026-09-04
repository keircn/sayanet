<?php

class Api {
    private $context;
    private $request;
    private $setup;

    public function __construct($context) {
        $this->context = $context;
        $this->request = $context->get_request();
        $this->setup = $context->get_setup();
    }

    public function apply() {
        $action = $this->request->query('action');
        $supported = ['download', 'get', 'login', 'logout', 'upload', 'mkdir', 'delete', 'rename', 'move', 'user_list', 'user_create', 'user_delete', 'user_update'];
        Util::json_fail(Util::ERR_UNSUPPORTED, 'unsupported action', !in_array($action, $supported));

        // rate limiting - generous for public
        $limiter = new RateLimiter($this->setup);
        $audit = new Audit($this->setup);
        $user = $this->context->get_current_user() ?? 'guest';
        if (!$limiter->check($action)) {
            $retry = $limiter->get_retry_after($action);
            header('Retry-After: ' . $retry);
            header('HTTP/1.1 429 Too Many Requests');
            $audit->log($action, $user, '', 'rate_limited');
            Util::json_fail(Util::ERR_FAILED, 'rate limited, retry after ' . $retry . 's', true);
        }

        $methodname = 'on_' . $action;
        $this->$methodname();
        // audit success (except get which is noisy, only log login/logout and write ops)
        if (in_array($action, ['login', 'logout', 'upload', 'mkdir', 'delete', 'rename', 'move', 'user_create', 'user_delete', 'user_update'])) {
            $audit->log($action, $this->context->get_current_user() ?? 'guest', $this->request->query('href', $this->request->query('destHref', '')), 'ok');
        }
    }

    private function on_download() {
        Util::json_fail(Util::ERR_DISABLED, 'download disabled', !$this->context->query_option('download.enabled', false));

        $as = $this->request->query('as');
        $type = $this->request->query('type');
        $base_href = $this->request->query('baseHref');
        $hrefs = $this->request->query('hrefs', '');

        $archive = new Archive($this->context);

        set_time_limit(0);
        session_write_close();
        $safeAs = basename(str_replace(['"', '\\', '/', "\r", "\n"], '', (string)$as));
        if ($safeAs === '') {
            $safeAs = 'download';
        }
        // RFC 5987 for unicode filenames
        $encoded = rawurlencode($safeAs);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeAs . '"; filename*=UTF-8\'\'' . $encoded);
        header('X-Content-Type-Options: nosniff');
        header('Connection: close');
        $ok = $archive->output($type, $base_href, $hrefs);

        Util::json_fail(Util::ERR_FAILED, 'packaging failed', !$ok);
        exit;
    }

    private function on_get() {
        $response = [];

        foreach (['langs', 'options', 'types'] as $name) {
            if ($this->request->query_boolean($name, false)) {
                $methodname = 'get_' . $name;
                $response[$name] = $this->context->$methodname();
            }
        }

        if ($this->request->query_boolean('setup', false)) {
            $response['setup'] = $this->setup->to_jsono($this->context->is_admin(), $this->context->get_current_user(), $this->context->get_current_role());
        }

        if ($this->request->query_boolean('theme', false)) {
            $theme = new Theme($this->context);
            $response['theme'] = $theme->get_icons();
        }

        if ($this->request->query('items', false)) {
            $href = $this->request->query('items.href');
            $what = $this->request->query_numeric('items.what');
            $response['items'] = $this->context->get_items($href, $what);
        }

        if ($this->request->query('custom', false)) {
            Util::json_fail(Util::ERR_DISABLED, 'custom disabled', !$this->context->query_option('custom.enabled', false));
            $href = $this->request->query('custom');
            $custom = new Custom($this->context);
            $response['custom'] = $custom->get_customizations($href);
        }

        if ($this->request->query('l10n', false)) {
            Util::json_fail(Util::ERR_DISABLED, 'l10n disabled', !$this->context->query_option('l10n.enabled', false));
            $iso_codes = $this->request->query_array('l10n');
            $iso_codes = array_filter($iso_codes);
            $response['l10n'] = $this->context->get_l10n($iso_codes);
        }

        if ($this->request->query('search', false)) {
            Util::json_fail(Util::ERR_DISABLED, 'search disabled', !$this->context->query_option('search.enabled', false));
            $href = $this->request->query('search.href');
            $pattern = $this->request->query('search.pattern');
            $ignorecase = $this->request->query_boolean('search.ignorecase', false);
            $search = new Search($this->context);
            $response['search'] = $search->get_items($href, $pattern, $ignorecase);
        }

        if ($this->request->query('thumbs', false)) {
            Util::json_fail(Util::ERR_DISABLED, 'thumbnails disabled', !$this->context->query_option('thumbnails.enabled', false));
            Util::json_fail(Util::ERR_UNSUPPORTED, 'thumbnails not supported', !$this->setup->get('HAS_PHP_JPEG'));
            $thumbs = $this->request->query_array('thumbs');
            $response['thumbs'] = $this->context->get_thumbs($thumbs);
        }

        if ($this->request->query('user', false) || $this->request->query('currentUser', false)) {
            $response['user'] = [
                'name' => $this->context->get_current_user(),
                'role' => $this->context->get_current_role(),
                'isAdmin' => $this->context->is_admin(),
                'canWrite' => $this->context->can_write()
            ];
        }

        Util::json_exit($response);
    }

    private function on_login() {
        $user = $this->request->query('user', null);
        $pass = $this->request->query('pass', '');
        $audit = new Audit($this->setup);
        $ok = false;
        if ($user !== null && $user !== '') {
            $ok = $this->context->login($user, $pass);
        } else {
            $ok = $this->context->login_admin($pass);
        }
        $audit->log('login', $this->context->get_current_user() ?? $user ?? 'guest', '', $ok ? 'ok' : 'failed');
        Util::json_exit([
            'asAdmin' => $this->context->is_admin(),
            'user' => $this->context->get_current_user(),
            'role' => $this->context->get_current_role(),
            'ok' => $ok
        ]);
    }

    private function on_logout() {
        $user = $this->context->get_current_user() ?? 'guest';
        $this->context->logout_admin();
        $audit = new Audit($this->setup);
        $audit->log('logout', $user, '', 'ok');
        Util::json_exit(['asAdmin' => false, 'user' => null, 'role' => null]);
    }

    private function on_upload() {
        Util::json_fail(Util::ERR_DISABLED, 'upload disabled', !$this->context->can_write());
        $destHref = $this->request->query('href', $this->request->query('destHref', ''));
        // handle both JSON and multipart
        $files = [];
        if (!empty($_FILES)) {
            // normalize $_FILES
            foreach ($_FILES as $key => $info) {
                if (is_array($info['name'])) {
                    for ($i = 0; $i < count($info['name']); $i++) {
                        $files[] = [
                            'name' => $info['name'][$i],
                            'tmp_name' => $info['tmp_name'][$i],
                            'error' => $info['error'][$i],
                            'size' => $info['size'][$i]
                        ];
                    }
                } else {
                    $files[] = $info;
                }
            }
        }
        if (empty($files)) {
            Util::json_fail(Util::ERR_FAILED, 'no files', true);
        }
        $paths = [];
        $rawPaths = $this->request->query('paths', []);
        if (is_array($rawPaths)) {
            $paths = $rawPaths;
        } elseif (is_string($rawPaths) && $rawPaths !== '') {
            $paths = [$rawPaths];
        }
        // also support single path param
        $singlePath = $this->request->query('path', null);
        if ($singlePath !== null && empty($paths)) {
            $paths = is_array($singlePath) ? $singlePath : [$singlePath];
        }
        $ops = new FileOps($this->context);
        $res = $ops->upload($destHref, $files, $paths);
        Util::json_exit($res);
    }

    private function on_mkdir() {
        Util::json_fail(Util::ERR_DISABLED, 'mkdir disabled', !$this->context->can_write());
        $href = $this->request->query('href', '');
        $name = $this->request->query('name', '');
        $ops = new FileOps($this->context);
        $ok = $ops->mkdir($href, $name);
        Util::json_fail(Util::ERR_FAILED, 'mkdir failed', !$ok);
        Util::json_exit(['ok' => true]);
    }

    private function on_delete() {
        Util::json_fail(Util::ERR_DISABLED, 'delete disabled', !$this->context->can_write());
        $hrefs = $this->request->query('hrefs', $this->request->query('href', ''));
        if (!is_array($hrefs)) $hrefs = [$hrefs];
        $ops = new FileOps($this->context);
        $ok = $ops->delete($hrefs);
        Util::json_fail(Util::ERR_FAILED, 'delete failed', !$ok);
        Util::json_exit(['ok' => true]);
    }

    private function on_rename() {
        Util::json_fail(Util::ERR_DISABLED, 'rename disabled', !$this->context->can_write());
        $href = $this->request->query('href', '');
        $name = $this->request->query('name', $this->request->query('newName', ''));
        $ops = new FileOps($this->context);
        $ok = $ops->rename($href, $name);
        Util::json_fail(Util::ERR_FAILED, 'rename failed', !$ok);
        Util::json_exit(['ok' => true]);
    }

    private function on_move() {
        Util::json_fail(Util::ERR_DISABLED, 'move disabled', !$this->context->can_write());
        $hrefs = $this->request->query('hrefs', $this->request->query('href', ''));
        $dest = $this->request->query('destHref', $this->request->query('dest', ''));
        if (!is_array($hrefs)) $hrefs = [$hrefs];
        $ops = new FileOps($this->context);
        $ok = $ops->move($hrefs, $dest);
        Util::json_fail(Util::ERR_FAILED, 'move failed', !$ok);
        Util::json_exit(['ok' => true]);
    }

    private function on_user_list() {
        Util::json_fail(Util::ERR_DISABLED, 'not admin', !$this->context->is_admin());
        Util::json_exit(['users' => $this->context->get_users_sanitized()]);
    }

    private function on_user_create() {
        Util::json_fail(Util::ERR_DISABLED, 'not admin', !$this->context->is_admin());
        $user = $this->request->query('user', $this->request->query('username', ''));
        $pass = $this->request->query('pass', $this->request->query('password', ''));
        $role = $this->request->query('role', 'viewer');
        $ok = $this->context->create_user($user, $pass, $role);
        Util::json_fail(Util::ERR_FAILED, 'create failed (exists or invalid)', !$ok);
        Util::json_exit(['ok' => true, 'users' => $this->context->get_users_sanitized()]);
    }

    private function on_user_delete() {
        Util::json_fail(Util::ERR_DISABLED, 'not admin', !$this->context->is_admin());
        $user = $this->request->query('user', $this->request->query('username', ''));
        $ok = $this->context->delete_user($user);
        Util::json_fail(Util::ERR_FAILED, 'delete failed', !$ok);
        Util::json_exit(['ok' => true, 'users' => $this->context->get_users_sanitized()]);
    }

    private function on_user_update() {
        Util::json_fail(Util::ERR_DISABLED, 'not admin', !$this->context->is_admin());
        $user = $this->request->query('user', $this->request->query('username', ''));
        $pass = $this->request->query('pass', $this->request->query('password', null));
        $role = $this->request->query('role', null);
        $ok = $this->context->update_user($user, $pass, $role);
        Util::json_fail(Util::ERR_FAILED, 'update failed', !$ok);
        Util::json_exit(['ok' => true, 'users' => $this->context->get_users_sanitized()]);
    }
}
