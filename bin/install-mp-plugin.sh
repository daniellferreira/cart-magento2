echo 'CURRENT PATH:'
pwd
curl -LO https://github.com/PluginAndPartners/cart-magento2/archive/develop.zip &&
unzip develop.zip &&
ls -la
mv cart-magento2-develop/ magento2/app/code/ &&
bin/magento setup:upgrade && 
bin/magento cache:clean
