<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Тип NAS RouterOS */
const NAS_TYPE = 'mikrotik';

/** Префикс наименования файла */
const FILE_NAME_PREFIX = '_____config';

/** Пароль архива */
const FILE_PASSWORD = '************';

/** Параметры серверов хранения файлов */
const CLOUDS = [
    [
        'server' => 'ftp*****.hostfx.ru',
        'login' => 'user*****',
        'password' => '************',
        'path' => '/_____backups/'
    ]
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
 * @param string $nasName Наименование NAS
 * @return string Наименование файла
 */
function getName(string $nasName): string
{
    return sprintf("%s-%s--%s",
        FILE_NAME_PREFIX,
        $nasName,
        date('Y-m-d-H-i-s'));
}

/**
 * @param string $name Наименование файла
 * @return string Команда
 */
function getCommand(string $name): string
{
    return sprintf("/system backup save name=\"%s\"",
        $name);
}

/** Получение системных зависимостей */
exec('yum install -y p7zip p7zip-plugins',
    $output,
    $status);

if ($status !== 0) print_r($output) && exit;

try {
    /** Получение параметров NAS */
    !is_null($nas = getNAS()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var array $backupOptions Параметры резервных копий */
$backupOptions = [];

/** @var array $v Параметры NAS */
foreach ($nas as $k => $v) {

    /** SSH-соединение */
    if (($ssh2 = ssh2_connect($v['nasname']))

        /** Аутентификация */
        && (ssh2_auth_password($ssh2,
            $v['naslogin'], $v['naspass'])))
    {
        /** @var string $fileName Наименование файла резервной копии */
        $fileName = getName($v['nasname']);

        /** Выполнение команды */
        if (ssh2_exec($ssh2, getCommand($fileName))) {

            /** Запись IP-ареса */
            $backupOptions[$k]['nasname'] = $v['nasname'];

            /** Запись логина */
            $backupOptions[$k]['naslogin'] = $v['naslogin'];

            /** Запись пароля */
            $backupOptions[$k]['naspass'] = $v['naspass'];

            /** Запись наименования файла резервной копии */
            $backupOptions[$k]['filename'] = $fileName;
        }
    }

    /** Закрытие SSH-соединения */
    ssh2_disconnect($ssh2);
}

sleep(10);

/** @var array $fileList Список файлов резервных копий */
$fileList = [];

/** @var array $v Параметры NAS */
foreach ($backupOptions as $k => $v) {

    /** FTP-соединение */
    if (($ftp = ftp_connect($v['nasname']))

        /** Аутентификация */
        && (ftp_login($ftp, $v['naslogin'], $v['naspass'])))
    {
        /** Копирование файла */
        if (ftp_get($ftp,
            sprintf("../%s.backup", $v['filename']),
            sprintf("%s.backup", $v['filename']),
            FTP_BINARY))
        {
            /** Запись наименования файла */
            $fileList[$k] = $v['filename'];

            /** Удаление файла */
            ftp_delete($ftp,
                sprintf("%s%s.backup", '/', $v['filename']));
        }
    }

    /** Закрытие FTP-соединения */
    ftp_close($ftp);
}

/** @var string $v Наименование файла */
foreach ($fileList as $file) {

    /** Архивация файла */
    exec(
        sprintf("7z a -sdel -p%s ../%s.backup.7z ../%s.backup",
            FILE_PASSWORD,
            $file,
            $file),
        $output,
        $status);

    if ($status !== 0) print_r($output) && exit;

    unset($output);
    unset($status);
}

/** @var array $v Параметры сервера хранения файлов */
foreach (CLOUDS as $v) {

    /** Соединение */
    if (($ftp = ftp_connect($v['server']))

        /** Аутентификация */
        && (ftp_login($ftp, $v['login'], $v['password'])))
    {
        /** Создание директории */
        @ftp_mkdir($ftp, $v['path']);

        /** @var string $file Наименование файла */
        foreach ($fileList as $file) {

            /** Копирование файла */
            if (ftp_put($ftp,
                sprintf("%s%s.backup.7z", $v['path'], $file),
                sprintf("../%s.backup.7z", $file),
                FTP_BINARY))
            {
                /** @var array $ftpFiles Список файлов на сервере */
                $ftpFiles = $ftpFiles ?? ftp_mlsd($ftp, $v['path']);
            }
        }

        if (isset($ftpFiles)) {

            /** @var array $ftpFile Параметры файла */
            foreach ($ftpFiles as $ftpFile) {
                if (($ftpFile['type'] === 'file')
                    && (strpos($ftpFile['name'], FILE_NAME_PREFIX) !== false))
                {
                    /** @var int $modifyTime Время модификации файла */
                    $modifyTime = ftp_mdtm($ftp,
                        sprintf("%s%s", $v['path'], $ftpFile['name']));

                    if ($modifyTime < time() - 604800)

                        /** Удаление файла */
                        ftp_delete($ftp,
                            sprintf("%s%s", $v['path'], $ftpFile['name']));
                }
            }
        }

        unset($ftpFiles);
    }

    /** Закрытие FTP-соединения */
    ftp_close($ftp);
}

if (!empty($fileList)) {

    /** @var string $fileName Наименование файла */
    foreach ($fileList as $file) {
        if (file_exists(sprintf("../%s.backup", $file)))

            /** Удаление файла */
            unlink(sprintf("../%s.backup", $file));

        if (file_exists(sprintf("../%s.backup.7z", $file)))

            /** Удаление файла */
            unlink(sprintf("../%s.backup.7z", $file));
    }
}
