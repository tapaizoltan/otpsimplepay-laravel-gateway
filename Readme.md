# OTP SimplePay integration for Laravel based website

This module helps you to easily embed an OTP SimplePay payment solution into your Laravel based website.

Fejlesztőknek
Dokumentációk, segédletek és minden féle hasznos fejlesztést támogató anyag. \
Az OTP Simplepay hivatalos illesztés az alábbi linken érhető el: [dokumentáció]([https://github.com/khpos/Payment-gateway_HU](https://simplepay.hu/fejlesztoknek/))

## IMPORTANT!!!
This module is not an official OTP module. Use at your own risk!

## INSTALLATION
Edit project composer.json
```php
"require": {
    ...
    "taki47/otpsimplepay": "^1.0.0"
    ...
},
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/taki47/otpsimplepay"
    }
],
```

Run composer update
```sh
composer update
```

## USAGE
Create a custom log channel is you wan't. If not, and OTP_PAYMENT_LOG settings is true, set the OTP_PAYMENT_LOG_CHANNEL to "daily"

Set the OTP_PAYMENT_RETURN_URL to your returning URL, which specified in web.php file.

Add these settings to your .env file
```php
OTP_PAYMENT_CURRENCY="HUF"
OTP_PAYMENT_LANG="HU"
OTP_PAYMENT_HUF_MERCHANT="PUBLICTESTHUF"
OTP_PAYMENT_HUF_SECRET_KEY="FxDa5w314kLlNseq2sKuVwaqZshZT5d6"
OTP_PAYMENT_RETURN_URL="${APP_URL}/payResult"
OTP_PAYMENT_TIMEOUT_IN_SEC="600"
OTP_PAYMENT_LOG=true
OTP_PAYMENT_LOG_CHANNEL="simplePay"
OTP_PAYMENT_SANDBOX=true
```

## That's it! Happy code :-)

## Example code - Start payment
```php
use Taki47\Otpsimplepay\SimplePayStart;

class PublicController extends Controller
{
    public function PayStart()
    {
        $simplePay = new SimplePayStart();

        //TWO STEP AUTH
        $simplePay->addData("twoStep", false);
                
        // ORDER REFERENCE NUMBER
        // uniq oreder reference number in the merchant system
        $simplePay->addData('orderRef', str_replace(array('.', ':', '/'), "", @$_SERVER['SERVER_ADDR']) . @date("U", time()) . rand(1000, 9999));

        // customer's registration mehod
        // 01: guest
        // 02: registered
        // 05: third party
        $simplePay->addData('threeDSReqAuthMethod', '02');

        // EMAIL
        // customer's email
        $simplePay->addData('customerEmail', "taki47@gmail.com");

        // METHODS
        // CARD or WIRE
        $simplePay->addData('methods', array('CARD'));

        //ORDER ITEMS
        $simplePay->addItems(
            array(
                'ref' => "Test product 1",
                'title' => "Test product 1",
                'description' => 'Test product description',
                'amount' => "2",
                'price' => "12000",
                'tax' => '0',
                )
        );

        // SHIPPING COST
        $simplePay->addData('shippingCost', "450");
        
        // INVOICE DATA
        $simplePay->addGroupData('invoice', 'name', "Test Kft.");
        $simplePay->addGroupData('invoice', 'company', "Test Kft.");
        $simplePay->addGroupData('invoice', 'country', "Hungary");
        $simplePay->addGroupData('invoice', 'state', "Budapest");
        $simplePay->addGroupData('invoice', 'city', "Budapest");
        $simplePay->addGroupData('invoice', 'zip', "1111");
        $simplePay->addGroupData('invoice', 'address', "Teszt road 11.");

        // DELIVERY DATA
        $simplePay->addGroupData('delivery', 'name', "Test Kft.");
        $simplePay->addGroupData('delivery', 'company', "Test Kft.");
        $simplePay->addGroupData('delivery', 'country', "Hungary");
        $simplePay->addGroupData('delivery', 'state', "Budapest");
        $simplePay->addGroupData('delivery', 'city', "Budapest");
        $simplePay->addGroupData('delivery', 'zip', "1111");
        $simplePay->addGroupData('delivery', 'address', "Teszt road 11.");
        $simplePay->addGroupData('delivery', 'phone', "+3611111111");

        //create transaction in SimplePay system
        $simplePay->runStart();

        $returnData = $simplePay->getReturnData();

        return redirect($returnData["paymentUrl"]);
    }
}
```


## Example code - Payment result (back)
```php
use Taki47\Otpsimplepay\SimplePayBack;

class PublicController extends Controller
{
    public function PayBack(Request $request)
    {
        $simplePayBack = new SimplePayBack();

        $result = array();
        if (isset($request->r) && isset($request->s)) {
            if ($simplePayBack->isBackSignatureCheck($request->r, $request->s)) {
                $result = $simplePayBack->getRawNotification();
            }
        }

        /**
         * DO SOMETHING WITH $result ARRAY
         */
    }
}
```
