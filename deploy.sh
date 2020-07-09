helpFunction()
{
   echo ""
   echo "Usage: $0 -d <absolute path of the root folder of your osCommerce installation>"
   exit 1 # Exit script
}

removeFile()
{
   folder=$(dirname $1)
   if test -f "$folder"; then

      if [ ! -w "$folder" ]
      then
         echo "Error: Write permission required on $1"
         exit 1 # Exit script
      fi

      if [ -d "$1" ]; 
      then 
         rm -r $1
      fi

      if test -f "$1"; 
      then
         rm $1
      fi

   fi
}


lastOperationCheck()
{
OUT=$?
if [ ! $OUT -eq 0 ];then
   echo "Copy failed!!! Exiting Installation!!!"
   exit 1 # Exit script 
fi
}

while getopts "d:" opt
do
   case "$opt" in
      d ) OSCPath="$OPTARG" ;;
      ? ) helpFunction ;; # Print helpFunction in case the parameter is non-existent
   esac
done

# Print helpFunction in case the parameter is empty
if [ -z "$OSCPath" ]
then
   helpFunction
fi

if [ -d "$OSCPath" ]; then
  echo "Installing Bleumi Pay osCommerce Extension to ${OSCPath}"
else
  echo "Error: osCommerce root folder ${OSCPath} not found."
  exit 1
fi

dir=`pwd`

echo "Begin: Removing any previously deployed Bleumi Pay osCommerce Extension..."
echo "Validating file permissions..."

removeFile $OSCPath/includes/modules/payment/bleumipay
removeFile $OSCPath/includes/modules/payment/bleumipay.php
removeFile $OSCPath/includes/languages/english/modules/payment/bleumipay.php
removeFile $OSCPath/bleumipay_cron.php
removeFile $OSCPath/bleumipay_success.php

echo "End: Removing any previously deployed Bleumi Pay osCommerce Extension..."

echo "Begin: Copying Bleumi Pay osCommerce Extension to ${OSCPath}"


cp -r $dir/includes/modules/payment/* $OSCPath/includes/modules/payment
lastOperationCheck
cp $dir/bleumipay_cron.php $OSCPath
lastOperationCheck
cp $dir/bleumipay_success.php $OSCPath
lastOperationCheck
cp $dir/includes/languages/english/modules/payment/bleumipay.php $OSCPath/includes/languages/english/modules/payment
lastOperationCheck

echo "End: Copying Bleumi Pay osCommerce Extension to ${OSCPath}"

echo "Installation Successful!!!"