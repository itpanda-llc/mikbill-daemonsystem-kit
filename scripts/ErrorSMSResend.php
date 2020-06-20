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

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

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
 * @return array|null Параметры сообщений
 */
function getMessages(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `sms_logs`.`sms_id`,
            `sms_logs`.`sms_phone`,
            `sms_logs`.`sms_text`
        FROM
            `sms_logs`
        WHERE
            `sms_logs`.`sms_error_text` != ''
                AND
            `sms_logs`.`sms_send_datetime` > DATE_SUB(
                NOW(),
                INTERVAL :interval HOUR
            )");
    
    $sth->bindParam(':interval', $_SERVER['argv'][1]);
    
    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $smsId ID сообщения
 * @param string $errorText Текст ошибки
 */
function updateMessage(string $smsId, string $errorText): void
{
    static $sth;
    
    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `sms_logs`
        SET
            `sms_logs`.`sms_error_text` = :errorText
        WHERE
            `sms_logs`.`sms_id` = :smsId");
    
    $sth->bindParam(':smsId', $smsId);
    $sth->bindParam(':errorText', $errorText);

    $sth->execute();
}

try {
    /** Получение параметров сообщений */
    !is_null($messages = getMessages()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var Pilot $pilot Экземпляр отправителя СМСПилот */
$pilot = new Pilot(SMS_PILOT_KEY);

/** @var array $v Параметры сообщения */
foreach ($messages as $v) {
    try {
        /** Начало транзакции */
        getConnect()->beginTransaction();
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    try {
        /** Замена текста ошибки пустой строкой */
        updateMessage($v['sms_id'], '');
    } catch (PDOException $e) {
        try {
            /** Откат транзакции */
            getConnect()->rollBack();
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        exit(sprintf("%s\n", $e->getMessage()));
    }

    /** @var Singleton $singleton Сообщение */
    $singleton = new Singleton($v['sms_text'],
        $v['sms_phone'], SMS_PILOT_NAME);

    /** Добавление параметра "Формат ответа" JSON */
    $singleton->addParam(Format::get(Format::JSON));

    try {
        /** @var stdClass $j Ответ СМСПилот */
        $j = json_decode($pilot->request($singleton));
    } catch (ClientException $e) {
        try {
            /** Откат транзакции */
            getConnect()->rollBack();
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        echo sprintf("%s\n", $e->getMessage());

        continue;
    }

    try {
        /** Обновление сообщения */
        updateMessage($v['sms_id'], $j->error->description ?? '');

        /** Фиксация транзакции */
        getConnect()->commit();
    } catch (PDOException $e) {
        try {
            /** Откат транзакции */
            getConnect()->rollBack();
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        exit(sprintf("%s\n", $e->getMessage()));
    }
}
