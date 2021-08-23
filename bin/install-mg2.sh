#!/bin/bash

echo "Getting Magento 2.3.6..."
curl -LO https://github.com/magento/magento2/archive/refs/tags/2.3.6.zip
unzip 2.3.6.zip
mv magento2-2.3.6 magento2

cd magento2

echo "Installing..."
composer install

bin/magento --version
sudo chmod -Rf 777 var/ pub/ generated/ app/etc/env.php
# php -d memory_limit=5G bin/magento indexer:reindex
php -d memory_limit=5G bin/magento
bin/magento setup:upgrade
bin/magento module:enable --all --clear-static-content
php -d memory_limit=5G bin/magento setup:di:compile
bin/magento cache:clean
bin/magento cache:flush
