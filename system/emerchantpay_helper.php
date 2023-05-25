<?php
/*
 * Copyright (C) 2018 emerchantpay Ltd.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * @author      emerchantpay
 * @copyright   2018 emerchantpay Ltd.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2 (GPL-2.0)
 */

namespace Opencart\Extension\Emerchantpay\System;

use Genesis\API\Constants\Banks;
use Genesis\API\Constants\Transaction\Names;
use Genesis\API\Constants\Transaction\Types;
use Genesis\API\Constants\Transaction\Parameters\Mobile\ApplePay\PaymentTypes as ApplePayPaymentTypes;
use Genesis\API\Constants\Transaction\Parameters\Mobile\GooglePay\PaymentTypes as GooglePayPaymentTypes;
use Genesis\API\Constants\Transaction\Parameters\Wallets\PayPal\PaymentTypes as PayPalPaymentTypes;
use Genesis\API\Request\Financial\Alternatives\Klarna\Item;
use Genesis\API\Request\Financial\Alternatives\Klarna\Items;
use Opencart\Catalog\Model\Extension\Emerchantpay\Payment\Emerchantpay\BaseModel;


/**
 * Class EmerchantpayHelper
 *
 * @package Opencart\Extension\Emerchantpay\System
 */
class EmerchantpayHelper
{
	const CONTROLLER_ACTION_SEPARATOR = '.';

	const PPRO_TRANSACTION_SUFFIX     = '_ppro';
	const TRANSACTION_LANGUAGE_PREFIX = 'text_transaction_';

	const GOOGLE_PAY_TRANSACTION_PREFIX     = Types::GOOGLE_PAY . '_';
	const GOOGLE_PAY_PAYMENT_TYPE_AUTHORIZE = GooglePayPaymentTypes::AUTHORIZE;
	const GOOGLE_PAY_PAYMENT_TYPE_SALE      = GooglePayPaymentTypes::SALE;

	const PAYPAL_TRANSACTION_PREFIX         = Types::PAY_PAL . '_';
	const PAYPAL_PAYMENT_TYPE_AUTHORIZE     = PayPalPaymentTypes::AUTHORIZE;
	const PAYPAL_PAYMENT_TYPE_SALE          = PayPalPaymentTypes::SALE;
	const PAYPAL_PAYMENT_TYPE_EXPRESS       = PayPalPaymentTypes::EXPRESS;

	const APPLE_PAY_TRANSACTION_PREFIX      = Types::APPLE_PAY . '_';
	const APPLE_PAY_PAYMENT_TYPE_AUTHORIZE  = ApplePayPaymentTypes::AUTHORIZE;
	const APPLE_PAY_PAYMENT_TYPE_SALE       = ApplePayPaymentTypes::SALE;

	const REFERENCE_ACTION_CAPTURE = 'capture';
	const REFERENCE_ACTION_REFUND  = 'refund';

	/**
	 * Retrieve Recurring Transaction Types
	 *
	 * @return array
	 */
	public static function getRecurringTransactionTypes(): array
	{
		return array(
			Types::INIT_RECURRING_SALE,
			Types::INIT_RECURRING_SALE_3D
		);
	}

	/**
	 * Retrieve common Transaction Type Names
	 *
	 * @return array
	 */
	public static function getTransactionTypeNames(): array
	{
		$data = array();

		foreach (Types::getWPFTransactionTypes() as $type) {
			$key        = EmerchantpayHelper::TRANSACTION_LANGUAGE_PREFIX . $type;
			$data[$key] = Names::getName($type);
		}

		return $data;
	}

	/**
	 * Create Klarna Authorize Items
	 *
	 * @param $order
	 *      Array array (
	 *          currency =>
	 *          additional => array (
	 *              product_info => array
	 *              order_total  => array
	 *          )
	 *      )
	 *
	 * @return Items
	 *
	 * @throws \Genesis\Exceptions\ErrorParameter
	 */
	public static function getKlarnaCustomParamItems($order): Items
	{
		$tax_class_ids = self::getTaxClassIdFromProductInfo($order['additional']['product_info']);

		$items = new Items($order['currency']);
		foreach ($order['additional']['product_order_info'] as $product) {
			$tax_class_id = Item::ITEM_TYPE_PHYSICAL;
			if ($tax_class_ids[$product['product_id']] == BaseModel::OC_TAX_CLASS_VIRTUAL_PRODUCT) {
				$tax_class_id = Item::ITEM_TYPE_DIGITAL;
			}

			$klarna_item = new Item(
				$product['name'],
				$tax_class_id,
				$product['quantity'],
				$product['price']
			);
			$items->addItem($klarna_item);

		}

		$taxes = floatval(self::getTaxFromOrderTotals($order['additional']['order_totals']));
		if ($taxes) {
			$items->addItem(
				new Item(
					'Taxes',
					Item::ITEM_TYPE_SURCHARGE,
					1,
					$taxes
				)
			);
		}

		$shipping = floatval(self::getShippingFromOrderTotals($order['additional']['order_totals']));
		if ($shipping) {
			$items->addItem(
				new Item(
					'Shipping Costs',
					Item::ITEM_TYPE_SHIPPING_FEE,
					1,
					$shipping
				)
			);
		}

		return $items;
	}

	/**
	 * Extract TaxClassId from ProductInfo
	 *      Returns Array (product_id => tax_class_id)
	 *
	 * @param array $products
	 *
	 * @return array
	 */
	public static function getTaxClassIdFromProductInfo($products): array
	{
		$class_ids = array();

		foreach($products as $product) {
			$class_ids[$product['product_id']] = $product['tax_class_id'];
		}

		return $class_ids;
	}

	/**
	 * Calculate the Shipping cost from Order Total
	 *
	 * @param $order_totals
	 *
	 * @return int
	 */
	public static function getShippingFromOrderTotals($order_totals): int
	{
		$shipping = 0;

		foreach($order_totals as $item_total) {
			if ($item_total['code'] == 'shipping') {
				$shipping += $item_total['value'];
			}
		}

		return $shipping;
	}

	/**
	 * Calculate the Taxes const from Order Total
	 *
	 * @param $order_totals
	 *
	 * @return int
	 */
	public static function getTaxFromOrderTotals($order_totals): int
	{
		$tax = 0;

		foreach($order_totals as $item_total) {
			if ($item_total['code'] == 'tax') {
				$tax += $item_total['value'];
			}
		}

		return $tax;
	}

	/**
	 * Return list of available Bank Codes for Online banking
	 *
	 * @return array
	 */
	public static function getAvailableBankCodes(): array
	{
		return [
			Banks::CPI => 'Interac Combined Pay-in',
			Banks::BCT => 'Bancontact'
		];
	}

	/**
	 * Check if the current visitor is on HTTPS
	 *
	 * @param $request
	 *
	 * @return bool
	 */
	public static function isSecureConnection($request): bool
	{
		if (!empty($request->server['HTTPS']) && strtolower($request->server['HTTPS']) != 'off') {
			return true;
		}

		if (!empty($request->server['HTTP_X_FORWARDED_PROTO']) && $request->server['HTTP_X_FORWARDED_PROTO'] == 'https') {
			return true;
		}

		if (!empty($request->server['HTTP_X_FORWARDED_PORT']) && $request->server['HTTP_X_FORWARDED_PORT'] == '443') {
			return true;
		}

		return false;
	}
}
