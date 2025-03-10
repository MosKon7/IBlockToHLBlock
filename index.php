<?php
require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");

use Bitrix\Main\Application;
use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;
use Bitrix\Iblock;

$APPLICATION->SetTitle("Перенос данных в Highload-блок");

// Убедитесь, что необходимые модули загружены
if (!Loader::includeModule('highloadblock') || !Loader::includeModule('iblock')) {
    die('Необходимые модули не загружены.');
}

/**
 * Возвращает ID существующего Highload-блока или создает новый.
 *
 * @param string $hlBlockName Название Highload-блока.
 * @param string $tableName Название таблицы для Highload-блока.
 * @return int ID созданного Highload-блока.
 */
function getOrCreateHighloadBlock(string $hlBlockName, string $tableName): int
{
    // Проверяем существование Highload-блока
    $hlBlock = HighloadBlockTable::getList([
        'filter' => ['NAME' => $hlBlockName],
        'select' => ['ID']
    ])->fetch();

    if ($hlBlock) {
        return $hlBlock['ID'];
    }

    $result = HighloadBlockTable::add([
        'NAME' => $hlBlockName,
        'TABLE_NAME' => $tableName,
    ]);

    if (!$result->isSuccess()) {
        $errors = $result->getErrorMessages();
        die('Ошибка при создании Highload-блока: ' . implode(', ', $errors));
    }

    $hlBlockId = $result->getId();

    // Добавляем поля в Highload-блок
    HighloadBlockTable::compileEntity(
        HighloadBlockTable::getById($hlBlockId)->fetch()
    );

    $hlEntity = HighloadBlockTable::compileEntity($hlBlockId);
    $hlDataClass = $hlEntity->getDataClass();

    // Добавляем пользовательские поля
    $oUserTypeEntity = new CUserTypeEntity();
    $nameUserFieldId = $oUserTypeEntity->Add(array(
        'ENTITY_ID' => 'HLBLOCK_' . $hlBlockId,
        'FIELD_NAME' => 'UF_NAME',
        'XML_ID' => 'UF_NAME',
        'USER_TYPE_ID' => 'string',
        'EDIT_FORM_LABEL' => array('ru' => 'Название', 'en' => 'Name'),
    ));
    $parentUserFieldId = $oUserTypeEntity->Add(array(
        'ENTITY_ID' => 'HLBLOCK_' . $hlBlockId,
        'FIELD_NAME' => 'UF_PARENT',
        'XML_ID' => 'UF_PARENT',
        'USER_TYPE_ID' => 'integer',
        'EDIT_FORM_LABEL' => array('ru' => 'Родительский ID', 'en' => 'Parent ID'),
    ));

    /**
     * TODO ДЛЯ НОВОГО ЯДРА ТРЕБУЕТСЯ ОБНОВЛЯТЬ ПРАВА ДОСТУПА ДЛЯ HLBLOCK
     */
    return $hlBlockId;
}

/**
 * Переносит элементы инфоблока в Highload-блок.
 *
 * @param int $iblockId ID инфоблока.
 * @param int|null $rootSectionId ID корневого раздела или null, если фильтрация не нужна.
 * @param int $hlBlockId ID Highload-блока.
 */
function transferElementsToHighloadBlock(int $iblockId, ?int $rootSectionId, int $hlBlockId): void
{
    // Получаем все разделы инфоблока
    $arResult = [];
    $arSelectSections = Array("ID", "NAME", "IBLOCK_SECTION_ID");
    $arFilterSections = Array("IBLOCK_ID" => $iblockId, "ACTIVE" => "Y");
    if(!empty($rootSectionId)){
        $arFilterSections["IBLOCK_SECTION_ID"] = $rootSectionId;
    }

    $resSections = CIBlockSection::GetList(Array(), $arFilterSections, false, $arSelectSections);
    while ($arSection = $resSections->GetNext()) {
        if (!empty($arSection["IBLOCK_SECTION_ID"]) && $arSection["IBLOCK_SECTION_ID"] == $rootSectionId) {
            $arResult[$arSection['ID']] = Array(
                "ID" => $arSection['ID'],
                "NAME" => $arSection['NAME'],
            );
        } else if (!empty($arSection["IBLOCK_SECTION_ID"])) {
            $arResult[$arSection['IBLOCK_SECTION_ID']]["ITEMS"][$arSection['ID']] = Array(
                "ID" => $arSection['ID'],
                "NAME" => $arSection['NAME'],
            );
        }
    }

    // Получение элементов инфоблока
    $arSelect = Array("ID", "NAME", "IBLOCK_SECTION_ID");
    $arFilter = Array("IBLOCK_ID" => $iblockId, "ACTIVE" => "Y");
    $res = CIBlockElement::GetList(Array(), $arFilter, false, false, $arSelect);
    while ($ob = $res->GetNextElement()) {
        $arFields = $ob->GetFields();
        if (!empty($arFields['IBLOCK_SECTION_ID'])) {
            addItemsToSection($arFields, $arResult);
        }
    }

    // Массив для хранения соответствия старых и новых ID
    $idMapping = [];

    // Переносим разделы и элементы в Highload-блок
    foreach ($arResult as $sectionId => $branch) {
        addBranchToHighloadBlock($branch, $hlBlockId, $idMapping, null);
    }

//    echo "<pre>";
//    var_dump($arResult);
//    echo "</pre>";
}

