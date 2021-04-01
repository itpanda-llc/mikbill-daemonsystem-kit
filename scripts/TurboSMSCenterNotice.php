<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-php-kit
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

/** Текст сообщения */
const MESSAGE = 'Услуга Турбо подключена.';

/** Подпись, добавляемая к сообщению */
const COMPLIMENT = '***';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\SmsCenter\MessengerSdk;

/**
 * @return array Параметры клиентов
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
            `bugh_uslugi_stat`
                ON
                    `bugh_uslugi_stat`.`uid` = `users`.`uid`
                        AND
                    `bugh_uslugi_stat`.`usluga` = 3
        WHERE
            `users`.`state` = 1
                AND
            `bugh_uslugi_stat`.`date_start` >= DATE_SUB(
                NOW(),
                INTERVAL :interval MINUTE
            )
                AND
            `bugh_uslugi_stat`.`uid` IS NOT NULL
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

$message = sprintf('%s %s', MESSAGE, COMPLIMENT);

foreach ($clients as $v) {
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
