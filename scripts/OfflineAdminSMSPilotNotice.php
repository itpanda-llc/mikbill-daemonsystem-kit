<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-kit
 */

declare(strict_types=1);

/**
 * API-ключ SMSPILOT.RU
 * @link https://smspilot.ru/apikey.php
 */
const SMS_PILOT_KEY = '***';

/**
 * Имя отправителя SMSPILOT.RU
 * @link https://smspilot.ru/apikey.php
 */
const SMS_PILOT_NAME = '***';

/**
 * Путь к конфигурационному файлу MikBill
 * @link https://wiki.mikbill.pro/billing/config_file
 */
const CONFIG = '/var/www/mikbill/admin/app/etc/config.xml';

/** Наименьшее количество офлайн-устройств */
const OFFLINE_COUNT = 10;

/**
 * ID роли администратора
 * @link https://wiki.mikbill.pro/billing/howto/stuff_personal
 */
const ADMIN_ROLE_ID = 1;

/** Текст сообщения */
const MESSAGE = 'Клиентских устройств выключено одновременно:';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once '../../../autoload.php';

use Panda\SmsPilot\MessengerSdk;

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

    return ($result !== false) ? $result : null;
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
    !is_null($admins = getAdmins()) || exit;
    !is_null($offline = getOffline()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$message = sprintf("%s %s", MESSAGE, $offline);

$singleton = new MessengerSdk\Singleton($message,
    implode(',', $admins),
    SMS_PILOT_NAME);

try {
    (new MessengerSdk\Pilot(SMS_PILOT_KEY))
        ->request($singleton);
} catch (MessengerSdk\Exception\ClientException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}
