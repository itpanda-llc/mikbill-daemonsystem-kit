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

/** Подпись, добавляемая к сообщению */
const COMPLIMENT = '#COMPLIMENT.';

/** Дополнительная информация, добавляемая к сообщению */
const INFO = 'Возможно, мы сможем помочь: +7(351)***-**-**.';

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
 * @return array Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `usersdel`.`user`,
            `usersdel`.`uid`,
            `usersdel`.`sms_tel`
        FROM
            `usersdel`
        WHERE
            `usersdel`.`del_date` > DATE_SUB(
                NOW(),
                INTERVAL :interval MINUTE
            )
                AND
            `usersdel`.`sms_tel` IS NOT NULL
                AND
            `usersdel`.`sms_tel` != ''");

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
    return sprintf("Ваша учетная запись #%s удалена. %s %s",
        $account,
        INFO,
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

        /** Установка параметра "Срок "жизни" сообщения" */
        ->setValid(Valid::min(1))

        /** Установка параметра "Кодировка сообщения" */
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
