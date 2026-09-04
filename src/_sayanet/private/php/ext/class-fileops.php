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

    public function upload($destHref, $files, $paths = []) {
        $this->ensure_write();
        $destPath = $this->context->to_path($destHref);
        if (!$this->context->is_managed_path($destPath) || !is_dir($destPath)) {
            return ['ok' => false, 'error' => 'invalid dest'];
        }
        $results = [];
        foreach ($files as $idx => $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $results[] = ['name' => $file['name'], 'ok' => false, 'error' => $file['error']];
                continue;
            }
            $rel = isset($paths[$idx]) ? (string)$paths[$idx] : '';
            if ($rel === '') {
                $rel = $file['name'];
            }
            $rel = str_replace('\\', '/', $rel);
            $rel = ltrim($rel, '/');
            $parts = explode('/', $rel);
            $sanitizedParts = [];
            foreach ($parts as $p) {
                if ($p === '' || $p === '.' || $p === '..') continue;
                $s = $this->sanitize_name($p);
                if ($s === null) continue;
                $sanitizedParts[] = $s;
            }
            if (empty($sanitizedParts)) {
                $results[] = ['name' => $file['name'], 'ok' => false, 'error' => 'invalid name'];
                continue;
            }
            $sanitizedRel = implode('/', $sanitizedParts);
            $dirPart = dirname($sanitizedRel);
            $fileName = basename($sanitizedRel);
            $targetDir = $destPath;
            if ($dirPart !== '.' && $dirPart !== '') {
                $targetDir = $destPath . '/' . $dirPart;
                if (!$this->context->is_managed_path($targetDir) && !is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                } elseif (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                if (!is_dir($targetDir)) {
                    $results[] = ['name' => $sanitizedRel, 'ok' => false, 'error' => 'mkdir failed'];
                    continue;
                }
            }
            $dst = $targetDir . '/' . $fileName;
            if (file_exists($dst)) {
                $info = pathinfo($fileName);
                $base = $info['filename'];
                $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                $i = 1;
                while (file_exists($dst)) {
                    $dst = $targetDir . '/' . $base . " ($i)" . $ext;
                    $i++;
                    if ($i > 100) break;
                }
                $fileName = basename($dst);
                $sanitizedRel = ($dirPart !== '.' && $dirPart !== '' ? $dirPart . '/' : '') . $fileName;
            }
            $ok = @move_uploaded_file($file['tmp_name'], $dst);
            if (!$ok) {
                $ok = @rename($file['tmp_name'], $dst);
            }
            $extracted = false;
            $extractError = null;
            if ($ok && $this->is_archive($dst)) {
                $ex = $this->extract_archive($dst, $destPath, $dirPart);
                if ($ex['ok']) {
                    $extracted = true;
                    @unlink($dst);
                } else {
                    $extractError = $ex['error'];
                }
            }
            $entry = ['name' => $sanitizedRel, 'ok' => $ok];
            if ($extracted) $entry['extracted'] = true;
            if ($extractError) $entry['extractError'] = $extractError;
            $results[] = $entry;
        }
        $allOk = true;
        foreach ($results as $r) if (!$r['ok']) $allOk = false;
        return ['ok' => $allOk, 'results' => $results];
    }

    private function is_archive($path) {
        $lower = strtolower($path);
        return preg_match('/\.(zip|tar|tgz|tar\.gz|tar\.bz2|tbz2|tar\.xz|txz|gz|bz2|xz|7z|rar)$/', $lower) === 1;
    }

    private function extract_archive($archivePath, $destRoot, $subDir) {
        $lower = strtolower($archivePath);
        $dest = $destRoot;
        if ($subDir !== '.' && $subDir !== '') {
            $dest = $destRoot . '/' . $subDir;
        }
        if (!is_dir($dest)) @mkdir($dest, 0755, true);
        // zip via ZipArchive
        if (preg_match('/\.zip$/', $lower)) {
            if (!class_exists('ZipArchive')) return ['ok' => false, 'error' => 'ZipArchive missing'];
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) return ['ok' => false, 'error' => 'zip open failed'];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, '..') !== false) continue;
                $name = ltrim(str_replace('\\', '/', $name), '/');
                if ($name === '') continue;
                $parts = explode('/', $name);
                $clean = [];
                foreach ($parts as $p) {
                    if ($p === '' || $p === '.' || $p === '..') continue;
                    $c = $this->sanitize_name($p);
                    if ($c !== null) $clean[] = $c;
                }
                if (empty($clean)) continue;
                $isDir = substr($name, -1) === '/';
                $target = $dest . '/' . implode('/', $clean);
                if ($isDir) {
                    @mkdir($target, 0755, true);
                } else {
                    $dir = dirname($target);
                    if (!is_dir($dir)) @mkdir($dir, 0755, true);
                    $stream = $zip->getStream($name);
                    if ($stream) {
                        $out = @fopen($target, 'w');
                        if ($out) {
                            while (!feof($stream)) fwrite($out, fread($stream, 8192));
                            fclose($out);
                        }
                        fclose($stream);
                    }
                }
            }
            $zip->close();
            return ['ok' => true];
        }
        // tar family via PharData or shell tar
        if (preg_match('/\.(tar|tgz|tar\.gz|tar\.bz2|tbz2|tar\.xz|txz)$/', $lower)) {
            if (class_exists('PharData')) {
                try {
                    $phar = new PharData($archivePath);
                    // for compressed, need decompress first
                    if (preg_match('/\.(tgz|tar\.gz)$/', $lower)) {
                        $phar->decompress();
                        $decompressed = substr($archivePath, 0, -3);
                        if (file_exists($decompressed)) {
                            $phar2 = new PharData($decompressed);
                            $phar2->extractTo($dest, null, true);
                            @unlink($decompressed);
                            return ['ok' => true];
                        }
                    } elseif (preg_match('/\.(tar\.bz2|tbz2)$/', $lower)) {
                        $phar->decompress();
                        $decompressed = substr($archivePath, 0, -4);
                        if (file_exists($decompressed)) {
                            $phar2 = new PharData($decompressed);
                            $phar2->extractTo($dest, null, true);
                            @unlink($decompressed);
                            return ['ok' => true];
                        }
                    } else {
                        $phar->extractTo($dest, null, true);
                        return ['ok' => true];
                    }
                } catch (Exception $e) {
                    // fallback to shell
                }
            }
            if ($this->context->get_setup()->get('HAS_CMD_TAR')) {
                $cmd = ['tar', '-xf', $archivePath, '-C', $dest];
                Util::exec_cmdv($cmd);
                return ['ok' => file_exists($dest)];
            }
            return ['ok' => false, 'error' => 'tar extract failed'];
        }
        // 7z / rar via shell if available
        if (preg_match('/\.(7z|rar)$/', $lower)) {
            $has7z = Util::exec_0('command -v 7z') || Util::exec_0('which 7z');
            $hasUnrar = Util::exec_0('command -v unrar') || Util::exec_0('which unrar');
            if ($has7z) {
                Util::exec_cmdv(['7z', 'x', $archivePath, '-o' . $dest, '-y']);
                return ['ok' => true];
            }
            if ($hasUnrar && preg_match('/\.rar$/', $lower)) {
                Util::exec_cmdv(['unrar', 'x', '-o+', $archivePath, $dest . '/']);
                return ['ok' => true];
            }
            return ['ok' => false, 'error' => 'no extractor for ' . $lower];
        }
        return ['ok' => false, 'error' => 'unsupported archive'];
    }
}
