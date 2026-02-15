<?php

namespace B24Rest\Bridge;

final class SimpleWebhookTransport
{
    private static ?string $webhookUrl = null;

    public static function useWebhook(string $webhookUrl): void
    {
        $webhookUrl = trim($webhookUrl);
        self::$webhookUrl = ($webhookUrl === '') ? null : (rtrim($webhookUrl, '/') . '/');
    }

    public static function clearWebhook(): void
    {
        self::$webhookUrl = null;
    }

    public static function call(string $method, array $params = []): array
    {
        if (self::$webhookUrl === null) {
            return [
                'error' => 'webhook_not_configured',
                'error_description' => 'Webhook URL is not configured.',
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'error' => 'error_php_lib_curl',
                'error_description' => 'Need install curl lib.',
            ];
        }

        $url = self::$webhookUrl . ltrim($method, '/') . '.json';
        $postFields = http_build_query($params);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POSTREDIR, 10);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        if ($postFields !== '') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
        }

        $out = curl_exec($curl);
        if ($out === false) {
            $error = curl_error($curl);
            curl_close($curl);

            return [
                'error' => 'curl_error',
                'error_description' => $error,
            ];
        }

        curl_close($curl);

        $decoded = json_decode($out, true);
        if (!is_array($decoded)) {
            return [
                'error' => 'invalid_json',
                'error_description' => 'Bitrix24 returned non-JSON response.',
            ];
        }

        return $decoded;
    }

    public static function callBatch(array $commands, int $halt = 0): array
    {
        $batchCommand = [];
        foreach ($commands as $key => $command) {
            if (!is_array($command) || !isset($command['method']) || !is_string($command['method'])) {
                continue;
            }

            $method = $command['method'];
            $params = (isset($command['params']) && is_array($command['params'])) ? $command['params'] : [];
            $query = http_build_query($params);
            $batchCommand[$key] = ($query === '') ? $method : ($method . '?' . $query);
        }

        return self::call('batch', [
            'halt' => $halt,
            'cmd' => $batchCommand,
        ]);
    }
}
