<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-php-kit
 */

declare(strict_types=1);

/**
 * Логин Сбербанк
 * @link https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:start
 */
const SBERBANK_USER_NAME = '***';

/**
 * Пароль Сбербанк
 * @link https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:start
 */
const SBERBANK_PASSWORD = '***';

/**
 * Адрес, на который требуется перенаправить пользователя в случае успешной оплаты
 * @link https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:requests:register
 */
const SBERBANK_RETURN_URL = '***';

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

/** Наименование таблицы для ведения заказов */
const ORDERS_TABLE_NAME = '__sberbank_orders_log';

/** Наименование колонки "ID пользователя" */
const USER_ID_COLUMN_NAME = 'user_id';

/** Наименование колонки "Номер заказа" */
const ORDER_ID_COLUMN_NAME = 'order_id';

/** Наименование колонки "Номер заказа банка" */
const EXT_ID_COLUMN_NAME = 'ext_id';

/** Наименование колонки "Время заказа" */
const ORDER_TIME_COLUMN_NAME = 'order_time';

/** Наименование колонки "Стоимость заказа" */
const ORDER_PRICE_COLUMN_NAME = 'order_price';

/**
 * Временная зона
 * @link https://www.php.net/manual/ru/timezones.php
 */
const TIME_ZONE = 'Asia/Yekaterinburg';

/** Продолжительность жизни заказа (дней) */
const ORDER_EXPIRATION_TIMEOUT = 3;

/** Наименование денежной единицы */
const CURRENCY_NAME = 'руб';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\Sberbank\AcquirerSdk;
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

function createOrderTable(): void
{
    getConnect()->exec("
        CREATE TABLE IF NOT EXISTS
            `" . ORDERS_TABLE_NAME . "` (
                `id` INT AUTO_INCREMENT,
                `" . USER_ID_COLUMN_NAME . "` VARCHAR(128) NOT NULL,
                `" . ORDER_ID_COLUMN_NAME . "` VARCHAR(128) NULL DEFAULT NULL,
                `" . EXT_ID_COLUMN_NAME . "` VARCHAR(128) NULL DEFAULT NULL,
                `" . ORDER_TIME_COLUMN_NAME . "` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `" . ORDER_PRICE_COLUMN_NAME . "` DECIMAL(10,2) NOT NULL,
                PRIMARY KEY (`id`)
            )
            ENGINE = InnoDB
            CHARSET=utf8
            COLLATE utf8_general_ci");
}

/**
 * @param string $userId ID пользователя
 * @param string $orderPrice Стоимость заказа
 */
function logOrder(string $userId, string $orderPrice): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `" . ORDERS_TABLE_NAME . "` (
                `" . USER_ID_COLUMN_NAME . "`,
                `" . ORDER_PRICE_COLUMN_NAME . "`
            )
        VALUES (
            :userId,
            :orderPrice
        )");

    $sth->bindParam(':userId', $userId);
    $sth->bindParam(':orderPrice', $orderPrice);

    $sth->execute();
}

/**
 * @param string $userId ID пользователя
 */
function setOrderId(string $userId): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `" . ORDERS_TABLE_NAME . "`
        SET
            `" . ORDER_ID_COLUMN_NAME . "` = CONCAT(
                DATE_FORMAT(
                    NOW(),
                    '%y%m'
                ),
                `id`
            )
        WHERE
            `" . ORDER_TIME_COLUMN_NAME . "` > DATE_SUB(
                NOW(),
                INTERVAL 10 SECOND
            )
                AND
            `" . USER_ID_COLUMN_NAME . "` = :userId
                AND
            `" . ORDER_ID_COLUMN_NAME . "` IS NULL
                AND
            `" . EXT_ID_COLUMN_NAME . "` IS NULL");

    $sth->bindParam(':userId', $userId);

    $sth->execute();
}

/**
 * @param string $userId ID пользователя
 * @return string|null Номер заказа
 */
function getOrderId(string $userId): ?string
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        SELECT
            IF(
                `" . ORDER_ID_COLUMN_NAME . "` IS NULL,
                CONCAT(
                    DATE_FORMAT(
                        NOW(),
                        '%y%m'
                    ),
                    `id`
                ),
                `" . ORDER_ID_COLUMN_NAME . "`
            ) AS
                `" . ORDER_ID_COLUMN_NAME . "`
        FROM
            `" . ORDERS_TABLE_NAME . "`
        WHERE
            `" . ORDER_TIME_COLUMN_NAME . "` > DATE_SUB(
                NOW(),
                INTERVAL 10 SECOND
            )
                AND
            `" . USER_ID_COLUMN_NAME . "` = :userId
                AND
            `" . ORDER_TIME_COLUMN_NAME . "` IS NOT NULL
                AND
            `" . EXT_ID_COLUMN_NAME . "` IS NULL
        ORDER BY
            `id`
        DESC
        LIMIT
            1");

    $sth->bindParam(':userId', $userId);

    $sth->execute();

    $result = $sth->fetch(PDO::FETCH_COLUMN);

    return ($result !== false) ? $result : null;
}

/**
 * @param string $extId Номер заказа Челябинвестбанк
 * @param string $orderId Номер заказа
 */
function updateOrder(string $extId, string $orderId): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `" . ORDERS_TABLE_NAME . "`
        SET
            `" . EXT_ID_COLUMN_NAME . "` = :extId
        WHERE
            `" . ORDER_ID_COLUMN_NAME . "` = :orderId");

    $sth->bindParam(':extId', $extId);
    $sth->bindParam(':orderId', $orderId);

    $sth->execute();
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
    createOrderTable();
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$acquirer = new AcquirerSdk\Acquirer(SBERBANK_USER_NAME,
    SBERBANK_PASSWORD);

$pilot = new MessengerSdk\Pilot(SMS_PILOT_KEY);

$singleton = (new MessengerSdk\Singleton)
    ->setFrom(SMS_PILOT_NAME)
    ->setFormat(MessengerSdk\Format::JSON);

foreach ($clients as $v) {
    try {
        getConnect()->beginTransaction() || exit(
            "Begin a transaction failed\n");
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    try {
        logOrder($v['uid'], $v['amount']);
        setOrderId($v['uid']);

        $orderId = getOrderId($v['uid']);

        $register = new AcquirerSdk\Register($orderId,
            (int) ((float) $v['amount'] * 100),
            SBERBANK_RETURN_URL);

        $dateTime = new DateTime("now", new DateTimeZone(TIME_ZONE));

        $dateTime->add(new DateInterval(sprintf("P%dD",
            ORDER_EXPIRATION_TIMEOUT)));

        $register->setExpirationDate($dateTime->format('Y-m-dTH:i:s'));

        $j = json_decode($acquirer->request($register));

        !is_null($j->orderId) || exit(
            sprintf("%s\n", (string) $j->errorMessage));

        updateOrder((string) $j->orderId, $orderId);

        getConnect()->commit() || exit(
            "Commit a transaction failed\n");
    } catch (PDOException | TypeError $e) {
        try {
            getConnect()->rollBack() || exit(
                "Rollback a transaction failed\n");
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        exit(sprintf("%s\n", $e->getMessage()));
    } catch (Exception | AcquirerSdk\Exception\ClientException $e) {
        try {
            getConnect()->rollBack() || exit(
                "Rollback a transaction failed\n");
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        echo sprintf("%s\n", $e->getMessage());

        continue;
    }

    $message = getMessage($v['user'],
        $v['amount'],
        $v['date'],
        (string) $j->formUrl);

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
