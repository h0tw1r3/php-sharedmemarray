<?php

/*
 * SharedMemArray
 *
 * Requires: PHP 5.3+, shmop, sysvsem, msgpack
 *
 * Author: Jeffrey Clark <http://github.com/h0tw1r3>
 * License: BSD 2-Clause License
 *          (http://www.opensource.org/licenses/bsd-license.php)
 */

abstract class SharedMemArray implements ArrayAccess, Iterator, Countable
{
    protected     $memsize   = 16384;
    public static $default   = array();
    public static $locktries = 5;

    private   $value = array();
    private   $cache = array();

    private   $shm_key = null;
    private   $sem_key = null;

    private   $sem  = null;
    private   $lock = null;

    private   $flock_fname = null;

    protected static $instances = array();

    protected function __construct()
    {
        $reflect = new ReflectionClass(get_called_class());
        $this->flock_fname = $reflect->getFileName();

        $this->shm_key = ftok($this->flock_fname, 'h');
        $this->sem_key = ftok($this->flock_fname, 'e');
    }

    public function __destruct()
    {
        $this->write();
    }

    public static function getInstance($name = null)
    {
        if (is_null($name)) {
            $name = get_called_class();
        }
        if (!array_key_exists($name, self::$instances)) {
            self::$instances[$name] = new static($name);
        }
        return self::$instances[$name];
    }

    private function refresh()
    {
        $this->value = static::$default;

        if ($this->isLocked()) {
            return;
        }

        $shm = @shmop_open($this->shm_key, 'a', 0660, $this->memsize);
        if ($shm !== FALSE) {
            $val = @msgpack_unpack(shmop_read($shm, 0, shmop_size($shm)));
            if (is_array($val)) {
                $this->value = $this->cache = $val;
            }
            shmop_close($shm);
        }
    }

    private function refreshIfEmpty()
    {
        if (empty($this->value)) {
            $this->refresh();
        }
    }

    private function write()
    {
        $result = FALSE;
        if ($this->value == $this->cache) {
            // no change
        } elseif (empty($this->cache) && ($this->value === static::$default)) {
            // no change
        } else {
            if ($this->acquireLock()) {
                $shm = shmop_open($this->shm_key, 'c', 0660, $this->memsize);
                if ($shm !== FALSE) {
                    $result = (shmop_write($shm, msgpack_pack($this->value), 0) !== FALSE);
                    shmop_close($shm);
                }
                $this->releaseLock();
            }
        }
        return $result;
    }

    private function isLocked()
    {
        $result = TRUE;
        for($tries = static::$locktries; $tries >= 0; $tries--) {
            $shm = @shmop_open($this->sem_key, 'a', 0660, 1);
            if ($shm == FALSE) {
                $result = FALSE;
                break;
            } else {
                shmop_close($shm);
            }
            usleep(50);
        }
        return $result;
    }

    private function acquireLock()
    {
        $result = FALSE;
        if ($this->acquireSemaphore()) {
            $key = ftok($this->flock_fname, 'l');
            for($tries = static::$locktries; $tries >= 0; $tries--) {
                if (($this->lock = @shmop_open($key, 'n', 0660, 8)) !== FALSE) {
                    $result = TRUE;
                    break;
                }
                usleep(50);
            }
            $this->releaseSemaphore();
        }
        return $result;
    }

    private function releaseLock()
    {
        $result = FALSE;
        if ($this->lock) {
            for($tries = static::$locktries; $tries >= 0; $tries--) {
                if ($result = shmop_delete($this->lock)) {
                    $this->lock = null;
                    break;
                }
                usleep(50);
            }
        }
        return $result;
    }

    private function acquireSemaphore()
    {
        $result = FALSE;
        if (is_null($this->sem)) {
            $this->sem = sem_get($this->sem_key, 1, 0660, 1);
            if (sem_acquire($this->sem)) {
                $result = TRUE;
            } else {
                $this->sem = null;
            }
        } else {
            $result = TRUE;
        }
        return $result;
    }

    private function releaseSemaphore()
    {
        $result = FALSE;
        if ($this->sem) {
            $result = sem_release($this->sem);
            $this->sem = null;
        }
        return $result;
    }

    public function offsetExists($value)
    {
        $this->refreshIfEmpty();
        return isset($this->value[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->value[$offset]) ? $this->value[$offset] : null;
    }

    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->value[] = $value;
        } else {
            $this->value[$offset] = $value;
        }
    }

    public function offsetUnset($offset)
    {
        unset($this->value[$offset]);
    }

    public function rewind()
    {
        $this->refreshIfEmpty();
        reset($this->value);
    }

    public function current()
    {
        return current($this->value);
    }

    public function key()
    {
        return key($this->value);
    }

    public function next()
    {
        return next($this->value);
    }

    public function valid()
    {
        return key($this->value) !== null;
    }

    public function count()
    {
        $this->refreshIfEmpty();
        return count($this->value);
    }

    public function __call($func, $argv)
    {
        $this->refreshIfEmpty();
        if (!is_callable($func) || substr($func, 0, 6) !== 'array_')
        {
            throw new BadMethodCallException(__CLASS__.'->'.$func);
        }
        return call_user_func_array($func, array_merge(array($this->value), $argv));
    }

    private function __clone() {}
    private function __sleep() {}
    private function __wakeup() {}

    public static function shutdown()
    {
        foreach (self::$instances as $instance) {
            $instance->releaseLock();
            $instance->releaseSemaphore();
        }
    }
}

register_shutdown_function(array('SharedMemArray', 'shutdown'));
