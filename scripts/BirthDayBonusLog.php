<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Размер бонуса (денежная единица) */
const BONUS_AMOUNT = 90;

/** Номер категории платежа */
const CATEGORY_ID = -1;

/** Наименование категории платежа */
const CATEGORY_NAME = 'Бонус в День рождения';

/** Комментарий к платежу */
const PAY_COMMENT = CATEGORY_NAME;

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
 * @return array|null ID клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->query("
        SELECT
            `clients`.`uid`
        FROM
            (
                SELECT
                    `users`.`uid`,
                    `users`.`sms_tel`,
                    `users`.`date_birth`
                FROM
                    `users`
                UNION
                SELECT
                    `usersfreeze`.`uid`,
                    `usersfreeze`.`sms_tel`,
                    `usersfreeze`.`date_birth`
                FROM
                    `usersfreeze`
            ) AS
                `clients`
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
                `discount_global`
                    ON
                        `discount_global`.`uid` = `clients`.`uid`
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
                `discount_extended`
                    ON
                        `discount_extended`.`uid` = `clients`.`uid`
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
                `discount_packet`
                    ON
                        `discount_packet`.`uid` = `clients`.`uid`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = 'ext_discount_subs'
            ) AS
                `discount_subs`
                    ON
                        `discount_subs`.`uid` = `clients`.`uid`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = 'ext_discount_device'
            ) AS
                `discount_device`
                    ON
                        `discount_device`.`uid` = `clients`.`uid`
        WHERE
            MONTH(
                `clients`.`date_birth`
            ) = MONTH(
                    NOW()
                )
                AND
            DAY(
                `clients`.`date_birth`
                ) = DAY(
                        NOW()
                    )
                AND
            `clients`.`sms_tel` IS NOT NULL
                AND
            `clients`.`sms_tel` != ''
                AND
            (
                (
                    (
                        `discount_global`.`value` IS NULL
                            OR
                        `discount_global`.`value` = '0'
                    )
                        AND
                    (
                        `discount_extended`.`value` IS NULL
                            OR
                        `discount_extended`.`value` = '0'
                    )
                )
                    OR
                (
                    `discount_extended`.`value` = '1'
                        AND
                    (
                        `discount_packet`.`value` IS NULL
                            OR
                        `discount_packet`.`value` = '0'
                    )
                        AND
                    (
                        `discount_subs`.`value` IS NULL
                            OR
                        `discount_subs`.`value` = '0'
                    )
                        AND
                    (
                        `discount_device`.`value` IS NULL
                            OR
                        `discount_device`.`value` = '0'
                    )
                )
            )"
    );

    $result = $sth->fetchAll(PDO::FETCH_COLUMN);

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
 * @param string $userRef ID пользователя
 * @param string $amount Размер бонуса
 */
function logBonus(string $userRef, string $amount): void
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
            '',
            :categoryId,
            :userRef,
            :amount,
            NOW(),
            NOW(),
            :payComment
        )");

    $sth->bindValue(':categoryId', CATEGORY_ID, PDO::PARAM_INT);
    $sth->bindValue(':payComment', PAY_COMMENT);
    $sth->bindParam(':userRef', $userRef);
    $sth->bindParam(':amount', $amount);

    $sth->execute();
}

try {
    /** Получение ID клиентов */
    !is_null($clients = getClients()) || exit;

    /** Проверка и добавление категории платежа */
    checkCategory() || addCategory();
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var string $v ID клиента */
foreach ($clients as $v) {
    try {
        /** Запись информации о бонусе в БД */
        logBonus($v, BONUS_AMOUNT);
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }
}
