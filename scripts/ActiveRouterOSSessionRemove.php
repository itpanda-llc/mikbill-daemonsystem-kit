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

try {
    !is_null($nas = getNas()) || exit;
} catch (PDOException $e) {
    exit(sprintf("%s\n", $e->getMessage()));
}

foreach ($nas as $v)
    try {
        $c = new RouterOS\Client(['host' => $v['nasname'],
            'user' => $v['naslogin'],
            'pass' => $v['naspass']]);

        $response = $c->query(new RouterOS\Query('/ppp/active/print'))
            ->readAsIterator();

        if (!empty($response->current()['.id'])) {
            for ($response->rewind(); $response->valid(); $response->next())
                $c->query((new RouterOS\Query('/ppp/active/remove'))
                    ->equal('.id', $response->current()['.id']));

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
