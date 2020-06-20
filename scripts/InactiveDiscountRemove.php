<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Наименования параметров скидок в БД */
const DISCOUNT_OPTIONS = [
    'DISCOUNT_EXT_KEY' => 'ext_discount_extended',
    'DISCOUNT_GLOBAL_KEY' => 'ext_discount_global',
    'DISCOUNT_GLOBAL_FIXED_KEY' => 'ext_discount_global_fixed',
    'DISCOUNT_EXT_PACKET_KEY' => 'ext_discount_packet',
    'DISCOUNT_EXT_PACKET_FIXED_KEY' => 'ext_discount_packet_fixed',
    'DISCOUNT_EXT_SUBS_KEY' => 'ext_discount_subs',
    'DISCOUNT_EXT_SUBS_FIXED_KEY' => 'ext_discount_subs_fixed',
    'DISCOUNT_EXT_DEVICE_KEY' => 'ext_discount_device',
    'DISCOUNT_EXT_DEVICE_FIXED_KEY' => 'ext_discount_device_fixed'
];

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
 * @return array|null Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `clients`.`uid`,
            `clients`.`gid`,
            `ext_discount_extended`.`key`,
            `ext_discount_extended`.`value`,
            `ext_discount_global`.`key`,
            `ext_discount_global`.`value`,
            `ext_discount_global_fixed`.`key`,
            `ext_discount_global_fixed`.`value`,
            `ext_discount_packet`.`key`,
            `ext_discount_packet`.`value`,
            `ext_discount_packet_fixed`.`key`,
            `ext_discount_packet_fixed`.`value`,
            `ext_discount_subs`.`key`,
            `ext_discount_subs`.`value`,
            `ext_discount_subs_fixed`.`key`,
            `ext_discount_subs_fixed`.`value`,
            `ext_discount_device`.`key`,
            `ext_discount_device`.`value`,
            `ext_discount_device_fixed`.`key`,
            `ext_discount_device_fixed`.`value`
        FROM
             (
                SELECT
                    `usersfreeze`.`uid`,
                    `usersfreeze`.`gid`,
                    `usersfreeze`.`freeze_date` AS
                        `date`
                FROM
                    `usersfreeze`
                UNION
                SELECT
                    `usersblok`.`uid`,
                    `usersblok`.`gid`,
                    `usersblok`.`block_date` AS
                        `date`
                FROM
                    `usersblok`
                UNION
                SELECT
                    `usersdel`.`uid`,
                    `usersdel`.`gid`,
                    `usersdel`.`del_date` AS
                        `date`
                FROM
                    `usersdel`
            ) AS
                `clients`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`key`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = :discountExtKey
            ) AS
                `ext_discount_extended`
                    ON
                        `ext_discount_extended`.`uid` = `clients`.`uid`                 
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`key`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = :discountGlobalKey
            ) AS
                `ext_discount_global`
                    ON
                        `ext_discount_global`.`uid` = `clients`.`uid`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`key`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = :discountGlobalFixedKey
            ) AS
                `ext_discount_global_fixed`
                    ON
                        `ext_discount_global_fixed`.`uid` = `clients`.`uid`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`key`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = :discountExtPacketKey
            ) AS
                `ext_discount_packet`
                    ON
                        `ext_discount_packet`.`uid` = `clients`.`uid`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`key`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = :discountExtPacketFixedKey
            ) AS
                `ext_discount_packet_fixed`
                    ON
                        `ext_discount_packet_fixed`.`uid` = `clients`.`uid`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`key`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = :discountExtSubsKey
            ) AS
                `ext_discount_subs`
                    ON
                        `ext_discount_subs`.`uid` = `clients`.`uid`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`key`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = :discountExtSubsFixedKey
            ) AS
                `ext_discount_subs_fixed`
                    ON
                        `ext_discount_subs_fixed`.`uid` = `clients`.`uid`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`key`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = :discountExtDeviceKey
            ) AS
                `ext_discount_device`
                    ON
                        `ext_discount_device`.`uid` = `clients`.`uid`
        LEFT JOIN
            (
                SELECT
                    `users_custom_fields`.`uid`,
                    `users_custom_fields`.`key`,
                    `users_custom_fields`.`value`
                FROM
                    `users_custom_fields`
                WHERE
                    `key` = :discountExtDeviceFixedKey
            ) AS
                `ext_discount_device_fixed`
                    ON
                        `ext_discount_device_fixed`.`uid` = `clients`.`uid`
        WHERE
            `clients`.`date` > DATE_SUB(
                NOW(),
                INTERVAL :interval MINUTE
            )");

    $sth->bindValue(':discountExtKey',
        DISCOUNT_OPTIONS['DISCOUNT_EXT_KEY']);
    $sth->bindValue(':discountGlobalKey',
        DISCOUNT_OPTIONS['DISCOUNT_GLOBAL_KEY']);
    $sth->bindValue(':discountGlobalFixedKey',
        DISCOUNT_OPTIONS['DISCOUNT_GLOBAL_FIXED_KEY']);
    $sth->bindValue(':discountExtPacketKey',
        DISCOUNT_OPTIONS['DISCOUNT_EXT_PACKET_KEY']);
    $sth->bindValue(':discountExtPacketFixedKey',
        DISCOUNT_OPTIONS['DISCOUNT_EXT_PACKET_FIXED_KEY']);
    $sth->bindValue(':discountExtSubsKey',
        DISCOUNT_OPTIONS['DISCOUNT_EXT_SUBS_KEY']);
    $sth->bindValue(':discountExtSubsFixedKey',
        DISCOUNT_OPTIONS['DISCOUNT_EXT_SUBS_FIXED_KEY']);
    $sth->bindValue(':discountExtDeviceKey',
        DISCOUNT_OPTIONS['DISCOUNT_EXT_DEVICE_KEY']);
    $sth->bindValue(':discountExtDeviceFixedKey',
        DISCOUNT_OPTIONS['DISCOUNT_EXT_DEVICE_FIXED_KEY']);

    $sth->bindParam(':interval', $_SERVER['argv'][1]);

    $sth->execute();

    /** @var array $result !! PDO::FETCH_NAMED */
    $result = $sth->fetchAll(PDO::FETCH_NAMED);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $uId ID пользователя
 * @param string $discountKey Наименование параметра скикдки
 */
