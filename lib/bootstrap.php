<?php

/**
 * PHP-FPM Tuner Bootstrap
 *
 * 初始化環境、載入 polyfills 和所有 lib 模組
 */

// =============================================================================
// Polyfills for PHP < 7.2 and PHP < 8.2
// =============================================================================

// PHP_OS_FAMILY polyfill (PHP 7.2+)
if (!defined('PHP_OS_FAMILY')) {
    if (stripos(PHP_OS, 'WIN') === 0) {
        define('PHP_OS_FAMILY', 'Windows');
    } elseif (stripos(PHP_OS, 'Darwin') === 0) {
        define('PHP_OS_FAMILY', 'Darwin');
    } else {
        define('PHP_OS_FAMILY', 'Linux');
    }
}

// ini_parse_quantity polyfill (PHP 8.2+)
if (!function_exists('ini_parse_quantity')) {
    function ini_parse_quantity($value) {
        if (is_numeric($value)) {
            return (int) $value;
        }

        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) substr($value, 0, -1);

        switch ($unit) {
            case 'g':
                $number *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $number *= 1024 * 1024;
                break;
            case 'k':
                $number *= 1024;
                break;
        }

        return $number;
    }
}

// =============================================================================
// 載入所有 lib 模組
// =============================================================================

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/SystemInfo.php';
require_once __DIR__ . '/Calculator.php';
require_once __DIR__ . '/Collector.php';
require_once __DIR__ . '/Analyzer.php';
