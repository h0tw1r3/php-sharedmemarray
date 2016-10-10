<?php

require_once 'memcache_servers.php';

$servers = MemcacheServers::getInstance();

foreach($servers as $ip => $status) {
    if ($servers->isDown($ip)) {
        printf("%s DOWN\n", $ip);
    } else {
        printf("%s %s\n", $ip, var_export($status,1));
    }
}

$servers->down('10.254.30.40:11211');
print_r($servers->array_keys());
print_r($servers->array_values());
