<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/**
 * Подключение библиотеки СМСПилот
 * @link https://github.com/itpanda-llc/smspilot-messenger-php-sdk
 */
include_once '../../smspilot-messenger-php-sdk/autoload.php';

/**
 * Импорт классов библиотеки СМСПилот
 * @link https://github.com/itpanda-llc/smspilot-messenger-php-sdk
 */
use Panda\SMSPilot\MessengerSDK\Pilot;
use Panda\SMSPilot\MessengerSDK\Massive;
use Panda\SMSPilot\MessengerSDK\Exception\ClientException;

/**
 * API-ключ СМСПилот
 * @link https://smspilot.ru/apikey.php
 */
const SMS_PILOT_KEY = 'SMS_PILOT_KEY';

/**
 * Имя отправителя СМСПилот
 * @link https://smspilot.ru/my-sender.php
 */
const SMS_PILOT_NAME = 'SMS_PILOT_NAME';

/** Путь к конфигурационному файлу АСР MikBill */
const CONFIG = '../../../../www/mikbill/admin/app/etc/config.xml';

/** Список путей для резервного копирования */
const PATHS = [
    '/etc/cron.d/',
    '/etc/firewalld/zones/',
    '/etc/nginx/conf.d/',
    '/root/.acme.sh/',
    '/var/mikbill/__ext/',
    '/var/www/mikbill/admin/favicon.ico',
    '/var/www/mikbill/admin/backend/',
    '/var/www/mikbill/admin/MBPlatform/resources/images/MBPlatform-logo.png',
    '/var/www/mikbill/admin/process/',
    '/var/www/mikbill/admin/sys/scripts/mikbill_payment_event.sh',
    '/var/www/mikbill/admin/sys/scripts/mikbill_tarif_change_event.sh',
    '/var/www/mikbill/stat/favicon.ico',
    '/var/www/mikbill/stat/captive/',
    '/var/www/mikbill/stat/sys/scripts/mikbill_payment_event.sh',
    '/var/www/mikbill/stat/sys/scripts/mikbill_tarif_change_event.sh'
];

/** Префикс наименования файла */
const FILE_NAME_PREFIX = '_____files';

/** Наименование файла */
const FILE_NAME = 'mikbill';

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

/** ID роли администратора */
const ADMIN_ROLE_ID = 1;

/** Текст сообщения */
const MESSAGE = 'Резервное копирование файлов не выполнено!';

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

/** Получение системных зависимостей */
exec('yum install -y p7zip p7zip-plugins',
    $output,
    $status);

if ($status !== 0) goto a;

unset($output);
unset($status);

/** @var string $fileName Наименование файла */
$fileName = sprintf("%s-%s--%s",
    FILE_NAME_PREFIX,
    FILE_NAME,
    date('Y-m-d-H-i-s'));

/** @var string $v Путь для резервного копирования */
foreach (PATHS as $v)

    /** Добавление в файл */
    exec(
        sprintf("tar -rf ../%s.tar %s",
            $fileName,
            $v),
        $output,
        $status);

if ($status !== 0) goto a;

unset($output);
unset($status);

/** Архивация файла */
exec(
    sprintf("7z a -sdel -p%s ../%s.tar.7z ../%s.tar",
        FILE_PASSWORD,
        $fileName,
        $fileName),
    $output,
    $status);

if ($status !== 0) goto a;

/** @var array $v Параметры сервера хранения файлов */
foreach (CLOUDS as $v) {

    /** FTP-соединение */
    if (($ftp = ftp_connect($v['server']))

        /** Аутентификация */
        && (ftp_login($ftp, $v['login'], $v['password'])))
    {
        /** Создание директории */
        @ftp_mkdir($ftp, $v['path']);

        /** Копирование файла */
        if (ftp_put($ftp,
            sprintf("%s%s.tar.7z", $v['path'], $fileName),
            sprintf("../%s.tar.7z", $fileName),
            FTP_BINARY))
        {
            /** @var array $ftpFiles Список файлов на сервере */
            $ftpFiles = ftp_mlsd($ftp, $v['path']);

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

            /** @var bool $ok Признак успешного копирования файла */
            $ok = true;
        }
    }

    /** Закрытие FTP-соединения */
    ftp_close($ftp);
}

a:

if (file_exists(sprintf("../%s.tar", $fileName)))

    /** Удаление файла */
    unlink(sprintf("../%s.tar", $fileName));

if (file_exists(sprintf("../%s.tar.7z", $fileName)))

    /** Удаление файла */
    unlink(sprintf("../%s.tar.7z", $fileName));

if (!isset($ok)) {
    try {
        /** Получение номеров телефонов */
        !is_null($admins = getAdmins()) || exit;
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    /** @var Massive $massive Сообщение */
    $massive = new Massive(MESSAGE, SMS_PILOT_NAME);

    try {
        /** @var DateTime $dateTime Настоящее время */
        $dateTime = new DateTime("now",
            new DateTimeZone('Asia/Yekaterinburg'));
    } catch (Exception $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    if (((int) $dateTime->format('H')) < 10)

        /** Установка времени задержки отправки сообщения */
        $massive->setTime($dateTime->format('Y-m-d 05:00:00'));

    if (((int) $dateTime->format('H')) === 23) {
        try {
            /** Формирование даты отправки */
            $dateTime->add(new DateInterval('P1D'));
        } catch (Exception $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }

        /** Установка времени задержки отправки сообщения */
        $massive->setTime($dateTime->format('Y-m-d 05:00:00'));
    }

    /** @var string $v Номер телефона */
    foreach ($admins as $v)

        /** Добавление номера телефона */
        $massive->addRecipient($v);

    /** @var Pilot $pilot Экземпляр отправителя СМСПилот */
    $pilot = new Pilot(SMS_PILOT_KEY);

    try {
        /** Отправка сообщения */
        $pilot->request($massive);
    } catch (ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());
    }
}
