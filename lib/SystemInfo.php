<?php

/**
 * 系統資訊收集類別
 *
 * 收集 CPU、記憶體和 PHP-FPM worker 資訊
 */
class SystemInfo
{
    /**
     * 收集所有系統資訊
     *
     * @param array $config 配置（用於 min_worker_memory）
     * @return array ['cpu_cores' => int, 'free_memory' => int, 'worker_memory' => int]
     */
    public static function collect(array $config = [])
    {
        $minWorkerMemory = isset($config['min_worker_memory']) ? $config['min_worker_memory'] : 32;

        return [
            'cpu_cores' => self::getCpuCores(),
            'free_memory' => self::getFreeMemory(),
            'worker_memory' => self::getWorkerMemory($minWorkerMemory),
        ];
    }

    /**
     * 取得 CPU 核心數
     *
     * @return int
     */
    public static function getCpuCores()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $cores = shell_exec('echo %NUMBER_OF_PROCESSORS%');
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $cores = shell_exec('sysctl -n hw.ncpu');
        } else {
            $cores = shell_exec('nproc');
        }

        return max(1, (int) $cores);
    }

    /**
     * 取得可用記憶體 (MB)
     *
     * @return int
     */
    public static function getFreeMemory()
    {
        $freeMemory = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            if (preg_match('~(\d+)~', shell_exec('wmic OS get FreePhysicalMemory'), $matches)) {
                $freeMemory = round((int) $matches[1] / 1024);
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            // macOS: 使用 vm_stat 計算可用記憶體
            $pageSize = (int) shell_exec('pagesize');
            $vmStat = shell_exec('vm_stat');
            if ($pageSize && $vmStat) {
                $free = 0;
                if (preg_match('~Pages free:\s+(\d+)~', $vmStat, $matches)) {
                    $free += (int) $matches[1];
                }
                if (preg_match('~Pages inactive:\s+(\d+)~', $vmStat, $matches)) {
                    $free += (int) $matches[1];
                }
                if (preg_match('~Pages purgeable:\s+(\d+)~', $vmStat, $matches)) {
                    $free += (int) $matches[1];
                }
                $freeMemory = round($free * $pageSize / 1024 / 1024);
            }
        } else {
            // Linux
            $meminfo = shell_exec('cat /proc/meminfo');

            // 優先使用 MemAvailable（更準確）
            if ($meminfo && preg_match('~MemAvailable:\s+(\d+)\s+~', $meminfo, $matches)) {
                $freeMemory = $matches[1] / 1024;
            }
            // 回退：MemFree + Buffers + Cached
            elseif ($meminfo && preg_match_all('~(MemFree|Buffers|Cached):\s+(\d+)\s+~', $meminfo, $matches, PREG_SET_ORDER)) {
                $total = 0;
                foreach ($matches as $match) {
                    if ($match[1] === 'Cached' || $match[1] === 'MemFree' || $match[1] === 'Buffers') {
                        $total += (int) $match[2];
                    }
                }
                $freeMemory = $total / 1024;
            }
        }

        return (int) $freeMemory;
    }

    /**
     * 取得 PHP-FPM worker 平均記憶體使用量 (MB)
     *
     * @param int $minMemory 最小記憶體值
     * @return int
     */
    public static function getWorkerMemory($minMemory = 32)
    {
        $processMemory = 0;

        if (PHP_OS_FAMILY !== 'Windows') {
            $psOutput = shell_exec('ps -eo size,command 2>/dev/null');
            if ($psOutput && preg_match_all('~(\d+).*php-fpm: pool~', $psOutput, $matches, PREG_PATTERN_ORDER)) {
                if (count($matches[1]) > 0) {
                    $processMemory = round(array_sum($matches[1]) / count($matches[1]) / 1024);
                }
            }
        }

        // 回退：使用 memory_limit
        if ($processMemory <= 0) {
            $memoryLimit = ini_get('memory_limit');
            if ($memoryLimit && $memoryLimit !== '-1') {
                $processMemory = round(ini_parse_quantity($memoryLimit) / 1048576);
            }
        }

        return max($minMemory, (int) $processMemory);
    }
}
