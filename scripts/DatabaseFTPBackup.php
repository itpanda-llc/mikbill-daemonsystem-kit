<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-kit
 */

declare(strict_types=1);

/**
 * API-ключ SMSPILOT.RU
 * @link https://smspilot.ru/apikey.php
 */
const SMS_PILOT_KEY = '***';

/**
 * Имя отправителя SMSPILOT.RU
 * @link https://smspilot.ru/apikey.php
 */
const SMS_PILOT_NAME = '***';

/**
 * Путь к конфигурационному файлу MikBill
 * @link https://wiki.mikbill.pro/billing/config_file
 */
const CONFIG = '/var/www/mikbill/admin/app/etc/config.xml';

/**
 * Временная зона
 * @link https://www.php.net/manual/ru/timezones.php
 */
const TIME_ZONE = 'Asia/Yekaterinburg';

/** Префикс наименования файла */
const FILE_NAME_PREFIX = '_____sqldump';

/** Пароль архива */
const FILE_PASSWORD = '***';

/**
 * Параметры серверов хранения файлов
 * @link https://www.php.net/manual/ru/book.ftp.php
 */
const CLOUDS = [
    [
        'server' => '***',
        'login' => '***',
        'password' => '***',
        'path' => '/__backups/'
    ]
];

/**
 * ID роли администратора
 * @link https://wiki.mikbill.pro/billing/howto/stuff_personal
 */
const ADMIN_ROLE_ID = 1;

/** Текст сообщения */
const MESSAGE = 'Резервное копирование БД не выполнено';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once '../../../autoload.php';

use Panda\SmsPilot\MessengerSdk;

/**
 * @return array|null Номера телефонов
 */
function getAdmins(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `stuff_personal`.`phone_mob`
        FROM
            `stuff_personal`
        WHERE
            `stuff_personal`.`aclid` = :adminRoleId
                AND
            `stuff_personal`.`phone_mob` IS NOT NULL
                AND
            `stuff_personal`.`phone_mob` != ''
        GROUP BY
            `stuff_personal`.`phone_mob`");

    $sth->bindValue(':adminRoleId',
        ADMIN_ROLE_ID,
        PDO::PARAM_INT);

    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_COLUMN);

    return ($result !== []) ? $result : null;
}

exec('yum install -y p7zip p7zip-plugins',
    $output,
    $status);

if ($status !== 0) goto a;

$output = null;
$status = null;

$fileName = sprintf("%s-%s--%s",
    FILE_NAME_PREFIX,
    (string) getConfig()->parameters->mysql->dbname,
    date('Y-m-d-H-i'));

exec(sprintf("mysqldump -h %s --single-transaction --quick"
    . " -u %s -p%s %s | 7z a -sdel -p%s -si ../%s.sql.7z",
    (string) getConfig()->parameters->mysql->host,
    (string) getConfig()->parameters->mysql->username,
    (string) getConfig()->parameters->mysql->password,
    (string) getConfig()->parameters->mysql->dbname,
    FILE_PASSWORD,
    $fileName),
    $output,
    $status);

if ($status !== 0) goto a;

foreach (CLOUDS as $v) {
    if (($ftp = ftp_connect($v['server']))
        && (ftp_login($ftp, $v['login'], $v['password'])))
    {
        @ftp_mkdir($ftp, $v['path']);

        if (ftp_put($ftp,
            sprintf("%s%s.sql.7z", $v['path'], $fileName),
            sprintf("../%s.sql.7z", $fileName)))
        {
            $ftpFiles = ftp_mlsd($ftp, $v['path']);

            foreach ($ftpFiles as $ftpFile) {
                if (($ftpFile['type'] === 'file')
                    && (strpos($ftpFile['name'], FILE_NAME_PREFIX) !== false))
                {
                    $modifyTime = ftp_mdtm($ftp,
                        sprintf("%s%s", $v['path'], $ftpFile['name']));

                    if ($modifyTime < (time() - 604800)) {
                        ftp_delete($ftp,
                            sprintf("%s%s", $v['path'], $ftpFile['name']));
                    }
                }
            }

            $ok = true;
        }
    }

    ftp_close($ftp);
}

a:

if (file_exists(sprintf("../%s.sql", $fileName)))
    unlink(sprintf("../%s.sql", $fileName));

if (file_exists(sprintf("../%s.sql.7z", $fileName)))
    unlink(sprintf("../%s.sql.7z", $fileName));

if (!isset($ok)) {
    try {
        !is_null($admins = getAdmins()) || exit;
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    $singleton = new MessengerSdk\Singleton(MESSAGE,
        implode(',', $admins),
        SMS_PILOT_NAME);

    try {
        $dateTime = new DateTime("now",
            new DateTimeZone(TIME_ZONE));
    } catch (Exception $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    switch (true) {
        case (((int) $dateTime->format('H')) < 10):
            $singleton->setSendDatetime($dateTime
                ->format('Y-m-d 05:00:00'));

            break;
        case (((int) $dateTime->format('H')) === 23):
            try {
                $dateTime->add(new DateInterval('P1D'));
            } catch (Exception $e) {
                exit(sprintf("%s\n", $e->getMessage()));
            }

            $singleton->setSendDatetime($dateTime
                ->format('Y-m-d 05:00:00'));
    }

    try {
        (new MessengerSdk\Pilot(SMS_PILOT_KEY))
            ->request($singleton);
    } catch (MessengerSdk\Exception\ClientException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }
}
