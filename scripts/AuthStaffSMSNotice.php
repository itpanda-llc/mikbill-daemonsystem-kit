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
use Panda\SMSPilot\MessengerSDK\Massive;
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

/** Текст сообщения */
const MESSAGE = 'Выполнен вход в панель управления.';

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
 * @return array|null Номера телефонов
 */
function getStaff(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `stuff_personal`.`phone_mob`
        FROM
            `stuff_personal`
        LEFT JOIN
            `logs_auth_admin`
                ON
                    `logs_auth_admin`.`stuffid`
                        =
                    `stuff_personal`.`stuffid`
                        AND
                    `logs_auth_admin`.`auth_date` > DATE_SUB(
                        NOW(),
                        INTERVAL :interval SECOND
                    )
        WHERE
            `logs_auth_admin`.`auth_type` = 'allowed'
                AND
            `stuff_personal`.`phone_mob` IS NOT NULL
                AND
            `stuff_personal`.`phone_mob` != ''
        GROUP BY
            `stuff_personal`.`stuffid`");
    
    $sth->bindParam(':interval', $_SERVER['argv'][1]);
    
    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_COLUMN);

    return ($result !== []) ? $result : null;
}

try {
    /** Получение номеров телефонов */
    !is_null($staff = getStaff()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var Massive $massive Сообщение */
$massive = new Massive(MESSAGE, SMS_PILOT_NAME);

/** @var string $v Номер телефона */
foreach ($staff as $v)

    /** Добавление номера телефона */
    $massive->addRecipient($v);

/** @var Pilot $pilot Экземпляр отправителя СМСПилот */
$pilot = new Pilot(SMS_PILOT_KEY);

try {
    /** Отправление сообщения */
    $pilot->request($massive);
} catch (ClientException $e) {
    echo sprintf("%s\n", $e->getMessage());
}
