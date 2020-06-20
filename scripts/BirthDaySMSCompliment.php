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

/** Подпись, добавляемая к сообщению */
const COMPLIMENT = '#COMPLIMENT.';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

/**
 * Тексты сообщения
 * (Имя, Отчество, запятая и пробел предусмтрены в начале строки)
 */
const SAMPLES = [
    'с Днем рождения! Хорошего Вам дня!',
    'огромного счастья Вам! С Днем рождения!',
    'сегодня прекрасный день! С Днем рождения!',
    'сегодня замечательный праздник! С Днем рождения!',
    'с Днем рождения, с прекрасным и светлым днем!',
    'с Днем рождения, с прекрасным праздником!',
    'доброго Вам дня! С Днем рождения!',
    'желаем больших успехов! С Днем рождения!',
    'всех благ и хорошего настроения Вам! С Днем рождения!',
    'с Днем рождения! Желаем Вам светлых идей!'
];

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
    $sth = getConnect()->query("
        SELECT
            `clients`.`uid`,
            SUBSTRING(
                `clients`.`fio`,
                (
                    LOCATE(
                        ' ', `clients`.`fio`
                    ) + 1
                )
            ) AS
                `name`,
                `clients`.`sms_tel`
        FROM
            (
                SELECT
                    `users`.`uid`,
                    `users`.`fio`,
                    `users`.`sms_tel`,
                    `users`.`date_birth`
                FROM
                    `users`
                UNION
                SELECT
                    `usersfreeze`.`uid`,
                    `usersfreeze`.`fio`,
                    `usersfreeze`.`sms_tel`,
                    `usersfreeze`.`date_birth`
                FROM
                    `usersfreeze`
            ) AS
                `clients`
        WHERE
            MONTH(
                `clients`.`date_birth`
            ) = MONTH(
                    NOW()
                )
                AND
            DAY(
                `clients`.`date_birth`
            ) = DAY(
                    NOW()
                )
                AND
            `clients`.`sms_tel` IS NOT NULL
                AND
            `clients`.`sms_tel` != ''");

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $name Имя пользователя
 * @return string Текст сообщения
 */
function getMessage(string $name): string
{    
    return sprintf("%s, %s %s",
        $name,
        SAMPLES[array_rand(SAMPLES, 1)],
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

/** @var Pilot $pilot Экземпляр отправителя СМСПилот */
$pilot = new Pilot(SMS_PILOT_KEY);

/** @var array $v Параметры клиента */
foreach ($clients as $v) {

    /** @var string $message Текст сообщения */
    $message = getMessage($v['name']);

    /** @var Singleton $singleton Сообщение */
    $singleton = new Singleton($message,
        $v['sms_tel'], SMS_PILOT_NAME);

    /** Добавление параметра "Формат ответа" JSON */
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
