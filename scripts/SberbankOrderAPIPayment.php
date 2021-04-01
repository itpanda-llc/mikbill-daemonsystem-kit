<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-php-kit
 */

declare(strict_types=1);

/**
 * Токен Сбербанк
 * @link https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:start
 */
const SBERBANK_TOKEN = '***';

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
 * Путь к конфигурационному файлу MikBill
 * @link https://wiki.mikbill.pro/billing/config_file
 */
const CONFIG = '/var/www/mikbill/admin/app/etc/config.xml';

/** Наименование таблицы для ведения заказов */
const ORDERS_TABLE_NAME = '__sberbank_orders_log';

/** Наименование колонки "ID пользователя" */
const USER_ID_COLUMN_NAME = 'user_id';

/** Наименование колонки "Номер заказа банка" */
const EXT_ID_COLUMN_NAME = 'ext_id';

/** Наименование колонки "Время заказа" */
const ORDER_TIME_COLUMN_NAME = 'order_time';

/** Наименование колонки "Стоимость заказа" */
const ORDER_PRICE_COLUMN_NAME = 'order_price';

/**
 * Значение параметра "Статус" успешно оплаченного заказа
 * @link https://securepayments.sberbank.ru/wiki/doku.php/integration:api:rest:requests:getorderstatusextended
 */
const SBERBANK_PAY_STATUS = 2;

/**
 * Номер категории платежа
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const CATEGORY_ID = 1;

/**
 * Наименование категории платежа
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const CATEGORY_NAME = 'пополнение Сбербанк';

/**
 * Комментарий к платежу
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const PAY_COMMENT = 'платеж Сбербанк';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once '../../../autoload.php';

use Panda\Sberbank\AcquirerSdk;

/**
 * @return array|null Параметры заказов
 */
function getOrders(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `" . ORDERS_TABLE_NAME . "`.`" . USER_ID_COLUMN_NAME . "`,
            `" . ORDERS_TABLE_NAME . "`.`" . EXT_ID_COLUMN_NAME . "`,
            `" . ORDERS_TABLE_NAME . "`.`" . ORDER_PRICE_COLUMN_NAME . "`
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
                    `addons_pay_api`.`creation_time` > DATE_SUB(
                        NOW(),
                        INTERVAL :interval DAY
                    )
        WHERE
            `" . ORDERS_TABLE_NAME . "`.`" . ORDER_TIME_COLUMN_NAME . "` > DATE_SUB(
                NOW(),
                INTERVAL :interval DAY
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

/**
 * @return bool Результат проверки категории платежа
 */
function checkCategory(): bool
{
    $sth = getConnect()->prepare("
        SELECT
            `addons_pay_api_category`.`category`
        FROM
            `addons_pay_api_category`
        WHERE
            `addons_pay_api_category`.`category` = :categoryId");

    $sth->bindValue(':categoryId', CATEGORY_ID, PDO::PARAM_INT);

    $sth->execute();

    return $sth->rowCount() !== 0;
}

function addCategory(): void
{
    $sth = getConnect()->prepare("
        INSERT INTO
            `addons_pay_api_category` (
                `category`,
                `categoryname`
            )
        VALUES (
            :categoryId,
            :categoryName
        )");

    $sth->bindValue(':categoryId', CATEGORY_ID, PDO::PARAM_INT);
    $sth->bindValue(':categoryName', CATEGORY_NAME);

    $sth->execute();
}

/**
 * @param string $miscId Номер заказа
 * @param string $userRef ID пользователя
 * @param string $amount Размер платежа
 */
function logPayment(string $miscId,
                    string $userRef,
                    string $amount): void
{
    static $sth;
    
    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `addons_pay_api` (  
                `misc_id`,
                `category`,
                `user_ref`,
                `amount`,
                `creation_time`,
                `update_time`,
                `comment`
            )
        VALUES (
            :miscId,
            :categoryId,
            :userRef,
            :amount,
            NOW(),
            NOW(),
            :payComment
        )");

    $sth->bindParam(':miscId', $miscId);
    $sth->bindParam(':userRef', $userRef);
    $sth->bindParam(':amount', $amount);
    $sth->bindValue(':categoryId', CATEGORY_ID, PDO::PARAM_INT);
    $sth->bindValue(':payComment', PAY_COMMENT);
    
    $sth->execute();
}

try {
    !is_null($orders = getOrders()) || exit;
    checkCategory() || addCategory();

    if (defined('SBERBANK_TOKEN'))
        $acquirer = new AcquirerSdk\Acquirer(SBERBANK_TOKEN);
    else
        $acquirer = new AcquirerSdk\Acquirer(SBERBANK_USER_NAME,
            SBERBANK_PASSWORD);
} catch (PDOException | AcquirerSdk\Exception\ClientException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

foreach ($orders as $v)
    try {
        $j = json_decode($acquirer->request(
            new AcquirerSdk\StatusExtended($v['ext_id'])));

        !is_null($j->orderStatus) || exit(sprintf("%s\n",
            (string) $j->errorMessage));

        if ((int) $j->orderStatus === SBERBANK_PAY_STATUS)
            logPayment($v['ext_id'], $v['user_id'], $v['order_price']);
    } catch (AcquirerSdk\Exception\ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }
