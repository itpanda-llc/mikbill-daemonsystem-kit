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

/** Наименование денежной единицы */
const CURRENCY_NAME = 'руб';

/** Подпись, добавляемая к сообщению */
const COMPLIMENT = '#COMPLIMENT.';

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
 * @return array|null Параметры клиентов и платежей
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `clients`.`user`,
            `clients`.`uid`,
            `clients`.`sms_tel`,
            ROUND(
                `addons_pay_api`.`amount`, 2
            ) AS
                `amount`,
            `addons_pay_api`.`comment`
        FROM
            (
                SELECT
                    `users`.`user`,
                    `users`.`uid`,
                    `users`.`sms_tel`
                FROM
                    `users`
                UNION
                SELECT
                    `usersfreeze`.`user`,
                    `usersfreeze`.`uid`,
                    `usersfreeze`.`sms_tel`
                FROM
                    `usersfreeze`
                UNION
                SELECT
                    `usersblok`.`user`,
                    `usersblok`.`uid`,
                    `usersblok`.`sms_tel`
                FROM
                    `usersblok`
                UNION
                SELECT
                    `usersdel`.`user`,
                    `usersdel`.`uid`,
                    `usersdel`.`sms_tel`
                FROM
                    `usersdel`
            ) AS
                `clients`
        LEFT JOIN
            `addons_pay_api`
                ON
                    `addons_pay_api`.`user_ref` = `clients`.`uid`
                        AND
                    `addons_pay_api`.`category` > 0
                        AND
                    `addons_pay_api`.`amount` >= 10
                        AND
                    `addons_pay_api`.`update_time` > DATE_SUB(
                        NOW(),
                        INTERVAL :interval MINUTE
                    )
                        AND
                    `addons_pay_api`.`status` = 1
        WHERE
            `clients`.`uid` IS NOT NULL
                AND
            `addons_pay_api`.`user_ref` IS NOT NULL
                AND
            `clients`.`sms_tel` IS NOT NULL
                AND
            `clients`.`sms_tel` != ''");

    $sth->bindParam(':interval', $_SERVER['argv'][1]);

    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $account Аккаунт
 * @param string $comment Комментарий
 * @param string $amount Размер платежа
 * @return string Текст сообщения
 */
function getMessage(string $account,
                    string $comment,
                    string $amount): string
{
    return sprintf("На счет #%s зачислен %s: %s %s. %s",
        $account,
        $comment,
        $amount,
        CURRENCY_NAME,
        COMPLIMENT);
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
    /** Получение параметров клиентов и платежей */
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var Pilot $pilot Экземпляр отправителя СМСПилот */
$pilot = new Pilot(SMS_PILOT_KEY);

/** @var array $v Параметры клиента и платежа */
foreach ($clients as $v) {

    /** @var string $message Текст сообщения */
    $message = getMessage($v['user'], $v['comment'], $v['amount']);

    /** @var Singleton $singleton Сообщение */
    $singleton = new Singleton($message,
        $v['sms_tel'], SMS_PILOT_NAME);

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
        logMessage($v['uid'], $v['sms_tel'], $message,
            $error ?? $j->error->description ?? '');
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    unset($error);
}
