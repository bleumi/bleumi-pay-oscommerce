---------------OS Commerce ----------------

git clone https://github.com/bharaniv1/bp-oscommerce.git
cd bp-oscommerce/
sudo ~/composer.phar install --ignore-platform-reqs -vvv

---- Copy the new Plugin to Temp DEV Path ----

cd /home/bleumi/Documents/code/plugins/oscommerce/bp-oscommerce

sudo ~/composer.phar install --ignore-platform-reqs -vvv

cd ..

zip -r bp-osc-jun24-v1.zip ./bp-oscommerce

scp -r /home/bleumi/Documents/code/plugins/oscommerce/bp-osc-jun24-v1.zip dh_6xniwc@pcube.network:/home/dh_6xniwc/osc.pcube.network/plugin

---- From Remote Server Commands ----


ssh dh_6xniwc@pcube.network

cd /home/dh_6xniwc/osc.pcube.network/plugin

chmod -R 777 bp-oscommerce

rm -r bp-oscommerce

unzip bp-osc-jun24-v1.zip

export OSPATH=/home/dh_6xniwc/osc.pcube.network/oscommerce/catalog
export OSDEVPATH=/home/dh_6xniwc/osc.pcube.network/plugin/bp-oscommerce

rm -r $OSPATH/includes/modules/payment/bleumipay
rm $OSPATH/includes/modules/payment/bleumipay.php
rm $OSPATH/includes/languages/english/modules/payment/bleumipay.php
rm $OSPATH/bleumipay_cron.php
rm $OSPATH/bleumipay_success.php

cp -r $OSDEVPATH/includes/modules/payment/* $OSPATH/includes/modules/payment
cp $OSDEVPATH/bleumipay_cron.php $OSPATH
cp $OSDEVPATH/bleumipay_success.php $OSPATH
cp $OSDEVPATH/includes/languages/english/modules/payment/bleumipay.php $OSPATH/includes/languages/english/modules/payment

 
