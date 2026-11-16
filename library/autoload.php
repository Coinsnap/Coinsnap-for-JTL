<?php

spl_autoload_register(function ($className) {
    if (!defined('COINSNAP_SERVER_PATH'))	define( 'COINSNAP_SERVER_PATH', 'stores' );  
    $searchPattern = 'Coinsnap';
    // Abort here if we do not try to load Coinsnap namespace.
    if (strpos($className, $searchPattern) !== 0) {
        return;
    }

    // Convert namespace and class to file path.
    // Strip only the leading prefix: replacing every "Coinsnap" made CoinsnapException unloadable.
    $filePath =  __DIR__ . str_replace('\\', DIRECTORY_SEPARATOR, substr($className, strlen($searchPattern))).'.php';
    if (file_exists($filePath)) {
        require_once($filePath);
        return;
    }
});
