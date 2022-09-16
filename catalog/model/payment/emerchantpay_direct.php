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

use Genesis\API\Constants\Endpoints;
use Genesis\API\Constants\Environments;
use Genesis\API\Constants\Transaction\Types;
use Genesis\Config;
use Genesis\Exceptions\ErrorAPI;
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
	 * @param $total   Order Total
	 *
	 * @return array
	 */
	public function getMethod($address, $total = 0): array
	{
		$this->load->language('extension/emerchantpay/payment/emerchantpay_direct');

		$query = $this->db->query("
			SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone
			WHERE geo_zone_id = '" . (int)$this->config->get('emerchantpay_direct_geo_zone_id') . "' AND
			country_id = '" . (int)$address['country_id'] . "' AND
			(zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')
		");

		if ($this->config->get('emerchantpay_direct_total') > 0 && $this->config->get('emerchantpay_direct_total') > $total) {
			$status = false;
		} elseif (!$this->config->get('emerchantpay_direct_geo_zone_id')) {
			$status = true;
		} elseif ($query->num_rows) {
			$status = true;
		} else {
			$status = false;
		}

		if (!EmerchantpayHelper::isSecureConnection($this->request)) {
			$status = false;
		}

		$method_data = array();

		if ($status) {
			$method_data = array(
				'code'       => 'emerchantpay_direct',
				'title'      => $this->language->get('text_title'),
				'terms'      => '',
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
	public function getTransactionById($reference_id): mixed
	{
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
	public function sendTransaction($data): mixed
	{
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

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (ErrorAPI $api) {
			throw $api;
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return false;
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
	public function reconcile($unique_id): mixed
	{
		try {
			$this->bootstrap();

			$genesis = new Genesis('WPF\Reconcile');

			$genesis->request()->setUniqueId($unique_id);

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (ErrorAPI $api) {
			throw $api;
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return false;
		}
	}

	/**
	 * Bootstrap Genesis Library
	 *
	 * @return void
	 */
	public function bootstrap(): void
	{
		// Look for, but DO NOT try to load via Auto-loader magic methods
		if (!class_exists('\Genesis\Genesis', false)) {
			include DIR_STORAGE . 'vendor/genesisgateway/genesis_php/vendor/autoload.php';

			Config::setEndpoint(
				Endpoints::EMERCHANTPAY
			);

			Config::setUsername(
				$this->config->get('emerchantpay_direct_username')
			);

			Config::setPassword(
				$this->config->get('emerchantpay_direct_password')
			);

			Config::setToken(
				$this->config->get('emerchantpay_direct_token')
			);

			Config::setEnvironment(
				$this->config->get('emerchantpay_direct_sandbox') ? Environments::STAGING : Environments::PRODUCTION
			);
		}
	}

	/**
	 * Check whether the selected transaction type is a 3d transaction
	 *
	 * @return bool
	 */
	public function is3dTransaction(): bool
	{
		$types = array(
			Types::AUTHORIZE_3D,
			Types::SALE_3D,
			Types::INIT_RECURRING_SALE_3D,
		);

		$transaction_type = $this->config->get(
			$this->isRecurringOrder() ? 'emerchantpay_direct_recurring_transaction_type' : 'emerchantpay_direct_transaction_type'
		);

		return in_array($transaction_type, $types);
	}

	/**
	 * Generate Transaction Id based on the order id
	 * and salted to avoid duplication
	 *
	 * @param string $prefix
	 *
	 * @return string
	 */
	public function genTransactionId($prefix = ''): string
	{
		$hash = md5(microtime(true) . uniqid() . mt_rand(PHP_INT_SIZE, PHP_INT_MAX));

		return (string)$prefix . substr($hash, -(strlen($hash) - strlen($prefix)));
	}

	/**
	 * Get a Usage string with the Store Name
	 *
	 * @return string
	 */
	public function getUsage(): string
	{
		return sprintf('%s direct transaction', $this->config->get('config_name'));
	}
}
