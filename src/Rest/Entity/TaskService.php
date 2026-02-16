<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Rest\AbstractRestService;
use B24Rest\Rest\Exception\Bitrix24RestException;
use RuntimeException;

class TaskService extends AbstractRestService
{
    private const METHOD_TASK_ADD = 'tasks.task.add';
    private const METHOD_TASK_UPDATE = 'tasks.task.update';
    private const METHOD_TASK_GET = 'tasks.task.get';
    private const METHOD_TASK_LIST = 'tasks.task.list';
    private const METHOD_TEMPLATE_LIST = 'tasks.template.list';
    private const METHOD_TEMPLATE_GET = 'tasks.template.get';
    private const METHOD_TEMPLATE_CHECKLIST_LIST = 'tasks.template.checklist.list';
    private const METHOD_TASK_FILES_ATTACH = 'tasks.task.files.attach';
    private const METHOD_TASK_DELEGATE = 'tasks.task.delegate';
    private const METHOD_TASK_COMPLETE = 'tasks.task.complete';
    private const METHOD_TASK_DELETE = 'tasks.task.delete';

    private const METHOD_CHECKLIST_ADD = 'task.checklistitem.add';
    private const METHOD_CHECKLIST_UPDATE = 'task.checklistitem.update';
    private const METHOD_CHECKLIST_GET = 'task.checklistitem.get';
    private const METHOD_CHECKLIST_GET_LIST = 'task.checklistitem.getlist';
    private const METHOD_CHECKLIST_MOVE_AFTER_ITEM = 'task.checklistitem.moveafteritem';
    private const METHOD_CHECKLIST_COMPLETE = 'task.checklistitem.complete';
    private const METHOD_CHECKLIST_RENEW = 'task.checklistitem.renew';
    private const METHOD_CHECKLIST_DELETE = 'task.checklistitem.delete';

    private const METHOD_COMMENT_ADD = 'task.commentitem.add';

    private const METHOD_ELAPSED_ADD = 'task.elapseditem.add';
    private const METHOD_ELAPSED_UPDATE = 'task.elapseditem.update';
    private const METHOD_ELAPSED_GET = 'task.elapseditem.get';
    private const METHOD_ELAPSED_GET_LIST = 'task.elapseditem.getlist';
    private const METHOD_ELAPSED_DELETE = 'task.elapseditem.delete';

    private const METHOD_ITEM_USER_FIELD_ADD = 'task.item.userfield.add';

