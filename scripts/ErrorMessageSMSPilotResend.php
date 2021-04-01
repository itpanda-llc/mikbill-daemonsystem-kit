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

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once '../../../autoload.php';

use Panda\SmsPilot\MessengerSdk;

/**
 * @return array|null Параметры сообщений
 */
function getMessages(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `sms_logs`.`sms_id`,
            `sms_logs`.`sms_phone`,
            `sms_logs`.`sms_text`
        FROM
            `sms_logs`
        WHERE
            `sms_logs`.`sms_error_text` != ''
                AND
            `sms_logs`.`sms_send_datetime` > DATE_SUB(
                NOW(),
                INTERVAL :interval HOUR
            )");
    
    $sth->bindParam(':interval', $_SERVER['argv'][1]);
    
    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $smsId ID сообщения
 * @param string $errorText Текст ошибки
 */
function updateMessage(string $smsId, string $errorText): void
{
    static $sth;
    
    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `sms_logs`
        SET
            `sms_logs`.`sms_error_text` = :errorText
        WHERE
            `sms_logs`.`sms_id` = :smsId");
    
    $sth->bindParam(':smsId', $smsId);
    $sth->bindParam(':errorText', $errorText);

    $sth->execute();
}

try {
    !is_null($messages = getMessages()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$pilot = new MessengerSdk\Pilot(SMS_PILOT_KEY);

$singleton = (new MessengerSdk\Singleton)
    ->setFrom(SMS_PILOT_NAME)
    ->setFormat(MessengerSdk\Format::JSON);

foreach ($messages as $v) {
    try {
        getConnect()->beginTransaction() || exit(
            "Begin a transaction failed\n");
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    try {
        updateMessage($v['sms_id'], '');
    } catch (PDOException $e) {
        try {
            getConnect()->rollBack() || exit(
                "Rollback a transaction failed\n");
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        exit(sprintf("%s\n", $e->getMessage()));
    }

    $singleton->setSend($v['sms_text'])
        ->setTo($v['sms_phone']);

    try {
        $j = json_decode($pilot->request($singleton));

        updateMessage($v['sms_id'],
            (string) ($j->error->description ?? ''));

        getConnect()->commit() || exit(
            "Commit a transaction failed\n");
    } catch (MessengerSdk\Exception\ClientException $e) {
        try {
            getConnect()->rollBack() || exit(
                "Rollback a transaction failed\n");
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        echo sprintf("%s\n", $e->getMessage());
    } catch (PDOException $e) {
        try {
            getConnect()->rollBack() || exit(
                "Rollback a transaction failed\n");
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        exit(sprintf("%s\n", $e->getMessage()));
    }
}
