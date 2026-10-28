<?php

namespace App\Services\Acceleration;

use Symfony\Component\Process\Process;

class ActiveConnectionProbe
{
    public function exitIp(array $proxy, float $timeout): ?string
    {
        $binary = config('acceleration.probe_binary');
        if (!is_string($binary) || !is_executable($binary) || !extension_loaded('curl')) return null;
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if (!$listener) return null;
        $address = stream_socket_get_name($listener, false);
        $port = (int) substr(strrchr($address, ':'), 1);
        fclose($listener);
        $directory = sys_get_temp_dir() . '/accel-probe-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0700)) return null;
        $process = null;
        $curl = null;
        $deadline = microtime(true) + $timeout;
        try {
            $proxy['name'] = 'accel-probe';
            $config = ['mixed-port' => $port, 'bind-address' => '127.0.0.1', 'allow-lan' => false,
                'mode' => 'rule', 'log-level' => 'silent', 'ipv6' => false,
                'proxies' => [$proxy], 'rules' => ['MATCH,accel-probe']];
            $file = $directory . '/config.json';
            file_put_contents($file, json_encode($config, JSON_THROW_ON_ERROR));
            chmod($file, 0600);
            $process = new Process([$binary, '-d', $directory, '-f', $file]);
            $process->disableOutput();
            $process->start();
            do {
                $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $message, 0.05);
                if ($socket) { fclose($socket); break; }
                if (!$process->isRunning()) return null;
                usleep(50000);
            } while (microtime(true) < $deadline);
            $remaining = $deadline - microtime(true);
            if (!$socket || $remaining <= 0) return null;
            // A fixed HTTPS target with certificate verification prevents response spoofing.
            // Random query and no-cache force a new request; no caller-controlled URL is accepted.
            $curl = curl_init('https://api.ipify.org?nonce=' . bin2hex(random_bytes(16)));
            $body = '';
            curl_setopt_array($curl, [CURLOPT_PROXY => '127.0.0.1:' . $port, CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
                CURLOPT_NOPROXY => '', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT_MS => max(1, (int) ($remaining * 1000)), CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ($remaining * 1000)),
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$body) {
                    if (strlen($body) + strlen($data) > 64) return 0;
                    $body .= $data;
                    return strlen($data);
                },
            ]);
            $success = curl_exec($curl);
            return $success !== false && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200
                && filter_var(trim($body), FILTER_VALIDATE_IP) ? trim($body) : null;
        } finally {
            if ($curl) curl_close($curl);
            if ($process?->isRunning()) $process->stop(0.2);
            foreach (['config.json', 'cache.db'] as $name) if (is_file($directory . '/' . $name)) unlink($directory . '/' . $name);
            @rmdir($directory);
        }
    }
}