function removeDiscount(string $uId,
                        string $discountKey): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        DELETE
        FROM
            `users_custom_fields`
        WHERE
            `users_custom_fields`.`uid` = :uId
                AND
            `users_custom_fields`.`key` = :discountKey");

    $sth->bindParam(':uId', $uId);
    $sth->bindParam(':discountKey', $discountKey);

    $sth->execute();
}

/**
 * @param string $uId ID пользователя
 * @param string $gId ID тарифного плана
 * @param string $valueName Наименование параметра
 * @param string $oldValue Старое значение параметра
 * @param string|null $newValue Новое значение параметра
 */
function logEvent(string $uId,
                  string $gId,
                  string $valueName,
                  string $oldValue,
                  string $newValue = null): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `logs` (
                `stuffid`,
                `date`,
                `logtypeid`,
                `uid`,
                `gid`,
                `valuename`,
                `oldvalue`,
                `newvalue`
            )
        VALUES (
            0,
            NOW(),
            1,
            :uId,
            :gId,
            :valueName,
            :oldValue,
            :newValue
        )");

    $sth->bindParam(':uId', $uId);
    $sth->bindParam(':gId', $gId);
    $sth->bindParam(':valueName', $valueName);
    $sth->bindParam(':oldValue', $oldValue);
    $sth->bindParam(':newValue',
        $newValue, (!is_null($newValue))
            ? PDO::PARAM_STR
            : PDO::PARAM_NULL);

    $sth->execute();
}

try {
    /** Получение параметров клиентов */
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var array $client Параметры клиента */
foreach ($clients as $client) {
    try {
        /** Начало транзакции */
        getConnect()->beginTransaction();
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    /** @var string $option Наименование параметра скидки*/
    foreach (DISCOUNT_OPTIONS as $option) {

        if (($key = array_search(
            $option, $client['key'], true)) !== false)
        {
            try {
                /** Удаление скидки */
                removeDiscount($client['uid'], $option);

                /** Запись события */
                logEvent($client['uid'],
                    $client['gid'], $option, $client['value'][$key]);
            } catch (PDOException $e) {
                try {
                    /** Откат транзакции */
                    getConnect()->rollBack();
                } catch (PDOException $e) {
                    exit(sprintf("%s\n", $e->getMessage()));
                }

                exit(sprintf("%s\n", $e->getMessage()));
            }
        }
    }

    try {
        /** Фиксация транзакции */
        getConnect()->commit();
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }
}
