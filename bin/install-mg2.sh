#!/bin/bash

cd magento2
bin/magento --version
sudo chmod -Rf 777 var/ pub/ generated/ app/etc/env.php
php -d memory_limit=5G bin/magento indexer:reindex
bin/magento setup:upgrade
php -d memory_limit=5G bin/magento setup:di:compile
bin/magento cache:clean
bin/magento cache:flush
bin/magento module:enable --all
sudo chmod -Rf 777 var/ pub/ generated/ app/etc/env.php
