<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Bitrix24RestFactory;
use B24Rest\Rest\Contract\AddOperationInterface;
use B24Rest\Rest\Contract\DeleteOperationInterface;
use B24Rest\Rest\Contract\GetByIdOperationInterface;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Rest\Contract\UpdateOperationInterface;
use InvalidArgumentException;
use RuntimeException;

class BankDetailService extends AbstractRestService implements
    ListOperationInterface,
    GetByIdOperationInterface,
    AddOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const MAX_LIST_ITERATIONS = 100000;
    private const METHOD_ADD = 'crm.requisite.bankdetail.add';
    private const METHOD_UPDATE = 'crm.requisite.bankdetail.update';
    private const METHOD_GET = 'crm.requisite.bankdetail.get';
    private const METHOD_LIST = 'crm.requisite.bankdetail.list';
    private const METHOD_DELETE = 'crm.requisite.bankdetail.delete';
    private const METHOD_FIELDS = 'crm.requisite.bankdetail.fields';

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/bank-detail/crm-requisite-bank-detail-list.html
     */
    public function list(array $params = [], int $page = 1): array
    {
        $this->ensurePositivePage($page);

        $request = $params;
        if (!isset($request['order']) || !is_array($request['order']) || $request['order'] === []) {
            $request['order'] = ['ID' => 'ASC'];
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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/bank-detail/crm-requisite-bank-detail-add.html
     */
    public function add(array $fields, array $params = []): array
    {
        $request = $params;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_ADD, $request);
        $result = $response['result'] ?? null;

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

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/bank-detail/crm-requisite-bank-detail-update.html
     */
    public function update(int|string $id, array $fields, array $params = []): bool
    {
        $request = $params;
        $request['id'] = $id;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_UPDATE, $request);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/bank-detail/crm-requisite-bank-detail-get.html
     */
    public function getById(int|string $id): array
    {
        $response = $this->call(self::METHOD_GET, ['id' => $id]);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/bank-detail/crm-requisite-bank-detail-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, ['id' => $id]);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/bank-detail/crm-requisite-bank-detail-fields.html
     */
    public function getFields(): array
    {
        $response = $this->call(self::METHOD_FIELDS);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * Возвращает все банковские реквизиты компании.
     */
    public function listByCompanyId(int|string $companyId, array $params = []): array
    {
        $requisiteService = (new Bitrix24RestFactory())->requisites();
        $requisiteIds = $requisiteService->getCompanyRequisiteIds($companyId);
        if ($requisiteIds === []) {
            return [];
        }

        $items = [];
        foreach ($requisiteIds as $requisiteId) {
            $chunk = $this->fetchAllByRequisiteId($requisiteId, $params);
            if ($chunk !== []) {
                $items = array_merge($items, $chunk);
            }
        }

        $order = $this->normalizeUserOrder($params['order'] ?? null);
        if ($order === []) {
            $order = ['ID' => 'ASC'];
        }

        $this->sortItemsByOrder($items, $order);
        return $items;
    }

    private function fetchAllByRequisiteId(int $requisiteId, array $params = []): array
    {
        $request = $params;
        unset($request['start'], $request['START']);

        $filter = is_array($request['filter'] ?? null) ? $request['filter'] : [];
        if (isset($request['FILTER']) && is_array($request['FILTER'])) {
            $filter = array_merge($request['FILTER'], $filter);
        }
        unset($request['FILTER']);

        $currentEntityId = $this->toPositiveInt($filter['ENTITY_ID'] ?? null);
        if ($currentEntityId !== null && $currentEntityId !== $requisiteId) {
            throw new InvalidArgumentException('BankDetail filter ENTITY_ID conflicts with target requisite ID.');
        }

        $filter['ENTITY_ID'] = $requisiteId;
        $request['filter'] = $filter;

        $page = 1;
        $iterations = 0;
        $items = [];

        while (true) {
            $iterations++;
            if ($iterations > self::MAX_LIST_ITERATIONS) {
                throw new RuntimeException('The listByCompanyId() loop exceeded safe iteration limit.');
            }

            $pageResult = $this->list($request, $page);
            $chunk = is_array($pageResult['items'] ?? null) ? $pageResult['items'] : [];
            if ($chunk !== []) {
                $items = array_merge($items, $chunk);
            }

            $hasNext = (bool) ($pageResult['pagination']['hasNext'] ?? false);
            if (!$hasNext) {
                break;
            }

            $page++;
        }

        return $items;
    }

    private function toPositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
            return ($parsed > 0) ? $parsed : null;
        }

        return null;
    }
}
