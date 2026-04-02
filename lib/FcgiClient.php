<?php

/**
 * 最小 FCGI 協議客戶端
 *
 * 透過 Unix socket 或 TCP 直連 PHP-FPM 取得 status 資訊
 * 實作最小子集的 FastCGI 協議，僅支援 GET 請求
 */
class FcgiClient
{
    const VERSION = 1;
    const FCGI_BEGIN_REQUEST = 1;
    const FCGI_END_REQUEST = 3;
    const FCGI_PARAMS = 4;
    const FCGI_STDIN = 5;
    const FCGI_STDOUT = 6;
    const FCGI_STDERR = 7;
    const FCGI_RESPONDER = 1;

    /**
     * 透過 FCGI 協議取得 PHP-FPM status
     *
     * @param string $address Unix socket 路徑或 host:port
     * @param string $statusPath status 端點路徑
     * @return array|null 解析後的 JSON 陣列或 null
     */
    public static function getStatus($address, $statusPath = '/fpm-status')
    {
        if (strpos($address, '/') === 0) {
            $uri = 'unix://' . $address;
        } elseif (strpos($address, ':') !== false) {
            $uri = 'tcp://' . $address;
        } else {
            return null;
        }

        $socket = @stream_socket_client($uri, $errno, $errstr, 5);
        if (!$socket) {
            fwrite(STDERR, "FCGI 連線失敗：{$errstr} ({$errno})\n");
            return null;
        }

        stream_set_timeout($socket, 5);

        $requestId = 1;

        // BEGIN_REQUEST body: role (2 bytes BE) + flags (1 byte) + reserved (5 bytes) = 8 bytes
        $body = pack('nCx5', self::FCGI_RESPONDER, 0);
        fwrite($socket, self::buildRecord(self::FCGI_BEGIN_REQUEST, $body, $requestId));

        $params = [
            'SCRIPT_NAME' => $statusPath,
            'SCRIPT_FILENAME' => $statusPath,
            'QUERY_STRING' => 'json',
            'REQUEST_METHOD' => 'GET',
            'SERVER_SOFTWARE' => 'php-fpm-tuner',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
        ];
        fwrite($socket, self::buildParamsRecord($params, $requestId));
        fwrite($socket, self::buildRecord(self::FCGI_PARAMS, '', $requestId));

        fwrite($socket, self::buildRecord(self::FCGI_STDIN, '', $requestId));

        $stdout = '';
        while (true) {
            $header = self::readBytes($socket, 8);
            if (strlen($header) < 8) {
                break;
            }

            $record = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $header);

            $content = '';
            if ($record['contentLength'] > 0) {
                $content = self::readBytes($socket, $record['contentLength']);
            }
            if ($record['paddingLength'] > 0) {
                self::readBytes($socket, $record['paddingLength']);
            }

            if ($record['type'] === self::FCGI_STDOUT) {
                $stdout .= $content;
            } elseif ($record['type'] === self::FCGI_END_REQUEST) {
                break;
            }
        }

        fclose($socket);

        // 剝除 FCGI 回應中的 HTTP headers，只取 JSON body
        $parts = preg_split('~\r?\n\r?\n~', $stdout, 2);
        if (count($parts) < 2) {
            return null;
        }

        $json = json_decode(trim($parts[1]), true);
        return is_array($json) ? $json : null;
    }

    /**
     * 建構 FCGI 記錄
     *
     * @param int $type 記錄類型
     * @param string $content 內容
     * @param int $requestId 請求 ID
     * @return string 二進位記錄
     */
    private static function buildRecord($type, $content, $requestId = 1)
    {
        $contentLength = strlen($content);
        $paddingLength = (8 - ($contentLength % 8)) % 8;

        $header = pack('CCnnCC',
            self::VERSION,
            $type,
            $requestId,
            $contentLength,
            $paddingLength,
            0
        );

        return $header . $content . str_repeat("\0", $paddingLength);
    }

    /**
     * 建構 FCGI PARAMS 記錄
     *
     * @param array $params 參數鍵值對
     * @param int $requestId 請求 ID
     * @return string 二進位記錄
     */
    private static function buildParamsRecord(array $params, $requestId = 1)
    {
        $body = '';
        foreach ($params as $name => $value) {
            $nameLen = strlen($name);
            $valueLen = strlen($value);

            $body .= self::encodeLength($nameLen);
            $body .= self::encodeLength($valueLen);
            $body .= $name;
            $body .= $value;
        }

        return self::buildRecord(self::FCGI_PARAMS, $body, $requestId);
    }

    /**
     * 從 socket 讀取精確 N bytes（處理部分讀取）
     *
     * @param resource $socket
     * @param int $length
     * @return string
     */
    private static function readBytes($socket, $length)
    {
        $buf = '';
        while (strlen($buf) < $length) {
            $chunk = fread($socket, $length - strlen($buf));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * 編碼 FCGI 長度欄位
     *
     * @param int $length
     * @return string
     */
    private static function encodeLength($length)
    {
        if ($length < 128) {
            return chr($length);
        }
        return pack('N', $length | 0x80000000);
    }
}
