<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Contract\AddManyOperationInterface;
use B24Rest\Rest\Contract\AddOperationInterface;
use B24Rest\Rest\Contract\AllOperationInterface;
use B24Rest\Rest\Contract\DeleteOperationInterface;
use B24Rest\Rest\Contract\GetByIdOperationInterface;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Rest\Contract\UpdateManyOperationInterface;
use B24Rest\Rest\Contract\UpdateOperationInterface;
use InvalidArgumentException;
use RuntimeException;

abstract class AbstractCrmItemService extends AbstractRestService implements
    ListOperationInterface,
    AllOperationInterface,
    GetByIdOperationInterface,
    AddManyOperationInterface,
    AddOperationInterface,
    UpdateManyOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const METHOD_LIST = 'crm.item.list';
    private const METHOD_GET = 'crm.item.get';
    private const METHOD_ADD = 'crm.item.add';
    private const METHOD_UPDATE = 'crm.item.update';
    private const METHOD_DELETE = 'crm.item.delete';
    private const MAX_ALL_ITERATIONS = 100000;

    public function __construct(private readonly int $entityTypeId)
    {
        if ($entityTypeId <= 0) {
            throw new InvalidArgumentException('Entity type ID must be greater than 0.');
        }
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/crm-item-list.html
     */
    public function list(array $params = [], int $page = 1): array
    {
        $this->ensurePositivePage($page);

        $start = ($page - 1) * self::PAGE_SIZE;
        $request = $this->prepareItemListRequest($params);
        if (!isset($request['order']) || !is_array($request['order']) || $request['order'] === []) {
            $request['order'] = ['id' => 'DESC'];
        }

        $request['start'] = $start;

        $response = $this->call(self::METHOD_LIST, $request);
        $items = $this->normalizeItemsFromItemList($this->normalizeListFromResult($response));

        $next = $this->extractNext($response);
        $total = $this->extractTotal($response);
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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/crm-item-list.html
     * @see https://apidocs.bitrix24.ru/settings/performance/huge-data.html
     */
    public function all(array $params = []): array
    {
        $requestBase = $this->prepareItemListRequest($params, false);
        $userOrder = $this->normalizeUserOrderForOutput($params['order'] ?? null);
        if ($this->hasIdOrderConflict($userOrder)) {
            throw new InvalidArgumentException(
                "Method all() uses internal ID cursor. Remove ID from order in any case variant."
            );
        }

        unset($requestBase['order']);
        $requestBase['start'] = -1;
        $requestBase['order'] = ['id' => 'DESC'];
        $requestBase['select'] = $this->ensureSelectContainsItemId($requestBase['select'] ?? null);

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
            $request['filter'] = $userFilter;
            if ($lastId !== null) {
                $request['filter']['<id'] = $lastId;
            }

            $response = $this->call(self::METHOD_LIST, $request);
            $chunk = $this->normalizeItemsFromItemList($this->normalizeListFromResult($response));
            if ($chunk === []) {
                break;
            }

            $items = array_merge($items, $chunk);
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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/crm-item-get.html
     */
    public function getById(int|string $id): array
    {
        $request = $this->withEntityTypeId(['id' => $id]);
        $request['useOriginalUfNames'] = 'N';

        $response = $this->call(self::METHOD_GET, $request);
        $item = $this->extractByPath($response, ['result', 'item']);
        if (!is_array($item)) {
            return [];
        }

        return $this->normalizeItemFromItemList($item);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/crm-item-add.html
     */
    public function add(array $fields, array $params = []): array
    {
        $request = $this->withEntityTypeId($params);
        $request['useOriginalUfNames'] = 'N';
        $request['fields'] = $this->normalizeFieldsForItemWrite($fields);

        $response = $this->call(self::METHOD_ADD, $request);
        $item = $this->extractByPath($response, ['result', 'item']);
        if (!is_array($item)) {
            return [];
        }

        $normalized = $this->normalizeItemFromItemList($item);
        $id = $normalized['ID'] ?? null;
        if (is_int($id) || (is_string($id) && $id !== '')) {
            return ['id' => (string) $id];
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{id:string}>
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/crm-item-add.html
     */
    public function addMany(array $items, array $params = []): array
    {
        if ($items === []) {
            return [];
        }

        $commands = [];
        $commandKeys = [];
        $position = 0;
        foreach ($items as $fields) {
            $position++;
            if (!is_array($fields)) {
                throw new InvalidArgumentException(
                    sprintf('Item at position %d must be an array of fields.', $position)
                );
            }

            $request = $this->withEntityTypeId($params);
            $request['useOriginalUfNames'] = 'N';
            $request['fields'] = $this->normalizeFieldsForItemWrite($fields);

            $key = 'add_' . $position;
            $commands[$key] = [
                'method' => self::METHOD_ADD,
                'params' => $request,
            ];
            $commandKeys[] = $key;
        }

        $resultMap = $this->callBatchCommands($commands);
        $result = [];
        foreach ($commandKeys as $key) {
            $result[] = $this->normalizeAddResult($resultMap[$key] ?? null);
        }

        return $result;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/crm-item-update.html
     */
    public function update(int|string $id, array $fields, array $params = []): bool
    {
        $request = $this->withEntityTypeId($params);
        $request['useOriginalUfNames'] = 'N';
        $request['id'] = $id;
        $request['fields'] = $this->normalizeFieldsForItemWrite($fields);

        $response = $this->call(self::METHOD_UPDATE, $request);
        $item = $this->extractByPath($response, ['result', 'item']);
        if (is_array($item)) {
            return true;
        }

        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @param list<array{id:int|string,fields:array<string,mixed>}> $items
     * @return list<bool>
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/crm-item-update.html
     */
    public function updateMany(array $items, array $params = []): array
    {
        if ($items === []) {
            return [];
        }

        $commands = [];
        $commandKeys = [];
        $position = 0;
        foreach ($items as $item) {
            $position++;
            if (!is_array($item)) {
                throw new InvalidArgumentException(sprintf('Item at position %d must be an array.', $position));
            }

            $id = $item['id'] ?? $item['ID'] ?? null;
            if (!is_int($id) && !(is_string($id) && $id !== '')) {
                throw new InvalidArgumentException(
                    sprintf('Item at position %d must contain non-empty id/ID.', $position)
                );
            }

            $fields = $item['fields'] ?? $item['FIELDS'] ?? null;
            if (!is_array($fields)) {
                throw new InvalidArgumentException(
                    sprintf('Item at position %d must contain fields/FIELDS array.', $position)
                );
            }

            $request = $this->withEntityTypeId($params);
            $request['useOriginalUfNames'] = 'N';
            $request['id'] = $id;
            $request['fields'] = $this->normalizeFieldsForItemWrite($fields);

            $key = 'update_' . $position;
            $commands[$key] = [
                'method' => self::METHOD_UPDATE,
                'params' => $request,
            ];
            $commandKeys[] = $key;
        }

        $resultMap = $this->callBatchCommands($commands);
        $result = [];
        foreach ($commandKeys as $key) {
            $result[] = $this->normalizeUpdateResult($resultMap[$key] ?? null);
        }

        return $result;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/universal/crm-item-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, $this->withEntityTypeId(['id' => $id]));
        if (is_array($response['result'] ?? null)) {
            return true;
        }

        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    protected function entityTypeId(): int
    {
        return $this->entityTypeId;
    }

    protected function prepareItemListRequest(array $params, ?bool $forceUseOriginalUfNames = null): array
    {
        $request = $this->withEntityTypeId($params);
        $useOriginalUfNames = $forceUseOriginalUfNames
            ?? $this->shouldUseOriginalUfNamesForItemList($request['select'] ?? null);
        $request['useOriginalUfNames'] = $useOriginalUfNames ? 'Y' : 'N';

        if (isset($request['select'])) {
            $request['select'] = $this->normalizeSelectForItemList($request['select'], $useOriginalUfNames);
        }

        if (isset($request['order']) && is_array($request['order'])) {
            $request['order'] = $this->normalizeOrderForItemList($request['order'], $useOriginalUfNames);
        }

        if (isset($request['filter']) && is_array($request['filter'])) {
            $request['filter'] = $this->normalizeFilterForItemList($request['filter'], $useOriginalUfNames);
        }

        return $request;
    }

    protected function withEntityTypeId(array $request): array
    {
        if (
            array_key_exists('entityTypeId', $request)
            && (int) $request['entityTypeId'] !== $this->entityTypeId
        ) {
            throw new InvalidArgumentException(sprintf(
                '%s supports only entityTypeId %d.',
                static::class,
                $this->entityTypeId
            ));
        }

        $request['entityTypeId'] = $this->entityTypeId;
        return $request;
    }

    protected function ensureSelectContainsItemId(mixed $select): array
    {
        if (!is_array($select) || $select === []) {
            return ['*', 'UF_*'];
        }

        $normalized = array_values($select);
        $containsId = in_array('id', $normalized, true) || in_array('*', $normalized, true);
        if (!$containsId) {
            $normalized[] = 'id';
        }

        return $normalized;
    }

    protected function normalizeFieldsForItemWrite(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $key => $value) {
            if (!is_string($key)) {
                $normalized[$key] = $value;
                continue;
            }

            $normalized[$this->normalizeFieldNameForItemListRequest($key, false)] = $value;
        }

        return $normalized;
    }

    protected function normalizeItemsFromItemList(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = $this->normalizeItemFromItemList($item);
        }

        return $normalized;
    }

    protected function normalizeItemFromItemList(array $item): array
    {
        $normalized = [];
        foreach ($item as $key => $value) {
            if (!is_string($key)) {
                $normalized[$key] = $value;
                continue;
            }

            $normalized[$this->normalizeFieldNameFromItemListResponse($key)] = $value;
        }

        return $normalized;
    }

    private function normalizeAddResult(mixed $value): array
    {
        if (is_int($value) || (is_string($value) && $value !== '')) {
            return ['id' => (string) $value];
        }

        if (!is_array($value)) {
            return [];
        }

        $item = $value['item'] ?? $value;
        if (is_int($item) || (is_string($item) && $item !== '')) {
            return ['id' => (string) $item];
        }

        if (!is_array($item)) {
            $id = $value['id'] ?? $value['ID'] ?? null;
            if (is_int($id) || (is_string($id) && $id !== '')) {
                return ['id' => (string) $id];
            }

            return [];
        }

        $normalized = $this->normalizeItemFromItemList($item);
        $id = $normalized['ID'] ?? $normalized['id'] ?? null;
        if (is_int($id) || (is_string($id) && $id !== '')) {
            return ['id' => (string) $id];
        }

        return [];
    }

    private function normalizeUpdateResult(mixed $value): bool
    {
        if (is_array($value)) {
            $item = $value['item'] ?? null;
            if (is_array($item)) {
                return true;
            }
        }

        return $this->normalizeBooleanResult($value);
    }

    protected function extractTotal(array $response): ?int
    {
        $total = $response['total'] ?? null;
        if (is_int($total)) {
            return $total;
        }

        if (is_string($total) && ctype_digit($total)) {
            return (int) $total;
        }

        return null;
    }

    private function shouldUseOriginalUfNamesForItemList(mixed $select): bool
    {
        // Bitrix24 quirk: with useOriginalUfNames=Y and select of system fields,
        // crm.item.list can return empty item objects. For such select we switch to N.
        if (!is_array($select) || $select === []) {
            return true;
        }

        foreach ($select as $field) {
            if (!is_string($field)) {
                continue;
            }

            $field = trim($field);
            if ($field === '' || $field === '*' || $field === 'UF_*') {
                continue;
            }

            if (!str_starts_with($field, 'UF_')) {
                return false;
            }
        }

        return true;
    }

    private function normalizeSelectForItemList(mixed $select, bool $useOriginalUfNames): array
    {
        if (!is_array($select)) {
            return [];
        }

        $normalized = [];
        foreach ($select as $field) {
            if (!is_string($field)) {
                continue;
            }

            $field = trim($field);
            if ($field === '') {
                continue;
            }

            $normalized[] = $this->normalizeFieldNameForItemListRequest($field, $useOriginalUfNames);
        }

        return $normalized;
    }

    private function normalizeOrderForItemList(array $order, bool $useOriginalUfNames): array
    {
        $normalized = [];
        foreach ($order as $field => $direction) {
            if (!is_string($field) || trim($field) === '') {
                continue;
            }

            $normalizedField = $this->normalizeFieldNameForItemListRequest(trim($field), $useOriginalUfNames);
            $normalizedDirection = is_string($direction) ? strtoupper(trim($direction)) : 'ASC';
            if ($normalizedDirection !== 'ASC' && $normalizedDirection !== 'DESC') {
                $normalizedDirection = 'ASC';
            }

            $normalized[$normalizedField] = $normalizedDirection;
        }

        return $normalized;
    }

    private function normalizeUserOrderForOutput(mixed $order): array
    {
        $normalized = $this->normalizeUserOrder($order);
        if ($normalized === []) {
            return [];
        }

        $output = [];
        foreach ($normalized as $field => $direction) {
            $requestField = $this->normalizeFieldNameForItemListRequest($field, false);
            $responseField = $this->normalizeFieldNameFromItemListResponse($requestField);
            $output[$responseField] = $direction;
        }

        return $output;
    }

    private function normalizeFilterForItemList(array $filter, bool $useOriginalUfNames): array
    {
        $normalized = [];
        foreach ($filter as $key => $value) {
            if (is_int($key) && is_array($value)) {
                $normalized[$key] = $this->normalizeFilterForItemList($value, $useOriginalUfNames);
                continue;
            }

            if (!is_string($key)) {
                $normalized[$key] = $value;
                continue;
            }

            if (strcasecmp($key, 'LOGIC') === 0 || strcasecmp($key, 'logic') === 0) {
                $normalized['logic'] = $value;
                continue;
            }

            $normalized[$this->normalizeFilterKeyForItemList($key, $useOriginalUfNames)] = is_array($value)
                ? $this->normalizeFilterForItemList($value, $useOriginalUfNames)
                : $value;
        }

        return $normalized;
    }

    private function normalizeFilterKeyForItemList(string $key, bool $useOriginalUfNames): string
    {
        if (preg_match('/^([!<>=@%~]*)(.+)$/', $key, $matches) !== 1) {
            return $key;
        }

        return $matches[1] . $this->normalizeFieldNameForItemListRequest(trim($matches[2]), $useOriginalUfNames);
    }

    private function normalizeFieldNameForItemListRequest(string $field, bool $useOriginalUfNames): string
    {
        if ($field === '*' || $field === 'UF_*') {
            return $field;
        }

        if (str_starts_with($field, 'ufCrm')) {
            return $field;
        }

        if (str_starts_with($field, 'UF_')) {
            return $useOriginalUfNames ? $field : $this->normalizeUfFieldNameForItemListRequest($field);
        }

        if (preg_match('/^[A-Z0-9_]+$/', $field) === 1) {
            $camel = lcfirst(str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $field)))));
            return $camel;
        }

        return $field;
    }

    private function normalizeUfFieldNameForItemListRequest(string $field): string
    {
        if (!str_starts_with($field, 'UF_CRM_')) {
            return $field;
        }

        $suffix = substr($field, strlen('UF_CRM_'));
        if (!is_string($suffix) || $suffix === '') {
            return $field;
        }

        if (preg_match('/[0-9]/', $suffix) === 1) {
            return 'ufCrm_' . strtoupper($suffix);
        }

        $camelSuffix = str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $suffix))));
        return 'ufCrm' . $camelSuffix;
    }

    private function normalizeFieldNameFromItemListResponse(string $field): string
    {
        if ($field === '' || $field === '*' || $field === 'UF_*') {
            return $field;
        }

        if (str_starts_with($field, 'UF_')) {
            return $field;
        }

        if (str_starts_with($field, 'ufCrm_')) {
            $suffix = strtoupper(substr($field, strlen('ufCrm_')));
            return 'UF_CRM_' . $suffix;
        }

        if (preg_match('/^[a-z0-9]+(?:[A-Z][a-z0-9]*)*$/', $field) !== 1) {
            return $field;
        }

        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $field);
        if (!is_string($snake)) {
            return $field;
        }

        return strtoupper($snake);
    }

    private function extractNext(array $response): ?int
    {
        $next = $response['next'] ?? null;
        if (is_int($next)) {
            return $next;
        }

        if (is_string($next) && ctype_digit($next)) {
            return (int) $next;
        }

        return null;
    }

    private function extractMinId(array $chunk): int
    {
        $minId = null;
        foreach ($chunk as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $item['ID'] ?? null;
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
