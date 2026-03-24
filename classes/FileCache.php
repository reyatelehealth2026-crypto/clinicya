<?php
/**
 * Simple File-based Cache (Fallback when Redis not available)
 * ใช้เมื่อไม่มี Redis หรือ Predis
 */

class FileCache {
    private static $instance = null;
    private $cacheDir;
    private $enabled = false;
    
    private function __construct() {
        $this->cacheDir = sys_get_temp_dir() . '/odoo_cache/';
        $this->ensureDirectory();
        $this->enabled = is_writable($this->cacheDir);
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function ensureDirectory() {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    private function getFilePath($key) {
        return $this->cacheDir . md5($key) . '.cache';
    }
    
    private function getMetaPath($key) {
        return $this->cacheDir . md5($key) . '.meta';
    }
    
    public function get($key) {
        if (!$this->enabled) return null;
        
        $file = $this->getFilePath($key);
        if (!file_exists($file)) return null;
        
        $data = unserialize(file_get_contents($file));
        if ($data['expires'] < time()) {
            unlink($file);
            return null;
        }
        
        return $data['value'];
    }
    
    public function set($key, $value, $ttl = 300) {
        if (!$this->enabled) return false;
        
        $file = $this->getFilePath($key);
        $data = [
            'expires' => time() + $ttl,
            'key'     => $key,
            'value'   => $value
        ];
        
        return file_put_contents($file, serialize($data)) !== false;
    }
    
    public function delete($key) {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return false;
    }
    
    public function deletePattern($pattern) {
        if (!$this->enabled) return 0;
        
        // Convert glob-style pattern (e.g. odoo:test:777:*) to regex
        $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        $deleted = 0;
        
        foreach (glob($this->cacheDir . '*.cache') as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) continue;
            $data = @unserialize($raw);
            if (!is_array($data) || !isset($data['key'])) continue;
            if (preg_match($regex, $data['key'])) {
                @unlink($file);
                $deleted++;
            }
        }
        
        return $deleted;
    }

    public function flush() {
        if (!$this->enabled) return 0;
        
        $deleted = 0;
        foreach (glob($this->cacheDir . '*.cache') as $file) {
            @unlink($file);
            $deleted++;
        }
        
        return $deleted;
    }

    public function isEnabled() {
        return $this->enabled;
    }
}
