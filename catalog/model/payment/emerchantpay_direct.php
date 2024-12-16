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

namespace Opencart\Catalog\Model\Extension\Emerchantpay\Payment;

use Genesis\Api\Constants\Endpoints;
use Genesis\Api\Constants\Environments;
use Genesis\Api\Constants\Transaction\Parameters\Threeds\V2\Control\ChallengeWindowSizes;
use Genesis\Api\Constants\Transaction\Parameters\Threeds\V2\Control\DeviceTypes;
use Genesis\Api\Constants\Transaction\Types;
use Genesis\Config;
use Genesis\Exceptions\InvalidArgument;
use Genesis\Genesis;
use Opencart\Catalog\Model\Extension\Emerchantpay\Payment\Emerchantpay\BaseModel;
use Opencart\Extension\Emerchantpay\System\EmerchantpayHelper;

/**
 * Front-end model for the "emerchantpay Direct" module
 *
 * @package EMerchantPayDirect
 */
class EmerchantpayDirect extends BaseModel
{
	/**
	 * Module Name
	 *
	 * @var string
	 */
	protected $module_name = 'emerchantpay_direct';

	/**
	 * Main method
	 *
	 * @param $address Order Address
	 *
	 * @return array
	 */
	public function getMethods($address): array {
		$this->load->language('extension/emerchantpay/payment/emerchantpay_direct');

		if (!$this->config->get('emerchantpay_direct_geo_zone_id')) {
			$status = true;
		} elseif (!$this->config->get('config_checkout_payment_address')) {
			// this is "Billing Address required" from store settings. If unchecked, no further checks are needed
			$status = true;
		} else {
			$status = $this->checkGeoZoneAvailability($address);
		}

		if (!EmerchantpayHelper::isSecureConnection($this->request)) {
			$status = false;
		}

		$method_data = array();

		if ($status) {
			$option_data = array();
			$option_data['emerchantpay_direct'] = [
				'code' => 'emerchantpay_direct.emerchantpay_direct',
				'name' => $this->language->get('text_title')
			];

			$method_data = array(
				'code'       => 'emerchantpay_direct',
				'name'       => $this->language->get('text_title'),
				'option'     => $option_data,
				'sort_order' => $this->config->get('emerchantpay_direct_sort_order')
			);
		}

		return $method_data;
	}

	/**
	 * Get saved transaction (from DB) by id
	 *
	 * @param $reference_id
	 *
	 * @return bool|mixed
	 */
	public function getTransactionById($reference_id): mixed {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "emerchantpay_direct_transactions` WHERE `unique_id` = '" . $this->db->escape($reference_id) . "' LIMIT 1");

		if ($query->num_rows) {
			return reset($query->rows);
		}

