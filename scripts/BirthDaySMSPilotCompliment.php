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

/**
 * Тексты сообщений
 * @example SAMPLES[array_rand(SAMPLES, 1)];
 */
const SAMPLES = [
    'с Днем рождения! Хорошего вам дня!',
    'огромного счастья вам! С Днем рождения!',
    'сегодня прекрасный день! С Днем рождения!',
    'сегодня замечательный праздник! С Днем рождения!',
    'с Днем рождения, с прекрасным и светлым днем!',
    'с Днем рождения, с прекрасным праздником!',
    'доброго вам дня! С Днем рождения!',
    'желаем больших успехов! С Днем рождения!',
    'всех благ и хорошего настроения вам! С Днем рождения!',
    'с Днем рождения! Желаем вам светлых идей!'
];

/** Подпись, добавляемая к сообщению */
const COMPLIMENT = '***';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\SmsPilot\MessengerSdk;

/**
 * @return array|null Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->query("
        SELECT
            `users`.`uid`,
            SUBSTRING(
                `users`.`fio`,
                (
                    LOCATE(
                        ' ',
                        `users`.`fio`
                    ) + 1
                )
            ) AS
                `name`,
                `users`.`sms_tel`
        FROM
            `users`
        WHERE
            (
                `users`.`state` = 1
                    OR
                `users`.`state` = 2
            )
                AND
            MONTH(
                `users`.`date_birth`
            ) = MONTH(
                    NOW()
                )
                AND
            DAY(
                `users`.`date_birth`
            ) = DAY(
                    NOW()
                )
                AND
            `users`.`sms_tel` IS NOT NULL
                AND
            `users`.`sms_tel` != ''
        GROUP BY
            `users`.`sms_tel`");

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

try {
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$pilot = new MessengerSdk\Pilot(SMS_PILOT_KEY);

$singleton = (new MessengerSdk\Singleton)
    ->setFrom(SMS_PILOT_NAME)
    ->setFormat(MessengerSdk\Format::JSON);

foreach ($clients as $v) {
    $message = getMessage($v['name']);

    $singleton->setSend($message)
        ->setTo($v['sms_tel']);

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
