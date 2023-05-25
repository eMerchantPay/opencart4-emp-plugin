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

namespace Opencart\Extension\Emerchantpay\System\Catalog;

if (!class_exists('Genesis\Genesis', false)) {
	require DIR_STORAGE . 'vendor/genesisgateway/genesis_php/vendor/autoload.php';
}

use Opencart\Extension\Emerchantpay\System\EmerchantpayHelper;
use Opencart\System\Engine\Controller;

/**
 * Base Abstract Class for Method Front Controllers
 *
 * Class BaseController
 */
abstract class BaseController extends Controller
{
	/**
	 * OpenCart custom prefix
	 */
	const PLATFORM_TRANSACTION_PREFIX = 'ocart-';

	/**
	 * Module Name
	 *
	 * @var string
	 */
	protected $module_name = null;

	/**
	 * Build URL query using predefined action separator
	 *
	 * @param string $controller Base URL to be used
	 * @param string $action (Optional) Action to be attached to the $url with the separator
	 * @return string
	 */
	protected function buildUrl($controller, $action = ''): string
	{
		if (!empty($action)) {
			$action_separator = EmerchantpayHelper::CONTROLLER_ACTION_SEPARATOR;
			$controller .= "$action_separator$action";
		}

		return $this->url->link(
			$controller,
			'language=' . $this->config->get('config_language')
		);
	}

	/**
	 * Populate billing and shipping address according to existing data
	 *
	 * @param $order_info
	 * @param $data

	 * @return void
	 */
	protected function populateAddresses($order_info, &$data): void
	{
		if (!empty($order_info['payment_firstname']) && !empty($order_info['payment_lastname']) && !empty($order_info['payment_address_1'])) {
			$data['billing'] =
				array(
					'first_name' => $order_info['payment_firstname'],
					'last_name'  => $order_info['payment_lastname'],
					'address1'   => $order_info['payment_address_1'],
					'address2'   => $order_info['payment_address_2'],
					'zip'        => $order_info['payment_postcode'],
					'city'       => $order_info['payment_city'],
					'state'      => $order_info['payment_zone_code'],
					'country'    => $order_info['payment_iso_code_2'],
				);
		} else {
			$data['billing'] =
				array(
					'first_name' => $order_info['shipping_firstname'],
					'last_name'  => $order_info['shipping_lastname'],
					'address1'   => $order_info['shipping_address_1'],
					'address2'   => $order_info['shipping_address_2'],
					'zip'        => $order_info['shipping_postcode'],
					'city'       => $order_info['shipping_city'],
					'state'      => $order_info['shipping_zone_code'],
					'country'    => $order_info['shipping_iso_code_2'],
				);
		}

		if (!empty($order_info['shipping_firstname']) && !empty($order_info['shipping_lastname']) && !empty($order_info['shipping_address_1'])) {
			$data['shipping'] = array(
				'first_name' => $order_info['shipping_firstname'],
				'last_name'  => $order_info['shipping_lastname'],
				'address1'   => $order_info['shipping_address_1'],
				'address2'   => $order_info['shipping_address_2'],
				'zip'        => $order_info['shipping_postcode'],
				'city'       => $order_info['shipping_city'],
				'state'      => $order_info['shipping_zone_code'],
				'country'    => $order_info['shipping_iso_code_2'],
			);
		} else {
			$data['shipping'] = array(
				'first_name' => $order_info['payment_firstname'],
				'last_name'  => $order_info['payment_lastname'],
				'address1'   => $order_info['payment_address_1'],
				'address2'   => $order_info['payment_address_2'],
				'zip'        => $order_info['payment_postcode'],
				'city'       => $order_info['payment_city'],
				'state'      => $order_info['payment_zone_code'],
				'country'    => $order_info['payment_iso_code_2'],
			);
		}
	}
}