		return false;
	}

	/**
	 * Send transaction to Genesis
	 *
	 * @param $data array Transaction Data
	 *
	 * @return mixed
	 *
	 * @throws /Exception
	 */
	public function sendTransaction($data): mixed {
		try {
			$this->bootstrap();

			$genesis = $this->createGenesisRequest(
				$this->config->get(
					$this->isRecurringOrder() ? 'emerchantpay_direct_recurring_transaction_type' : 'emerchantpay_direct_transaction_type'
				)
			);

			$genesis
				->request()
				->setTransactionId($data['transaction_id'])
				->setRemoteIp($data['remote_address'])
				// Financial
				->setCurrency($data['currency'])
				->setAmount($data['amount'])
				->setUsage($data['usage'])
				// Personal
				->setCustomerEmail($data['customer_email'])
				->setCustomerPhone($data['customer_phone'])
				// CC
				->setCardHolder($data['card_holder'])
				->setCardNumber($data['card_number'])
				->setCvv($data['cvv'])
				->setExpirationMonth($data['expiration_month'])
				->setExpirationYear($data['expiration_year'])
				->setClientSideEncryption($data['encrypted'])
				// Billing
				->setBillingFirstName($data['billing']['first_name'])
				->setBillingLastName($data['billing']['last_name'])
				->setBillingAddress1($data['billing']['address1'])
				->setBillingAddress2($data['billing']['address2'])
				->setBillingZipCode($data['billing']['zip'])
				->setBillingCity($data['billing']['city'])
				->setBillingState($data['billing']['state'])
				->setBillingCountry($data['billing']['country'])
				// Shipping
				->setShippingFirstName($data['shipping']['first_name'])
				->setShippingLastName($data['shipping']['last_name'])
				->setShippingAddress1($data['shipping']['address1'])
				->setShippingAddress2($data['shipping']['address2'])
				->setShippingZipCode($data['shipping']['zip'])
				->setShippingCity($data['shipping']['city'])
				->setShippingState($data['shipping']['state'])
				->setShippingCountry($data['shipping']['country']);

			if ($this->is3dTransaction()) {
				$genesis
					->request()
					->setNotificationUrl($data['notification_url'])
					->setReturnSuccessUrl($data['return_success_url'])
					->setReturnFailureUrl($data['return_failure_url']);
			}

			if ($this->isThreedsAllowed() && $this->is3dTransaction()) {
				$this->addThreedsParamsToRequest($genesis, $data);
				$this->addThreedsBrowserParamsToRequest($genesis, $data);
			}

			$genesis->execute();

			return $genesis->response();
		} catch (\Exception $exception) {
			$this->logEx($exception);

			throw $exception;
		}
	}

	/**
	 * Genesis Request - Reconcile
	 *
	 * @param $unique_id string - Id of a Genesis Transaction
	 *
	 * @return mixed
	 *
	 * @throws /Exception
	 */
	public function reconcile($unique_id): mixed {
		try {
			$this->bootstrap();

			$genesis = new Genesis('Wpf\Reconcile');

			$genesis->request()->setUniqueId($unique_id);

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return false;
		}
	}

	/**
	 * Bootstrap Genesis Library
	 *
	 * @return void
	 *
	 * @throws InvalidArgument
	 */
	public function bootstrap(): void {
		parent::bootstrap();

		$token = $this->config->get("{$this->module_name}_token");

		if (empty($token)) {
			Config::setForceSmartRouting(true);

			return;
		}

		Config::setToken($token);
	}

	/**
	 * Generate Transaction Id based on the order id
	 * and salted to avoid duplication
	 *
	 * @param string $prefix
	 *
	 * @return string
	 */
	public function genTransactionId($prefix = ''): string {
		$hash = md5(microtime(true) . uniqid() . mt_rand(PHP_INT_SIZE, PHP_INT_MAX));

		return (string)$prefix . substr($hash, -(strlen($hash) - strlen($prefix)));
	}

	/**
	 * Get a Usage string with the Store Name
	 *
	 * @return string
	 */
	public function getUsage(): string {
		return sprintf('%s direct transaction', $this->config->get('config_name'));
	}

	/**
	 * Append 3DSv2 browser parameters to the Genesis Request
	 *
	 * @param $genesis
	 * @param $data
	 *
	 * @return void
	 */
	protected function addThreedsBrowserParamsToRequest($genesis, $data): void {
		$http_accept = $this->request->server['HTTP_ACCEPT'] ?? null;

		/** @var Create $request */
		$request = $genesis->request();
		$request
			->setThreedsV2ControlDeviceType(DeviceTypes::BROWSER)
			->setThreedsV2ControlChallengeWindowSize(ChallengeWindowSizes::FULLSCREEN)
			->setThreedsV2BrowserAcceptHeader($http_accept)
			->setThreedsV2BrowserJavaEnabled($data['browser_data'][self::THREEDS_V2_JAVA_ENABLED])
			->setThreedsV2BrowserLanguage($data['browser_data'][self::THREEDS_V2_BROWSER_LANGUAGE])
			->setThreedsV2BrowserColorDepth($data['browser_data'][self::THREEDS_V2_COLOR_DEPTH])
			->setThreedsV2BrowserScreenHeight($data['browser_data'][self::THREEDS_V2_SCREEN_HEIGHT])
			->setThreedsV2BrowserScreenWidth($data['browser_data'][self::THREEDS_V2_SCREEN_WIDTH])
			->setThreedsV2BrowserTimeZoneOffset($data['browser_data'][self::THREEDS_V2_BROWSER_TIMEZONE_ZONE_OFFSET])
			->setThreedsV2BrowserUserAgent($data['browser_data'][self::THREEDS_V2_USER_AGENT]);
	}
}
