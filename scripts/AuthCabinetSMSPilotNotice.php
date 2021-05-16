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

/** Текст сообщения */
const MESSAGE = 'Выполнен вход в личный кабинет.';

/** Подпись, добавляемая к сообщению */
const COMPLIMENT = '***';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\SmsPilot\MessengerSdk;

/**
 * @return array|null Номера телефонов
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `users`.`uid`,
            `users`.`sms_tel`
        FROM
            `users`
        LEFT JOIN
            `logs_auth_cabinet`
                ON
                    `logs_auth_cabinet`.`login` = `users`.`user`
                        AND
                    `logs_auth_cabinet`.`auth_date` > DATE_SUB(
                        NOW(),
                        INTERVAL :interval SECOND
                    )
        LEFT JOIN
            (
                SELECT
                    `logs_auth_cabinet`.`login`
                FROM
                    `logs_auth_cabinet`
                WHERE
                    `logs_auth_cabinet`.`auth_type` = 'allowed'
                        AND
                    `logs_auth_cabinet`.`auth_date` > DATE_SUB(
                        NOW(),
                        INTERVAL 20 MINUTE
                    )
                        AND
                    `logs_auth_cabinet`.`auth_date` < DATE_SUB(
                        NOW(),
                        INTERVAL :interval SECOND
                    )
            ) AS
                `auth_old`
                    ON
                        `auth_old`.`login` = `users`.`user`
        WHERE
            `logs_auth_cabinet`.`auth_type` = 'allowed'
                AND
            `auth_old`.`login` IS NULL
                AND
            `users`.`sms_tel` IS NOT NULL
                AND
            `users`.`sms_tel` != ''
        GROUP BY
            `users`.`sms_tel`");
    
    $sth->bindParam(':interval', $_SERVER['argv'][1]);
    
    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

try {
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$pilot = new MessengerSdk\Pilot(SMS_PILOT_KEY);

$message = sprintf("%s %s", MESSAGE, COMPLIMENT);

$singleton = (new MessengerSdk\Singleton($message))
    ->setFrom(SMS_PILOT_NAME)
    ->setFormat(MessengerSdk\Format::JSON);

foreach ($clients as $v) {
    $singleton->setTo($v['sms_tel']);

    try {
        $j = json_decode($pilot->request($singleton));
    } catch (MessengerSdk\Exception\ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());

        $error = ERROR_TEXT;
    }

    try {
        logMessage($v['uid'],
            $v['sms_tel'],
            $message,
            (string) ($error ?? $j->error->description ?? ''));
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    $error = null;
}
