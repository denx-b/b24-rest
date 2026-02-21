<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Support\CrmEntity;
use B24Rest\Support\Str;
use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Contract\AddOperationInterface;
use B24Rest\Rest\Contract\DeleteOperationInterface;
use B24Rest\Rest\Contract\GetByIdOperationInterface;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Rest\Contract\UpdateOperationInterface;
use InvalidArgumentException;

class DealCategoryStageService extends AbstractRestService implements
    ListOperationInterface,
    GetByIdOperationInterface,
    AddOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const METHOD_LIST = 'crm.status.list';
    private const METHOD_GET = 'crm.status.get';
    private const METHOD_ADD = 'crm.status.add';
    private const METHOD_UPDATE = 'crm.status.update';
    private const METHOD_DELETE = 'crm.status.delete';

    /**
     * Список стадий воронки
     * @see https://apidocs.bitrix24.ru/api-reference/crm/status/crm-status-list.html
     */
    public function list(array $params = [], int $page = 1): array
    {
        $request = $params;
        if (!isset($request['order']) || !is_array($request['order']) || $request['order'] === []) {
            $request['order'] = ['ID' => 'ASC'];
        }

        $response = $this->call(self::METHOD_LIST, $request);
        return $this->normalizeStageList($this->normalizeListFromResult($response));
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/status/crm-status-list.html
     */
    public function listByCategoryId(int|string $categoryId, array $params = []): array
    {
        $entityId = $this->buildEntityIdByCategoryId($categoryId);
        $request = $params;
        $filter = is_array($request['filter'] ?? null) ? $request['filter'] : [];
        $currentEntity = isset($filter['ENTITY_ID']) ? (string) $filter['ENTITY_ID'] : '';
        if ($currentEntity !== '' && strtoupper($currentEntity) !== strtoupper($entityId)) {
            throw new InvalidArgumentException('ENTITY_ID conflict in filter.');
        }

        $filter['ENTITY_ID'] = $entityId;
        $request['filter'] = $filter;

        return $this->list($request);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/status/crm-status-get.html
     */
    public function getById(int|string $id): array
    {
        $response = $this->call(self::METHOD_GET, ['id' => $id]);
        $result = $response['result'] ?? null;

        return is_array($result) ? $result : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/status/crm-status-add.html
     */
    public function add(array $fields, array $params = []): array
    {
        $request = $params;
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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/status/crm-status-add.html
     */
    public function addForCategory(int|string $categoryId, array $fields, array $params = []): array
    {
        return $this->add($this->applyCategoryEntity($categoryId, $fields), $params);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/status/crm-status-add.html
     */
    public function addForCategoryWithGeneratedStatusId(
        int|string $categoryId,
        string $name,
        string $statusId = '',
        array $params = []
    ): array {
        $normalizedName = Str::filterString($name);
        if ($normalizedName === '') {
            $normalizedName = 'Новая стадия';
        }

        return $this->addForCategory($categoryId, [
            'STATUS_ID' => $this->resolveStatusId($statusId),
            'NAME' => $normalizedName,
        ], $params);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/status/crm-status-update.html
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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/status/crm-status-update.html
     */
    public function updateForCategory(int|string $categoryId, int|string $id, array $fields, array $params = []): bool
    {
        return $this->update($id, $this->applyCategoryEntity($categoryId, $fields), $params);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/status/crm-status-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, ['id' => $id]);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    private function normalizeStageList(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!isset($item['STAGE_ID']) && isset($item['STATUS_ID'])) {
                $item['STAGE_ID'] = (string) $item['STATUS_ID'];
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function applyCategoryEntity(int|string $categoryId, array $fields): array
    {
        $entityId = $this->buildEntityIdByCategoryId($categoryId);
        $currentEntity = isset($fields['ENTITY_ID']) ? (string) $fields['ENTITY_ID'] : '';
        if ($currentEntity !== '' && strtoupper($currentEntity) !== strtoupper($entityId)) {
            throw new InvalidArgumentException('ENTITY_ID in fields conflicts with selected category.');
        }

        $fields['ENTITY_ID'] = $entityId;
        return $fields;
    }

    private function resolveStatusId(string $statusId): string
    {
        $normalized = $this->normalizeStageStatusId($statusId);
        if ($normalized !== '') {
            return $normalized;
        }

        return $this->generateStageStatusId();
    }

    private function normalizeStageStatusId(string $value): string
    {
        $value = strtoupper(Str::filterString($value));
        if ($value === '') {
            return '';
        }

        $normalized = preg_replace('/[^A-Z0-9_]+/', '_', $value);
        if (!is_string($normalized)) {
            return '';
        }

        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            return '';
        }

        return $normalized;
    }

    private function generateStageStatusId(): string
    {
        return 'UC_' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }

    private function buildEntityIdByCategoryId(int|string $categoryId): string
    {
        $normalized = $this->normalizeCategoryId($categoryId);
        return CrmEntity::dealStageEntityIdByCategoryId($normalized);
    }

    private function normalizeCategoryId(int|string $categoryId): int
    {
        if (is_int($categoryId)) {
            if ($categoryId < 0) {
                throw new InvalidArgumentException('Category ID must be greater than or equal to 0.');
            }

            return $categoryId;
        }

        $value = trim($categoryId);
        if ($value === '' || !ctype_digit($value)) {
            throw new InvalidArgumentException('Category ID must be an integer string.');
        }

        return (int) $value;
    }
}
