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
 * Тексты сообщений
 * @example SAMPLES[array_rand(SAMPLES, 1)];
 */
const SAMPLES = [
    'С Днем рождения! Хорошего вам дня!',
    'Огромного счастья вам! С Днем рождения!',
    'Сегодня прекрасный день! С Днем рождения!',
    'Сегодня замечательный праздник! С Днем рождения!',
    'С Днем рождения, с прекрасным и светлым днем!',
    'С Днем рождения, с прекрасным праздником!',
    'Доброго вам дня! С Днем рождения!',
    'Желаем больших успехов! С Днем рождения!',
    'Всех благ и хорошего настроения вам! С Днем рождения!',
    'С Днем рождения! Желаем вам светлых идей!'
];

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
function getStaff(): ?array
{
    $sth = getConnect()->query("
        SELECT
            `stuff_personal`.`phone_mob`
        FROM
            `stuff_personal`
        WHERE
            MONTH(
                `stuff_personal`.`date_birth`
            ) = MONTH(
                    NOW()
                )
                AND
            DAY(
                `stuff_personal`.`date_birth`
            ) = DAY(
                    NOW()
                )
                AND
            `stuff_personal`.`phone_mob` IS NOT NULL
                AND
            `stuff_personal`.`phone_mob` != ''
        GROUP BY
            `stuff_personal`.`phone_mob`");

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

try {
    !is_null($staff = getStaff()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$packet = (new MessengerSdk\Packet)
    ->setFrom(SMS_PILOT_NAME);

foreach ($staff as $v)
    $packet->addSend(sprintf("%s %s",
        SAMPLES[array_rand(SAMPLES, 1)],
        COMPLIMENT),
        $v['phone_mob']);

try {
    (new MessengerSdk\Pilot(SMS_PILOT_KEY))
        ->request($packet);
} catch (MessengerSdk\Exception\ClientException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}
