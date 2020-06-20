<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/**
 * Подключение библиотеки СМСЦентр
 * @link https://github.com/itpanda-llc/smsc-sender-php-sdk
 */
require_once '../../smsc-sender-php-sdk/autoload.php';

/**
 * Импорт классов библиотеки СМСЦентр
 * @link https://github.com/itpanda-llc/smsc-sender-php-sdk
 */
use Panda\SMSC\SenderSDK\Sender;
use Panda\SMSC\SenderSDK\Format;
use Panda\SMSC\SenderSDK\Message;
use Panda\SMSC\SenderSDK\Valid;
use Panda\SMSC\SenderSDK\Charset;
use Panda\SMSC\SenderSDK\Exception\ClientException;

/**
 * Логин СМСЦентр
 * @link https://smsc.ru/user/
 */
const SMSC_LOGIN = 'SMSC_LOGIN';

/**
 * Пароль СМСЦентр
 * @link https://smsc.ru/passwords/
 */
const SMSC_PASSWORD = 'SMSC_PASSWORD';

/**
 * Имя отправителя СМСЦентр
 * @link https://smsc.ru/api/
 */
const SMSC_SENDER = 'SMSC_SENDER';

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Наименование параметра глобальной скидки в БД */
const DISCOUNT_GLOBAL_KEY = 'ext_discount_global';

/** Наименование параметра расширенной (Пакет) скидки в БД */
const DISCOUNT_EXT_PACKET_KEY = 'ext_discount_packet';

/** Наименование параметра расширенной (Подписки) скидки в БД */
const DISCOUNT_EXT_SUBS_KEY = 'ext_discount_subs';

/** Наименование параметра расширенной (Аренда) скидки в БД */
const DISCOUNT_EXT_DEVICE_KEY = 'ext_discount_device';

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
 * @return array|null Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `clients`.`user`,
            `clients`.`uid`,
            `clients`.`sms_tel`
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
            `logs`
                ON
                    `logs`.`uid` = `clients`.`uid`
                        AND
                    `logs`.`stuffid` = 0
                        AND
                    `logs`.`date` > DATE_SUB(
                        NOW(),
                        INTERVAL :interval MINUTE
                    )
                        AND
                    `logs`.`logtypeid` = 1
                        AND
                    (
                        `logs`.`valuename` = :discountGlobalKey
                            OR
                        `logs`.`valuename` = :discountExtPacketKey
                            OR
                        `logs`.`valuename` = :discountExtSubsKey 
                            OR
                        `logs`.`valuename` = :discountExtDeviceKey 
                    )
                        AND
                    `logs`.`oldvalue` IS NOT NULL
                        AND
                    `logs`.`oldvalue` != '0'
                        AND
                    `logs`.`oldvalue` != ''
                        AND
                    `logs`.`newvalue` IS NULL
        WHERE
            `logs`.`uid` IS NOT NULL
                AND
            `clients`.`sms_tel` IS NOT NULL
                AND
            `clients`.`sms_tel` != ''
        GROUP BY
            `clients`.`uid`");

    $sth->bindValue(':discountGlobalKey',
        DISCOUNT_GLOBAL_KEY);
    $sth->bindValue(':discountExtPacketKey',
        DISCOUNT_EXT_PACKET_KEY);
    $sth->bindValue(':discountExtSubsKey',
        DISCOUNT_EXT_SUBS_KEY);
    $sth->bindValue(':discountExtDeviceKey',
        DISCOUNT_EXT_DEVICE_KEY);

    $sth->bindParam(':interval', $_SERVER['argv'][1]);
    
    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $account Аккаунт
 * @return string Текст сообщения
 */
function getMessage(string $account): string
{
    return sprintf("Скидки для аккаунта #%s были отменены. %s",
        $account,
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
    /** Получение параметров клиентов */
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var Sender $sender Экземпляр отправителя СМСЦентр */
$sender = new Sender(SMSC_LOGIN, SMSC_PASSWORD, Format::JSON);

/** @var array $v Параметры клиента */
foreach ($clients as $v) {

    /** @var string $message Текст сообщения */
    $message = getMessage($v['user']);

    /** @var Message $notice Сообщение */
    $notice = new Message(SMSC_SENDER, $message, $v['sms_tel']);

    /** Установка признака soc-сообщения */
    $notice->setSoc()

        /** Установка параметра "Срок "жизни" сообщения" 1 минута */
        ->setValid(Valid::min(1))

        /** Установка параметра "Кодировка сообщения" UTF-8 */
        ->setCharset(Charset::UTF_8);

    try {
        /** @var stdClass $j Ответ СМСЦентр */
        $j = json_decode($sender->request($notice));
    } catch (ClientException $e) {

        /** @var string $error Текст ошибки */
        $error = ERROR_TEXT;
    }

    try {
        /** Запись сообщения в БД */
        logMessage($v['uid'], $v['sms_tel'],
            $message, $error ?? $j->error ?? '');
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    unset($error);
}