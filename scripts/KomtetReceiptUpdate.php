<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/**
 * Подключение библиотеки Комтет Касса
 * @link https://github.com/Komtet/komtet-kassa-php-sdk
 */
require_once '../../komtet-kassa-php-sdk/autoload.php';

/**
 * Импорт классов библиотеки Комтет Касса
 * @link https://github.com/Komtet/komtet-kassa-php-sdk
 */
use Komtet\KassaSdk\Client;
use Komtet\KassaSdk\TaskManager;
use Komtet\KassaSdk\Exception\ClientException;

/**
 * ID магазина Комтет Касса
 * @link https://kassa.komtet.ru/integration/api
 * @link https://github.com/Komtet/komtet-kassa-php-sdk
 */
const MARKET_ID = 'MARKET_ID';

/**
 * Секретный ключ магазина Комтет Касса
 * @link https://kassa.komtet.ru/integration/api
 * @link https://github.com/Komtet/komtet-kassa-php-sdk
 */
const MARKET_KEY = 'MARKET_KEY';

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Наименование таблицы для ведения документов */
const RECEIPTS_TABLE = 'receipts_log';

/**
 * Значение параметра "Состояние" успешно выполненной задачи
 * @link https://kassa.komtet.ru/integration/api
 */
const PERFORMED_STATE = 'done';

/**
 * @return SimpleXMLElement Объект конфигурационного файла
 */
function getConfig(): SimpleXMLElement
{
    static $sxe;

    if (!isset($sxe)) {
        try {
            $sxe = new SimpleXMLElement(CONFIG,
                LIBXML_ERR_NONE,
                true);
        } catch (Exception $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }
    }

    return $sxe;
}

/**
 * @return PDO Обработчик запросов к БД
 */
function getConnect(): PDO
{
    static $dbh;

    if (!isset($dbh)) {
        $dsn = sprintf("mysql:host=%s;dbname=%s;charset=utf8",
            getConfig()->parameters->mysql->host,
            getConfig()->parameters->mysql->dbname);

        try {
            $dbh = new PDO($dsn,
                getConfig()->parameters->mysql->username,
                getConfig()->parameters->mysql->password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }
    }

    return $dbh;
}

/**
 * @return array|null Номера документов
 */
function getReceipts(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `" . RECEIPTS_TABLE . "`.`ext_id`
        FROM
            `" . RECEIPTS_TABLE . "`
        WHERE
            `" . RECEIPTS_TABLE . "`.`create_time` > DATE_SUB(
                NOW(),
                INTERVAL :interval DAY
            )
                AND
            `" . RECEIPTS_TABLE . "`.`update_time` > DATE_SUB(
                NOW(),
                INTERVAL :interval DAY
            )
                AND
            `" . RECEIPTS_TABLE . "`.`ext_id` IS NOT NULL
                AND
            `" . RECEIPTS_TABLE . "`.`error` IS NULL
                AND
            `" . RECEIPTS_TABLE . "`.`state` != :performedState");

    $sth->bindValue(':performedState', PERFORMED_STATE);
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
            `" . RECEIPTS_TABLE . "`
        SET
            `" . RECEIPTS_TABLE . "`.`state` = :state,
            `" . RECEIPTS_TABLE . "`.`error` = :error
        WHERE
            `" . RECEIPTS_TABLE . "`.`ext_id` = :extId");
    
    $sth->bindParam(':extId', $extId);
    $sth->bindParam(':state', $state);
    $sth->bindParam(':error',
        $error, (!is_null($error))
            ? PDO::PARAM_STR
            : PDO::PARAM_NULL);
    
    $sth->execute();
}

try {
    /** Получение параметров заказов */
    !is_null($receipts = getReceipts()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var Client $client Экземпляр клиента Комтет Касса */
$client = new Client(MARKET_ID, MARKET_KEY, null);

/** @var TaskManager $taskManager Экземпляр менеджера задач Комтет Касса */
$taskManager = new TaskManager($client);

/** @var string $v Номер документа */
foreach ($receipts as $v) {

    /** @var string $extId Номер задачи (документа) */
    $extId = strval($v);

    try {
        /** @var array $status Ответ Комтет Касса */
        $status = $taskManager->getTaskInfo($extId);
    } catch (ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());

        continue;
    }

    try {
        /** Обновление информации о документе */
        updateReceipt($extId,
            $status['state'], $status['error_description']);
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }
}
