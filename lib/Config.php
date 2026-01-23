<?php

/**
 * 配置管理類別
 *
 * 負責載入、合併和驗證配置
 */
class Config
{
    /**
     * 載入配置檔
     *
     * @param string|null $path 配置檔路徑，null 則載入預設配置
     * @return array 配置陣列
     */
    public static function load($path = null)
    {
        $defaultPath = dirname(__DIR__) . '/config/default.php';
        $default = file_exists($defaultPath) ? require $defaultPath : [];

        if ($path === null) {
            return $default;
        }

        if (!file_exists($path)) {
            fwrite(STDERR, "警告：配置檔不存在：{$path}，使用預設配置\n");
            return $default;
        }

        $custom = require $path;
        return self::merge($default, $custom);
    }

    /**
     * 合併配置（自訂值覆蓋預設值）
     *
     * @param array $default 預設配置
     * @param array $override 覆蓋配置
     * @return array 合併後的配置
     */
    public static function merge(array $default, array $override)
    {
        foreach ($override as $key => $value) {
            $default[$key] = $value;
        }
        return $default;
    }

    /**
     * 從 CLI 參數解析配置覆蓋值
     *
     * @param array $argv 命令列參數
     * @return array 包含 'config_path' 和 'overrides' 的陣列
     */
    public static function fromCliArgs(array $argv)
    {
        $result = [
            'config_path' => null,
            'overrides' => [],
            'options' => [],
        ];

        $i = 1;
        while ($i < count($argv)) {
            $arg = $argv[$i];

            if ($arg === '--config' && isset($argv[$i + 1])) {
                $result['config_path'] = $argv[$i + 1];
                $i += 2;
                continue;
            }

            if ($arg === '--help' || $arg === '-h') {
                $result['options']['help'] = true;
                $i++;
                continue;
            }

            if ($arg === '--json') {
                $result['options']['json'] = true;
                $i++;
                continue;
            }

            if ($arg === '--once') {
                $result['options']['once'] = true;
                $i++;
                continue;
            }

            if (strpos($arg, '--') === 0 && isset($argv[$i + 1])) {
                $key = substr($arg, 2);
                $key = str_replace('-', '_', $key);
                $value = $argv[$i + 1];

                // 嘗試轉換數值
                if (is_numeric($value)) {
                    $value = strpos($value, '.') !== false ? (float) $value : (int) $value;
                }

                $result['overrides'][$key] = $value;
                $i += 2;
                continue;
            }

            $i++;
        }

        return $result;
    }

    /**
     * 驗證配置值
     *
     * @param array $config 配置陣列
     * @return array 驗證錯誤列表（空陣列表示通過）
     */
    public static function validate(array $config)
    {
        $errors = [];

        if (isset($config['memory_reserve_ratio'])) {
            $ratio = $config['memory_reserve_ratio'];
            if ($ratio < 0 || $ratio >= 1) {
                $errors[] = 'memory_reserve_ratio 必須介於 0 和 1 之間';
            }
        }

        if (isset($config['min_worker_memory']) && $config['min_worker_memory'] < 1) {
            $errors[] = 'min_worker_memory 必須大於 0';
        }

        return $errors;
    }
}
