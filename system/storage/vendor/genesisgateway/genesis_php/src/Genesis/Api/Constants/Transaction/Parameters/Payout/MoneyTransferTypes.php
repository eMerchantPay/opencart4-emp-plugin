<?php

/**
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NON-INFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @author      emerchantpay
 * @copyright   Copyright (C) 2015-2025 emerchantpay Ltd.
 * @license     http://opensource.org/licenses/MIT The MIT License
 */

namespace Genesis\Api\Constants\Transaction\Parameters\Payout;

use Genesis\Utils\Common as CommonUtils;

/**
 * Money transfer payout is a standard payout with additional parameters.
 * The section and parameters below are optional and to be considered only when present.
 *
 * @package Genesis\Api\Constants\Transaction\Parameters\Payout
 */
class MoneyTransferTypes
{
    const ACCOUNT_TO_ACCOUNT = 'account_to_account';

    const PERSON_TO_PERSON   = 'person_to_person';

    const WALLET_TRANSFER    = 'wallet_transfer';

    const FUNDS_TRANSFER     = 'funds_transfer';

    /**
     * Get payment allowed payment
     *
     * @return array
     */
    public static function getAllowedMoneyTransferTypes()
    {
        return CommonUtils::getClassConstants(self::class);
    }
}
