<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Тип NAS RouterOS */
const NAS_TYPE = 'mikrotik';

/** Наименование конфигурационного файла RouterOS */
const FILE_NAME = '_____ckassa-greenzone-auto';

/** Команда NAS Импорт файла */
const IMPORT_FILE_NAS_COMMAND = '/import file="' . FILE_NAME . '"';

/** @var array $networks Список сетей CKassa */
$networks = [
    '94.138.149.214/32',
    '94.138.149.128/27',
    '91.142.87.220/32',
    '213.208.182.174/32',
    '77.75.157.168/32',
    '77.75.157.169/32',
    '77.75.159.166/32',
    '77.75.159.170/32',
    '89.111.54.163/32',
    '89.111.54.165/32',
    '185.77.232.26/32',
    '185.77.233.26/32',
    '185.77.232.27/32',
    '185.77.233.27/32',
    '193.186.162.114/32'
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

/**
 * @param array $networks Список сетей CKassa
 * @return bool Результат записи файла
 */
function createFile(array $networks): bool
{
    if (($handle =
            fopen(sprintf("../%s", FILE_NAME), 'a')) === false)
        return false;

    $string = "/ip firewall filter\n";
    $string .= sprintf("remove [find comment=\"%s\"]\n",
        FILE_NAME);
    $string .= sprintf("add action=accept chain=forward"
        . " comment=%s src-address-list=%s place-before=0\n",
        FILE_NAME,
        FILE_NAME);
    $string .= sprintf("add action=accept chain=forward"
        ." comment=%s dst-address-list=%s place-before=0\n",
        FILE_NAME,
        FILE_NAME);

    $string .= "/ip firewall nat\n";
    $string .= sprintf("remove [find comment=\"%s\"]\n",
        FILE_NAME);
    $string .= sprintf("add action=masquerade chain=srcnat"
        . " comment=%s dst-address-list=%s place-before=0\n",
        FILE_NAME,
        FILE_NAME);

    $string .= "/ip firewall address-list\n";
    $string .= sprintf("remove [find list=\"%s\"]\n",
        FILE_NAME);

    foreach ($networks as $v) {
        $string .= sprintf("add list=\"%s\" address=%s\n",
            FILE_NAME,
            $v);
    }

    $string .= sprintf("/file remove \"%s\"\n",
        FILE_NAME);

    if (fwrite($handle, $string) === false) return false;

    if (fclose($handle) === false) return false;

    return true;
}

try {
    /** Получение параметров NAS */
    !is_null($nas = getNAS()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** Создание файла с конфигурацией RouterOS */
if (!createFile($networks)) {
    if (file_exists(sprintf("../%s", FILE_NAME))) {

        /** Удаление конфигурационного файла RouterOS */
        unlink(sprintf("../%s", FILE_NAME));
    }

    exit("Creation file failed.\n");
}

/** @var array $v Параметры NAS */
foreach ($nas as $v) {

    /** FTP-соединение */
    if (($ftp = ftp_connect($v['nasname']))

        /** SSH-соединение */
        && ($ssh2 = ssh2_connect($v['nasname'], 22)))
    {
        /** Аутентификация */
        if ((ftp_login($ftp, $v['naslogin'], $v['naspass']))

            /** Аутентификация */
            && (ssh2_auth_password($ssh2,
                $v['naslogin'], $v['naspass'])))
        {
            /** Копирование файла */
            ftp_put($ftp, FILE_NAME,
                sprintf('../%s', FILE_NAME), FTP_BINARY);

            /** Выполнение команды */
            ssh2_exec($ssh2, IMPORT_FILE_NAS_COMMAND);
        }

        /** Закрытие FTP-соединения */
        ftp_close($ftp);

        /** Закрытие SSH-соединения */
        ssh2_disconnect($ssh2);
    }
}

if (file_exists(sprintf("../%s", FILE_NAME)))

    /** Удаление файла */
    unlink(sprintf("../%s", FILE_NAME));
