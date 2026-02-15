<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Contract\AddOperationInterface;
use B24Rest\Rest\Contract\AllOperationInterface;
use B24Rest\Rest\Contract\DeleteOperationInterface;
use B24Rest\Rest\Contract\GetByIdOperationInterface;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Rest\Contract\UpdateOperationInterface;
use InvalidArgumentException;
use RuntimeException;

class PriceTypeService extends AbstractRestService implements
    ListOperationInterface,
    AllOperationInterface,
    GetByIdOperationInterface,
    AddOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const METHOD_ADD = 'catalog.priceType.add';
    private const METHOD_UPDATE = 'catalog.priceType.update';
    private const METHOD_GET = 'catalog.priceType.get';
    private const METHOD_LIST = 'catalog.priceType.list';
    private const METHOD_DELETE = 'catalog.priceType.delete';
    private const METHOD_GET_FIELDS = 'catalog.priceType.getFields';
    private const MAX_ALL_ITERATIONS = 100000;

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/price-type/catalog-price-type-list.html
     */
    public function list(array $params = [], int $page = 1): array
    {
        $this->ensurePositivePage($page);

        $request = $params;
        if (!isset($request['order']) || !is_array($request['order']) || $request['order'] === []) {
            $request['order'] = ['id' => 'DESC'];
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
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/price-type/catalog-price-type-list.html
     * @see https://apidocs.bitrix24.ru/settings/performance/huge-data.html
     */
    public function all(array $params = []): array
    {
        $requestBase = $params;
        unset($requestBase['start'], $requestBase['START']);

        $userOrder = $this->normalizeUserOrder($requestBase['order'] ?? null);
        if ($this->hasIdOrderConflict($userOrder)) {
            throw new InvalidArgumentException(
                "Method all() uses internal ID cursor. Remove id/ID from order."
            );
        }

        $requestBase['order'] = ['id' => 'DESC'];
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
                $request['filter']['<id'] = $lastId;
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

            $newLastId = $this->extractMinId($chunk);
            if ($lastId !== null && $newLastId >= $lastId) {
                throw new RuntimeException('The all() cursor did not advance. Stop to avoid infinite loop.');
            }

            $lastId = $newLastId;
        }

        if ($userOrder !== []) {
            $this->sortItemsByOrder($items, $userOrder);
        }

        return $items;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/price-type/catalog-price-type-get.html
     */
    public function getById(int|string $id): array
    {
        $response = $this->call(self::METHOD_GET, ['id' => $id]);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/price-type/catalog-price-type-add.html
     */
    public function add(array $fields, array $params = []): array
    {
        $request = $params;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_ADD, $request);
        return $this->normalizeIdResponse($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/price-type/catalog-price-type-update.html
     */
    public function update(int|string $id, array $fields, array $params = []): bool
    {
        $request = $params;
        $request['id'] = $id;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_UPDATE, $request);
        if (is_array($response['result'] ?? null)) {
            return true;
        }

        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/price-type/catalog-price-type-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, ['id' => $id]);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/catalog/price-type/catalog-price-type-get-fields.html
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

    private function extractMinId(array $chunk): int
    {
        $minId = null;
        foreach ($chunk as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? $item['ID'] ?? null;
            $idAsInt = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int) $id : 0);
            if ($idAsInt <= 0) {
                continue;
            }

            if ($minId === null || $idAsInt < $minId) {
                $minId = $idAsInt;
            }
        }

        if ($minId === null) {
            throw new RuntimeException('Unable to extract ID cursor from response chunk.');
        }

        return $minId;
    }
}
