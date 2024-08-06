emerchantpay Gateway Module for OpenCart
========================================
[![Software License](https://img.shields.io/badge/license-GPL-green.svg?style=flat)](http://opensource.org/licenses/gpl-2.0.php)

This is a Payment Module for OpenCart, that gives you the ability to process payments through emerchantpay's Payment Gateway - Genesis.

Requirements
------------

* OpenCart 4.0.2.X (due to architectural changes, this module is __incompatible__ with older OpenCart versions)
* [GenesisPHP v2.0.2](https://github.com/GenesisGateway/genesis_php/tree/2.0.2) - (Integrated in Module)
* PCI-certified server in order to use ```emerchantpay Direct```

GenesisPHP Requirements
------------

* PHP version 5.5.9 or newer
* PHP Extensions:
    * [BCMath](https://php.net/bcmath)
    * [CURL](https://php.net/curl) (required, only if you use the curl network interface)
    * [Filter](https://php.net/filter)
    * [Hash](https://php.net/hash)
    * [XMLReader](https://php.net/xmlreader)
    * [XMLWriter](https://php.net/xmlwriter)

Installation via Extension Installer
------------
1.	Download the __emerchantpay Payment Gateway__, extract the contents of the folder
2.	Create a compressed ```zip``` file of the folder ```upload``` with name ```emerchantpay.ocmod.zip``` (excluding ```README.md```)
3.	Login inside the __OpenCart Admin Panel__
4.	Navigate to ```Extensions -> Installer``` and click on button ```Upload``` and choose the ```zip``` file ```emerchantpay.ocmod.zip```
5.	Navigate to ```Extensions -> Payments``` and click install on ```emerchantpay Direct``` and/or ```emerchantpay Checkout```
6.	Set the login credentials (```Username```, ```Password```, ```Token```) and adjust the configuration to your needs.

Enable OpenCart SSL
------------
This steps should be followed if you wish to use the ```emerchantpay Direct``` Method.

* Ensure you have installed a valid __SSL Certificate__ on your __PCI-DSS Certified__ Web Server and you have configured your __Virtual Host__ properly.
* Login to your OpenCart Admin Panel
* Navigate to ```Settings``` -> ```your Store``` -> ```Server```
* Set ```Use SSL``` to __Yes__ in ```Security``` tab and save your changes
* Set the __HTTPS_SERVER__ and __HTTPS_CATALOG__ settings in your ```admin/config.php``` to use ```https``` protocol
* Set the __HTTPS_SERVER__ setting in your ```config.php``` to use ```https``` protocol
* Set the __site_ssl__ setting to ```true``` in your ```system/config/default.php``` file
* It is recommended to add a __Rewrite Rule__ from ```http``` to ```https``` or to add a __Permanent Redirect__ to ```https``` in your virtual host

Supported Transactions & Payment Methods
---------------------
* ```emerchantpay Direct``` Payment Method
  * __Authorize__
  * __Authorize (3D-Secure)__
  * __Sale__
  * __Sale (3D-Secure)__

* ```emerchantpay Checkout``` Payment Method
  * __Apple Pay__ 
  * __Argencard__
  * __Aura__
  * __Authorize__
  * __Authorize (3D-Secure)__
  * __Baloto__
  * __Bancomer__
  * __Bancontact__
  * __Banco de Occidente__
  * __Banco do Brasil__
  * __BitPay__
  * __Boleto__
  * __Bradesco__
  * __Cabal__
  * __CashU__
  * __Cencosud__
  * __Davivienda__
  * __Efecty__
  * __Elo__
  * __eps__
  * __eZeeWallet__
  * __Fashioncheque__
  * __Google Pay__
  * __iDeal__
  * __iDebit__
  * __InstaDebit__
  * __InitRecurringSale__
  * __InitRecurringSale (3D-Secure)__
  * __Intersolve__
  * __Itau__
  * __Klarna__
  * __Multibanco__
  * __MyBank__
  * __Naranja__
  * __Nativa__
  * __Neosurf__
  * __Neteller__
  * __Online Banking__
    * __Interac Combined Pay-in (CPI)__ 
    * __Bancontact (BCT)__ 
    * __BLIK (BLK)__
    * __SPEI (SE)__
    * __LatiPay (PID)__
  * __OXXO__
  * __P24__
  * __Pago Facil__
  * __PayPal__
  * __PaySafeCard__
  * __PayU__
  * __POLi__
  * __Post Finance__
  * __PSE__
  * __RapiPago__
  * __Redpagos__
  * __SafetyPay__
  * __Sale__
  * __Sale (3D-Secure)__
  * __Santander__
  * __Sepa Direct Debit__
  * __SOFORT__
  * __Tarjeta Shopping__
  * __TCS__
  * __Trustly__
  * __TrustPay__
  * __UPI__
  * __WebMoney__
  * __WebPay__
  * __WeChat__

_Note_: If you have trouble with your credentials or terminal configuration, get in touch with our [support] team

You're now ready to process payments through our gateway.

Development
------------
* Install dev packages
```shell
composer install
```
* Run PHP Code Sniffer
```shell
composer php-cs
```
* Run PHP Mess Detector
```shell
composer php-md
```
* Pack installation archive (Linux or macOS only)
```shell
composer pack
```
[support]: mailto:tech-support@emerchantpay.net
