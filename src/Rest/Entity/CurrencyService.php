<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Contract\AddManyOperationInterface;
use B24Rest\Rest\Contract\AddOperationInterface;
use B24Rest\Rest\Contract\AllOperationInterface;
use B24Rest\Rest\Contract\DeleteOperationInterface;
use B24Rest\Rest\Contract\GetByIdOperationInterface;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Rest\Contract\UpdateManyOperationInterface;
use B24Rest\Rest\Contract\UpdateOperationInterface;
use InvalidArgumentException;
use RuntimeException;

class CurrencyService extends AbstractRestService implements
    ListOperationInterface,
    AllOperationInterface,
    GetByIdOperationInterface,
    AddManyOperationInterface,
    AddOperationInterface,
    UpdateManyOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const METHOD_ADD = 'crm.currency.add';
    private const METHOD_UPDATE = 'crm.currency.update';
    private const METHOD_GET = 'crm.currency.get';
    private const METHOD_LIST = 'crm.currency.list';
    private const METHOD_DELETE = 'crm.currency.delete';
    private const METHOD_BASE_GET = 'crm.currency.base.get';
    private const METHOD_BASE_SET = 'crm.currency.base.set';
    private const MAX_ALL_ITERATIONS = 100000;

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-list.html
     */
    public function list(array $params = [], int $page = 1): array
    {
        $this->ensurePositivePage($page);

        $request = $params;
        $order = $this->normalizeUserOrder($request['order'] ?? null);
        $request['order'] = ($order !== []) ? $order : ['currency' => 'ASC'];
        $request['start'] = ($page - 1) * self::PAGE_SIZE;

        $response = $this->call(self::METHOD_LIST, $request);
        $items = $this->normalizeListFromResult($response);
        $total = $this->extractTotalCount($response);
        $next = $this->extractNextOffset($response);
        $totalPages = ($total !== null) ? (int) ceil($total / self::PAGE_SIZE) : null;
        $hasNext = ($next !== null) || ($totalPages !== null && $page < $totalPages);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => self::PAGE_SIZE,
                'total' => $total,
                'totalPages' => $totalPages,
                'hasNext' => $hasNext,
            ],
        ];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-list.html
     * @see https://apidocs.bitrix24.ru/settings/performance/huge-data.html
     */
    public function all(array $params = []): array
    {
        $requestBase = $params;
        unset($requestBase['start'], $requestBase['START']);

        unset($requestBase['order'], $requestBase['ORDER']);
        $requestBase['order'] = ['ID' => 'ASC'];
        if (array_key_exists('select', $requestBase)) {
            $requestBase['select'] = $this->ensureSelectContainsField($requestBase['select'], 'ID');
        }

        $userFilter = is_array($requestBase['filter'] ?? null) ? $requestBase['filter'] : [];
        if ($this->hasIdCursorConflicts($userFilter)) {
            throw new InvalidArgumentException(
                "Method all() manages ID cursor internally. Remove explicit ID-based filter conditions."
            );
        }

        $items = [];
        $lastId = null;
        $iterations = 0;
        while (true) {
            $iterations++;
            if ($iterations > self::MAX_ALL_ITERATIONS) {
                throw new RuntimeException('The all() loop exceeded safe iteration limit.');
            }

            $request = $requestBase;
            $request['start'] = -1;
            $request['filter'] = $userFilter;
            if ($lastId !== null) {
                $request['filter']['>ID'] = $lastId;
            }

            $response = $this->call(self::METHOD_LIST, $request);
            $chunk = $this->normalizeListFromResult($response);
            if ($chunk === []) {
                break;
            }

            $items = array_merge($items, $chunk);
            if (count($chunk) < self::PAGE_SIZE) {
                break;
            }

            $newLastId = $this->extractMaxId($chunk);
            if ($lastId !== null && $newLastId <= $lastId) {
                throw new RuntimeException('The all() cursor did not advance. Stop to avoid infinite loop.');
            }

            $lastId = $newLastId;
        }

        return $items;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-get.html
     */
    public function getById(int|string $id): array
    {
        $response = $this->call(self::METHOD_GET, ['id' => $id]);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-add.html
     */
    public function add(array $fields, array $params = []): array
    {
        $request = $params;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_ADD, $request);
        return $this->normalizeIdResponse($response['result'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{id:string}>
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-add.html
     */
    public function addMany(array $items, array $params = []): array
    {
        if ($items === []) {
            return [];
        }

        $commands = [];
        $commandKeys = [];
        $position = 0;
        foreach ($items as $fields) {
            $position++;
            if (!is_array($fields)) {
                throw new InvalidArgumentException(
                    sprintf('Item at position %d must be an array of fields.', $position)
                );
            }

            $request = $params;
            $request['fields'] = $fields;
            $key = 'currency_add_' . $position;
            $commands[$key] = [
                'method' => self::METHOD_ADD,
                'params' => $request,
            ];
            $commandKeys[] = $key;
        }

        $resultMap = $this->callBatchCommands($commands);
        $result = [];
        foreach ($commandKeys as $key) {
            $result[] = $this->normalizeIdResponse($resultMap[$key] ?? null);
        }

        return $result;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-update.html
     */
    public function update(int|string $id, array $fields, array $params = []): bool
    {
        $request = $params;
        $request['ID'] = (string) $id;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_UPDATE, $request);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @param list<array{id?:int|string,ID?:int|string,fields?:array<string,mixed>,FIELDS?:array<string,mixed>}> $items
     * @return list<bool>
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-update.html
     */
    public function updateMany(array $items, array $params = []): array
    {
        if ($items === []) {
            return [];
        }

        $commands = [];
        $commandKeys = [];
        $position = 0;
        foreach ($items as $item) {
            $position++;
            if (!is_array($item)) {
                throw new InvalidArgumentException(sprintf('Item at position %d must be an array.', $position));
            }

            $id = $this->normalizeBatchEntityId($item['id'] ?? $item['ID'] ?? null);
            if ($id === null) {
                throw new InvalidArgumentException(
                    sprintf('Item at position %d must contain non-empty id/ID.', $position)
                );
            }

            $fields = $item['fields'] ?? $item['FIELDS'] ?? null;
            if (!is_array($fields)) {
                throw new InvalidArgumentException(
                    sprintf('Item at position %d must contain fields/FIELDS array.', $position)
                );
            }

            $request = $params;
            $request['ID'] = (string) $id;
            $request['fields'] = $fields;
            $key = 'currency_update_' . $position;
            $commands[$key] = [
                'method' => self::METHOD_UPDATE,
                'params' => $request,
            ];
            $commandKeys[] = $key;
        }

        $resultMap = $this->callBatchCommands($commands);
        $result = [];
        foreach ($commandKeys as $key) {
            $result[] = $this->normalizeBooleanResult($resultMap[$key] ?? null);
        }

        return $result;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, ['id' => (string) $id]);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-base-get.html
     */
    public function baseGet(): string
    {
        $response = $this->call(self::METHOD_BASE_GET);
        $result = $response['result'] ?? null;
        return is_scalar($result) ? (string) $result : '';
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/currency/crm-currency-base-set.html
     */
    public function baseSet(int|string $id): bool
    {
        $response = $this->call(self::METHOD_BASE_SET, ['id' => (string) $id]);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    private function normalizeIdResponse(mixed $result): array
    {
        if (is_scalar($result) && $result !== '') {
            return ['id' => (string) $result];
        }

        if (is_array($result)) {
            $id = $result['id'] ?? $result['ID'] ?? null;
            if (is_scalar($id) && $id !== '') {
                return ['id' => (string) $id];
            }
        }

        return [];
    }

    private function normalizeBatchEntityId(mixed $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function extractMaxId(array $chunk): int
    {
        $maxId = null;
        foreach ($chunk as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $item['ID'] ?? $item['id'] ?? null;
            $idAsInt = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : 0);
            if ($idAsInt <= 0) {
                continue;
            }

            if ($maxId === null || $idAsInt > $maxId) {
                $maxId = $idAsInt;
            }
        }

        if ($maxId === null) {
            throw new RuntimeException('Unable to extract ID cursor from response chunk.');
        }

        return $maxId;
    }
}
