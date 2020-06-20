<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Тип NAS RouterOS */
const NAS_TYPE = 'mikrotik';

/** Команда NAS "Сброс" Simple Queue-правил */
const NAS_COMMAND = '/queue simple remove [/queue simple find]';

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
 * @return array|null Параметры NAS
 */
function getNAS(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `radnas`.`nasname`,
            `radnas`.`naslogin`,
            `radnas`.`naspass`
        FROM
            `radnas`
        WHERE
            `radnas`.`nastype` = :nasType
                AND
            `radnas`.`nasname` != ''
                AND
            `radnas`.`naslogin` != ''");

    $sth->bindValue(':nasType', NAS_TYPE);

    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

try {
    /** Получение параметров NAS */
    !is_null($nas = getNAS()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var array $v Параметры NAS */
foreach ($nas as $v) {

    /** SSH-соединение */
    if (($ssh2 = ssh2_connect($v['nasname']))

        /** Аутентификация */
        && (ssh2_auth_password($ssh2,
            $v['naslogin'], $v['naspass'])))
    {
        /** Выполнение команды */
        ssh2_exec($ssh2, NAS_COMMAND);
    }

    /** Закрытие SSH-соединения */
    ssh2_disconnect($ssh2);
}
