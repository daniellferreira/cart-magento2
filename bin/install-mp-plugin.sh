rm -rf ~/workspace/app/code/MercadoPago;
cd &&
curl -LO https://github.com/PluginAndPartners/cart-magento2/archive/develop.zip &&
unzip develop.zip &&
mv cart-magento2-develop ~/workspace/app/code/ &&
cd ~/workspace &&
bin/magento setup:upgrade && 
bin/magento cache:clean
