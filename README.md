# MikBill-DaemonSystem-PHP-Kit

Набор PHP-скриптов в дополнение функционалу биллинговой системы [АСР "MikBill"](https://mikbill.pro), периодически запускаемых в операционной системе

[![GitHub license](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

## Ссылки

* [Разработка](https://github.com/itpanda-llc)
* [О проекте (АСР "MikBill")](https://mikbill.pro)
* [Документация (АСР "MikBill")](https://wiki.mikbill.pro)
* [Сообщество (АСР "MikBill")](https://mikbill.userecho.com)

## Требования

* CentOS >= 7
* PHP >= 7.2
* PDO
* ssh2
* FTP
* [smspilot-messenger-php-sdk](https://github.com/itpanda-llc/smspilot-messenger-php-sdk)
* [smsc-sender-php-sdk](https://github.com/itpanda-llc/smsc-sender-php-sdk)
* [chelinvest-acquirer-php-sdk](https://github.com/itpanda-llc/chelinvest-acquirer-php-sdk)
* [komtet-kassa-php-sdk](https://github.com/Komtet/komtet-kassa-php-sdk)


* !! Для продолжения функционала репозитория и расширения возможностей, при пользовании системой [АСР "MikBill"](https://mikbill.pro), дополнительно, рекомендовано применять набор [mikbill-eventsystem-php-kit](https://github.com/itpanda-llc/mikbill-eventsystem-php-kit), осуществляющий другие (остальные) полезные действия, используя "Систему событий" биллинга.

## Рекомендуемая установка и подготовка

Создание и переход в директорию, например "mkdir /var/mikbill/__ext/ && cd /var/mikbill/__ext/".

Клонирование необходимых репозиториев:

* git clone https://github.com/itpanda-llc/mikbill-daemonsystem-php-kit 
* git clone https://github.com/itpanda-llc/mikbill-eventsystem-php-kit
* git clone https://github.com/itpanda-llc/smspilot-messenger-php-sdk
* git clone https://github.com/itpanda-llc/smsc-sender-php-sdk
* git clone https://github.com/itpanda-llc/chelinvest-acquirer-php-sdk
* git clone https://github.com/Komtet/komtet-kassa-php-sdk

Конфигурация скриптов по пути "/var/mikbill/__ext/mikbill-daemonsystem-php-kit/scripts/" - корректирование путей, констант и значений.

##### ..Для запуска скриптов необходимо использовать планировщик (cron). Каждый файл самостоятелен и независим от соседних. Для понимания логики действия и условий срабатывания программ в подробностях, необходимо изучение SQL-запросов в скриптах..
