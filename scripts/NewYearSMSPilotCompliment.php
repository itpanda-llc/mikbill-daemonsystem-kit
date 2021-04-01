<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-PHP-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-php-kit
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

/** Адрес web-сайта оператора */
const COMPANY_SITE = '***';

/** Подпись, добавляемая к сообщению */
const COMPLIMENT = '***';

/** Текст ошибки */
const ERROR_TEXT = 'Не отправлено';

/**
 * Тексты сообщений
 * @example $samples[array_rand($samples, 1)];
 */
$samples = [
    'С праздником! Приятно вступить в Новый ' . (string) ((int) date('Y') + 1) . ' год вместе! ' . COMPLIMENT,
    'С Новым ' . (string) ((int) date('Y') + 1) . ' годом! В наступающем, при пополнении счета - приятный, денежный бонус! Подробности: ' . COMPANY_SITE,
    'С наступающим! В Новом ' . (string) ((int) date('Y') + 1) . ' году новым клиентам - месяц услуг в подарок! Узнать больше: ' . COMPANY_SITE,
    'С наступающим праздником, Друзья! Для вас действуют выгодные предложения! Подробности: ' . COMPANY_SITE,
    'С Новым годом — лучшим временем для нового и хорошего! В праздничные дни подключение частных домов и коттеджей - Бесплатно! Подробности: ' . COMPANY_SITE,
    'С праздником! В наступающем ' . (string) ((int) date('Y') + 1) . ' году новому клиенту - Подарок. Узнать больше: ' . COMPANY_SITE,
    'С наступающим ' . (string) ((int) date('Y') + 1) . ' годом! Для вас доступно множество бесплатных сервисов и услуг! Узнать больше: ' . COMPANY_SITE,
    'С наступающим ' . (string) ((int) date('Y') + 1) . ' годом! Интересные новогодние предложения уже действуют! Подробности: ' . COMPANY_SITE,
    'С наступающими праздниками! В Новом ' . (string) ((int) date('Y') + 1) . ' году для вас все самое лучшее! Подробности: ' . COMPANY_SITE,
    'С наступающим, Друзья! Все Акции продлены на новый ' . (string) ((int) date('Y') + 1) . ' год! Узнать больше: ' . COMPANY_SITE
];

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/logMessage.php';
require_once '../../../autoload.php';

use Panda\SmsPilot\MessengerSdk;

/**
 * @return array Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->query("
        SELECT
            `users`.`uid`,
            `users`.`sms_tel`
        FROM
            `users`
        WHERE
            (
                `users`.`state` = 1
                    OR
                `users`.`state` = 2
            )
                AND
            MONTH(
                NOW()
            ) = 12
                AND
            `users`.`sms_tel` IS NOT NULL
                AND
            `users`.`sms_tel` != ''
        GROUP BY
            `users`.`sms_tel`");

    $result = $sth->fetchAll(PDO::FETCH_ASSOC);

    return ($result !== []) ? $result : null;
}

try {
    !is_null($clients = getClients()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

$pilot = new MessengerSdk\Pilot(SMS_PILOT_KEY);

$singleton = (new MessengerSdk\Singleton)
    ->setFrom(SMS_PILOT_NAME)
    ->setFormat(MessengerSdk\Format::JSON);

foreach ($clients as $v) {
    $message = $samples[array_rand($samples, 1)];

    $singleton->setSend($message)
        ->setTo($v['sms_tel']);

    try {
        $j = json_decode($pilot->request($singleton));
    } catch (MessengerSdk\Exception\ClientException $e) {
        echo sprintf("%s\n", $e->getMessage());

        $error = ERROR_TEXT;
    }

    try {
        logMessage($v['uid'],
            $v['sms_tel'],
            $message,
            (string) ($error ?? $j->error->description ?? ''));
    } catch (PDOException $e) {
        exit(sprintf("%s\n", $e->getMessage()));
    }

    $error = null;
}
