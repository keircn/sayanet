<?php
class FileOps {
    private $context;

    public function __construct($context) {
        $this->context = $context;
    }

    private function ensure_write() {
        if (!$this->context->can_write()) {
            Util::json_fail(Util::ERR_DISABLED, 'write disabled or not authorized', true);
        }
    }

    private function sanitize_name($name) {
        $name = trim((string)$name);
        // prevent traversal and hidden
        $name = basename(str_replace(['/', '\\'], '', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        if ($this->context->is_hidden($name)) {
            return null;
        }
        return $name;
    }

    public function mkdir($href, $name) {
        $this->ensure_write();
        $name = $this->sanitize_name($name);
        if ($name === null) {
            return false;
        }
        $basePath = $this->context->to_path($href);
        if (!$this->context->is_managed_path($basePath) || !is_dir($basePath)) {
            return false;
        }
        $newPath = Util::normalize_path($basePath . '/' . $name, false);
        if (file_exists($newPath)) {
            return false;
        }
        return @mkdir($newPath, 0755, false);
    }

    public function delete($hrefs) {
        $this->ensure_write();
        if (!is_array($hrefs)) $hrefs = [$hrefs];
        $ok = true;
        foreach ($hrefs as $href) {
            $path = $this->context->to_path($href);
            if (!$this->context->is_managed_path(dirname($path)) || $this->context->is_hidden(basename($path))) {
                $ok = false;
                continue;
            }
            // prevent deleting managed boundaries
            if (!$this->context->is_managed_path($path) && !is_file($path) && !is_dir($path)) {
                $ok = false;
                continue;
            }
            // don't allow deleting root or _sayanet itself
            if ($path === $this->context->get_setup()->get('ROOT_PATH')) {
                $ok = false;
                continue;
            }
            if (is_dir($path) && !is_link($path)) {
                $ok = $this->rrmdir($path) && $ok;
            } elseif (is_file($path) || is_link($path)) {
                $ok = @unlink($path) && $ok;
            } else {
                $ok = false;
            }
        }
        return $ok;
    }

    private function rrmdir($dir) {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $f) {
            $p = $dir . '/' . $f;
            if (is_dir($p) && !is_link($p)) {
                if (!$this->rrmdir($p)) return false;
            } else {
                if (!@unlink($p)) return false;
            }
        }
        return @rmdir($dir);
    }

    public function rename($href, $newName) {
        $this->ensure_write();
        $newName = $this->sanitize_name($newName);
        if ($newName === null) return false;
        $oldPath = $this->context->to_path($href);
        if (!$this->context->is_managed_path(dirname($oldPath))) return false;
        $newPath = dirname($oldPath) . '/' . $newName;
        if (file_exists($newPath)) return false;
        // ensure parent is managed
        if (!$this->context->is_managed_path(dirname($newPath))) return false;
        return @rename($oldPath, $newPath);
    }

    public function move($hrefs, $destHref) {
        $this->ensure_write();
        if (!is_array($hrefs)) $hrefs = [$hrefs];
        $destPath = $this->context->to_path($destHref);
        if (!$this->context->is_managed_path($destPath) || !is_dir($destPath)) return false;
        $ok = true;
        foreach ($hrefs as $href) {
            $srcPath = $this->context->to_path($href);
            if (!$this->context->is_managed_path(dirname($srcPath))) { $ok = false; continue; }
            $base = basename($srcPath);
            if ($this->context->is_hidden($base)) { $ok = false; continue; }
            $dst = $destPath . '/' . $base;
            if (file_exists($dst)) { $ok = false; continue; }
            if (!@rename($srcPath, $dst)) $ok = false;
        }
        return $ok;
    }

    public function upload($destHref, $files) {
        $this->ensure_write();
        $destPath = $this->context->to_path($destHref);
        if (!$this->context->is_managed_path($destPath) || !is_dir($destPath)) {
            return ['ok' => false, 'error' => 'invalid dest'];
        }
        $results = [];
        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $results[] = ['name' => $file['name'], 'ok' => false, 'error' => $file['error']];
                continue;
            }
            $name = $this->sanitize_name($file['name']);
            if ($name === null) {
                $results[] = ['name' => $file['name'], 'ok' => false, 'error' => 'invalid name'];
                continue;
            }
            $dst = $destPath . '/' . $name;
            if (file_exists($dst)) {
                // auto-rename with suffix
                $info = pathinfo($name);
                $base = $info['filename'];
                $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                $i = 1;
                while (file_exists($dst)) {
                    $dst = $destPath . '/' . $base . " ($i)" . $ext;
                    $i++;
                    if ($i > 100) break;
                }
                $name = basename($dst);
            }
            $ok = @move_uploaded_file($file['tmp_name'], $dst);
            if (!$ok) {
                $ok = @rename($file['tmp_name'], $dst);
            }
            $results[] = ['name' => $name, 'ok' => $ok];
        }
        $allOk = true;
        foreach ($results as $r) if (!$r['ok']) $allOk = false;
        return ['ok' => $allOk, 'results' => $results];
    }
}
