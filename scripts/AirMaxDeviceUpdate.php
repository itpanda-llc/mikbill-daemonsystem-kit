<?php

/**
 * Файл из репозитория MikBill-DaemonSystem-Kit
 * @link https://github.com/itpanda-llc/mikbill-daemonsystem-kit
 */

declare(strict_types=1);

/**
 * URL-адрес web-запроса
 * @link https://github.com/itpanda-llc/mikbill-deviceview-php-api
 */
const API_URL = 'https://***/api/deviceview/';

/**
 * Параметр "Секретный ключ"
 * @link https://github.com/itpanda-llc/mikbill-deviceview-php-api
 */
const API_SECRET_PARAM = ['secret' => '***'];

/**
 * Параметр "Причина запроса"
 * @link https://github.com/itpanda-llc/mikbill-deviceview-php-api
 */
const API_REASON_PARAM  = ['reason' => 'devices'];

/**
 * Параметр "Тип устройств"
 * @link https://github.com/itpanda-llc/mikbill-deviceview-php-api
 */
const API_TYPE_PARAM  = ['type' => '1'];

/** Хост БД */
const DB_HOST = '***';

/** Имя БД */
const DB_NAME = 'radius';

/** Имя пользователя БД */
const DB_USER = '***';

/** Пароль пользователя БД */
const DB_PASSWORD = '***';

/** Имя таблицы БД */
const TABLE_NAME = 'radcheck';

/**
 * @param string $param Параметры web-запроса
 * @return string Результат web-запроса
 */
function getContent(string $param): string
{
    $ch = curl_init(API_URL);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $param);

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

/**
 * @return PDO Обработчик запросов к БД
 */
function getConnect(): PDO
{
    static $dbh;

    if (!isset($dbh)) {
        $dsn = sprintf("mysql:host=%s;dbname=%s;charset=utf8",
            DB_HOST,
            DB_NAME);

        try {
            $dbh = new PDO($dsn,
                DB_USER,
                DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            exit(sprintf("%s\n", $e->getMessage()));
        }
    }

    return $dbh;
}

function prepareTable(): void
{
    getConnect()->exec(sprintf("TRUNCATE TABLE `%s`.`%s`",
        DB_NAME,
        TABLE_NAME));
}

/**
 * @param string $userName Устройство
 */
function addDevice(string $userName): void
{
    static $sth;

    $sth = $sth ?? getConnect()->prepare("
        INSERT INTO
            `" . TABLE_NAME . "` (
                `username`,
                `attribute`,
                `op`,
                `value`
            )
        VALUES (
            :userName,
            'Auth-Type',
            ':=',
            'Accept'
        )");
    
    $sth->bindParam(':userName', $userName);
    
    $sth->execute();
}

$param = http_build_query(array_merge(API_SECRET_PARAM,
    API_REASON_PARAM,
    API_TYPE_PARAM));

try {
    ($j = json_decode(getContent($param)))->code === 0 || exit;
    !is_null($j->result) && prepareTable();

    getConnect()->beginTransaction() || exit(
        "Begin a transaction failed\n");
} catch (TypeError | PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

try {
    foreach ($j->result as $v) addDevice((string) $v[0]);

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
