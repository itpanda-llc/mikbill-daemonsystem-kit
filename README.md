# MikBill-DaemonSystem-Kit

Набор периодических скриптов для биллинговой системы ["MikBill"](https://mikbill.pro)

[![Packagist Downloads](https://img.shields.io/packagist/dt/itpanda-llc/mikbill-daemonsystem-kit)](https://packagist.org/packages/itpanda-llc/mikbill-daemonsystem-kit/stats)
![Packagist License](https://img.shields.io/packagist/l/itpanda-llc/mikbill-daemonsystem-kit)
![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/itpanda-llc/mikbill-daemonsystem-kit)

## Ссылки

* [Разработка](https://github.com/itpanda-llc)
* [О проекте (MikBill)](https://mikbill.pro)
* [Документация (MikBill)](https://wiki.mikbill.pro)

## Возможности

см. [файлы](scripts)

## Требования

* PHP >= 7.2
* cURL
* FTP
* JSON
* libxml
* PDO
* SimpleXML
* [EvilFreelancer/routeros-api-php](https://github.com/EvilFreelancer/routeros-api-php)
* [Komtet/komtet-kassa-php-sdk](https://github.com/Komtet/komtet-kassa-php-sdk)
* [markus-perl/gender-api-client](https://github.com/markus-perl/gender-api-client)
* [itpanda-llc/chelinvest-acquirer-sdk](https://github.com/itpanda-llc/chelinvest-acquirer-sdk)
* [itpanda-llc/mikbill-deviceview-api](https://github.com/itpanda-llc/mikbill-deviceview-api)
* [itpanda-llc/sberbank-acquirer-sdk](https://github.com/itpanda-llc/sberbank-acquirer-sdk)
* [itpanda-llc/smscenter-messenger-sdk](https://github.com/itpanda-llc/smscenter-messenger-sdk)
* [itpanda-llc/smspilot-messenger-sdk](https://github.com/itpanda-llc/smspilot-messenger-sdk)

## Установка

```shell script
composer require itpanda-llc/mikbill-daemonsystem-kit
```

## Конфигурация

Указание

* Путей к [конфигурационному файлу](https://wiki.mikbill.pro/billing/config_file), интерфейсам и значений констант в [файлах-скриптах](scripts)
* В файлах "cron" заданий с запуском [скриптов](scripts) (см. далее)

/etc/crontab или [/etc/cron.d/*](examples/cron.d/__daemonsystem)

```text
34 10 * * * root cd /var/mikbill/__ext/vendor/itpanda-llc/mikbill-daemonsystem-kit/scripts/ && /usr/bin/php ./BirthDaySMSPilotCompliment.php > /dev/null 2>&1
15 11 31 12 * root cd /var/mikbill/__ext/vendor/itpanda-llc/mikbill-daemonsystem-kit/scripts/ && /usr/bin/php ./NewYearSMSPilotCompliment.php > /dev/null 2>&1
```
