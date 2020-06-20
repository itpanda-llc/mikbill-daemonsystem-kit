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

/** Наименьшее количество офлайн-устройств */
const OFFLINE_COUNT = 10;

/** ID роли администратора */
const ADMIN_ROLE_ID = 1;

/** Текст сообщения */
const MESSAGE = 'Клиентских устройств выключено одновременно:';

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
 * @return string|null Количество офлайн-устройств
 */
function getOffline(): ?string
{
    $sth = getConnect()->prepare("
        SELECT
            `offline`.`count`
        FROM
            (
                SELECT
                    COUNT(
                        `users`.`uid`
                    ) AS
                        `count`
                FROM
                    `users`
                LEFT JOIN
                    `radacctbras`
                        ON
                            `radacctbras`.`uid` = `users`.`uid`
                LEFT JOIN
                    (
                        SELECT
                            `radacct`.`uid`
                        FROM
                            `radacct`
                        WHERE
                            `radacct`.`acctstoptime` > DATE_SUB(
                                NOW(),
                                INTERVAL :interval MINUTE
                            )
                        GROUP BY
                            `radacct`.`uid`
                    ) AS
                        `radacct_new`
                        ON
                            `radacct_new`.`uid` = `users`.`uid`
                LEFT JOIN
                    (
                        SELECT
                            `radacct`.`uid`
                        FROM
                            `radacct`
                        WHERE
                            `radacct`.`acctstoptime` > DATE_SUB(
                                NOW(),
                                INTERVAL (:interval * 10) MINUTE
                            )
                                AND
                            `radacct`.`acctstoptime` < DATE_SUB(
                                NOW(),
                                INTERVAL :interval MINUTE
                            )
                        GROUP BY
                            `radacct`.`uid`
                    ) AS
                        `radacct_old`
                        ON
                            `radacct_old`.`uid` = `users`.`uid`
                WHERE
                    `radacctbras`.`uid` IS NULL
                        AND
                    `radacct_new`.`uid` IS NOT NULL
                        AND
                    `radacct_old`.`uid` IS NULL
                LIMIT
                    1
            ) AS
                `offline`
        WHERE
            `offline`.`count` >= :offlineCount");
    
    $sth->bindValue(':offlineCount',
        OFFLINE_COUNT,
        PDO::PARAM_INT);
    $sth->bindParam(':interval', $_SERVER['argv'][1]);

    $sth->execute();

    $result = $sth->fetch(PDO::FETCH_COLUMN);

    return ($result !== '') ? $result : null;
}

/**
 * @return array|null Номера телефонов
 */
function getAdmins(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `stuff_personal`.`phone_mob`
        FROM
            `stuff_personal`
        WHERE
            `stuff_personal`.`aclid` = :adminRoleId
                AND
            `stuff_personal`.`phone_mob` IS NOT NULL
                AND
            `stuff_personal`.`phone_mob` != ''
        GROUP BY
            `stuff_personal`.`phone_mob`");
    
    $sth->bindValue(':adminRoleId',
        ADMIN_ROLE_ID,
        PDO::PARAM_INT);
    
    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_COLUMN);

    return ($result !== []) ? $result : null;
}

try {
    /** Получение номеров телефонов */
    !is_null($admins = getAdmins()) || exit;

    /** Получение количества офлайн-устройств */
    !is_null($offline = getOffline()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var string $message Текст сообщения */
$message = getMessage($offline);

/** @var Massive $massive Сообщение */
$massive = new Massive(
    sprintf("%s %s", MESSAGE, $offline),
    SMS_PILOT_NAME);

/** @var string $v Номер телефона */
foreach ($admins as $v)

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
