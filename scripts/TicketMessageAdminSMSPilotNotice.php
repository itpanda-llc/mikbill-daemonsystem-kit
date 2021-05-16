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

/**
 * ID роли администратора
 * @link https://wiki.mikbill.pro/billing/howto/stuff_personal
 */
const ADMIN_ROLE_ID = 1;

/** Текст сообщения */
const MESSAGE = 'Новых сообщений в системе:';

/** Дополнительная информация, добавляемая к сообщению */
const INFO = '***';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once '../../../autoload.php';

use Panda\SmsPilot\MessengerSdk;

/**
 * @return string|null Заявки
 */
function getTickets(): ?string
{
    $sth = getConnect()->prepare("
        SELECT
            `tickets`.`count`
        FROM
            (
                SELECT
                    COUNT(
                        `tickets_messages`.`messageid`
                    ) AS
                        `count`
                FROM
                    `tickets_tickets`
                LEFT JOIN
                    `tickets_performers`
                        ON
                            `tickets_performers`.`ticketid` = `tickets_tickets`.`ticketid`
                LEFT JOIN
                    `tickets_messages`
                        ON
                            `tickets_messages`.`ticketid` = `tickets_tickets`.`ticketid`
                                AND
                            `tickets_messages`.`stuffid` = 0
                                AND
                            `tickets_messages`.`useruid` != 0
                WHERE
                    `tickets_tickets`.`show_ticket_to_user` = 1
                        AND
                    `tickets_tickets`.`creationdate` < DATE_SUB(
                        NOW(),
                        INTERVAL :interval MINUTE
                    )
                        AND
                    `tickets_messages`.`date` > DATE_SUB(
                        NOW(),
                        INTERVAL :interval MINUTE
                    )
                        AND
                    `tickets_performers`.`ticketid` IS NULL
                HAVING
                    `count`
            ) AS
                `tickets`
        WHERE
            `tickets`.`count` > 0");

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
    !is_null($tickets = getTickets()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$message = sprintf("%s %s. %s", MESSAGE, $tickets, INFO);

$singleton = new MessengerSdk\Singleton($message,
    implode(',', $admins),
    SMS_PILOT_NAME);

try {
    (new MessengerSdk\Pilot(SMS_PILOT_KEY))
        ->request($singleton);
} catch (MessengerSdk\Exception\ClientException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}
