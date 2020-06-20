<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/**
 * Наименование параметра АСР MikBill "Блокировка пользователя"
 */
const BLOCK_PARAM_NAME = 'blocked';

/**
 * Наименование параметра АСР MikBill "День снятия абонентской платы"
 */
const PAYMENT_DAY_PARAM_NAME = 'date_abonka';

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
                    `logs`.`valuename` = :blockParamName
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

    $sth->bindValue(':blockParamName', BLOCK_PARAM_NAME);
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

    return ($sth->rowCount() !== 0) ? true : false;
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
    /** Получение параметров заказов */
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var array $v Параметры клиента */
foreach ($clients as $v) {
    try {
        /** Начало транзакции */
        getConnect()->beginTransaction();
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    try {
        /** Обновление дня снятия абонентской платы */
        updateClient($v['uid'], $v['day']);

        /** Запись события */
        logEvent($v['uid'], $v['gid'],
            PAYMENT_DAY_PARAM_NAME, $v['date_abonka'], $v['day']);

        /** Фиксация транзакции */
        getConnect()->commit();
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
