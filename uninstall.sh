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
  echo "Removing Bleumi Pay osCommerce Extension from ${OSCPath}"
else
  echo "Error: osCommerce root folder ${OSCPath} not found."
  exit 1
fi

dir=`pwd`

echo "Begin: Removing Bleumi Pay osCommerce Extension..."

removeFile $OSCPath/includes/modules/payment/bleumipay
removeFile $OSCPath/includes/modules/payment/bleumipay.php
removeFile $OSCPath/includes/languages/english/modules/payment/bleumipay.php
removeFile $OSCPath/bleumipay_cron.php
removeFile $OSCPath/bleumipay_success.php

echo "End: Removing Bleumi Pay osCommerce Extension..."
