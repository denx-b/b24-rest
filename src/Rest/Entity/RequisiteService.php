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

class RequisiteService extends AbstractRestService implements
    ListOperationInterface,
    GetByIdOperationInterface,
    AddOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const COMPANY_ENTITY_TYPE_ID = 4;
    private const MAX_LIST_ITERATIONS = 100000;
    private const METHOD_ADD = 'crm.requisite.add';
    private const METHOD_UPDATE = 'crm.requisite.update';
    private const METHOD_GET = 'crm.requisite.get';
    private const METHOD_LIST = 'crm.requisite.list';
    private const METHOD_DELETE = 'crm.requisite.delete';
    private const METHOD_FIELDS = 'crm.requisite.fields';

    /** @var array<string, string> */
    private static array $cacheLabels = [];
    /** @var array<string, list<int>> */
    private static array $cacheCompanyRequisiteIds = [];

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/universal/crm-requisite-list.html
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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/universal/crm-requisite-add.html
     */
    public function add(array $fields, array $params = []): array
    {
        $request = $params;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_ADD, $request);
        $result = $response['result'] ?? null;
        $this->invalidateCompanyCacheByFields($fields);

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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/universal/crm-requisite-update.html
     */
    public function update(int|string $id, array $fields, array $params = []): bool
    {
        $request = $params;
        $request['id'] = $id;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_UPDATE, $request);
        $this->clearCompanyIdsCache();
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/universal/crm-requisite-get.html
     */
    public function getById(int|string $id): array
    {
        $response = $this->call(self::METHOD_GET, ['id' => $id]);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/universal/crm-requisite-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, ['id' => $id]);
        $this->clearCompanyIdsCache();
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/universal/crm-requisite-fields.html
     */
    public function getFields(): array
    {
        $response = $this->call(self::METHOD_FIELDS);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * Возвращает подписи полей реквизита (listLabel/formLabel/title) с кешем в рамках процесса.
     * Удобно для быстрого построения UI-форм без ручного маппинга ключей.
     *
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/universal/crm-requisite-fields.html
     */
    public function fields(): array
    {
        if (self::$cacheLabels !== []) {
            return self::$cacheLabels;
        }

        $fields = $this->getFields();
        if ($fields === []) {
            return [];
        }

        $labels = [];
        foreach ($fields as $fieldCode => $fieldMeta) {
            if (!is_array($fieldMeta)) {
                continue;
            }

            $label = $fieldMeta['listLabel'] ?? ($fieldMeta['formLabel'] ?? ($fieldMeta['title'] ?? null));
            if (!is_string($label) || $label === '') {
                continue;
            }

            $labels[(string) $fieldCode] = $label;
        }

        self::$cacheLabels = $labels;
        return $labels;
    }

    /**
     * Сбрасывает кеш подписей полей реквизита, накопленный методом fields().
     */
    public function clearFieldsCache(): void
    {
        self::$cacheLabels = [];
    }

    /**
     * Возвращает реквизиты компании постранично.
     * Это sugar над list(): фиксирует ENTITY_TYPE_ID=Company и ENTITY_ID=$companyId.
     *
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/universal/crm-requisite-list.html
     */
    public function listByCompanyId(int|string $companyId, array $params = [], int $page = 1): array
    {
        $normalizedCompanyId = $this->normalizePositiveId($companyId, 'Company ID');

        $request = $params;
        $filter = [];
        if (isset($request['FILTER']) && is_array($request['FILTER'])) {
            $filter = $request['FILTER'];
            unset($request['FILTER']);
        }

        if (isset($request['filter']) && is_array($request['filter'])) {
            $filter = array_merge($filter, $request['filter']);
        }

        $existingEntityTypeId = $this->toPositiveInt($filter['ENTITY_TYPE_ID'] ?? null);
        if ($existingEntityTypeId !== null && $existingEntityTypeId !== self::COMPANY_ENTITY_TYPE_ID) {
            throw new InvalidArgumentException('Filter ENTITY_TYPE_ID conflicts with company requisites entity type.');
        }

        $existingEntityId = $this->toPositiveInt($filter['ENTITY_ID'] ?? null);
        if ($existingEntityId !== null && $existingEntityId !== $normalizedCompanyId) {
            throw new InvalidArgumentException('Filter ENTITY_ID conflicts with target company ID.');
        }

        $filter['ENTITY_TYPE_ID'] = self::COMPANY_ENTITY_TYPE_ID;
        $filter['ENTITY_ID'] = $normalizedCompanyId;

        $request['filter'] = $filter;
        return $this->list($request, $page);
    }

    /**
     * Возвращает список ID реквизитов компании с кешированием в рамках процесса.
     * Используется как общий источник для listByCompanyId() в адресах и банковских реквизитах.
     */
    public function getCompanyRequisiteIds(int|string $companyId): array
    {
        $normalizedCompanyId = (string) $this->normalizePositiveId($companyId, 'Company ID');
        if (isset(self::$cacheCompanyRequisiteIds[$normalizedCompanyId])) {
            return self::$cacheCompanyRequisiteIds[$normalizedCompanyId];
        }

        $items = $this->fetchAllCompanyRequisites((int) $normalizedCompanyId);
        $ids = $this->extractRequisiteIds($items);
        self::$cacheCompanyRequisiteIds[$normalizedCompanyId] = $ids;

        return $ids;
    }

    /**
     * Возвращает реквизиты компании с вложенными списками адресов и банковских реквизитов.
     * При $includeLabels=true добавляет карты подписей полей для всех секций.
     * Метод предназначен для сценариев UI, где нужны данные и структура формы в одном ответе.
     */
    public function listByIdWithAddressAndBank(int|string $companyId, bool $includeLabels = false): array
    {
        $normalizedCompanyId = $this->normalizePositiveId($companyId, 'Company ID');
        $items = $this->fetchAllCompanyRequisites($normalizedCompanyId);
        if ($items === []) {
            if (!$includeLabels) {
                return [];
            }

            $factory = new Bitrix24RestFactory();
            $addressService = $factory->addresses();
            $bankDetailService = $factory->bankDetails();

            return [
                'items' => [],
                'labels' => [
                    'requisite' => $this->fields(),
                    'address' => $this->extractFieldLabels($addressService->getFields()),
                    'bankDetail' => $this->extractFieldLabels($bankDetailService->getFields()),
                ],
            ];
        }

        $factory = new Bitrix24RestFactory();
        $addressService = $factory->addresses();
        $bankDetailService = $factory->bankDetails();

        $addresses = $addressService->listByCompanyId($normalizedCompanyId);
        $bankDetails = $bankDetailService->listByCompanyId($normalizedCompanyId);

        $addressesByRequisiteId = $this->groupByRequisiteId($addresses);
        $bankDetailsByRequisiteId = $this->groupByRequisiteId($bankDetails);

        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }

            $requisiteId = (string) ($item['ID'] ?? $item['id'] ?? '');
            $item['ADDRESSES'] = $addressesByRequisiteId[$requisiteId] ?? [];
            $item['BANK_DETAILS'] = $bankDetailsByRequisiteId[$requisiteId] ?? [];
        }
        unset($item);

        if (!$includeLabels) {
            return $items;
        }

        return [
            'items' => $items,
            'labels' => [
                'requisite' => $this->fields(),
                'address' => $this->extractFieldLabels($addressService->getFields()),
                'bankDetail' => $this->extractFieldLabels($bankDetailService->getFields()),
            ],
        ];
    }

    /**
     * Сбрасывает кеш companyId=>requisiteIds:
     * без аргумента очищает всё, с аргументом очищает только одну компанию.
     */
    public function clearCompanyIdsCache(?int $companyId = null): void
    {
        if ($companyId === null) {
            self::$cacheCompanyRequisiteIds = [];
            return;
        }

        unset(self::$cacheCompanyRequisiteIds[(string) $companyId]);
    }

    private function fetchAllCompanyRequisites(int $companyId): array
    {
        $page = 1;
        $items = [];
        $iterations = 0;

        while (true) {
            $iterations++;
            if ($iterations > self::MAX_LIST_ITERATIONS) {
                throw new RuntimeException('The listByCompanyId() loop exceeded safe iteration limit.');
            }

            $chunkResult = $this->listByCompanyId($companyId, ['order' => ['ID' => 'ASC']], $page);
            $chunk = is_array($chunkResult['items'] ?? null) ? $chunkResult['items'] : [];
            if ($chunk !== []) {
                $items = array_merge($items, $chunk);
            }

            $hasNext = (bool) ($chunkResult['pagination']['hasNext'] ?? false);
            if (!$hasNext) {
                break;
            }

            $page++;
        }

        self::$cacheCompanyRequisiteIds[(string) $companyId] = $this->extractRequisiteIds($items);
        return $items;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string, list<array<string,mixed>>>
     */
    private function groupByRequisiteId(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $requisiteId = (string) ($item['ENTITY_ID'] ?? $item['entityId'] ?? '');
            if ($requisiteId === '') {
                continue;
            }

            if (!isset($result[$requisiteId])) {
                $result[$requisiteId] = [];
            }

            $result[$requisiteId][] = $item;
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<int>
     */
    private function extractRequisiteIds(array $items): array
    {
        $ids = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $this->toPositiveInt($item['ID'] ?? $item['id'] ?? null);
            if ($id === null || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $ids[] = $id;
        }

        sort($ids);
        return $ids;
    }

    private function invalidateCompanyCacheByFields(array $fields): void
    {
        $entityTypeId = $this->toPositiveInt($fields['ENTITY_TYPE_ID'] ?? null);
        $entityId = $this->toPositiveInt($fields['ENTITY_ID'] ?? null);
        if ($entityTypeId === self::COMPANY_ENTITY_TYPE_ID && $entityId !== null) {
            $this->clearCompanyIdsCache($entityId);
        }
    }

    private function normalizePositiveId(int|string $value, string $label): int
    {
        $parsed = $this->toPositiveInt($value);
        if ($parsed === null) {
            throw new InvalidArgumentException($label . ' must be a positive integer.');
        }

        return $parsed;
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

    private function extractFieldLabels(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $labels = [];
        foreach ($fields as $fieldCode => $fieldMeta) {
            if (!is_array($fieldMeta)) {
                continue;
            }

            $label = $fieldMeta['listLabel'] ?? ($fieldMeta['formLabel'] ?? ($fieldMeta['title'] ?? null));
            if (!is_string($label) || $label === '') {
                continue;
            }

            $labels[(string) $fieldCode] = $label;
        }

        return $labels;
    }
}
