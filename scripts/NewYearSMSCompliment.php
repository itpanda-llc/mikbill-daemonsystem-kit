<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc
 */

/**
 * Подключение библиотеки СМСПилот
 * @link https://github.com/itpanda-llc/smspilot-messenger-php-sdk
 */
require_once '../../smspilot-messenger-php-sdk/autoload.php';

/**
 * Импорт классов библиотеки СМСПилот
 * @link https://github.com/itpanda-llc/smspilot-messenger-php-sdk
 */
use Panda\SMSPilot\MessengerSDK\Pilot;
use Panda\SMSPilot\MessengerSDK\Singleton;
use Panda\SMSPilot\MessengerSDK\Format;
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

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

/** Наименование оператора */
const COMPANY_NAME = '#COMPANY_NAME';

/** Адрес web-сайта оператора */
const COMPANY_SITE = 'COMPANY_SITE';

/** @var array $samples Тексты сообщений */
$samples = [
    'С праздником! Приятно вступить в Новый ' . ((int) date('Y') + 1) . ' год вместе! ' . COMPANY_NAME . '.',
    'С Новым ' . ((int) date('Y') + 1) . ' годом! В наступающем, при пополнении счета - приятный, денежный бонус! Подробности: ' . COMPANY_SITE,
    'С наступающим! В Новом ' . ((int) date('Y') + 1) . ' году новым клиентам - месяц услуг в подарок! Узнать больше: ' . COMPANY_SITE,
    'С наступающим праздником, Друзья! Для вас действуют выгодные предложения! Подробности: ' . COMPANY_SITE,
    'С Новым годом — лучшим временем для нового и хорошего! В праздничные дни подключение частных домов и коттеджей - Бесплатно! Подробности: ' . COMPANY_SITE,
    'С праздником! В наступающем ' . ((int) date('Y') + 1) . ' году новому клиенту - Подарок. Узнать больше: ' . COMPANY_SITE,
    'С наступающим ' . ((int) date('Y') + 1) . ' годом! Для вас доступно множество бесплатных сервисов и услуг! Узнать больше: ' . COMPANY_SITE,
    'С наступающим ' . ((int) date('Y') + 1) . ' годом! Интересные новогодние предложения уже действуют! Подробности: ' . COMPANY_SITE,
    'С наступающими праздниками! В Новом ' . ((int) date('Y') + 1) . ' году для вас все самое лучшее! Подробности: ' . COMPANY_SITE,
    'С наступающим, Друзья! Все Акции продлены на новый ' . ((int) date('Y') + 1) . ' год! Узнать больше: ' . COMPANY_SITE
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
 * @return array Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->query("
        SELECT
            `clients`.`uid`,
            `clients`.`sms_tel`
        FROM
            (
                SELECT
                    `users`.`uid`,
                    `users`.`sms_tel`
                FROM
                    `users`    
                UNION
                SELECT
                    `usersfreeze`.`uid`,
                    `usersfreeze`.`sms_tel`
                FROM
                    `usersfreeze`
            ) AS
                `clients`
        WHERE
            MONTH(
                NOW()
            ) = 12
                AND
            `clients`.`sms_tel` IS NOT NULL
                AND
            `clients`.`sms_tel` != ''
        GROUP BY
            `clients`.`sms_tel`");

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

/**
 * @param string $uId ID пользователя
 * @param string $phone Номер телефона
 * @param string $text Текст сообщения
 * @param string $errorText Текст ошибки
 */
function logMessage(string $uId,
                    string $phone,
                    string $text,
                    string $errorText): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `sms_logs` (
                `sms_type_id`,
                `uid`,
                `sms_phone`,
                `sms_text`,
                `sms_error_text`
            )
        VALUES (
            0,
            :uId,
            :phone,
            :text,
            :errorText
        )");

    $sth->bindParam(':uId', $uId);
    $sth->bindParam(':phone', $phone);
    $sth->bindParam(':text', $text);
    $sth->bindParam(':errorText', $errorText);

    $sth->execute();
}

try {
    /** Получение параметров клиентов */
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

/** @var Pilot $pilot Экземпляр отправителя СМСПилот */
$pilot = new Pilot(SMS_PILOT_KEY);

/** @var array $v Параметры клиента */
foreach ($clients as $v) {

    /** @var string $message Текст сообщения */
    $message = $samples[array_rand($samples, 1)];

    /** @var Singleton $singleton Сообщение */
    $singleton = new Singleton($message,
        $v['sms_tel'], SMS_PILOT_NAME);

    /** Добавление параметра "Формат ответа" */
    $singleton->addParam(Format::get(Format::JSON));

    try {
        /** @var stdClass $j Ответ СМСПилот */
        $j = json_decode($pilot->request($singleton));
    } catch (ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());

        /** @var string $error Текст ошибки */
        $error = ERROR_TEXT;
    }

    try {
        /** Запись сообщения в БД */
        logMessage($v['uid'], $v['sms_tel'], $message,
            $error ?? $j->error->description ?? '');
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    unset($error);
}
