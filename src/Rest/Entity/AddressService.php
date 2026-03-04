<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Bitrix24RestFactory;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class AddressService extends AbstractRestService implements ListOperationInterface
{
    private const REQUISITE_ENTITY_TYPE_ID = 8;
    private const MAX_LIST_ITERATIONS = 100000;
    private const METHOD_ADD = 'crm.address.add';
    private const METHOD_UPDATE = 'crm.address.update';
    private const METHOD_LIST = 'crm.address.list';
    private const METHOD_DELETE = 'crm.address.delete';
    private const METHOD_FIELDS = 'crm.address.fields';

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/addresses/crm-address-add.html
     */
    public function add(array $fields, array $params = []): bool
    {
        $request = $params;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_ADD, $request);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/addresses/crm-address-update.html
     */
    public function update(array $fields, array $params = []): bool
    {
        $request = $params;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_UPDATE, $request);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/addresses/crm-address-list.html
     */
    public function list(array $params = [], int $page = 1): array
    {
        $this->ensurePositivePage($page);

        $request = $params;
        if (!isset($request['order']) || !is_array($request['order']) || $request['order'] === []) {
            $request['order'] = ['TYPE_ID' => 'ASC'];
        }
        $request['start'] = ($page - 1) * self::PAGE_SIZE;

        $response = $this->call(self::METHOD_LIST, $request);
        $items = $this->normalizeListFromResult($response);
        $items = $this->appendShortAddressToItems($items);

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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/addresses/crm-address-delete.html
     */
    public function delete(array $fields, array $params = []): bool
    {
        $request = $params;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_DELETE, $request);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/addresses/crm-address-fields.html
     */
    public function getFields(): array
    {
        $response = $this->call(self::METHOD_FIELDS);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * Возвращает все адреса реквизитов компании.
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
            $order = ['TYPE_ID' => 'ASC'];
        }

        $this->sortItemsByOrder($items, $order);
        return $items;
    }

    private function appendShortAddressToItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item['ADDRESS_SHORT'] = self::formatAddressShort($item);
            $result[] = $item;
        }

        return $result;
    }

    protected static function formatAddressShort(array $address): string
    {
        $availableFields = [
            'POSTAL_CODE',
            'COUNTRY',
            'PROVINCE',
            'CITY',
            'ADDRESS_1',
            'ADDRESS_2',
        ];

        $parts = [];
        foreach ($availableFields as $field) {
            $value = $address[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || stripos($value, 'Россия') !== false) {
                continue;
            }

            $parts[] = Str::filterString($value);
        }

        return implode(', ', $parts);
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

        $currentEntityType = $this->toPositiveInt($filter['ENTITY_TYPE_ID'] ?? null);
        if ($currentEntityType !== null && $currentEntityType !== self::REQUISITE_ENTITY_TYPE_ID) {
            throw new InvalidArgumentException('Address filter ENTITY_TYPE_ID conflicts with requisite entity type.');
        }

        $currentEntityId = $this->toPositiveInt($filter['ENTITY_ID'] ?? null);
        if ($currentEntityId !== null && $currentEntityId !== $requisiteId) {
            throw new InvalidArgumentException('Address filter ENTITY_ID conflicts with target requisite ID.');
        }

        $filter['ENTITY_TYPE_ID'] = self::REQUISITE_ENTITY_TYPE_ID;
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
