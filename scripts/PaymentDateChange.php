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

/**
 * Наименование параметра "День снятия абонентской платы" MikBill
 */
const PAYMENT_DAY_PARAM_NAME = 'date_abonka';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';

/**
 * @return array|null Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `users`.`uid`,
            `users`.`gid`,
            `users`.`date_abonka`,
            DATE_FORMAT(
                NOW() - INTERVAL 2 DAY,
                '%e'
            ) AS
                `day`
        FROM
            `users`
        LEFT JOIN
            (
                SELECT
                    `logs`.`uid`
                FROM
                    `logs`
                WHERE
                    `logs`.`date` > DATE_SUB(
                        NOW(),
                        INTERVAL 1 MONTH
                    )
                        AND
                    `logs`.`valuename` = 'blocked'
                        AND
                    `logs`.`oldvalue` = 0
                        AND
                    `logs`.`newvalue` = 1
            ) AS
                `events`
                ON
                    `events`.`uid` = `users`.`uid`
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
            `events`.`uid` IS NOT NULL
                AND
            `users`.`date_abonka` = DATE_FORMAT(
                NOW() - INTERVAL :interval DAY,
                '%e'
            )
        GROUP BY
            `users`.`uid`");

    $sth->bindParam(':interval', $_SERVER['argv'][1]);

    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $uId ID пользователя
 * @param string $day День снятия абонентской платы
 * @return bool Факт обновления дня снятия абонентской платы
 */
function updateClient(string $uId, string $day): bool
{
    static $sth;
    
    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `users`
        SET
            `users`.`date_abonka` = :day
        WHERE
            `users`.`uid` = :uId");

    $sth->bindParam(':uId', $uId);
    $sth->bindParam(':day', $day);

    $sth->execute();

    return $sth->rowCount() !== 0;
}

/**
 * @param string $uId ID пользователя
 * @param string $gId ID тарифного плана
 * @param string $valueName Наименование параметра
 * @param string $oldValue Старое значение параметра
 * @param string $newValue Новое значение параметра
 */
function logEvent(string $uId,
                  string $gId,
                  string $valueName,
                  string $oldValue,
                  string $newValue): void
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
    $sth->bindParam(':newValue', $newValue);

    $sth->execute();
}

try {
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

foreach ($clients as $v) {
    try {
        getConnect()->beginTransaction() || exit(
            "Begin a transaction failed\n");
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    try {
        updateClient($v['uid'], $v['day']);
        logEvent($v['uid'],
            $v['gid'],
            PAYMENT_DAY_PARAM_NAME,
            $v['date_abonka'],
            $v['day']);

        getConnect()->commit() || exit(
            "Commit a transaction failed\n");
    } catch (PDOException $e) {
        try {
            getConnect()->rollBack() || exit(
                "Rollback a transaction failed\n");
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        exit(sprintf("%s\n", $e->getMessage()));
    }
}
