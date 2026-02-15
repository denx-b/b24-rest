<?php

namespace B24Rest\Rest\Entity;

use InvalidArgumentException;

abstract class AbstractCrmItemWithProductRowsService extends AbstractCrmItemService
{
    private const METHOD_PRODUCT_ROW_ADD = 'crm.item.productrow.add';
    private const METHOD_PRODUCT_ROW_UPDATE = 'crm.item.productrow.update';
    private const METHOD_PRODUCT_ROW_GET = 'crm.item.productrow.get';
    private const METHOD_PRODUCT_ROW_LIST = 'crm.item.productrow.list';
    private const METHOD_PRODUCT_ROW_DELETE = 'crm.item.productrow.delete';

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/product-rows/crm-item-productrow-add.html
     */
    public function productRowAdd(int|string $ownerId, array $fields = [], array $params = []): array
    {
        $request = $params;
        $request['fields'] = $this->normalizeFieldsForItemWrite($fields);
        $request['fields']['ownerId'] = $ownerId;
        $request['fields']['ownerType'] = $this->productRowOwnerType();

        $response = $this->call(self::METHOD_PRODUCT_ROW_ADD, $request);
        $row = $this->extractByPath($response, ['result', 'productRow']);
        if (!is_array($row)) {
            return [];
        }

        return $this->normalizeItemFromItemList($row);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/product-rows/crm-item-productrow-update.html
     */
    public function productRowUpdate(int|string $id, array $fields, array $params = []): array
    {
        $request = $params;
        $request['id'] = $id;
        $request['fields'] = $this->normalizeFieldsForItemWrite($fields);

        $response = $this->call(self::METHOD_PRODUCT_ROW_UPDATE, $request);
        $row = $this->extractByPath($response, ['result', 'productRow']);
        if (!is_array($row)) {
            return [];
        }

        return $this->normalizeItemFromItemList($row);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/product-rows/crm-item-productrow-get.html
     */
    public function productRowGet(int|string $id, array $params = []): array
    {
        $request = $params;
        $request['id'] = $id;

        $response = $this->call(self::METHOD_PRODUCT_ROW_GET, $request);
        $row = $this->extractByPath($response, ['result', 'productRow']);
        if (!is_array($row)) {
            return [];
        }

        return $this->normalizeItemFromItemList($row);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/product-rows/crm-item-productrow-list.html
     */
    public function productRowList(int|string $ownerId, array $params = []): array
    {
        $request = $params;
        $filter = is_array($request['filter'] ?? null) ? $request['filter'] : [];
        if ($this->hasProductRowOwnerConflicts($filter)) {
            throw new InvalidArgumentException(
                "Method productRowList() manages ownerType/ownerId internally. Remove these conditions from filter."
            );
        }

        $filter['=ownerType'] = $this->productRowOwnerType();
        $filter['=ownerId'] = $ownerId;
        $request['filter'] = $filter;

        $response = $this->call(self::METHOD_PRODUCT_ROW_LIST, $request);
        $rows = $this->extractByPath($response, ['result', 'productRows'], []);
        if (!is_array($rows)) {
            $rows = [];
        }

        $items = $this->normalizeItemsFromItemList($rows);
        return [
            'items' => $items,
            'total' => $this->extractTotal($response),
        ];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/product-rows/crm-item-productrow-delete.html
     */
    public function productRowDelete(int|string $id, array $params = []): bool
    {
        $request = $params;
        $request['id'] = $id;

        $response = $this->call(self::METHOD_PRODUCT_ROW_DELETE, $request);
        if (array_key_exists('result', $response) && $response['result'] === null) {
            return true;
        }

        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    protected function productRowOwnerType(): string
    {
        return $this->productRowOwnerTypeAbbr();
    }

    abstract protected function productRowOwnerTypeAbbr(): string;

    private function hasProductRowOwnerConflicts(array $filter): bool
    {
        foreach ($filter as $key => $value) {
            if (is_int($key) && is_array($value)) {
                if ($this->hasProductRowOwnerConflicts($value)) {
                    return true;
                }

                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            if (preg_match('/^[!<>=@%~]*(ownerType|ownerId)$/i', trim($key)) === 1) {
                return true;
            }

            if (is_array($value) && $this->hasProductRowOwnerConflicts($value)) {
                return true;
            }
        }

        return false;
    }
}
