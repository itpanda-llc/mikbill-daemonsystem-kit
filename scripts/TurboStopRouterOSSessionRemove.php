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
 * Тип NAS RouterOS
 * @link https://wiki.mikbill.pro/billing/nas_access_server/mikbillnas
 */
const NAS_TYPE = 'mikrotik';

require_once 'lib/func/getConfig.php';
require_once 'lib/func/getConnect.php';
require_once 'lib/func/getNas.php';
require_once '../../../autoload.php';

/**
 * @return array|null Параметры клиентов
 */
function getClients(): ?array
{
    $sth = getConnect()->prepare("
        SELECT
            `users`.`user`
        FROM
            `users`
        LEFT JOIN
            `bugh_uslugi_stat`
                ON
                    `bugh_uslugi_stat`.`uid` = `users`.`uid`
                        AND
                    `bugh_uslugi_stat`.`usluga` = 3
                        AND
                    `bugh_uslugi_stat`.`active` = 0
                        AND
                    `bugh_uslugi_stat`.`date_stop` >= DATE_SUB(
                        NOW(),
                        INTERVAL :interval MINUTE
                    )
        WHERE
            `users`.`state` = 1
                AND
            `bugh_uslugi_stat`.`uid` IS NOT NULL
        GROUP BY
            `bugh_uslugi_stat`.`uid`");

    $sth->bindParam(':interval', $_SERVER['argv'][1]);

    $sth->execute();

    $result = $sth->fetchAll(PDO::FETCH_COLUMN);

    return ($result !== []) ? $result : null;
}

try {
    !is_null($clients = getClients()) || exit;
    !is_null($nas = getNas()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

foreach ($nas as $v)
    try {
        $c = new RouterOS\Client(['host' => $v['nasname'],
            'user' => $v['naslogin'],
            'pass' => $v['naspass']]);

        $active = [];

        foreach ($clients as $client)
            $active[] = $c->query((new RouterOS\Query('/ppp/active/print'))
                ->where('name', $client))
                ->read();

        if ($active !== []) {
            $active = array_filter($active,
                function ($a) { return (!empty($a[0]['.id'])); });

            foreach ($active as $item)
                $c->query((new RouterOS\Query('/ppp/active/remove'))
                    ->equal('.id', $item[0]['.id']));

            $c->read();
        }
    } catch (
        RouterOS\Exceptions\BadCredentialsException
        | RouterOS\Exceptions\ConnectException
        | RouterOS\Exceptions\ClientException
        | RouterOS\Exceptions\ConfigException
        | RouterOS\Exceptions\QueryException
        | Exception $e
    ) {
        echo sprintf("%s\n", $e->getMessage());
    }
