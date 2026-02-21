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

class MeasureService extends AbstractRestService implements
    ListOperationInterface,
    AllOperationInterface,
    GetByIdOperationInterface,
    AddManyOperationInterface,
    AddOperationInterface,
    UpdateManyOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const METHOD_ADD = 'catalog.measure.add';
    private const METHOD_UPDATE = 'catalog.measure.update';
    private const METHOD_GET = 'catalog.measure.get';
    private const METHOD_LIST = 'catalog.measure.list';
    private const METHOD_DELETE = 'catalog.measure.delete';
    private const METHOD_GET_FIELDS = 'catalog.measure.getFields';
    private const MAX_ALL_ITERATIONS = 100000;

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/measure/catalog-measure-list.html
     */
    public function list(array $params = [], int $page = 1): array
    {
        $this->ensurePositivePage($page);

        $request = $params;
        if (!isset($request['order']) || !is_array($request['order']) || $request['order'] === []) {
            $request['order'] = ['id' => 'ASC'];
        }

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
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/measure/catalog-measure-list.html
     * @see https://apidocs.bitrix24.ru/settings/performance/huge-data.html
     */
    public function all(array $params = []): array
    {
        $requestBase = $params;
        unset($requestBase['start'], $requestBase['START']);

        unset($requestBase['order'], $requestBase['ORDER']);
        $requestBase['order'] = ['id' => 'ASC'];
        if (array_key_exists('select', $requestBase)) {
            $requestBase['select'] = $this->ensureSelectContainsField($requestBase['select'], 'id');
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
                $request['filter']['>id'] = $lastId;
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
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/measure/catalog-measure-get.html
     */
    public function getById(int|string $id): array
    {
        $response = $this->call(self::METHOD_GET, ['id' => $id]);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/measure/catalog-measure-add.html
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
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/measure/catalog-measure-add.html
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
            $key = 'measure_add_' . $position;
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
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/measure/catalog-measure-update.html
     */
    public function update(int|string $id, array $fields, array $params = []): bool
    {
        $request = $params;
        $request['id'] = $id;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_UPDATE, $request);
        return $this->normalizeUpdateResult($response['result'] ?? null);
    }

    /**
     * @param list<array{id?:int|string,ID?:int|string,fields?:array<string,mixed>,FIELDS?:array<string,mixed>}> $items
     * @return list<bool>
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/measure/catalog-measure-update.html
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
            $request['id'] = $id;
            $request['fields'] = $fields;
            $key = 'measure_update_' . $position;
            $commands[$key] = [
                'method' => self::METHOD_UPDATE,
                'params' => $request,
            ];
            $commandKeys[] = $key;
        }

        $resultMap = $this->callBatchCommands($commands);
        $result = [];
        foreach ($commandKeys as $key) {
            $result[] = $this->normalizeUpdateResult($resultMap[$key] ?? null);
        }

        return $result;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/measure/catalog-measure-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, ['id' => $id]);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/measure/catalog-measure-get-fields.html
     */
    public function getFields(): array
    {
        $response = $this->call(self::METHOD_GET_FIELDS);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
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

    private function normalizeUpdateResult(mixed $result): bool
    {
        if (is_array($result)) {
            return true;
        }

        return $this->normalizeBooleanResult($result);
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

            $id = $item['id'] ?? $item['ID'] ?? null;
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