    private const MAX_ALL_ITERATIONS = 100000;

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-add.html
     */
    public function taskAdd(array $fields, array $params = []): array
    {
        $request = $params;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_TASK_ADD, $request);
        return $this->normalizeTaskResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-update.html
     */
    public function taskUpdate(int|string $taskId, array $fields, array $params = []): bool
    {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_TASK_UPDATE, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-get.html
     */
    public function taskGet(int|string $taskId, array $params = []): array
    {
        $request = $params;
        $request['taskId'] = $taskId;

        $response = $this->call(self::METHOD_TASK_GET, $request);
        $task = $this->extractByPath($response, ['result', 'task']);
        if (is_array($task)) {
            return $task;
        }

        $items = $this->normalizeListFromTaskResponse($response, ['task']);
        $first = $items[0] ?? null;
        return is_array($first) ? $first : [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-list.html
     */
    public function taskList(array $params = [], int $page = 1): array
    {
        $this->ensurePositivePage($page);

        $request = $params;
        if (!array_key_exists('start', $request)) {
            $request['start'] = ($page - 1) * self::PAGE_SIZE;
        }

        $response = $this->call(self::METHOD_TASK_LIST, $request);
        $items = $this->normalizeListFromTaskResponse($response, ['tasks']);
        $total = $this->extractTotal($response);
        $next = $this->extractNext($response);
        $totalPages = ($total !== null) ? (int) ceil($total / self::PAGE_SIZE) : null;
        $hasNext = ($next !== null) || ($totalPages !== null && $page < $totalPages);

        return [
            'items' => $items,
            'next' => $next,
            'total' => $total,
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
     * Все задачи по фильтру.
     * Использует быстрый режим start=-1 и курсор по ID (<ID при ORDER ID DESC).
     *
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-list.html
     */
    public function taskAll(array $params = []): array
    {
        $requestBase = $params;
        unset($requestBase['start'], $requestBase['START']);

        $requestBase['order'] = is_array($requestBase['order'] ?? null)
            ? $requestBase['order']
            : (is_array($requestBase['ORDER'] ?? null) ? $requestBase['ORDER'] : ['ID' => 'DESC']);
        $requestBase['order']['ID'] = 'DESC';
        unset($requestBase['ORDER']);

        $requestBase['filter'] = is_array($requestBase['filter'] ?? null)
            ? $requestBase['filter']
            : (is_array($requestBase['FILTER'] ?? null) ? $requestBase['FILTER'] : []);
        unset($requestBase['FILTER']);

        $items = [];
        $lastId = null;
        $iterations = 0;
        $hasIdConflicts = $this->hasIdCursorConflicts($requestBase['filter']);
        while (true) {
            $iterations++;
            if ($iterations > self::MAX_ALL_ITERATIONS) {
                throw new RuntimeException('The taskAll() loop exceeded safe iteration limit.');
            }

            $request = $requestBase;
            if (!$hasIdConflicts && $lastId !== null) {
                $request['filter']['<ID'] = $lastId;
            }
            $request['start'] = -1;

            $response = $this->call(self::METHOD_TASK_LIST, $request);
            $chunk = $this->normalizeListFromTaskResponse($response, ['tasks']);
            if ($chunk === []) {
                break;
            }
            $items = array_merge($items, $chunk);

            if ($hasIdConflicts || count($chunk) < self::PAGE_SIZE) {
                break;
            }

            $tail = end($chunk);
            if (!is_array($tail)) {
                break;
            }
            $nextLastId = $this->toPositiveInt($tail['id'] ?? $tail['ID'] ?? null);
            if ($nextLastId === null || ($lastId !== null && $nextLastId >= $lastId)) {
                break;
            }

            $lastId = $nextLastId;
        }

        if ($items !== []) {
            $this->sortItemsByOrder($items, $requestBase['order']);
        }

        return $items;
    }

    /**
     * Undocumented method.
     * Actual behavior on B24 portals: stable response requires `start=-1`.
     *
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/index.html
     */
    public function templateList(array $params = []): array
    {
        $request = $params;
        $request['start'] = -1;

        $response = $this->call(self::METHOD_TEMPLATE_LIST, $request);
        return $this->normalizeTemplateListResponse($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/index.html
     */
    public function templateGet(int|string $templateId): array
    {
        $response = $this->call(self::METHOD_TEMPLATE_GET, [
            'templateId' => $templateId,
        ]);

        $result = $response['result'] ?? null;
        return is_array($result) ? $result : [];
    }

    /**
     * Undocumented method.
     *
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/index.html
     */
    public function templateChecklistList(int|string $templateId): array
    {
        $response = $this->call(self::METHOD_TEMPLATE_CHECKLIST_LIST, [
            'templateId' => $templateId,
        ]);

        $items = $this->normalizeTemplateChecklistItems($response);
        return [
            'items' => $items,
            'total' => count($items),
        ];
    }

    /**
     * Создать задачу по шаблону:
     * - получает шаблон;
     * - создаёт задачу через tasks.task.add;
     * - переносит чек-лист шаблона в новую задачу.
     *
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-add.html
     */
    public function taskAddFromTemplate(
        int|string $templateId,
        array $overrideFields = [],
        array $params = []
    ): array {
        $template = $this->templateGet($templateId);
        if ($template === []) {
            return [];
        }

        $fields = $this->buildTaskFieldsFromTemplate($template);
        if ($overrideFields !== []) {
            $fields = array_replace($fields, $overrideFields);
        }

        try {
            $task = $this->taskAdd($fields, $params);
        } catch (Bitrix24RestException $exception) {
            $canRetryWithoutWebdavFiles = isset($fields['UF_TASK_WEBDAV_FILES'])
                && (
                    str_contains($exception->getMessage(), 'Не удалось найти файл')
                    || str_contains($exception->getMessage(), 'Could not find file')
                );
            if (!$canRetryWithoutWebdavFiles) {
                throw $exception;
            }

            unset($fields['UF_TASK_WEBDAV_FILES']);
            $task = $this->taskAdd($fields, $params);
        }

        $taskId = $this->extractTaskIdFromTaskResult($task);
        if ($taskId === null) {
            return [
                'task' => $task,
                'checklist' => [],
            ];
        }

        $templateChecklist = $this->templateChecklistList($templateId);
        $createdChecklist = $this->createTaskChecklistFromTemplateItems($taskId, $templateChecklist['items'] ?? []);

        return [
            'task' => $task,
            'checklist' => $createdChecklist,
        ];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-files-attach.html
     */
    public function taskFilesAttach(int|string $taskId, int|string $fileId, array $params = []): bool
    {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['fileId'] = $fileId;

        $response = $this->call(self::METHOD_TASK_FILES_ATTACH, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-delegate.html
     */
    public function taskDelegate(int|string $taskId, int|string $userId, array $params = []): bool
    {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['userId'] = $userId;

        $response = $this->call(self::METHOD_TASK_DELEGATE, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-complete.html
     */
    public function taskComplete(int|string $taskId, array $params = []): bool
    {
        $request = $params;
        $request['taskId'] = $taskId;

        $response = $this->call(self::METHOD_TASK_COMPLETE, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/tasks-task-delete.html
     */
    public function taskDelete(int|string $taskId, array $params = []): bool
    {
        $request = $params;
        $request['taskId'] = $taskId;

        $response = $this->call(self::METHOD_TASK_DELETE, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * Унифицированное добавление пунктов чек-листа через batch.
     * Даже для одного пункта используется batch-вызов.
     * Варианты привязки пунктов:
     * - передать `$params['checklistId']` (или `parentId`) для существующего чек-листа;
     * - передать `$title`, чтобы создать новый чек-лист и добавить пункты в него;
     * - передать пустой `$title`, чтобы добавить в первый корневой чек-лист задачи.
     *
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/checklist-item/task-checklist-item-add.html
     */
    public function checklistItemAdd(int|string $taskId, string $title, array $items, array $params = []): array
    {
        $normalizedItems = $this->normalizeChecklistItems($items);
        if ($normalizedItems === []) {
            return [];
        }

        $title = trim($title);
        $parentId = $this->resolveChecklistParentId($taskId, $title, $params);
        $apiParams = $this->sanitizeChecklistItemAddParams($params);

        $commands = [];
        $index = 0;
        foreach ($normalizedItems as $fields) {
            $index++;
            if (
                $parentId !== null
                && !isset($fields['PARENT_ID'])
                && !isset($fields['parentId'])
                && !isset($fields['parent_id'])
            ) {
                $fields['PARENT_ID'] = $parentId;
            }

            $request = $apiParams;
            $request['taskId'] = $taskId;
            $request['fields'] = $fields;
            $commands['checklist_add_' . $index] = [
                'method' => self::METHOD_CHECKLIST_ADD,
                'params' => $request,
            ];
        }

        $resultMap = $this->callBatchCommands($commands);
        $result = [];
        foreach (array_keys($commands) as $key) {
            $item = $resultMap[$key] ?? null;
            if (is_array($item)) {
                $result[] = $item;
                continue;
            }

            if (is_scalar($item) && $item !== '') {
                $result[] = ['id' => (string) $item];
                continue;
            }

            $result[] = [];
        }

        return $result;
    }

    private function resolveChecklistParentId(int|string $taskId, string $title, array $params): ?int
    {
        $explicitParentId = $this->extractChecklistParentIdFromParams($params);
        if ($explicitParentId !== null) {
            return $explicitParentId;
        }

        if ($title !== '') {
            return $this->createChecklistRoot($taskId, $title);
        }

        return $this->findFirstChecklistRootId($taskId);
    }

    private function extractChecklistParentIdFromParams(array $params): ?int
    {
        foreach (['checklistId', 'checklist_id', 'parentId', 'parent_id', 'PARENT_ID'] as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }

            $value = $this->toPositiveInt($params[$key]);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function sanitizeChecklistItemAddParams(array $params): array
    {
        unset($params['checklistId'], $params['checklist_id'], $params['parentId'], $params['parent_id'], $params['PARENT_ID']);
        return $params;
    }

    private function createChecklistRoot(int|string $taskId, string $title): int
    {
        $response = $this->call(self::METHOD_CHECKLIST_ADD, [
            'taskId' => $taskId,
            'fields' => [
                'TITLE' => $title,
                'PARENT_ID' => 0,
            ],
        ]);

        $created = $this->normalizeCreatedResult($response);
        $rootId = $this->toPositiveInt($created['id'] ?? null);
        if ($rootId === null) {
            throw new RuntimeException('Unable to create checklist root item.');
        }

        return $rootId;
    }

    private function findFirstChecklistRootId(int|string $taskId): ?int
    {
        $list = $this->checklistItemGetList($taskId);
        $items = $list['items'] ?? [];
        if (!is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $parentId = $this->toIntOrNull($item['PARENT_ID'] ?? $item['parentId'] ?? $item['parent_id'] ?? null);
            if ($parentId !== 0) {
                continue;
            }

            $itemId = $this->toPositiveInt($item['ID'] ?? $item['id'] ?? null);
            if ($itemId !== null) {
                return $itemId;
            }
        }

        return null;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/checklist-item/task-checklist-item-update.html
     */
    public function checklistItemUpdate(
        int|string $taskId,
        int|string $itemId,
        array $fields,
        array $params = []
    ): bool {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['itemId'] = $itemId;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_CHECKLIST_UPDATE, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/checklist-item/task-checklist-item-get.html
     */
    public function checklistItemGet(int|string $taskId, int|string $itemId, array $params = []): array
    {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['itemId'] = $itemId;

        $response = $this->call(self::METHOD_CHECKLIST_GET, $request);
        $result = $response['result'] ?? null;
        if (is_array($result)) {
            return $result;
        }

        return [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/checklist-item/task-checklist-item-get-list.html
     */
    public function checklistItemGetList(int|string $taskId, array $params = []): array
    {
        $request = $params;
        $request['taskId'] = $taskId;

        $response = $this->call(self::METHOD_CHECKLIST_GET_LIST, $request);
        $items = $this->normalizeListFromTaskResponse($response, ['checklist', 'checkListItems', 'checklistItems']);

        return [
            'items' => $items,
            'next' => $this->extractNext($response),
            'total' => $this->extractTotal($response),
        ];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/checklist-item/task-checklist-item-move-after-item.html
     */
    public function checklistItemMoveAfterItem(
        int|string $taskId,
        int|string $itemId,
        int|string $afterItemId,
        array $params = []
    ): bool {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['itemId'] = $itemId;
        $request['afterItemId'] = $afterItemId;

        $response = $this->call(self::METHOD_CHECKLIST_MOVE_AFTER_ITEM, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/checklist-item/task-checklist-item-complete.html
     */
    public function checklistItemComplete(int|string $taskId, int|string $itemId, array $params = []): bool
    {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['itemId'] = $itemId;

        $response = $this->call(self::METHOD_CHECKLIST_COMPLETE, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/checklist-item/task-checklist-item-renew.html
     */
    public function checklistItemRenew(int|string $taskId, int|string $itemId, array $params = []): bool
    {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['itemId'] = $itemId;

        $response = $this->call(self::METHOD_CHECKLIST_RENEW, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/checklist-item/task-checklist-item-delete.html
     */
    public function checklistItemDelete(int|string $taskId, int|string $itemId, array $params = []): bool
    {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['itemId'] = $itemId;

        $response = $this->call(self::METHOD_CHECKLIST_DELETE, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * Переименовать чек-лист по его текущему заголовку.
     * Чек-лист определяется как корневой пункт с PARENT_ID = 0.
     */
    public function checklistRenameByTitle(int|string $taskId, string $oldTitle, string $newTitle): bool
    {
        $oldTitle = trim($oldTitle);
        $newTitle = trim($newTitle);
        if ($oldTitle === '' || $newTitle === '') {
            return false;
        }

        $list = $this->checklistItemGetList($taskId);
        $items = $list['items'] ?? [];
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $parentId = $this->toIntOrNull($item['PARENT_ID'] ?? $item['parentId'] ?? $item['parent_id'] ?? null);
            if ($parentId !== 0) {
                continue;
            }

            $title = trim((string) ($item['TITLE'] ?? $item['title'] ?? ''));
            if ($title !== $oldTitle) {
                continue;
            }

            $itemId = $this->toPositiveInt($item['ID'] ?? $item['id'] ?? null);
            if ($itemId === null) {
                continue;
            }

            return $this->checklistItemUpdate($taskId, $itemId, [
                'TITLE' => $newTitle,
            ]);
        }

        return false;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/comment-item/task-comment-item-add.html
     */
    public function commentItemAdd(int|string $taskId, string $message, int|string $authorId = ''): array
    {
        $request = [
            'taskId' => $taskId,
            'fields' => [
                'POST_MESSAGE' => $message,
            ],
        ];
        if ($authorId !== '') {
            $request['fields']['AUTHOR_ID'] = $authorId;
        }

        $response = $this->call(self::METHOD_COMMENT_ADD, $request);
        return $this->normalizeCreatedResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/elapsed-item/task-elapsed-item-add.html
     */
    public function elapsedItemAdd(
        int|string $taskId,
        int $seconds,
        string $commentText = '',
        int|string $authorId = '',
        string $createdDate = ''
    ): array
    {
        $request = [
            'taskId' => $taskId,
            'fields' => [
                'SECONDS' => $seconds,
            ],
        ];
        if ($commentText !== '') {
            $request['fields']['COMMENT_TEXT'] = $commentText;
        }
        if ($authorId !== '') {
            $request['fields']['USER_ID'] = $authorId;
        }
        if ($createdDate !== '') {
            $request['fields']['CREATED_DATE'] = $createdDate;
        }

        $response = $this->call(self::METHOD_ELAPSED_ADD, $request);
        return $this->normalizeCreatedResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/elapsed-item/task-elapsed-item-update.html
     */
    public function elapsedItemUpdate(
        int|string $taskId,
        int|string $itemId,
        int $seconds,
        string $commentText = '',
        string $createdDate = ''
    ): bool
    {
        $request = [
            'taskId' => $taskId,
            'itemId' => $itemId,
            'fields' => [
                'SECONDS' => $seconds,
            ],
        ];
        if ($commentText !== '') {
            $request['fields']['COMMENT_TEXT'] = $commentText;
        }
        if ($createdDate !== '') {
            $request['fields']['CREATED_DATE'] = $createdDate;
        }

        $response = $this->call(self::METHOD_ELAPSED_UPDATE, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/elapsed-item/task-elapsed-item-get.html
     */
    public function elapsedItemGet(int|string $taskId, int|string $itemId, array $params = []): array
    {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['itemId'] = $itemId;

        $response = $this->call(self::METHOD_ELAPSED_GET, $request);
        $result = $response['result'] ?? null;
        if (is_array($result)) {
            return $result;
        }

        return [];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/elapsed-item/task-elapsed-item-get-list.html
     */
    public function elapsedItemGetList(array $params = []): array
    {
        $filter = is_array($params['FILTER'] ?? null) ? $params['FILTER'] : [];
        if (isset($params['filter']) && is_array($params['filter'])) {
            $filter = array_merge($filter, $params['filter']);
        }

        $order = is_array($params['ORDER'] ?? null)
            ? $params['ORDER']
            : (is_array($params['order'] ?? null) ? $params['order'] : []);

        $request = $params;
        unset($request['ORDER'], $request['order'], $request['FILTER'], $request['filter']);

        // task.elapseditem.getlist parses arguments positionally.
        // If FILTER is provided without ORDER, API may treat filter as ORDER and fail.
        if ($filter !== [] && $order === []) {
            $order = ['ID' => 'DESC'];
        }

        $request = [
            ...($order !== [] ? ['ORDER' => $order] : []),
            ...($filter !== [] ? ['FILTER' => $filter] : []),
            ...$request,
        ];

        $response = $this->call(self::METHOD_ELAPSED_GET_LIST, $request);
        $items = $this->normalizeListFromTaskResponse($response, ['elapsedItems', 'elapseditems', 'elapsed']);

        return [
            'items' => $items,
            'next' => $this->extractNext($response),
            'total' => $this->extractTotal($response),
        ];
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/elapsed-item/task-elapsed-item-delete.html
     */
    public function elapsedItemDelete(int|string $taskId, int|string $itemId, array $params = []): bool
    {
        $request = $params;
        $request['taskId'] = $taskId;
        $request['itemId'] = $itemId;

        $response = $this->call(self::METHOD_ELAPSED_DELETE, $request);
        return $this->normalizeSuccessResult($response);
    }

    /**
     * Все записи о затраченном времени по фильтру.
     * Параметры фильтра/сортировки передавайте в формате task.elapseditem.getlist.
     *
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/elapsed-item/task-elapsed-item-get-list.html
     */
    public function elapsedItemAll(array $params = []): array
    {
        $requestBase = $params;
        unset($requestBase['start'], $requestBase['START']);
        $requestBase['ORDER'] = is_array($requestBase['ORDER'] ?? null)
            ? $requestBase['ORDER']
            : (is_array($requestBase['order'] ?? null) ? $requestBase['order'] : ['ID' => 'DESC']);
        $requestBase['ORDER']['ID'] = 'DESC';
        unset($requestBase['order']);
        if (isset($requestBase['filter']) && is_array($requestBase['filter'])) {
            $requestBase['FILTER'] = is_array($requestBase['FILTER'] ?? null)
                ? array_merge($requestBase['FILTER'], $requestBase['filter'])
                : $requestBase['filter'];
            unset($requestBase['filter']);
        }
        $requestBase['FILTER'] = is_array($requestBase['FILTER'] ?? null) ? $requestBase['FILTER'] : [];

        $items = [];
        $lastId = null;
        $iterations = 0;
        $hasIdConflicts = $this->hasIdCursorConflicts($requestBase['FILTER']);
        while (true) {
            $iterations++;
            if ($iterations > self::MAX_ALL_ITERATIONS) {
                throw new RuntimeException('The elapsedItemAll() loop exceeded safe iteration limit.');
            }

            $request = $requestBase;
            if (!$hasIdConflicts && $lastId !== null) {
                $request['FILTER']['<ID'] = $lastId;
            }

            $request = [
                'ORDER' => $request['ORDER'],
                'FILTER' => $request['FILTER'],
                'start' => -1,
                ...$request,
            ];

            $response = $this->call(self::METHOD_ELAPSED_GET_LIST, $request);
            $chunk = $this->normalizeListFromTaskResponse($response, ['elapsedItems', 'elapseditems', 'elapsed']);
            if ($chunk === []) {
                break;
            }
            $items = array_merge($items, $chunk);

            if ($hasIdConflicts || count($chunk) < self::PAGE_SIZE) {
                break;
            }

            $tail = end($chunk);
            if (!is_array($tail)) {
                break;
            }
            $nextLastId = $this->toPositiveInt($tail['ID'] ?? $tail['id'] ?? null);
            if ($nextLastId === null || ($lastId !== null && $nextLastId >= $lastId)) {
                break;
            }
            $lastId = $nextLastId;
        }

        return $items;
    }

    /**
     * Все записи о затраченном времени по задаче.
     *
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/elapsed-item/task-elapsed-item-get-list.html
     */
    public function elapsedItemAllByTaskId(int|string $taskId, array $params = []): array
    {
        $request = $params;
        $request['FILTER'] = is_array($request['FILTER'] ?? null) ? $request['FILTER'] : [];
        $request['FILTER']['TASK_ID'] = $taskId;
        unset($request['filter']);

        return $this->elapsedItemAll($request);
    }

    /**
     * Все записи о затраченном времени по группе:
     * 1) собирает ID задач группы через tasks.task.list;
     * 2) запрашивает elapsed по массиву TASK_ID.
     *
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/elapsed-item/task-elapsed-item-get-list.html
     */
    public function elapsedItemAllByGroupId(int|string $groupId, array $params = []): array
    {
        $taskIds = $this->collectGroupTaskIds((string) $groupId);
        if ($taskIds === []) {
            return [];
        }

        $baseRequest = $params;
        $baseRequest['FILTER'] = is_array($baseRequest['FILTER'] ?? null) ? $baseRequest['FILTER'] : [];
        if (isset($baseRequest['filter']) && is_array($baseRequest['filter'])) {
            $baseRequest['FILTER'] = array_merge($baseRequest['FILTER'], $baseRequest['filter']);
        }
        unset($baseRequest['filter']);
        unset($baseRequest['FILTER']['GROUP_ID']);

        $items = [];
        foreach (array_chunk($taskIds, self::PAGE_SIZE) as $chunkTaskIds) {
            $request = $baseRequest;
            $request['FILTER']['TASK_ID'] = $chunkTaskIds;
            $chunkItems = $this->elapsedItemAll($request);
            if ($chunkItems !== []) {
                $items = array_merge($items, $chunkItems);
            }
        }

        if ($items !== []) {
            $this->sortItemsByOrder($items, ['ID' => 'DESC']);
        }

        return $items;
    }

    /**
     * @see https://apidocs.bitrix24.ru/api-reference/tasks/user-field/task-item-user-field-add.html
     */
    public function itemUserFieldAdd(array $fields, array $params = []): array
    {
        $request = $params;
        $request['fields'] = $fields;

        $response = $this->call(self::METHOD_ITEM_USER_FIELD_ADD, $request);
        return $this->normalizeCreatedResult($response);
    }

    private function normalizeTaskResult(array $response): array
    {
        $task = $this->extractByPath($response, ['result', 'task']);
        if (is_array($task)) {
            return $task;
        }

        return $this->normalizeCreatedResult($response);
    }

    private function normalizeTemplateListResponse(array $response): array
    {
        $raw = $this->extractByPath($response, ['result', 'task_templates'], []);
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = $item;
        }

        if ($items !== []) {
            $this->sortItemsByOrder($items, ['ID' => 'DESC']);
        }

        return $items;
    }

    private function normalizeTemplateChecklistItems(array $response): array
    {
        $raw = $this->extractByPath($response, ['result', 'checkListItems'], []);
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = $item;
        }

        if ($items !== []) {
            usort($items, function (array $left, array $right): int {
                $leftParent = $this->toIntOrNull($left['parentId'] ?? $left['PARENT_ID'] ?? null) ?? 0;
                $rightParent = $this->toIntOrNull($right['parentId'] ?? $right['PARENT_ID'] ?? null) ?? 0;

                if (($leftParent === 0) !== ($rightParent === 0)) {
                    return ($leftParent === 0) ? -1 : 1;
                }

                $leftSort = $this->toIntOrNull($left['sortIndex'] ?? $left['SORT'] ?? null) ?? 0;
                $rightSort = $this->toIntOrNull($right['sortIndex'] ?? $right['SORT'] ?? null) ?? 0;
                if ($leftSort !== $rightSort) {
                    return $leftSort <=> $rightSort;
                }

                $leftId = $this->toIntOrNull($left['id'] ?? $left['ID'] ?? null) ?? 0;
                $rightId = $this->toIntOrNull($right['id'] ?? $right['ID'] ?? null) ?? 0;
                return $leftId <=> $rightId;
            });
        }

        return $items;
    }

    private function buildTaskFieldsFromTemplate(array $template): array
    {
        $fields = [];
        foreach ([
            'TITLE',
            'DESCRIPTION',
            'DESCRIPTION_IN_BBCODE',
            'PRIORITY',
            'TIME_ESTIMATE',
            'ALLOW_CHANGE_DEADLINE',
            'ALLOW_TIME_TRACKING',
            'TASK_CONTROL',
            'ADD_IN_REPORT',
            'MATCH_WORK_TIME',
            'UF_CRM_TASK',
            'ACCOMPLICES',
            'AUDITORS',
        ] as $key) {
            if (!array_key_exists($key, $template)) {
                continue;
            }

            $value = $template[$key];
            if (is_array($value)) {
                if ($value === []) {
                    continue;
                }

                $fields[$key] = $value;
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            $fields[$key] = $value;
        }

        $description = (string) ($fields['DESCRIPTION'] ?? '');
        $description = $this->normalizeDiskFileTagsInDescription($description);
        if ($description !== '') {
            $fields['DESCRIPTION'] = $description;
        }

        $descriptionDiskTokens = $this->extractDiskFileTokensFromDescription($description);
        if ($descriptionDiskTokens !== []) {
            $fields['UF_TASK_WEBDAV_FILES'] = $descriptionDiskTokens;
            $fields['DESCRIPTION_IN_BBCODE'] = 'Y';
        } else {
            $templateFiles = $template['UF_TASK_WEBDAV_FILES'] ?? null;
            if (is_array($templateFiles) && $templateFiles !== []) {
                $fields['UF_TASK_WEBDAV_FILES'] = $templateFiles;
            }
        }

        $groupId = $this->toPositiveInt($template['GROUP_ID'] ?? null);
        if ($groupId !== null) {
            $fields['GROUP_ID'] = $groupId;
        }

        $responsibleId = $this->toPositiveInt($template['RESPONSIBLE_ID'] ?? null);
        if ($responsibleId === null) {
            $responsibleId = $this->toPositiveInt($template['CREATED_BY'] ?? null);
        }
        if ($responsibleId !== null) {
            $fields['RESPONSIBLE_ID'] = $responsibleId;
        }

        return $fields;
    }

    /**
     * Extract values from `[disk file id=...]` tags in task bbcode.
     *
     * @return list<string>
     */
    private function extractDiskFileTokensFromDescription(string $description): array
    {
        if ($description === '') {
            return [];
        }

        if (preg_match_all('/\\[disk\\s+file\\s+id=([^\\]\\s]+)[^\\]]*\\]/i', $description, $matches) !== 1) {
            return [];
        }

        $tokens = $matches[1] ?? [];
        if (!is_array($tokens) || $tokens === []) {
            return [];
        }

        $result = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }

            $value = trim($token);
            if ($value === '') {
                continue;
            }

            $result[] = $value;
        }

        return array_values(array_unique($result));
    }

    private function normalizeDiskFileTagsInDescription(string $description): string
    {
        if ($description === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\\[disk\\s+file\\s+id=([^\\]\\s]+)([^\\]]*)\\]/i',
            static function (array $matches): string {
                $id = trim((string) ($matches[1] ?? ''));
                $tail = (string) ($matches[2] ?? '');
                return "[DISK FILE ID={$id}{$tail}]";
            },
            $description
        );
    }

    private function extractTaskIdFromTaskResult(array $task): ?int
    {
        $id = $this->toPositiveInt($task['id'] ?? null);
        if ($id !== null) {
            return $id;
        }

        $id = $this->toPositiveInt($task['ID'] ?? null);
        if ($id !== null) {
            return $id;
        }

        $id = $this->toPositiveInt($task['taskId'] ?? null);
        if ($id !== null) {
            return $id;
        }

        return null;
    }

    private function createTaskChecklistFromTemplateItems(int $taskId, array $templateItems): array
    {
        if ($templateItems === []) {
            return [];
        }

        $nodesById = [];
        foreach ($templateItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $templateChecklistId = $this->toPositiveInt($item['id'] ?? $item['ID'] ?? null);
            if ($templateChecklistId === null) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? $item['TITLE'] ?? ''));
            if ($title === '') {
                continue;
            }

            $parentId = $this->toIntOrNull($item['parentId'] ?? $item['PARENT_ID'] ?? null) ?? 0;
            $sortIndex = $this->toIntOrNull($item['sortIndex'] ?? $item['SORT'] ?? null) ?? 0;

            $nodesById[$templateChecklistId] = [
                'templateId' => $templateChecklistId,
                'parentId' => $parentId,
                'title' => $title,
                'sortIndex' => $sortIndex,
            ];
        }

        if ($nodesById === []) {
            return [];
        }

        $childrenByParent = [];
        foreach ($nodesById as $templateChecklistId => $node) {
            $parentId = $node['parentId'];
            if ($parentId > 0 && !isset($nodesById[$parentId])) {
                $parentId = 0;
            }

            $childrenByParent[$parentId][] = $templateChecklistId;
        }

        foreach ($childrenByParent as &$children) {
            usort($children, function (int $leftId, int $rightId) use ($nodesById): int {
                $left = $nodesById[$leftId] ?? null;
                $right = $nodesById[$rightId] ?? null;
                if (!is_array($left) || !is_array($right)) {
                    return 0;
                }

                $sortCompare = $left['sortIndex'] <=> $right['sortIndex'];
                if ($sortCompare !== 0) {
                    return $sortCompare;
                }

                return $left['templateId'] <=> $right['templateId'];
            });
        }
        unset($children);

        $createdByTemplateId = [];
        $created = [];
        $createBranch = function (int $templateParentId, int $targetParentId) use (
            &$createBranch,
            $taskId,
            $childrenByParent,
            $nodesById,
            &$createdByTemplateId,
            &$created
        ): void {
            $siblings = $childrenByParent[$templateParentId] ?? [];
            if ($siblings === []) {
                return;
            }

            // Bitrix24 inserts new checklist items at top.
            // To keep natural visual order, create siblings in reverse.
            $reversedTemplateIds = array_reverse($siblings);
            $batchTemplateIds = [];
            $batchItems = [];
            foreach ($reversedTemplateIds as $templateChecklistId) {
                $node = $nodesById[$templateChecklistId] ?? null;
                if (!is_array($node)) {
                    continue;
                }

                $batchTemplateIds[] = $templateChecklistId;
                $batchItems[] = [
                    'TITLE' => $node['title'],
                    'PARENT_ID' => $targetParentId,
                ];
            }

            if ($batchItems === []) {
                return;
            }

            $createdIds = $this->createChecklistItemsBatch($taskId, $batchItems);
            foreach ($createdIds as $index => $createdId) {
                $templateChecklistId = $batchTemplateIds[$index] ?? null;
                if ($templateChecklistId === null) {
                    continue;
                }

                $node = $nodesById[$templateChecklistId] ?? null;
                if (!is_array($node)) {
                    continue;
                }

                $createdByTemplateId[$templateChecklistId] = $createdId;
                $created[] = [
                    'templateId' => $templateChecklistId,
                    'id' => $createdId,
                    'parentId' => $targetParentId,
                    'title' => $node['title'],
                ];
            }

            // Recurse in natural order to keep template tree order deterministic.
            foreach ($siblings as $templateChecklistId) {
                $createdId = $createdByTemplateId[$templateChecklistId] ?? null;
                if (!is_int($createdId) || $createdId <= 0) {
                    continue;
                }

                $createBranch($templateChecklistId, $createdId);
            }
        };
        $createBranch(0, 0);

        return $created;
    }

    /**
     * Create checklist items via batch and return created IDs in input order.
     *
     * @param list<array<string, mixed>> $items
     * @return list<int>
     */
    private function createChecklistItemsBatch(int $taskId, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $commands = [];
        $index = 0;
        foreach ($items as $fields) {
            if (!is_array($fields) || $fields === []) {
                continue;
            }

            $index++;
            $commands['checklist_add_' . $index] = [
                'method' => self::METHOD_CHECKLIST_ADD,
                'params' => [
                    'taskId' => $taskId,
                    'fields' => $fields,
                ],
            ];
        }

        $resultMap = $this->callBatchCommands($commands);
        $createdIds = [];
        foreach (array_keys($commands) as $key) {
            $createdRaw = $resultMap[$key] ?? null;
            $createdId = $this->toPositiveInt($createdRaw);
            if ($createdId === null) {
                continue;
            }

            $createdIds[] = $createdId;
        }

        return $createdIds;
    }

    /**
     * Собирает ID всех задач группы.
     * Использует taskAll() с минимальным select.
     *
     * @return list<int>
     */
    private function collectGroupTaskIds(string $groupId): array
    {
        $items = $this->taskAll([
            'filter' => ['GROUP_ID' => $groupId],
            'select' => ['ID'],
            'order' => ['ID' => 'DESC'],
        ]);

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

        return $ids;
    }

    private function normalizeCreatedResult(array $response): array
    {
        $result = $response['result'] ?? null;
        if (is_array($result)) {
            $id = $result['ID'] ?? $result['id'] ?? null;
            if (is_scalar($id) && $id !== '') {
                return ['id' => (string) $id];
            }

            return $result;
        }

        if (is_scalar($result) && $result !== '') {
            return ['id' => (string) $result];
        }

        return [];
    }

    private function normalizeSuccessResult(array $response): bool
    {
        if (array_key_exists('result', $response) && $response['result'] === null) {
            return true;
        }

        $result = $response['result'] ?? null;
        if (is_array($result)) {
            return true;
        }

        return $this->normalizeBooleanResult($result);
    }

    private function normalizeListFromTaskResponse(array $response, array $priorityKeys = []): array
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            return [];
        }

        foreach ($priorityKeys as $key) {
            if (isset($result[$key]) && is_array($result[$key]) && $this->isListArray($result[$key])) {
                return $result[$key];
            }
        }

        foreach (['tasks', 'checklist', 'checkList', 'checklistItems', 'elapsedItems', 'comments', 'items', 'list'] as $key) {
            if (isset($result[$key]) && is_array($result[$key]) && $this->isListArray($result[$key])) {
                return $result[$key];
            }
        }

        if ($this->isListArray($result)) {
            return $result;
        }

        return [];
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

    private function extractTotal(array $response): ?int
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

    private function normalizeChecklistItems(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $title = trim($item);
                if ($title === '') {
                    continue;
                }

                $result[] = ['TITLE' => $title];
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            if (isset($item['fields']) && is_array($item['fields'])) {
                $result[] = $item['fields'];
                continue;
            }

            if ($item !== []) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function toPositiveInt(mixed $value): ?int
    {
        $parsed = $this->toIntOrNull($value);
        if ($parsed === null || $parsed <= 0) {
            return null;
        }

        return $parsed;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

}
