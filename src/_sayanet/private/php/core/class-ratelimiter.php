<?php
// RateLimiter: generous for public, file-backed token bucket
class RateLimiter {
    private static $LIMITS = [
        'login' => [10, 60],      // 10 per 60s
        'search' => [30, 60],     // 30 per 60s
        'thumbs' => [120, 60],    // 120 per 60s
        'download' => [10, 60],
        'upload' => [20, 60],
        'mkdir' => [30, 60],
        'delete' => [30, 60],
        'rename' => [30, 60],
        'move' => [30, 60],
        'get' => [100, 60],
        'items' => [100, 60],
    ];

    private $cachePath;
    private $ip;

    public function __construct($setup) {
        $this->cachePath = $setup->get('CACHE_PRV_PATH') . '/ratelimit.json';
        $this->ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        // respect X-Forwarded-For if behind nginx
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $this->ip = trim($parts[0]);
        }
    }

    public function check($action) {
        $limits = self::$LIMITS[$action] ?? [60, 60];
        $max = $limits[0];
        $window = $limits[1];
        $now = time();

        $data = Json::load($this->cachePath);
        if (!is_array($data)) $data = [];

        $key = $this->ip . ':' . $action;
        if (!isset($data[$key]) || !is_array($data[$key])) {
            $data[$key] = [];
        }
        // prune old
        $data[$key] = array_values(array_filter($data[$key], fn($t) => $t > $now - $window));
        if (count($data[$key]) >= $max) {
            // still save pruned
            Json::save($this->cachePath, $data);
            return false;
        }
        $data[$key][] = $now;
        // cap total entries to avoid bloat (keep last 1000)
        if (count($data[$key]) > 1000) {
            $data[$key] = array_slice($data[$key], -1000);
        }
        Json::save($this->cachePath, $data);
        return true;
    }

    public function get_retry_after($action) {
        $limits = self::$LIMITS[$action] ?? [60, 60];
        $window = $limits[1];
        $data = Json::load($this->cachePath);
        $key = $this->ip . ':' . $action;
        if (empty($data[$key])) return 0;
        $oldest = min($data[$key]);
        $retry = ($oldest + $window) - time();
        return max(0, $retry);
    }
}
