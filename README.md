# b24-rest

REST-обёртка Bitrix24 для интеграций с Bitrix24.

## Установка

```bash
composer require denx-b/b24-rest:dev-main
```

## Базовая инициализация

```php
use B24Rest\Rest\Bitrix24RestFactory;

$b24 = Bitrix24RestFactory::fromWebhook('https://portal.bitrix24.ru/rest/1/<webhook>/');
```

## Bitrix24 REST: Сделки (примеры)

```php
// Получить список сделок через crm.item.list: страница N, фиксированный размер страницы = 50 (сортировка по умолчанию ID DESC)
$page = $b24->deals()->list([
    'select' => ['ID', 'TITLE', 'STAGE_ID'],
], 1);
```

```php
// Выгрузить все сделки (start = -1 + внутренний курсор по <ID, сортировка ID DESC)
$allDeals = $b24->deals()->all([
    'select' => ['ID', 'TITLE', 'STAGE_ID'],
]);
```

```php
// Выгрузить все сделки с фильтром
$allInWork = $b24->deals()->all([
    'filter' => ['STAGE_ID' => 'NEW'],
    'select' => ['ID', 'TITLE', 'STAGE_ID'],
]);
```

```php
// Получить по ID
$deal = $b24->deals()->getById(123);
```

```php
// Добавить
$created = $b24->deals()->add([
    'TITLE' => 'Сделка из API',
    'STAGE_ID' => 'NEW',
]);
```

```php
// Обновить
$ok = $b24->deals()->update(123, [
    'TITLE' => 'Обновлённый заголовок',
]);
```

```php
// Массовое добавление (под капотом crm.item.add через batch, с чанками по 50)
$created = $b24->deals()->addMany([
    ['TITLE' => 'Сделка #1', 'STAGE_ID' => 'NEW'],
    ['TITLE' => 'Сделка #2', 'STAGE_ID' => 'NEW'],
]);
```

```php
// Массовое обновление (под капотом crm.item.update через batch, с чанками по 50)
$updated = $b24->deals()->updateMany([
    ['id' => 123, 'fields' => ['TITLE' => 'Сделка #123 v2']],
    ['id' => 124, 'fields' => ['TITLE' => 'Сделка #124 v2']],
]);
```

```php
// Удалить
$deleted = $b24->deals()->delete(123);
```

```php
// Добавить товарную позицию в сделку
$row = $b24->deals()->productRowAdd(359, [
    'productName' => 'Пакет услуг',
    'price' => 1000,
    'quantity' => 1,
]);
```

```php
// Обновить товарную позицию
$row = $b24->deals()->productRowUpdate(5001, [
    'price' => 1500,
    'quantity' => 2,
]);
```

```php
// Получить товарную позицию по ID
$row = $b24->deals()->productRowGet(5001);
```

```php
// Получить список товарных позиций по сделке
$rows = $b24->deals()->productRowList(359);
```

```php
// Удалить товарную позицию
$deleted = $b24->deals()->productRowDelete(5001);
```

```php
// Направления сделок (воронки)
$directions = $b24->dealCategories()->list();
```

```php
// Направление сделки по ID
$direction = $b24->dealCategories()->getById(1);
```

```php
// Добавить направление сделки
$createdDirection = $b24->dealCategories()->add([
    'name' => 'Новая воронка',
    'sort' => 500,
]);
```

```php
// Обновить направление сделки
$updatedDirection = $b24->dealCategories()->update(1, [
    'name' => 'Воронка v2',
]);
```

```php
// Удалить направление сделки
$deletedDirection = $b24->dealCategories()->delete(1);
```

```php
// Получить список стадий выбранного направления (ID категории)
$stages = $b24->dealCategoryStages()->listByCategoryId(1);
```

```php
// Получить список стадий (любой фильтр ENTITY_ID можно передать в params)
$allStages = $b24->dealCategoryStages()->list([
    'filter' => ['ENTITY_ID' => 'DEAL_STAGE_1'],
]);
```

```php
// Получить стадию по ID
$stage = $b24->dealCategoryStages()->getById(100);
```

