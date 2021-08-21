cd magento2/app/code &&
curl -LO https://github.com/PluginAndPartners/cart-magento2/archive/develop.zip &&
unzip develop.zip &&
ls -la
cd ~/magento2 &&
bin/magento setup:upgrade && 
bin/magento cache:clean
