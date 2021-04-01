<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-php-kit
 */

declare(strict_types=1);

/**
 * Логин Челябинвестбанк
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_USER_NAME = '***';

/**
 * Пароль Челябинвестбанк
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_PASSWORD = '***';

/**
 * URL-адрес для возврата после оплаты
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_RETURN_URL = '***';

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
const ORDERS_TABLE_NAME = '__chelinvest_orders_log';

/** Наименование колонки "ID пользователя" */
const USER_ID_COLUMN_NAME = 'user_id';

/** Наименование колонки "Номер заказа банка" */
const EXT_ID_COLUMN_NAME = 'ext_id';

/** Наименование колонки "Время заказа" */
const ORDER_TIME_COLUMN_NAME = 'order_time';

/** Наименование колонки "Стоимость заказа" */
const ORDER_PRICE_COLUMN_NAME = 'order_price';

/** Наименование сервиса */
const SERVICE_NAME = 'Домашний интернет';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\Chelinvest\AcquirerSdk;
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
            `clients`.`amount`
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
                        `amount`
                FROM
                    `users`
                LEFT JOIN 
                    `bugh_uslugi_stat`
                        ON
                            `bugh_uslugi_stat`.`uid` = `users`.`uid`
                                AND
                            (
                                `bugh_uslugi_stat`.`usluga` = 1
                                    OR
                                `bugh_uslugi_stat`.`usluga` = 2
                            )
                                AND
                            `bugh_uslugi_stat`.`active` = 1
                                AND
                            DAY(
                                `bugh_uslugi_stat`.`date_start`) = DATE_FORMAT(
                                    (
                                        NOW() - INTERVAL :interval DAY
                                    ),
                                    '%e'
                            )
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
                    `packets`.`do_credit_vremen` = 1
                        AND
                    `packets`.`do_credit_swing_date` = 1
                        AND
                    `users`.`credit` != 0
                        AND
                    `bugh_uslugi_stat`.`uid` IS NOT NULL
                        AND
                    `users`.`sms_tel` IS NOT NULL
                        AND
                    `users`.`sms_tel` != ''
                GROUP BY
                    `users`.`uid`
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
 * @return string|null Номер заказа
 */
function getOrderId(string $userId): ?string
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        SELECT
            `id`
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
 * @param string $account Аккаунт
 * @return string Наименование позиции
 */
function getProduct(string $account): string
{
    return sprintf("%s (Л/СЧ N%s)", SERVICE_NAME, $account);
}

/**
 * @param string $extId Номер заказа Челябинвестбанк
 * @param string $id Номер заказа
 */
function updateOrder(string $extId, string $id): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `" . ORDERS_TABLE_NAME . "`
        SET
            `" . EXT_ID_COLUMN_NAME . "` = :extId
        WHERE
            `id` = :id");

    $sth->bindParam(':extId', $extId);
    $sth->bindParam(':id', $id);

    $sth->execute();
}

/**
 * @param string $account Аккаунт
 * @param string $url URL-адрес страницы оплаты
 * @return string Текст сообщения
 */
function getMessage(string $account, string $url): string
{
    return sprintf("Напоминаем о необходимости внесения"
        . " платежа на счет #%s. Удобная оплата: %s",
        $account,
        $url);
}

try {
    !is_null($clients = getClients()) || exit;
    createOrderTable();
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$acquirer = new AcquirerSdk\Acquirer(CHELINVEST_USER_NAME,
    CHELINVEST_PASSWORD);

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
        $orderId = getOrderId($v['uid']);

        $registerCommon = new AcquirerSdk\RegisterCommon(
            CHELINVEST_RETURN_URL);

        $registerCommon->addProduct(getProduct($v['user']),
            1,
            (int) ((float) $v['amount'] * 100),
            '0');

        $j = json_decode($acquirer->request($registerCommon));

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

    $message = getMessage($v['user'], (string) $j->formUrl);

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
