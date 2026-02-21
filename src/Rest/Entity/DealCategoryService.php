<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Support\CrmEntity;
use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Contract\AddOperationInterface;
use B24Rest\Rest\Contract\DeleteOperationInterface;
use B24Rest\Rest\Contract\GetByIdOperationInterface;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Rest\Contract\UpdateOperationInterface;
use InvalidArgumentException;

class DealCategoryService extends AbstractRestService implements
    ListOperationInterface,
    GetByIdOperationInterface,
    AddOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const METHOD_LIST = 'crm.category.list';
    private const METHOD_GET = 'crm.category.get';
    private const METHOD_ADD = 'crm.category.add';
    private const METHOD_UPDATE = 'crm.category.update';
    private const METHOD_DELETE = 'crm.category.delete';

    /**
     * Список воронок сделок
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/category/crm-category-list.html
     */
    public function list(array $params = [], int $page = 1): array
    {
        $request = $this->withDealEntityTypeId($params);
        if (!isset($request['order']) || !is_array($request['order']) || $request['order'] === []) {
            $request['order'] = ['ID' => 'ASC'];
        }

        $response = $this->call(self::METHOD_LIST, $request);
        return $this->normalizeListFromResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/category/crm-category-get.html
     */
    public function getById(int|string $id): array
    {
        $response = $this->call(self::METHOD_GET, $this->withDealEntityTypeId(['id' => $id]));
        $result = $response['result'] ?? null;

        return is_array($result) ? $result : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/category/crm-category-add.html
     */
    public function add(array $fields, array $params = []): array
    {
        $request = $this->withDealEntityTypeId($params);
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_ADD, $request);
        $result = $response['result'] ?? null;

        if (is_array($result)) {
            return $result;
        }

        if (is_scalar($result)) {
            return ['id' => (string) $result];
        }

        return [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/category/crm-category-update.html
     */
    public function update(int|string $id, array $fields, array $params = []): bool
    {
        $request = $this->withDealEntityTypeId($params);
        $request['id'] = $id;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_UPDATE, $request);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/category/crm-category-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, $this->withDealEntityTypeId(['id' => $id]));
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    private function withDealEntityTypeId(array $request): array
    {
        if (array_key_exists('entityTypeId', $request) && (int) $request['entityTypeId'] !== CrmEntity::TYPE_DEAL) {
            throw new InvalidArgumentException(
                'DealCategoryService supports only deal pipelines. entityTypeId must be TYPE_DEAL (2).'
            );
        }

        $request['entityTypeId'] = CrmEntity::TYPE_DEAL;
        return $request;
    }
}
