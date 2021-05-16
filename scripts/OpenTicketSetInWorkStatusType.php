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
 * ID сотрудника
 * @link https://wiki.mikbill.pro/billing/howto/stuff_personal
 */
const STAFF_ID = 1;

/** Наименование записи */
const RECORD = 'Состояние: Поставлен в работу';

/** Наименование записи */
const NOTE = 'Поставлен в работу автоматически';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';

/**
 * @return string|null Заявки
 */
function getTickets(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `tickets_tickets`.`ticketid`
        FROM
            `tickets_tickets`
        LEFT JOIN
            `tickets_performers`
                ON
                    `tickets_performers`.`ticketid` = `tickets_tickets`.`ticketid`
        WHERE
            `tickets_tickets`.`statustypeid` = 1
                AND
            `tickets_tickets`.`creationdate` > DATE_SUB(
                NOW(),
                INTERVAL :interval DAY 
            )
                AND
            `tickets_performers`.`ticketid` IS NULL");

    $sth->bindParam(':interval', $_SERVER['argv'][1]);

    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_COLUMN);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $ticketId ID тикета
 * @return bool Успешное обновление тикетов
 */
function updateTicket(string $ticketId): bool
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        UPDATE
            `tickets_tickets`
        SET
            `tickets_tickets`.`inworkdate` = NOW(),
            `tickets_tickets`.`inwork_by_stuffid` = :staffId,
            `tickets_tickets`.`statustypeid` = 3
        WHERE
            `tickets_tickets`.`ticketid` = :ticketId");

    $sth->bindParam(':ticketId', $ticketId);
    $sth->bindValue(':staffId', STAFF_ID, PDO::PARAM_INT);

    $sth->execute();

    return $sth->rowCount() !== 0;
}

/**
 * @param string $ticketId ID тикета
 * @return bool Успешная запись
 */
function logTicket(string $ticketId): bool
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `tickets_logs` (
                `ticketid`,
                `logdate`,
                `record`,
                `stuffid`
            )
        VALUES (
            :ticketId,
            NOW(),
            :record,
            :staffId
        )");

    $sth->bindParam(':ticketId', $ticketId);
    $sth->bindValue(':record', RECORD);
    $sth->bindValue(':staffId', STAFF_ID, PDO::PARAM_INT);

    $sth->execute();

    return $sth->rowCount() !== 0;
}

/**
 * @param string $ticketId ID тикета
 * @return bool Успешная запись
 */
function logNote(string $ticketId): bool
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `tickets_notes` (
                `ticketid`,
                `stuffid`,
                `note`
            )
        VALUES (
            :ticketId,
            :staffId,
            :note
        )");

    $sth->bindParam(':ticketId', $ticketId);
    $sth->bindValue(':note', NOTE);
    $sth->bindValue(':staffId', STAFF_ID, PDO::PARAM_INT);

    $sth->execute();

    return $sth->rowCount() !== 0;
}

try {
    !is_null($tickets = getTickets()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

foreach ($tickets as $v) {
    try {
        getConnect()->beginTransaction() || exit(
            "Begin a transaction failed\n");
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    try {
        updateTicket($v);
        logTicket($v);
        logNote($v);

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
