<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-php-kit
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

/** Текст сообщения */
const MESSAGE = 'Выполнен вход в панель управления.';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\SmsPilot\MessengerSdk;

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
    !is_null($staff = getStaff()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$singleton = new MessengerSdk\Singleton(MESSAGE,
    implode(',', $staff),
    SMS_PILOT_NAME);

try {
    (new MessengerSdk\Pilot(SMS_PILOT_KEY))
        ->request($singleton);
} catch (MessengerSdk\Exception\ClientException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}
