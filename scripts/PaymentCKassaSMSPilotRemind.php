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
 * Персональный код "Центральная касса"
 * @link https://docs.ckassa.ru/doc/shop-api/#payform
 */
const CKASSA_SERVICE_ID = '***';

/** Наименование денежной единицы */
const CURRENCY_NAME = 'руб';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\SmsPilot\MessengerSdk;

/**
 * @return array|null Параметры клиентов/платежей
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `clients`.`user`,
            `clients`.`uid`,
            `clients`.`sms_tel`,
            `clients`.`amount`,
            `clients`.`date`
        FROM
            (
                SELECT
                    `users`.`user`,
                    `users`.`uid`,
                    `users`.`sms_tel`,
                    ABS(
                        ROUND(
                            IF(
                                (
                                    @amount :=
                                    `users`.`deposit`
                                        +
                                    IF(
                                        `users`.`credit_unlimited` = 1,
                                        `users`.`credit`,
                                        0
                                    )
                                        +
                                    `packets`.`razresh_minus`
                                        -
                                    (
                                        (
                                            @fixedCost :=
                                            `packets`.`fixed_cost`
                                        )
                                            +
                                        (
                                            @realPrice :=
                                            CASE
                                                WHEN
                                                    `users`.`real_ip` = 0
                                                        OR
                                                    `users`.`real_ipfree` = 1
                                                THEN
                                                    0
                                                WHEN
                                                    `users`.`real_price` = 0
                                                THEN
                                                    `packets`.`real_price`
                                            END
                                        )
                                            -
                                        IF(
                                            (
                                                `ext_discount_extended`.`value` = '0'
                                                    OR
                                                `ext_discount_extended`.`value` IS NULL
                                            ),
                                            IF(
                                                (
                                                    `ext_discount_global`.`value` = '0'
                                                        OR
                                                    `ext_discount_global`.`value` IS NULL
                                                ),
                                                0,
                                                IF(
                                                    (
                                                        `ext_discount_global_fixed`.`value` = '0'
                                                            OR
                                                        `ext_discount_global_fixed`.`value` IS NULL
                                                    ),
                                                    (
                                                        (
                                                            @fixedCost
                                                                +
                                                            @realPrice
                                                        )
                                                            *
                                                        `ext_discount_global`.`value` / 100
                                                    ),
                                                    `ext_discount_global`.`value`
                                                )
                                            ),
                                            IF(
                                                (
                                                    `ext_discount_packet`.`value` = '0'
                                                        OR
                                                    `ext_discount_packet`.`value` IS NULL
                                                ),
                                                0,
                                                IF(                    
                                                    (
                                                        `ext_discount_packet_fixed`.`value` = '0'
                                                            OR
                                                        `ext_discount_packet_fixed`.`value` IS NULL
                                                    ),
                                                    @fixedCost * `ext_discount_packet`.`value` / 100,
                                                    `ext_discount_packet`.`value`
                                                )
                                            )
                                        )
                                    )
                                ) < 0,
                                @amount,
                                0
                            ),
                            2
                        )
                    ) AS
                        `amount`,
                    CONCAT_WS(
                        ' ',
                        DATE_FORMAT(
                            NOW() + INTERVAL :interval DAY,
                            '%e'
                        ),
                        CASE
                            WHEN
                                (
                                    @month :=
                                    DATE_FORMAT(
                                        NOW() + INTERVAL :interval DAY,
                                        '%c'
                                    )
                                ) = '1'
                            THEN
                                'января'
                            WHEN 
                                @month = '2'
                            THEN
                                'февраля'
                            WHEN
                                @month = '3'
                            THEN
                                'марта'
                            WHEN
                                @month = '4'
                            THEN
                                'апреля'
                            WHEN
                                @month = '5'
                            THEN
                                'мая'
                            WHEN
                                @month = '6'
                            THEN
                                'июня'
                            WHEN
                                @month = '7'
                            THEN
                                'июля'
                            WHEN
                                @month = '8'
                            THEN
                                'августа'
                            WHEN
                                @month = '9'
                            THEN
                                'сентября'
                            WHEN
                                @month = '10'
                            THEN
                                'октября'
                            WHEN
                                @month = '11'
                            THEN
                                'ноября'
                            WHEN
                                @month = '12'
                            THEN
                                'декабря'
                        END
                    ) AS
                        `date`
                FROM
                    `users`
                LEFT JOIN
                    (
                        SELECT
                            `users_custom_fields`.`uid`,
                            `users_custom_fields`.`value`
                        FROM
                            `users_custom_fields`
                        WHERE
                            `key` = 'ext_discount_extended'
                    ) AS
                        `ext_discount_extended`
                            ON
                                `ext_discount_extended`.`uid` = `users`.`uid`
                LEFT JOIN
                    (
                        SELECT
                            `users_custom_fields`.`uid`,
                            `users_custom_fields`.`value`
                        FROM
                            `users_custom_fields`
                        WHERE
                            `key` = 'ext_discount_global'
                    ) AS
                        `ext_discount_global`
                            ON
                                `ext_discount_global`.`uid` = `users`.`uid`
                LEFT JOIN
                    (
                        SELECT
                            `users_custom_fields`.`uid`,
                            `users_custom_fields`.`value`
                        FROM
                            `users_custom_fields`
                        WHERE
                            `key` = 'ext_discount_global_fixed'
                    ) AS
                        `ext_discount_global_fixed`
                            ON
                                `ext_discount_global_fixed`.`uid` = `users`.`uid`
                LEFT JOIN
                    (
                        SELECT
                            `users_custom_fields`.`uid`,
                            `users_custom_fields`.`value`
                        FROM
                            `users_custom_fields`
                        WHERE
                            `key` = 'ext_discount_packet'
                    ) AS
                        `ext_discount_packet`
                            ON
                                `ext_discount_packet`.`uid` = `users`.`uid`
                LEFT JOIN
                    (
                        SELECT
                            `users_custom_fields`.`uid`,
                            `users_custom_fields`.`value`
                        FROM
                            `users_custom_fields`
                        WHERE
                            `key` = 'ext_discount_packet_fixed'
                    ) AS
                        `ext_discount_packet_fixed`
                            ON
                                `ext_discount_packet_fixed`.`uid` = `users`.`uid`
                LEFT JOIN
                    `packets`
                        ON
                            `packets`.`gid` = `users`.`gid`
                WHERE
                    `users`.`state` = 1
                        AND
                    `packets`.`fixed` = 11
                        AND
                    `users`.`blocked` = 0
                        AND
                    `users`.`date_abonka` = DATE_FORMAT(
                        NOW() + INTERVAL :interval DAY,
                        '%e'
                    )
                        AND
                    `users`.`sms_tel` IS NOT NULL
                        AND
                    `users`.`sms_tel` != ''
            ) AS
                `clients`
        WHERE
            `clients`.`amount` != 0");

    $sth->bindParam(':interval', $_SERVER['argv'][1]);

    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $account Аккаунт
 * @param string $amount Размер платежа
 * @return string URL-адрес страницы оплаты
 */
function getUrl(string $account, string $amount): string
{
    return sprintf("https://payframe.ckassa.ru/?lite-version=true"
        . "&service=%s&%s=%s&amount=%d",
        CKASSA_SERVICE_ID,
        urlencode('Л_СЧЕТ'),
        $account,
        100 * (int) $amount);
}

/**
 * @param string $account Аккаунт
 * @param string $amount Размер платежа
 * @param string $date Дата
 * @param string $url URL-адрес страницы оплаты
 * @return string Текст сообщения
 */
function getMessage(string $account,
                    string $amount,
                    string $date,
                    string $url): string
{
    return sprintf("Рекомендуемый платеж по счету #%s:"
        . " %s %s. до %s. Удобная оплата: %s",
        $account,
        $amount,
        CURRENCY_NAME,
        $date,
        $url);
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
    $message = getMessage($v['user'],
        $v['amount'],
        $v['date'],
        getUrl($v['user'], $v['amount']));

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
