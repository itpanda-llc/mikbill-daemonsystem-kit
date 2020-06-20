<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/**
 * Подключение библиотеки СМСПилот
 * @link https://github.com/itpanda-llc/smspilot-messenger-php-sdk
 */
require_once '../../smspilot-messenger-php-sdk/autoload.php';

/**
 * Импорт классов библиотеки СМСПилот
 * @link https://github.com/itpanda-llc/smspilot-messenger-php-sdk
 */
use Panda\SMSPilot\MessengerSDK\Pilot;
use Panda\SMSPilot\MessengerSDK\Singleton;
use Panda\SMSPilot\MessengerSDK\Format;
use Panda\SMSPilot\MessengerSDK\Exception\ClientException;

/**
 * API-ключ СМСПилот
 * @link https://smspilot.ru/apikey.php
 */
const SMS_PILOT_KEY = 'SMS_PILOT_KEY';

/**
 * Имя отправителя СМСПилот
 * @link https://smspilot.ru/my-sender.php
 */
const SMS_PILOT_NAME = 'SMS_PILOT_NAME';

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Наименование таблицы для ведения документов */
const RECEIPTS_TABLE = 'receipts_log';

/**
 * Значение параметра состояния задачи "Успешное выполнение"
 * @link https://kassa.komtet.ru/integration/api
 */
const PERFORMED_STATE = 'done';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

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
 * @return array Параметры документов
 */
function getReceipts(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `" . RECEIPTS_TABLE . "`.`user_id`,
            `" . RECEIPTS_TABLE . "`.`int_id`,
            `" . RECEIPTS_TABLE . "`.`ext_id`,
            IF (
                SUBSTRING(
                    `" . RECEIPTS_TABLE . "`.`contact`, 1, 1
                ) = '+',
                SUBSTRING(
                    `" . RECEIPTS_TABLE . "`.`contact`, 2
                ),
                `" . RECEIPTS_TABLE . "`.`contact`
            ) AS
                `contact`
        FROM
            `" . RECEIPTS_TABLE . "`
        WHERE
            `" . RECEIPTS_TABLE . "`.`update_time` > DATE_SUB(
                NOW(),
                INTERVAL :interval MINUTE
            )
                AND
            `" . RECEIPTS_TABLE . "`.`state` = :performedState
                AND
            `" . RECEIPTS_TABLE . "`.`contact` != ''");

    $sth->bindValue(':performedState', PERFORMED_STATE);
    $sth->bindParam(':interval', $_SERVER['argv'][1]);
    
    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $id Номер документа Комтет Касса
 * @param string $extId Номер документа
 * @return string Текст сообщения
 */
function getMessage(string $id, string $extId): string
{
    return sprintf("Чек https://kassa.komtet.ru/"
        . "receipts?id=%s&external_id=%s",
        $id,
        $extId);
}

/**
 * @param string $uId ID пользователя
 * @param string $phone Номер телефона
 * @param string $text Текст сообщения
 * @param string $errorText Текст ошибки
 */
function logMessage(string $uId,
                    string $phone,
                    string $text,
                    string $errorText): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `sms_logs` (
                `sms_type_id`,
                `uid`,
                `sms_phone`,
                `sms_text`,
                `sms_error_text`
            )
        VALUES (
            0,
            :uId,
            :phone,
            :text,
            :errorText
        )");

    $sth->bindParam(':uId', $uId);
    $sth->bindParam(':phone', $phone);
    $sth->bindParam(':text', $text);
    $sth->bindParam(':errorText', $errorText);

    $sth->execute();
}

try {
    /** Получение параметров документов */
    !is_null($receipts = getReceipts()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var Pilot $pilot Экземпляр отправителя СМСПилот */
$pilot = new Pilot(SMS_PILOT_KEY);

/** @var array $v Параметры документа */
foreach ($receipts as $v) {

    /** @var string $message Текст сообщения */
    $message = getMessage($v['ext_id'], $v['int_id']);

    /** @var Singleton $singleton Сообщение */
    $singleton = new Singleton($message,
        $v['contact'], SMS_PILOT_NAME);

    /** Добавление параметра "Формат ответа" */
    $singleton->addParam(Format::get(Format::JSON));

    try {
        /** @var stdClass $j Ответ СМСПилот */
        $j = json_decode($pilot->request($singleton));
    } catch (ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());

        /** @var string $error Текст ошибки */
        $error = ERROR_TEXT;
    }

    try {
        /** Запись сообщения в БД */
        logMessage($v['user_id'], $v['contact'], $message,
            $error ?? $j->error->description ?? '');
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    unset($error);
}
