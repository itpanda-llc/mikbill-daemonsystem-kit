<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-kit
 */

declare(strict_types=1);

/**
 * Логин SMSЦентр
 * @link https://smsc.ru/api/http/
 */
const SMS_CENTER_LOGIN = '***';

/**
 * Пароль SMSЦентр
 * @link https://smsc.ru/api/http/
 */
const SMS_CENTER_PASSWORD = '***';

/**
 * Имя отправителя SMSЦентр
 * @link https://smsc.ru/api/http/
 */
const SMS_CENTER_SENDER = '***';

/**
 * Путь к конфигурационному файлу MikBill
 * @link https://wiki.mikbill.pro/billing/config_file
 */
const CONFIG = '/var/www/mikbill/admin/app/etc/config.xml';

/** Подпись, добавляемая к сообщению */
const COMPLIMENT = '***';

/**
 * Временная зона
 * @link https://www.php.net/manual/ru/timezones.php
 */
const TIME_ZONE = 'Asia/Yekaterinburg';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\SmsCenter\MessengerSdk;

/**
 * @return array|null Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `users`.`user`,
            `users`.`uid`,
            `users`.`sms_tel`
        FROM
            `users`
        LEFT JOIN
            `logs`
                ON
                    `logs`.`uid` = `users`.`uid`
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
                        `logs`.`valuename` = 'ext_discount_global'
                            OR
                        `logs`.`valuename` = 'ext_discount_packet'
                            OR
                        `logs`.`valuename` = 'ext_discount_subs'
                            OR
                        `logs`.`valuename` = 'ext_discount_device'
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
            `users`.`sms_tel` IS NOT NULL
                AND
            `users`.`sms_tel` != ''
        GROUP BY
            `users`.`uid`");

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

try {
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$center = new MessengerSdk\Center(SMS_CENTER_LOGIN,
    SMS_CENTER_PASSWORD,
    MessengerSdk\Charset::UTF_8,
    MessengerSdk\Fmt::JSON);

$task = (new MessengerSdk\Send\Task)
    ->setSender(SMS_CENTER_SENDER)
    ->setSoc(MessengerSdk\Send\Soc::YES)
    ->setValid(MessengerSdk\Send\Valid::min(1));

try {
    $dateTime = new DateTime("now",
        new DateTimeZone(TIME_ZONE));
} catch (Exception $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

switch (true) {
    case (((int) $dateTime->format('H')) < 10):
        $task->setTime($dateTime->format('dmy1000'));

        break;
    case (((int) $dateTime->format('H')) === 23):
        try {
            $dateTime->add(new DateInterval('P1D'));
        } catch (Exception $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        $task->setTime($dateTime->format('dmy1000'));
}

foreach ($clients as $v) {
    $message = getMessage($v['user']);

    $task->setMes($message)
        ->setPhones($v['sms_tel']);

    try {
        $j = json_decode($center->request($task));
    } catch (MessengerSdk\Exception\ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());

        $error = ERROR_TEXT;
    }

    try {
        logMessage($v['uid'],
            $v['sms_tel'],
            $message,
            (string) ($error ?? $j->error ?? ''));
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    $error = null;
}
