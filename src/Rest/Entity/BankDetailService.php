<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Contract\AddOperationInterface;
use B24Rest\Rest\Contract\DeleteOperationInterface;
use B24Rest\Rest\Contract\GetByIdOperationInterface;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Rest\Contract\UpdateOperationInterface;

class BankDetailService extends AbstractRestService implements
    ListOperationInterface,
    GetByIdOperationInterface,
    AddOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
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
}

