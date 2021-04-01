<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-php-kit
 */

declare(strict_types=1);

/**
 * Путь к конфигурационному файлу MikBill
 * @link https://wiki.mikbill.pro/billing/config_file
 */
const CONFIG = '/var/www/mikbill/admin/app/etc/config.xml';

/** Наименование таблицы для ведения заказов */
const ORDERS_TABLE_NAME = '__sberbank_orders_log';

/** Наименование колонки "Номер заказа банка" */
const EXT_ID_COLUMN_NAME = 'ext_id';

/** Наименование колонки "Время заказа" */
const ORDER_TIME_COLUMN_NAME = 'order_time';

/**
 * Номер категории платежа
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const CATEGORY_ID = 1;

/**
 * Адрес оплаты заказа
 * @link https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:start
 */
const SBERBANK_ORDER_URL = 'https://securepayments.sberbank.ru/payment/merchants/test/payment_ru.html?mdOrder=';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';

/**
 * @return array|null Параметры заказов
 */
function getOrders(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `" . ORDERS_TABLE_NAME . "`.`" . EXT_ID_COLUMN_NAME . "`
        FROM
            `" . ORDERS_TABLE_NAME . "`
        LEFT JOIN
            `addons_pay_api`
                ON
                    `addons_pay_api`.`misc_id`
                        =
                    `" . ORDERS_TABLE_NAME . "`.`" . EXT_ID_COLUMN_NAME . "`
                        AND
                    `addons_pay_api`.`category` = :categoryId
                        AND
                    `addons_pay_api`.`creation_time` < DATE_SUB(
                        NOW(),
                        INTERVAL :interval DAY
                    )
                        AND
                    `addons_pay_api`.`creation_time` > DATE_SUB(
                        NOW(),
                        INTERVAL :interval * 3 DAY
                    )
        WHERE
            `" . ORDERS_TABLE_NAME . "`.`" . ORDER_TIME_COLUMN_NAME . "` < DATE_SUB(
                NOW(),
                INTERVAL :interval DAY
            )
                AND
            `" . ORDERS_TABLE_NAME . "`.`" . ORDER_TIME_COLUMN_NAME . "` > DATE_SUB(
                NOW(),
                INTERVAL :interval * 3 DAY
            )
                AND
            `" . ORDERS_TABLE_NAME . "`.`" . EXT_ID_COLUMN_NAME . "` IS NOT NULL
                AND
            `addons_pay_api`.`record_id` IS NULL");

    $sth->bindValue(':categoryId', CATEGORY_ID, PDO::PARAM_INT);
    $sth->bindParam(':interval', $_SERVER['argv'][1]);

    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

try {
    !is_null($orders = getOrders()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

foreach ($orders as $v) {
    $ch = curl_init(sprintf("%s%s",
        SBERBANK_ORDER_URL,
        $orders['ext_id']));

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    curl_exec($ch);
    curl_close($ch);
}
