echo 'CURRENT PATH:' &&
pwd &&
cd magento2/app/code &&
echo 'PRINT MAGENTO2/APP/CODE CONTENT BEFORE DOWNLOAD MP PLUGIN:' &&
ls -la &&
curl -LO https://github.com/PluginAndPartners/cart-magento2/archive/develop.zip &&
unzip develop.zip
echo 'PRINT MAGENTO2/APP/CODE AFTER BEFORE DOWNLOAD MP PLUGIN:' &&
cd .. &&
cd .. &&
bin/magento setup:upgrade && 
bin/magento cache:clean