```php
// Добавить стадию (с явным ENTITY_ID)
$createdStage = $b24->dealCategoryStages()->add([
    'ENTITY_ID' => 'DEAL_STAGE_1',
    'STATUS_ID' => 'NEW_CUSTOM_STAGE',
    'NAME' => 'Новая пользовательская стадия',
    'SORT' => 500,
]);
```

```php
// Добавить стадию в выбранное направление
$createdStage = $b24->dealCategoryStages()->addForCategory(1, [
    'STATUS_ID' => 'NEW_CUSTOM_STAGE',
    'NAME' => 'Новая пользовательская стадия',
    'SORT' => 500,
]);
```

```php
// Добавить стадию в выбранное направление (STATUS_ID генерируется автоматически)
$createdStage = $b24->dealCategoryStages()->addForCategoryWithGeneratedStatusId(
    1,
    'Стадия с авто STATUS_ID'
);
```

```php
// Обновить стадию
$updatedStage = $b24->dealCategoryStages()->updateForCategory(1, 100, [
    'NAME' => 'Переименованная стадия',
]);
```

```php
// Обновить стадию по ID (с явным ENTITY_ID)
$updatedStage = $b24->dealCategoryStages()->update(100, [
    'ENTITY_ID' => 'DEAL_STAGE_1',
    'NAME' => 'Переименованная стадия',
]);
```

```php
// Удалить стадию
$deletedStage = $b24->dealCategoryStages()->delete(100);
```

## Bitrix24 REST: Другие сущности crm.item (примеры)

```php
// Лиды
$lead = $b24->leads()->list();
```

```php
// Контакты
$contactsPage = $b24->contacts()->list();
```

```php
// Компании
$companyId = $b24->companies()->list();
```

```php
// Счета
$invoice = $b24->invoices()->list();
```

```php
// Предложения
$quote = $b24->quotes()->list();
```

```php
// Элементы смарт-процесса (пример entityTypeId=136)
$smartItems = $b24->smartItems(1086)->list();
```

`contacts()` и `companies()` поддерживают только `crm.item.*` (без `productRow*`).

## Bitrix24 REST: Структура компании (примеры)

```php
// Все департаменты (внутренняя пагинация по start/next, сортировка по умолчанию ID DESC)
$departments = $b24->departments()->all();
```

```php
// Департамент по ID
$department = $b24->departments()->getById(1);
```

```php
// Сотрудники департамента по ID департамента
$departmentUsers = $b24->departments()->getUsersById(1, [
    'SELECT' => ['ID', 'NAME', 'LAST_NAME', 'UF_DEPARTMENT'],
]);
```

```php
// Добавить департамент
$createdDepartment = $b24->departments()->add([
    'NAME' => 'Новый департамент',
    'PARENT' => 1,
]);
```

```php
// Обновить департамент
$updatedDepartment = $b24->departments()->update(100, [
    'NAME' => 'Переименованный департамент',
]);
```

```php
// Удалить департамент
$deletedDepartment = $b24->departments()->delete(100);
```

## Bitrix24 REST: Задачи (примеры)

```php
// Добавить задачу
$task = $b24->tasks()->taskAdd([
    'TITLE' => 'Задача из API',
    'RESPONSIBLE_ID' => 1,
]);
```

```php
// Обновить задачу
$ok = $b24->tasks()->taskUpdate(100, [
    'TITLE' => 'Обновлённый заголовок задачи',
]);
```

```php
// Получить задачу
$task = $b24->tasks()->taskGet(100);
```

```php
// Список задач (page = 1, фиксированный размер страницы = 50)
$tasks = $b24->tasks()->taskList([
    'order' => ['ID' => 'DESC'],
    'select' => ['ID', 'TITLE', 'STATUS'],
], 1);
```

```php
// Все задачи (start=-1 + курсор по ID)
$allTasks = $b24->tasks()->taskAll([
    'filter' => ['GROUP_ID' => 7],
    'select' => ['ID', 'TITLE', 'STATUS'],
]);
```

```php
// Список шаблонов задач (недокументированный метод, на практике используем start=-1)
$templates = $b24->tasks()->templateList();
```

