<?php

class Session {
    private static $KEY_PREFIX = '__SAYANET__';
    private static $LEGACY_KEY_PREFIX = '__H5AI__';
    private $store;

    public function __construct(&$store) {
        $this->store = &$store;
    }

    public function set($key, $value) {
        $prefixed = Session::$KEY_PREFIX . $key;
        $this->store[$prefixed] = $value;
        // also clear legacy key to avoid stale data
        $legacy = Session::$LEGACY_KEY_PREFIX . $key;
        if (array_key_exists($legacy, $this->store)) {
            unset($this->store[$legacy]);
        }
    }

    public function get($key, $default = null) {
        $new = Session::$KEY_PREFIX . $key;
        if (array_key_exists($new, $this->store)) {
            return $this->store[$new];
        }
        // fallback to legacy key for backwards compatibility
        $legacy = Session::$LEGACY_KEY_PREFIX . $key;
        if (array_key_exists($legacy, $this->store)) {
            // migrate to new key
            $this->store[$new] = $this->store[$legacy];
            return $this->store[$new];
        }
        return $default;
    }
}
