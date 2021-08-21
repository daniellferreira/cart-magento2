cd magento2/app/code &&
echo 'CURRENT PATH:'
pwd
curl -LO https://github.com/PluginAndPartners/cart-magento2/archive/develop.zip &&
unzip develop.zip &&
ls -la
cd .. &&
cd .. &&
echo 'CURRENT PATH:'
pwd
bin/magento setup:upgrade && 
bin/magento cache:clean
