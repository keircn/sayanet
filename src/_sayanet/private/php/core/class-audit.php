<?php
class Audit {
    private $cachePath;

    public function __construct($setup) {
        $this->cachePath = $setup->get('CACHE_PRV_PATH') . '/audit.log';
    }

    public function log($action, $user, $path = '', $status = 'ok', $extra = []) {
        $entry = [
            'time' => gmdate('c'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user' => $user ?? 'guest',
            'action' => $action,
            'path' => $path,
            'status' => $status
        ];
        if (!empty($extra)) {
            $entry['extra'] = $extra;
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($this->cachePath, $line, FILE_APPEND | LOCK_EX);
        // also to error_log for docker
        @error_log('[AUDIT] ' . $line);
    }

    public function get_recent($limit = 100) {
        if (!is_readable($this->cachePath)) return [];
        $lines = @file($this->cachePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return [];
        $out = [];
        $lines = array_slice($lines, -$limit);
        foreach ($lines as $l) {
            $j = json_decode($l, true);
            if ($j) $out[] = $j;
        }
        return array_reverse($out);
    }
}
