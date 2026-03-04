<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Bitrix24RestFactory;
use B24Rest\Rest\Contract\AddOperationInterface;
use B24Rest\Rest\Contract\DeleteOperationInterface;
use B24Rest\Rest\Contract\GetByIdOperationInterface;
use B24Rest\Rest\Contract\ListOperationInterface;
use B24Rest\Rest\Contract\UpdateOperationInterface;
use B24Rest\Support\CrmEntity;
use InvalidArgumentException;

class RequisitePresetService extends AbstractRestService implements
    ListOperationInterface,
    GetByIdOperationInterface,
    AddOperationInterface,
    UpdateOperationInterface,
    DeleteOperationInterface
{
    private const METHOD_ADD = 'crm.requisite.preset.add';
    private const METHOD_UPDATE = 'crm.requisite.preset.update';
    private const METHOD_DELETE = 'crm.requisite.preset.delete';
    private const METHOD_COUNTRIES = 'crm.requisite.preset.countries';
    private const METHOD_GET = 'crm.requisite.preset.get';
    private const METHOD_LIST = 'crm.requisite.preset.list';
    private const METHOD_FIELDS = 'crm.requisite.preset.fields';
    private const METHOD_FIELD_LIST = 'crm.requisite.preset.field.list';
    private const REQUISITE_ENTITY_TYPE_ID = CrmEntity::TYPE_REQUISITE;

    /** @var array<string, list<array<string,mixed>>> */
    private static array $cachePresetFields = [];

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/presets/crm-requisite-preset-list.html
     */
    public function list(array $params = [], int $page = 1): array
    {
        $this->ensurePositivePage($page);

        $request = $params;
        $request['filter'] = $this->applyRequisiteEntityTypeFilter($request['filter'] ?? null);

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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/presets/crm-requisite-preset-add.html
     */
    public function add(array $fields, array $params = []): array
    {
        $request = $params;
        $request['fields'] = $this->applyRequisiteEntityTypeFilter($fields);

        $response = $this->call(self::METHOD_ADD, $request);
        $result = $response['result'] ?? null;
        $this->clearPresetFieldsCache();

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
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/presets/crm-requisite-preset-update.html
     */
    public function update(int|string $id, array $fields, array $params = []): bool
    {
        $request = $params;
        $request['id'] = $id;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_UPDATE, $request);
        $normalizedId = $this->normalizePositiveIntOrNull($id);
        $this->clearPresetFieldsCache($normalizedId);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/presets/crm-requisite-preset-delete.html
     */
    public function delete(int|string $id): bool
    {
        $response = $this->call(self::METHOD_DELETE, ['id' => $id]);
        $normalizedId = $this->normalizePositiveIntOrNull($id);
        $this->clearPresetFieldsCache($normalizedId);
        return $this->normalizeBooleanResult($response['result'] ?? null);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/presets/crm-requisite-preset-countries.html
     */
    public function countries(): array
    {
        $response = $this->call(self::METHOD_COUNTRIES);
        return $this->normalizeListFromResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/presets/crm-requisite-preset-get.html
     */
    public function getById(int|string $id): array
    {
        $response = $this->call(self::METHOD_GET, ['id' => $id]);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/crm/requisites/presets/crm-requisite-preset-fields.html
     */
    public function getFields(): array
    {
        $response = $this->call(self::METHOD_FIELDS);
        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * Возвращает поля конкретного шаблона реквизитов.
     * Метод использует рабочий REST контракт: crm.requisite.preset.field.list (PRESET[ID]).
     * Результат кешируется в рамках процесса, чтобы снизить количество повторных запросов.
     */
    public function fieldList(int|string $presetId, array $params = []): array
    {
        $normalizedId = $this->normalizePositiveId($presetId, 'Preset ID');
        $cacheKey = (string) $normalizedId;
        if (isset(self::$cachePresetFields[$cacheKey])) {
            return self::$cachePresetFields[$cacheKey];
        }

        $request = $params;
        if (!isset($request['order']) || !is_array($request['order']) || $request['order'] === []) {
            $request['order'] = ['SORT' => 'ASC'];
        }

        if (!isset($request['PRESET']) || !is_array($request['PRESET'])) {
            $request['PRESET'] = [];
        }

        $request['PRESET']['ID'] = $normalizedId;

        $response = $this->call(self::METHOD_FIELD_LIST, $request);
        $items = $this->normalizeListFromResult($response);
        self::$cachePresetFields[$cacheKey] = $items;

        return $items;
    }

    /**
     * Sugar-метод для UI:
     * возвращает список шаблонов, где у каждого есть список полей и подписи полей.
     * Подходит для сценария: select шаблонов + мгновенная отрисовка полей выбранного шаблона.
     */
    public function listWithFieldsAndLabels(array $params = [], int $page = 1): array
    {
        $result = $this->list($params, $page);
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        if ($items === []) {
            return $result;
        }

        $missingPresetIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $presetId = $this->normalizePositiveIntOrNull($item['ID'] ?? $item['id'] ?? null);
            if ($presetId === null) {
                continue;
            }

            if (!isset(self::$cachePresetFields[(string) $presetId])) {
                $missingPresetIds[] = $presetId;
            }
        }

        if ($missingPresetIds !== []) {
            $this->loadPresetFieldsBatch($missingPresetIds);
        }

        $requisiteLabels = (new Bitrix24RestFactory())->requisites()->fields();
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }

            $presetId = $this->normalizePositiveIntOrNull($item['ID'] ?? $item['id'] ?? null);
            if ($presetId === null) {
                $item['FIELDS'] = [];
                $item['FIELD_LABELS'] = [];
                continue;
            }

            $fields = self::$cachePresetFields[(string) $presetId] ?? [];
            $item['FIELDS'] = $this->attachFieldLabels($fields, $requisiteLabels);
            $item['FIELD_LABELS'] = $this->buildFieldLabelsMap($item['FIELDS']);
        }
        unset($item);

        $result['items'] = $items;
        return $result;
    }

    /**
     * Сбрасывает кеш полей шаблонов:
     * без аргумента очищает всё, с аргументом очищает только один presetId.
     */
    public function clearPresetFieldsCache(?int $presetId = null): void
    {
        if ($presetId === null) {
            self::$cachePresetFields = [];
            return;
        }

        unset(self::$cachePresetFields[(string) $presetId]);
    }

    /**
     * Загружает отсутствующие поля шаблонов одним batch-запросом.
     *
     * @param list<int> $presetIds
     */
    private function loadPresetFieldsBatch(array $presetIds): void
    {
        $presetIds = array_values(array_unique(array_filter($presetIds, static fn ($id): bool => is_int($id) && $id > 0)));
        if ($presetIds === []) {
            return;
        }

        $commands = [];
        $keyToPresetId = [];
        foreach ($presetIds as $presetId) {
            $key = 'preset_field_' . $presetId;
            $commands[$key] = [
                'method' => self::METHOD_FIELD_LIST,
                'params' => [
                    'PRESET' => ['ID' => $presetId],
                    'order' => ['SORT' => 'ASC'],
                ],
            ];
            $keyToPresetId[$key] = $presetId;
        }

        $resultMap = $this->callBatchCommands($commands);
        foreach ($keyToPresetId as $key => $presetId) {
            $value = $resultMap[$key] ?? [];
            self::$cachePresetFields[(string) $presetId] = $this->normalizeFieldListFromBatchValue($value);
        }
    }

    private function normalizePositiveId(int|string $value, string $label): int
    {
        $parsed = $this->normalizePositiveIntOrNull($value);
        if ($parsed === null) {
            throw new InvalidArgumentException($label . ' must be a positive integer.');
        }

        return $parsed;
    }

    private function normalizePositiveIntOrNull(mixed $value): ?int
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

    /**
     * Принудительно фиксирует ENTITY_TYPE_ID=CRM_REQUISITE (8)
     * и игнорирует любое пользовательское значение ENTITY_TYPE_ID.
     *
     * @return array<string,mixed>
     */
    private function applyRequisiteEntityTypeFilter(mixed $source): array
    {
        $data = is_array($source) ? $source : [];
        unset($data['ENTITY_TYPE_ID']);
        $data['ENTITY_TYPE_ID'] = self::REQUISITE_ENTITY_TYPE_ID;
        return $data;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeFieldListFromBatchValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if ($this->isListArray($value)) {
            return $value;
        }

        if (isset($value['result']) && is_array($value['result'])) {
            return $this->normalizeListFromResult($value);
        }

        return $this->normalizeListFromResult(['result' => $value]);
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @param array<string,string> $requisiteLabels
     * @return list<array<string,mixed>>
     */
    private function attachFieldLabels(array $fields, array $requisiteLabels): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldName = (string) ($field['FIELD_NAME'] ?? '');
            $fieldTitle = (string) ($field['FIELD_TITLE'] ?? '');

            $label = trim($fieldTitle);
            if ($label === '' && $fieldName !== '' && isset($requisiteLabels[$fieldName])) {
                $label = $requisiteLabels[$fieldName];
            }

            if ($label === '' && $fieldName !== '') {
                $label = $fieldName;
            }

            $field['LABEL'] = $label;
            $result[] = $field;
        }

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @return array<string,string>
     */
    private function buildFieldLabelsMap(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $fieldName = (string) ($field['FIELD_NAME'] ?? '');
            if ($fieldName === '') {
                continue;
            }

            $label = (string) ($field['LABEL'] ?? '');
            if ($label === '') {
                $label = $fieldName;
            }

            $result[$fieldName] = $label;
        }

        return $result;
    }
}
