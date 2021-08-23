#!/bin/bash

cd magento2
bin/magento --version
sudo chmod -Rf 777 var/ pub/ generated/ app/etc/env.php
# php -d memory_limit=5G bin/magento indexer:reindex
php -d memory_limit=5G bin/magento
bin/magento setup:upgrade
bin/magento module:enable --all --clear-static-content
php -d memory_limit=5G bin/magento setup:di:compile
bin/magento cache:clean
bin/magento cache:flush
