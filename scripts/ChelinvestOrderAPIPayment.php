<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-kit
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

/**
 * Значение параметра "Статус" успешно оплаченного заказа
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_PAY_STATUS = 0;

/**
 * Номер категории платежа
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const CATEGORY_ID = 2;

/**
 * Наименование категории платежа
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const CATEGORY_NAME = 'пополнение Челябинвестбанк';

/**
 * Комментарий к платежу
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const PAY_COMMENT = 'платеж Город74';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once '../../../autoload.php';

use Panda\Chelinvest\AcquirerSdk;

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
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$acquirer = new AcquirerSdk\Acquirer(CHELINVEST_USER_NAME,
    CHELINVEST_PASSWORD);

foreach ($orders as $v)
    try {
        $j = json_decode($acquirer->request(
            new AcquirerSdk\StatusShort($v['ext_id'])));

        !is_null($j->status) || exit(sprintf("%s\n",
            (string) $j->errorMessage));

        if ((int) $j->status === CHELINVEST_PAY_STATUS)
            logPayment($v['ext_id'], $v['user_id'], $v['order_price']);
    } catch (AcquirerSdk\Exception\ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }
