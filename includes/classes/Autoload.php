<?php
// Gewoon hard alle files die ik nodig heb includen, oudere versies van prestashop ondersteunen spl_autoload_register niet
// - Uarghh, ik ken die ellende! Gelukkig heeft thirty bees daar geen last van :) ^MD
// - Check deze voor performance geoptimaliseerde autoloader: ^MD

spl_autoload_register(
    function ($class) {
        if (!in_array($class, [
            'Pay_Api',
            'Pay_Exception',
            'Pay_Helper',
            'Pay_Api_Exception',
            'Pay_Api_Getservice',
            'Pay_Api_Info',
            'Pay_Api_Start',
            'Pay_Helper_Transaction',
        ])) {
            return;
        }

        // project-specific namespace prefix
        $prefix = 'Pay_';

        // base directory for the namespace prefix
        $baseDir = __DIR__.'/Pay/';

        // does the class use the namespace prefix?
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            // no, move to the next registered autoloader
            return;
        }

        // get the relative class name
        $relativeClass = substr($class, $len);

        // replace the namespace prefix with the base directory, replace namespace
        // separators with directory separators in the relative class name, append
        // with .php
        $file = $baseDir.str_replace('_', '/', $relativeClass).'.php';

        // if the file exists, require it
        if (file_exists($file)) {
            require $file;
        }
    }
);