```php
// Шаблон задачи по ID
$template = $b24->tasks()->templateGet(3);
```

```php
// Чек-лист шаблона задачи
$templateChecklist = $b24->tasks()->templateChecklistList(3);
```

```php
// Создать задачу по шаблону (поля задачи можно переопределить)
$created = $b24->tasks()->taskAddFromTemplate(3, [
    'RESPONSIBLE_ID' => 1,
    'TITLE' => 'Задача из шаблона #3',
]);
```

```php
// Создать задачу по шаблону (минимально)
$created = $b24->tasks()->taskAddFromTemplate(3, ['RESPONSIBLE_ID' => 1]);
```

```php
// Прикрепить файл к задаче
$ok = $b24->tasks()->taskFilesAttach(100, 555);
```

```php
// Делегировать задачу
$ok = $b24->tasks()->taskDelegate(100, 1);
```

```php
// Завершить задачу
$ok = $b24->tasks()->taskComplete(100);
```

```php
// Удалить задачу
$ok = $b24->tasks()->taskDelete(100);
```

```php
// Добавить пункты чек-листа (всегда через batch, даже для одного пункта): создать новый чек-лист по названию
$checklistItems = $b24->tasks()->checklistItemAdd(100, 'Основной чек-лист', [
    ['TITLE' => 'Первый пункт'],
    ['TITLE' => 'Второй пункт'],
]);
```

```php
// Добавить пункты в существующий чек-лист (checklistId / parentId)
$checklistItems = $b24->tasks()->checklistItemAdd(100, '', [
    ['TITLE' => 'Третий пункт'],
], [
    'checklistId' => 200,
]);
```

```php
// Обновить пункт чек-листа
$ok = $b24->tasks()->checklistItemUpdate(100, 200, [
    'TITLE' => 'Пункт обновлён',
]);
```

```php
// Получить пункт чек-листа
$checklistItem = $b24->tasks()->checklistItemGet(100, 200);
```

```php
// Получить список пунктов чек-листа
$checklist = $b24->tasks()->checklistItemGetList(100);
```

```php
// Переместить пункт чек-листа после другого пункта
$ok = $b24->tasks()->checklistItemMoveAfterItem(100, 201, 200);
```

```php
// Отметить пункт чек-листа как выполненный
$ok = $b24->tasks()->checklistItemComplete(100, 200);
```

```php
// Возобновить пункт чек-листа
$ok = $b24->tasks()->checklistItemRenew(100, 200);
```

```php
// Удалить пункт чек-листа
$ok = $b24->tasks()->checklistItemDelete(100, 200);
```

```php
// Переименовать чек-лист по текущему названию
$ok = $b24->tasks()->checklistRenameByTitle(100, 'Основной чек-лист', 'Основной чек-лист v2');
```

```php
// Добавить комментарий к задаче
$comment = $b24->tasks()->commentItemAdd(100, 'Комментарий к задаче из API', 1);
```

```php
// Добавить запись о затраченном времени
$elapsed = $b24->tasks()->elapsedItemAdd(100, 600, 'Потратил 10 минут', 1);
```

```php
// Обновить запись о затраченном времени
$ok = $b24->tasks()->elapsedItemUpdate(100, 300, 900, 'Потратил 15 минут');
```

```php
// Получить запись о затраченном времени
$elapsedItem = $b24->tasks()->elapsedItemGet(100, 300);
```

```php
// Получить список записей о затраченном времени
$elapsedItems = $b24->tasks()->elapsedItemGetList([
    'filter' => ['TASK_ID' => 100],
    'order' => ['ID' => 'DESC'],
]);
```

```php
// Удалить запись о затраченном времени
$ok = $b24->tasks()->elapsedItemDelete(100, 300);
```

```php
// Получить все записи о затраченном времени по произвольному фильтру
$allElapsed = $b24->tasks()->elapsedItemAll([
    'filter' => ['USER_ID' => 1],
    'order' => ['ID' => 'DESC'],
]);
```

```php
// Получить все записи о затраченном времени по задаче
$allTaskElapsed = $b24->tasks()->elapsedItemAllByTaskId(100);
```

