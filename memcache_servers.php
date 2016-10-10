<?php

require_once 'shared_mem_array.php';

class MemcacheServers extends SharedMemArray
{
    protected $memsize = 256;
    public static $default = array(
        '10.254.30.40:11211' => 0,
        '10.254.30.41:11211' => 0,
    );
    public static $expire = 300;

    public function isDown($key) {
        return ($this[$key] > time());
    }

    public function down($key = null) {
        if ($key) {
            return ($this[$key] = $this->down());
        }
        return time() + static::$expire;
    }

    public function up($key = null) {
        if ($key) {
            return ($this[$key] = $this->up());
        }
        return 0;
    }
}
