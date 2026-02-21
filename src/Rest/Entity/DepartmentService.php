<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Contract\AddOperationInterface;
use B24Rest\Rest\Contract\AllOperationInterface;
use B24Rest\Rest\Contract\DeleteOperationInterface;
use B24Rest\Rest\Contract\GetByIdOperationInterface;
use B24Rest\Rest\Contract\UpdateOperationInterface;
use RuntimeException;

class DepartmentService extends AbstractRestService implements
    AllOperationInterface,
    GetByIdOperationInterface,
    AddOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const METHOD_GET = 'department.get';
    private const METHOD_ADD = 'department.add';
    private const METHOD_UPDATE = 'department.update';
    private const METHOD_DELETE = 'department.delete';
    private const METHOD_USER_GET = 'user.get';
    private const MAX_ALL_ITERATIONS = 100000;

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/departments/department-get.html
     * @see https://apidocs.bitrix24.ru/settings/performance/huge-data.html
     */
    public function all(array $params = []): array
    {
        $requestBase = $params;
        unset($requestBase['ID'], $requestBase['id'], $requestBase['start'], $requestBase['order'], $requestBase['ORDER']);
        $requestBase['order'] = ['ID' => 'ASC'];

        $items = [];
        $start = 0;
        $iterations = 0;
        while (true) {
            $iterations++;
            if ($iterations > self::MAX_ALL_ITERATIONS) {
                throw new RuntimeException('The all() loop exceeded safe iteration limit.');
            }

            $request = $requestBase;
            $request['start'] = $start;
            $response = $this->call(self::METHOD_GET, $request);
            $chunk = $this->normalizeListFromResult($response);
            if ($chunk !== []) {
                $items = array_merge($items, $chunk);
            }

            $next = $response['next'] ?? null;
            if (is_string($next) && ctype_digit($next)) {
                $next = (int) $next;
            }

            if (!is_int($next) || $next <= $start) {
                break;
            }

            $start = $next;
        }

        return $items;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/departments/department-get.html
     */
    public function getById(int|string $id): array
    {
        $response = $this->call(self::METHOD_GET, ['ID' => $id]);
        $items = $this->normalizeListFromResult($response);
        $first = $items[0] ?? null;

        return is_array($first) ? $first : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/departments/department-add.html
     */
    public function add(array $fields, array $params = []): array
    {
        $request = $params;
        foreach ($fields as $key => $value) {
            $request[$key] = $value;
        }

        $response = $this->call(self::METHOD_ADD, $request);
        $result = $response['result'] ?? null;
        if (is_scalar($result) && $result !== '') {
            return ['id' => (string) $result];
        }

        if (is_array($result)) {
            $id = $result['ID'] ?? $result['id'] ?? null;
            if (is_scalar($id) && $id !== '') {
                return ['id' => (string) $id];
            }
        }

        return [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/departments/department-update.html
     */
    public function update(int|string $id, array $fields, array $params = []): bool
    {
        $request = $params;
        foreach ($fields as $key => $value) {
            $request[$key] = $value;
        }

        $request['ID'] = $id;
        $response = $this->call(self::METHOD_UPDATE, $request);

        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/departments/department-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, ['ID' => $id]);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/user/user-get.html
     */
    public function getUsersById(int|string $id, array $params = []): array
    {
        $requestBase = $params;
        unset($requestBase['start']);

        $requestBase['FILTER'] = is_array($requestBase['FILTER'] ?? null) ? $requestBase['FILTER'] : [];
        $requestBase['FILTER']['UF_DEPARTMENT'] = $id;

        if (!isset($requestBase['SORT']) && !isset($requestBase['sort'])) {
            $requestBase['SORT'] = 'ID';
        }

        if (!isset($requestBase['ORDER']) && !isset($requestBase['order'])) {
            $requestBase['ORDER'] = 'ASC';
        }

        $items = [];
        $start = 0;
        $iterations = 0;
        while (true) {
            $iterations++;
            if ($iterations > self::MAX_ALL_ITERATIONS) {
                throw new RuntimeException('The getUsersById() loop exceeded safe iteration limit.');
            }

            $request = $requestBase;
            $request['start'] = $start;
            $response = $this->call(self::METHOD_USER_GET, $request);
            $chunk = $this->normalizeListFromResult($response);
            if ($chunk !== []) {
                $items = array_merge($items, $chunk);
            }

            $next = $response['next'] ?? null;
            if (is_string($next) && ctype_digit($next)) {
                $next = (int) $next;
            }

            if (!is_int($next) || $next <= $start) {
                break;
            }

            $start = $next;
        }

        return $items;
    }
}
