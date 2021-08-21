echo 'CURRENT PATH:' &&
pwd &&
ls -la &&
echo 'START DOWNLOAD' &&
curl -LO https://github.com/PluginAndPartners/cart-magento2/archive/develop.zip &&
echo 'FINISH DOWNLOAD' &&
unzip develop.zip &&
echo 'CURRENT PATH:' &&
pwd &&
ls -la &&
#mv cart-magento2-develop/ magento2/app/code/ &&
bin/magento setup:upgrade && 
bin/magento cache:clean
