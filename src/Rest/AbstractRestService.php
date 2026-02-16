<?php

namespace B24Rest\Rest;

use B24Rest\Bridge\Bitrix24Gateway;
use B24Rest\Rest\Exception\Bitrix24RestException;
use InvalidArgumentException;

abstract class AbstractRestService
{
    protected const PAGE_SIZE = 50;

    protected function call(string $method, array $params = []): array
    {
        $response = Bitrix24Gateway::call($method, $params);
        if (!is_array($response)) {
            throw new Bitrix24RestException("Bitrix24 method '{$method}' returned invalid response.");
        }

        if (!empty($response['error'])) {
            $message = (string) ($response['error_description'] ?? $response['error_information'] ?? $response['error']);
            throw new Bitrix24RestException(
                "Bitrix24 method '{$method}' failed: {$message}",
                0,
                $response
            );
        }

        return $response;
    }

    /**
     * @param array<string, array{method:string, params?:array}> $commands
     */
    protected function callBatch(array $commands, int $halt = 0): array
    {
        $response = Bitrix24Gateway::callBatch($commands, $halt);
        if (!is_array($response)) {
            throw new Bitrix24RestException('Bitrix24 batch call returned invalid response.');
        }

        if (!empty($response['error'])) {
            $message = (string) ($response['error_description'] ?? $response['error_information'] ?? $response['error']);
            throw new Bitrix24RestException(
                "Bitrix24 batch call failed: {$message}",
                0,
                $response
            );
        }

        return $response;
    }

    protected function batchCount(): int
    {
        return Bitrix24Gateway::batchCount();
    }

    /**
     * @param array<string, array{method:string, params?:array}> $commands
     * @return array<string, mixed>
     */
    protected function callBatchCommands(array $commands, int $halt = 0): array
    {
        if ($commands === []) {
            return [];
        }

        $resultMap = [];
        foreach (array_chunk($commands, $this->batchCount(), true) as $chunk) {
            $response = $this->callBatch($chunk, $halt);

            $batchResult = $this->extractByPath($response, ['result', 'result'], []);
            $batchErrors = $this->extractByPath($response, ['result', 'result_error'], []);

            if (!is_array($batchResult)) {
                $batchResult = [];
            }

            if (!is_array($batchErrors)) {
                $batchErrors = [];
            }

            if ($batchErrors !== []) {
                throw new Bitrix24RestException('Bitrix24 batch command failed.', 0, ['response' => $response]);
            }

            foreach (array_keys($chunk) as $key) {
                if (array_key_exists($key, $batchResult)) {
                    $resultMap[$key] = $batchResult[$key];
                }
            }
        }

        return $resultMap;
    }

    protected function extractByPath(array $payload, array $path, mixed $default = null): mixed
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    protected function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    protected function normalizeListFromResult(array $response): array
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return [];
        }

        if ($this->isListArray($result)) {
            return $result;
        }

        foreach (['list', 'items', 'categories', 'task_templates'] as $key) {
            if (isset($result[$key]) && is_array($result[$key]) && $this->isListArray($result[$key])) {
                return $result[$key];
            }
        }

        return [];
    }

    protected function ensurePositivePage(int $page): void
    {
        if ($page < 1) {
            throw new InvalidArgumentException('Page must be greater than or equal to 1.');
        }
    }

    protected function normalizeBooleanResult(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value > 0;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'y', 'yes'], true);
        }

        return false;
    }

    protected function ensureSelectContainsId(mixed $select): array
    {
        if (!is_array($select) || $select === []) {
            return ['*', 'UF_*'];
        }

        $normalized = array_values($select);
        $containsId = in_array('ID', $normalized, true) || in_array('*', $normalized, true);
        if (!$containsId) {
            $normalized[] = 'ID';
        }

        return $normalized;
    }

    protected function ensureSelectContainsField(mixed $select, string $field): array
    {
        $field = trim($field);
        if ($field === '') {
            return is_array($select) ? array_values($select) : [];
        }

        if (!is_array($select) || $select === []) {
            return [$field];
        }

        $normalized = array_values($select);
        foreach ($normalized as $item) {
            if (!is_string($item)) {
                continue;
            }

            if (strcasecmp($item, $field) === 0 || $item === '*') {
                return $normalized;
            }
        }

        $normalized[] = $field;
        return $normalized;
    }

    protected function extractNextOffset(array $response): ?int
    {
        $next = $response['next'] ?? null;
        if (is_int($next)) {
            return $next;
        }

        if (is_string($next) && ctype_digit($next)) {
            return (int) $next;
        }

        return null;
    }

    protected function extractTotalCount(array $response): ?int
    {
        $total = $response['total'] ?? null;
        if (is_int($total)) {
            return $total;
        }

        if (is_string($total) && ctype_digit($total)) {
            return (int) $total;
        }

        return null;
    }

    protected function hasIdCursorConflicts(array $filter): bool
    {
        foreach ($filter as $key => $value) {
            if (is_int($key) && is_array($value)) {
                if ($this->hasIdCursorConflicts($value)) {
                    return true;
                }

                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            if (strtoupper($key) === 'LOGIC') {
                continue;
            }

            if (preg_match('/^[!<>=@%~]*ID$/i', $key) === 1) {
                return true;
            }

            if (is_array($value) && $this->hasIdCursorConflicts($value)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeUserOrder(mixed $order): array
    {
        if (!is_array($order) || $order === []) {
            return [];
        }

        $normalized = [];
        foreach ($order as $field => $direction) {
            if (!is_string($field) || trim($field) === '') {
                continue;
            }

            $dir = is_string($direction) ? strtoupper(trim($direction)) : 'ASC';
            if ($dir !== 'ASC' && $dir !== 'DESC') {
                $dir = 'ASC';
            }

            $normalized[trim($field)] = $dir;
        }

        return $normalized;
    }

    protected function hasIdOrderConflict(array $order): bool
    {
        foreach (array_keys($order) as $field) {
            if (!is_string($field)) {
                continue;
            }

            if (preg_match('/^ID$/i', trim($field)) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function sortItemsByOrder(array &$items, array $order): void
    {
        usort($items, static function ($left, $right) use ($order): int {
            $left = is_array($left) ? $left : [];
            $right = is_array($right) ? $right : [];

            foreach ($order as $field => $direction) {
                $leftValue = $left[$field] ?? null;
                $rightValue = $right[$field] ?? null;

                $cmp = self::compareSortValues($leftValue, $rightValue);
                if ($cmp === 0) {
                    continue;
                }

                return ($direction === 'DESC') ? -$cmp : $cmp;
            }

            return 0;
        });
    }

    protected static function compareSortValues(mixed $left, mixed $right): int
    {
        if ($left === $right) {
            return 0;
        }

        if ($left === null) {
            return -1;
        }

        if ($right === null) {
            return 1;
        }

        $leftIsNumeric = is_int($left) || is_float($left) || (is_string($left) && is_numeric($left));
        $rightIsNumeric = is_int($right) || is_float($right) || (is_string($right) && is_numeric($right));

        if ($leftIsNumeric && $rightIsNumeric) {
            return (float) $left <=> (float) $right;
        }

        return strcmp((string) $left, (string) $right);
    }
}
