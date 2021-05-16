<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-kit
 */

declare(strict_types=1);

/**
 * Путь к конфигурационному файлу MikBill
 * @link https://wiki.mikbill.pro/billing/config_file
 */
const CONFIG = '/var/www/mikbill/admin/app/etc/config.xml';

/** Размер бонуса (Денежная единица) */
const BONUS_AMOUNT = 90;

/**
 * Номер категории платежа
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const CATEGORY_ID = -1;

/**
 * Наименование категории платежа
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const CATEGORY_NAME = 'Бонус в День рождения';

/**
 * Комментарий к платежу
 * @link https://wiki.mikbill.pro/billing/configuration/payment_api
 */
const PAY_COMMENT = CATEGORY_NAME;

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';

/**
 * @return array|null Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->query("
        SELECT
            `users`.`uid`
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
                    `key` = 'ext_discount_global'
            ) AS
                `discount_global`
                    ON
                        `discount_global`.`uid` = `users`.`uid`
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
                        `discount_extended`.`uid` = `users`.`uid`
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
                        `discount_packet`.`uid` = `users`.`uid`
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
                        `discount_subs`.`uid` = `users`.`uid`
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
                        `discount_device`.`uid` = `users`.`uid`
        WHERE
            `users`.`state` = 1
                AND
            MONTH(
                `users`.`date_birth`
            ) = MONTH(
                    NOW()
                )
                AND
            DAY(
                `users`.`date_birth`
                ) = DAY(
                        NOW()
                    )
                AND
            `users`.`sms_tel` IS NOT NULL
                AND
            `users`.`sms_tel` != ''
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
 * @param string $userRef ID пользователя
 * @param int $amount Размер бонуса
 */
function logBonus(string $userRef, int $amount): void
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
    $sth->bindParam(':amount', $amount, PDO::PARAM_INT);

    $sth->execute();
}

try {
    !is_null($clients = getClients()) || exit;
    checkCategory() || addCategory();

    foreach ($clients as $v) logBonus($v, BONUS_AMOUNT);
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}