```php
// Получить все записи о затраченном времени по группе (сначала собираются ID задач группы, затем выборка по TASK_ID)
$allGroupElapsed = $b24->tasks()->elapsedItemAllByGroupId(7);
```

```php
// Добавить пользовательское поле задачи
$userField = $b24->tasks()->itemUserFieldAdd([
    'ENTITY_ID' => 'TASKS_TASK',
    'FIELD_NAME' => 'UF_API_FIELD',
    'USER_TYPE_ID' => 'string',
    'EDIT_FORM_LABEL' => ['ru' => 'Поле API'],
]);
```

## Bitrix24 REST: Типы цен (примеры)

```php
// Список типов цен (page = 1, фиксированный размер страницы = 50)
$priceTypesPage = $b24->priceTypes()->list([
    'select' => ['id', 'name', 'xmlId'],
], 1);
```

```php
// Все типы цен (start=-1 + внутренний курсор по <id)
$allPriceTypes = $b24->priceTypes()->all([
    'select' => ['id', 'name', 'xmlId'],
]);
```

```php
// Тип цены по ID
$priceType = $b24->priceTypes()->getById(2);
```

```php
// Добавить тип цены
$createdPriceType = $b24->priceTypes()->add([
    'name' => 'Base wholesale price',
    'base' => 'N',
    'sort' => 200,
    'xmlId' => 'basewholesale',
]);
```

```php
// Обновить тип цены
$updatedPriceType = $b24->priceTypes()->update(2, [
    'name' => 'Base wholesale price v2',
    'sort' => 300,
]);
```

```php
// Удалить тип цены
$deletedPriceType = $b24->priceTypes()->delete(2);
```

```php
// Получить доступные поля типа цены
$priceTypeFields = $b24->priceTypes()->getFields();
```

## Bitrix24 REST: Единицы измерения (примеры)

```php
// Список единиц измерения (page = 1, фиксированный размер страницы = 50)
$measuresPage = $b24->measures()->list([
    'select' => ['id', 'code', 'symbolIntl'],
], 1);
```

```php
// Все единицы измерения (start=-1 + внутренний курсор по <id)
$allMeasures = $b24->measures()->all([
    'select' => ['id', 'code', 'symbolIntl'],
]);
```

```php
// Единица измерения по ID
$measure = $b24->measures()->getById(6);
```

```php
// Добавить единицу измерения
$createdMeasure = $b24->measures()->add([
    'code' => 800,
    'measureTitle' => 'Комплект',
    'symbolLetterIntl' => 'set',
    'symbolIntl' => 'pcs',
]);
```

```php
// Обновить единицу измерения
$updatedMeasure = $b24->measures()->update(6, [
    'measureTitle' => 'Комплект v2',
    'symbolIntl' => 'set.',
]);
```

```php
// Удалить единицу измерения
$deletedMeasure = $b24->measures()->delete(6);
```

```php
// Получить доступные поля единиц измерения
$measureFields = $b24->measures()->getFields();
```

## Bitrix24 REST: Валюты (примеры)

```php
// Список валют (page = 1, фиксированный размер страницы = 50)
$currenciesPage = $b24->currencies()->list([
    'order' => ['currency' => 'DESC'],
], 1);
```

```php
// Все валюты (первый запрос с start=-1)
$allCurrencies = $b24->currencies()->all([
    'order' => ['currency' => 'DESC'],
]);
```

```php
// Валюта по коду
$currency = $b24->currencies()->getById('USD');
```

```php
// Добавить валюту
$createdCurrency = $b24->currencies()->add([
    'CURRENCY' => 'CNY',
    'AMOUNT' => 1,
    'AMOUNT_CNT' => 1,
    'SORT' => 100,
]);
```

```php
// Обновить валюту
$updatedCurrency = $b24->currencies()->update('CNY', [
    'AMOUNT' => 15.3449,
]);
```

```php
// Удалить валюту
$deletedCurrency = $b24->currencies()->delete('IDR');
```

```php
// Получить базовую валюту
$baseCurrency = $b24->currencies()->baseGet();
```

```php
// Установить базовую валюту
$setBaseCurrency = $b24->currencies()->baseSet('RUB');
```