/**
 * Добавляет элементы в соответствующий раздел.
 *
 * @param array $arFields Поля элемента.
 * @param array $sections Массив разделов и элементов.
 */
function addItemsToSection(array $arFields, array &$sections): void
{
    $sectionId = $arFields['IBLOCK_SECTION_ID'];
    $item = Array(
        "ID" => $arFields['ID'],
        "NAME" => $arFields['NAME'],
    );

    // Проверяем, есть ли такой раздел в массиве
    foreach ($sections as &$section) {
        if ($section['ID'] == $sectionId) {
            $section["ITEMS"][$item["ID"]] = $item;
            return;
        } elseif (!empty($section["ITEMS"])) {
            addItemsToSection($arFields, $section["ITEMS"]);
        }
    }
}

/**
 * Рекурсивно добавляет разделы и элементы в Highload-блок.
 *
 * @param array $branch Ветка разделов и элементов.
 * @param int $hlBlockId ID Highload-блока.
 * @param array $idMapping Массив для хранения соответствия старых и новых ID.
 * @param int|null $parentId Родительский ID для текущей ветки.
 */
function addBranchToHighloadBlock(array $branch, int $hlBlockId, array &$idMapping, int $parentId = null): void
{
    $arHLBlock = HighloadBlockTable::getById($hlBlockId)->fetch();
    $hlEntity = HighloadBlockTable::compileEntity($arHLBlock);
    $hlDataClass = $hlEntity->getDataClass();

    // Проверяем существование элемента
    $existingItem = $hlDataClass::getList([
        'filter' => ['UF_NAME' => $branch['NAME'], 'UF_PARENT' => $parentId],
        'select' => ['ID']
    ])->fetch();

    if ($existingItem) {
        // Обновляем существующий элемент
        $result = $hlDataClass::update($existingItem['ID'], [
            'UF_NAME' => $branch['NAME'],
            'UF_PARENT' => $parentId,
        ]);
        $newSectionId = $existingItem['ID'];
    } else {
        // Создаем новый элемент
        $result = $hlDataClass::add([
            'UF_NAME' => $branch['NAME'],
            'UF_PARENT' => $parentId,
        ]);
        $newSectionId = $result->getId();
    }

    if (!$result->isSuccess()) {
        $errors = $result->getErrorMessages();
        die('Ошибка при добавлении раздела в Highload-блок: ' . implode(', ', $errors));
    }

    // Сохраняем соответствие старого и нового ID
    $idMapping[(int)$branch['ID']] = $newSectionId;

    // Рекурсивно добавляем вложенные элементы
    if (!empty($branch['ITEMS'])) {
        foreach ($branch['ITEMS'] as $item) {
            addBranchToHighloadBlock($item, $hlBlockId, $idMapping, $newSectionId);
        }
    }
}
?>

<style>
    .error { color: red; }
</style>

<h1><?php echo $APPLICATION->GetTitle(false);?></h1>

<form action="" method="post">
    <label for="iblock_id">ID инфоблока:</label>
    <input type="number" id="iblock_id" name="iblock_id" required><br><br>

    <label for="root_section_id">ID корневого раздела:</label>
    <input type="number" id="root_section_id" name="root_section_id" required><br><br>

    <label for="hl_block_name">Название Highload-блока:</label>
    <input type="text" id="hl_block_name" name="hl_block_name" pattern="^[A-Z][A-Za-z0-9]*$" required>
    <span class="error">* Должно начинаться с заглавной буквы и состоять только из латинских букв и цифр</span><br><br>

    <label for="table_name">Название таблицы:</label>
    <input type="text" id="table_name" name="table_name" pattern="^[a-z0-9_]+$" required>
    <span class="error">* Должно состоять только из строчных латинских букв, цифр и знака подчеркивания</span><br><br>

    <button type="submit">Запустить перенос</button>
</form>

<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Получаем данные из формы
    $iblockId = intval($_POST['iblock_id']);
    $rootSectionId = isset($_POST['root_section_id']) ? intval($_POST['root_section_id']) : null;
    $hlBlockName = $_POST['hl_block_name'];
    $tableName = $_POST['table_name'];

    // Проверяем корректность введенных данных
    if (!preg_match("/^[A-Z][A-Za-z0-9]*$/", $hlBlockName)) {
        die("Ошибка: Название Highload-блока должно начинаться с заглавной буквы и состоять только из латинских букв и цифр.");
    }

    if (!preg_match("/^[a-z0-9_]+$/", $tableName)) {
        die("Ошибка: Название таблицы должно состоять только из строчных латинских букв, цифр и знака подчеркивания.");
    }

    // Создаем Highload-блок и переносим данные
    $hlBlockId = getOrCreateHighloadBlock($hlBlockName, $tableName);
    transferElementsToHighloadBlock($iblockId, $rootSectionId, $hlBlockId);

    echo 'Перенос элементов завершен.';
}
?>

<?php require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php"); ?>
