<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-php-kit
 */

declare(strict_types=1);

/**
 * API-ключ Gender API
 * @link https://gender-api.com/en/api-docs
 * @link https://github.com/markus-perl/gender-api-client
 */
const GENDER_API_KEY = '***';

/**
 * Код страны RUSSIA Gender API
 * @link https://gender-api.com/en/api-docs
 * @link https://github.com/markus-perl/gender-api-client
 */
const GENDER_API_RU_COUNTRY_CODE = 'RU';

/**
 * Значение пола "Женский" Gender API
 * @link https://gender-api.com/en/api-docs
 * @link https://github.com/markus-perl/gender-api-client
 */
const GENDER_API_FEMALE_GENDER_VALUE = 'female';

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
    'желаем счастья и любви! С 8 марта!',
    'пусть весны подарки восхищают вас! С 8 марта!',
    'пусть мир вокруг становится ярче! С 8 марта!',
    'желаем красивых слов и цветов! С 8 марта!',
    'сегодня ваш женский и яркий день! С праздником!',
    'пусть эта весна дарит вам счастье! С 8 марта!',
    'сегодня прекрасный весенний день! С 8 марта!',
    'с празником, с прекрасным весенним днем!',
    'пусть все будет прекрасно! С праздником!',
    'хорошего настроения вам! С 8 марта!'
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
 * @return array Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->query("
        SELECT
            `users`.`uid`,
            @name :=
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
            SUBSTRING(
                @name,
                1,
                LENGTH(
                    SUBSTRING(
                        @name,
                        1,
                        (
                            LOCATE(
                                ' ',
                                @name
                            ) - 1
                        )
                    )
                )
            ) AS
                `first_name`,
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
                NOW()
            ) = 3
                AND
            DAY(
                NOW()
            ) = 8
                AND
            `users`.`sms_tel` IS NOT NULL
                AND
            `users`.`sms_tel` != ''
        GROUP BY
            `users`.`sms_tel`");

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

try {
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$client = new GenderApi\Client(GENDER_API_KEY);

foreach ($clients as $v) {
    try {
        $gender = $client->getByFirstNameAndCountry($v['first_name'],
            GENDER_API_RU_COUNTRY_CODE)->getGender();
    } catch (GenderApi\Exception $e) {
        echo sprintf("%s\n", $e->getMessage());

        continue;
    }

    if ($gender === GENDER_API_FEMALE_GENDER_VALUE)
        $females[] = ['uid' => $v['uid'],
            'name' => $v['name'],
            'sms_tel' => $v['sms_tel']];
}

!is_null($females) || exit;

$pilot = new MessengerSdk\Pilot(SMS_PILOT_KEY);

$singleton = (new MessengerSdk\Singleton)
    ->setFrom(SMS_PILOT_NAME)
    ->setFormat(MessengerSdk\Format::JSON);

foreach ($females as $v) {
    $message = sprintf("%s, %s %s",
        $v['name'],
        SAMPLES[array_rand(SAMPLES, 1)],
        COMPLIMENT);

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
