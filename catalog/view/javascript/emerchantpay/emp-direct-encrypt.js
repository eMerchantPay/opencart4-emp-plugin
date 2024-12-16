// Copyright (C) 2018-2024 emerchantpay Ltd.
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// @author   emerchantpay
// @copyright  2018-2024 emerchantpay Ltd.
// @license   http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2 (GPL-2.0)

const empClassicDirectEncrypt = {
    paymentMethod: window.empEncryptConf.methodName,
    creditCardData: function () {
        let card_holder   = document.querySelector(`input[name="${this.paymentMethod}-cc-holder"]`)?.value;
        let card_number   = document.querySelector(`input[name="${this.paymentMethod}-cc-number"]`)?.value;
        let card_expiry   = document.querySelector(`input[name="${this.paymentMethod}-cc-expiration"]`)?.value;
        let card_cvv      = document.querySelector(`input[name="${this.paymentMethod}-cc-cvv"]`)?.value;
        let [month, year] = empCardDataEncrypt.transformCardExpiry(card_expiry);

        return {
            card_holder: card_holder,
            card_number: card_number?.replaceAll(/\s/g, ''),
            month:       month,
            year:        year,
            cvv:         card_cvv?.trim()
        };
    },
}

const empCardDataEncrypt = {
    transformCardExpiry: function (card_expiry) {
        if (!card_expiry) return ['', ''];

        let year_now      = (new Date()).getFullYear();
        let [month, year] = card_expiry.toString().split('/');

        if (month && year) {
            month = month.trim();
            // Extract last two digits of the year and prepend the current century
            year  = year.trim()
            year  = `${year_now.toString().substring(0, 2)}${year.substring(year.length - 2)}`
        }

        return [month ?? '', year ?? ''];
    },
    encrypt: function (key, data) {
        let cse = Encrypto.createEncryption(key);

        return cse.encrypt(data)
    }
}
