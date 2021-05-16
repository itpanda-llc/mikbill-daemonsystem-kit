<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-kit
 */

declare(strict_types=1);

/**
 * ID магазина Комтет Касса
 * @link https://kassa.komtet.ru/integration/api
 * @link https://github.com/Komtet/komtet-kassa-php-sdk
 */
const KOMTET_MARKET_ID = '***';

/**
 * Секретный ключ магазина Комтет Касса
 * @link https://kassa.komtet.ru/integration/api
 * @link https://github.com/Komtet/komtet-kassa-php-sdk
 */
const KOMTET_MARKET_KEY = '***';

/**
 * Путь к конфигурационному файлу MikBill
 * @link https://wiki.mikbill.pro/billing/config_file
 */
const CONFIG = '/var/www/mikbill/admin/app/etc/config.xml';

/** Наименование таблицы для ведения документов */
const RECEIPTS_TABLE_NAME = '__komtet_receipts_log';

/** Наименование колонки "Время обновления" */
const UPDATE_TIME_COLUMN_NAME = 'update_time';

/** Наименование колонки "Внешний номер" */
const EXT_ID_COLUMN_NAME = 'ext_id';

/** Наименование колонки "Состояние задачи" */
const STATE_COLUMN_NAME = 'state';

/** Наименование колонки "Описание ошибки" */
const ERROR_COLUMN_NAME = 'error';

/**
 * Значение параметра "Состояние" успешно выполненной задачи
 * @link https://kassa.komtet.ru/integration/api
 */
const KOMTET_DONE_STATE = 'done';

/**
 * Значение параметра "Состояние" ошибки выполнения задачи
 * @link https://kassa.komtet.ru/integration/api
 */
const KOMTET_ERROR_STATE = 'error';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once '../../../autoload.php';

use Komtet\KassaSdk;

/**
 * @return array|null Номера документов
 */
function getReceipts(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `" . EXT_ID_COLUMN_NAME . "`
        FROM
            `" . RECEIPTS_TABLE_NAME . "`
        WHERE
            `" . UPDATE_TIME_COLUMN_NAME . "` > DATE_SUB(
                NOW(),
                INTERVAL :interval DAY
            )
                AND
            `" . EXT_ID_COLUMN_NAME . "` IS NOT NULL
                AND
            `" . STATE_COLUMN_NAME . "` != :doneState
                AND
            `" . STATE_COLUMN_NAME . "` != :errorState");

    $sth->bindValue(':doneState', KOMTET_DONE_STATE);
    $sth->bindValue(':errorState', KOMTET_ERROR_STATE);
    $sth->bindParam(':interval', $_SERVER['argv'][1]);

    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_COLUMN);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $extId Номер документа
 * @param string $state Состояние задачи
 * @param string|null $error Описание ошибки
 */
function updateReceipt(string $extId,
                       string $state,
                       ?string $error): void
{
    static $sth;
    
    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `" . RECEIPTS_TABLE_NAME . "`
        SET
            `" . STATE_COLUMN_NAME . "` = :state,
            `" . ERROR_COLUMN_NAME . "` = :error
        WHERE
            `" . EXT_ID_COLUMN_NAME . "` = :extId");
    
    $sth->bindParam(':extId', $extId);
    $sth->bindParam(':state', $state);
    $sth->bindParam(':error',
        $error, (!is_null($error))
            ? PDO::PARAM_STR
            : PDO::PARAM_NULL);
    
    $sth->execute();
}

try {
    !is_null($receipts = getReceipts()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$taskManager = new KassaSdk\TaskManager(
    new KassaSdk\Client(KOMTET_MARKET_ID, KOMTET_MARKET_KEY));

foreach ($receipts as $v)
    try {
        $status = $taskManager->getTaskInfo($v);

        updateReceipt($v,
            $status['state'],
            $status['error_description']);
    } catch (
        InvalidArgumentException
        | KassaSdk\Exception\ApiValidationException
        | KassaSdk\Exception\ClientException $e
    ) {
        echo sprintf("%s\n", $e->getMessage());
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }
