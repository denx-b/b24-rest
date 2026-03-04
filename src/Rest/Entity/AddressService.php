<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Support\Str;

class AddressService extends AbstractRestService implements ListOperationInterface
{
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
}

