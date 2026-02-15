<?php

namespace B24Rest\Bridge;

use RuntimeException;

final class Bitrix24Gateway
{
    /** @var null|callable(string, array):array */
    private static $callHandler = null;

    /** @var null|callable(array, int):array */
    private static $callBatchHandler = null;

    /** @var null|callable(string):void */
    private static $useWebhookHandler = null;

    /** @var null|callable():void */
    private static $clearWebhookHandler = null;

    /** @var null|callable(string):void */
    private static $setCurrentBitrix24Handler = null;

    private static ?int $batchCount = null;
    private static bool $isBootstrapped = false;

    public static function configure(
        callable $call,
        ?callable $callBatch = null,
        ?callable $useWebhook = null,
        ?callable $clearWebhook = null,
        ?callable $setCurrentBitrix24 = null,
        ?int $batchCount = null
    ): void {
        self::$callHandler = $call;
        self::$callBatchHandler = $callBatch;
        self::$useWebhookHandler = $useWebhook;
        self::$clearWebhookHandler = $clearWebhook;
        self::$setCurrentBitrix24Handler = $setCurrentBitrix24;
        self::$batchCount = $batchCount;
        self::$isBootstrapped = true;
    }

    public static function call(string $method, array $params = []): array
    {
        self::bootstrapDefaults();
        if (!is_callable(self::$callHandler)) {
            throw new RuntimeException('Bitrix24 call handler is not configured.');
        }

        $response = call_user_func(self::$callHandler, $method, $params);
        return is_array($response) ? $response : [];
    }

    public static function callBatch(array $commands, int $halt = 0): array
    {
        self::bootstrapDefaults();
        if (!is_callable(self::$callBatchHandler)) {
            throw new RuntimeException('Bitrix24 batch handler is not configured.');
        }

        $response = call_user_func(self::$callBatchHandler, $commands, $halt);
        return is_array($response) ? $response : [];
    }

    public static function useWebhook(string $webhookUrl): void
    {
        self::bootstrapDefaults();
        if (!is_callable(self::$useWebhookHandler)) {
            throw new RuntimeException('Bitrix24 webhook handler is not configured.');
        }

        call_user_func(self::$useWebhookHandler, $webhookUrl);
    }

    public static function clearWebhook(): void
    {
        self::bootstrapDefaults();
        if (!is_callable(self::$clearWebhookHandler)) {
            throw new RuntimeException('Bitrix24 clear-webhook handler is not configured.');
        }

        call_user_func(self::$clearWebhookHandler);
    }

    public static function setCurrentBitrix24(string $memberId): void
    {
        self::bootstrapDefaults();
        if (!is_callable(self::$setCurrentBitrix24Handler)) {
            throw new RuntimeException(
                'Bitrix24 context handler is not configured. Configure gateway via Bitrix24Gateway::configure(...).'
            );
        }

        call_user_func(self::$setCurrentBitrix24Handler, $memberId);
    }

    public static function batchCount(): int
    {
        self::bootstrapDefaults();
        return (int) (self::$batchCount ?? 50);
    }

    private static function bootstrapDefaults(): void
    {
        if (self::$isBootstrapped) {
            return;
        }

        // Library default: autonomous webhook transport without framework dependencies.
        self::configure(
            [SimpleWebhookTransport::class, 'call'],
            [SimpleWebhookTransport::class, 'callBatch'],
            [SimpleWebhookTransport::class, 'useWebhook'],
            [SimpleWebhookTransport::class, 'clearWebhook'],
            null,
            50
        );
    }
}
