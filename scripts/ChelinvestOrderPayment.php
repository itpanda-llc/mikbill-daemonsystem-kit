<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/**
 * Подключение библиотеки Челябинвестбанк
 * @link https://github.com/itpanda-llc/chelinvest-acquirer-php-sdk
 */
require_once '../../chelinvest-acquirer-php-sdk/autoload.php';

/**
 * Импорт классов библиотеки Челябинвестбанк
 * @link https://github.com/itpanda-llc/chelinvest-acquirer-php-sdk
 */
use Panda\Chelinvest\AcquirerSDK\Acquirer;
use Panda\Chelinvest\AcquirerSDK\Exception\ClientException;

/**
 * Логин Челябинвестбанк
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_USER = 'CHELINVEST_USER';

/**
 * Пароль Челябинвестбанк
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_PASSWORD = 'CHELINVEST_PASSWORD';

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/**
 * Значение параметра "Статус" успешно оплаченного заказа
 * @link https://mpi.chelinvest.ru/gorodUnified/documentation/inf/MPI/MPI
 */
const CHELINVEST_PAY_STATUS = 0;

/** Наименование таблицы для ведения заказов */
const ORDERS_TABLE = 'orders_log';

/** Номер категории платежа */
const CATEGORY_ID = 1;

/** Наименование категории платежа */
const CATEGORY_NAME = 'пополнение Челябинвестбанк';

/** Комментарий к платежу */
const PAY_COMMENT = 'платеж Город74';

/**
 * @return SimpleXMLElement Объект конфигурационного файла
 */
function getConfig(): SimpleXMLElement
{
    static $sxe;

    if (!isset($sxe)) {
        try {
            $sxe = new SimpleXMLElement(CONFIG,
                LIBXML_ERR_NONE,
                true);
        } catch (Exception $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }
    }

    return $sxe;
}

/**
 * @return PDO Обработчик запросов к БД
 */
function getConnect(): PDO
{
    static $dbh;

    if (!isset($dbh)) {
        $dsn = sprintf("mysql:host=%s;dbname=%s;charset=utf8",
            getConfig()->parameters->mysql->host,
            getConfig()->parameters->mysql->dbname);

        try {
            $dbh = new PDO($dsn,
                getConfig()->parameters->mysql->username,
                getConfig()->parameters->mysql->password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }
    }

    return $dbh;
}

/**
 * @return array|null Параметры заказов
 */
function getOrders(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `" . ORDERS_TABLE . "`.`user_id`,
            `" . ORDERS_TABLE . "`.`order_id`,
            `" . ORDERS_TABLE . "`.`ext_id`,
            `" . ORDERS_TABLE . "`.`order_price`
        FROM
            `" . ORDERS_TABLE . "`
        LEFT JOIN
            `addons_pay_api`
                ON
                    `addons_pay_api`.`misc_id` = `" . ORDERS_TABLE . "`.`ext_id`
                        AND
                    `addons_pay_api`.`category` = :categoryId
                        AND
                    `addons_pay_api`.`creation_time` > DATE_SUB(
                        NOW(),
                        INTERVAL :interval DAY
                    )
        WHERE
            `" . ORDERS_TABLE . "`.`order_time` > DATE_SUB(
                NOW(),
                INTERVAL :interval DAY
            )
                AND
            `" . ORDERS_TABLE . "`.`ext_id` IS NOT NULL
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

    return ($sth->rowCount() !== 0) ? true : false;
}

/** Добавление категории платежа */
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
    /** Получение параметров заказов */
    !is_null($orders = getOrders()) || exit;

    /** Проверка и добавление категории платежа */
    checkCategory() || addCategory();
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var Acquirer $acquirer Экземпляр Челябинвестбанк Эквайер */
$acquirer = new Acquirer(CHELINVEST_USER, CHELINVEST_PASSWORD);

/** @var array $v Параметры заказа */
foreach ($orders as $v) {
    try {
        /** @var stdClass $j Ответ Челябинвестбанк Эквайер */
        $j = json_decode($acquirer->getState($v['ext_id']));
    } catch (ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());

        continue;
    }

    if ($j->status === CHELINVEST_PAY_STATUS) {
        try {
            /** Запись информации о платеже БД */
            logPayment($v['ext_id'], $v['user_id'], $v['order_price']);
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }
    }
}
