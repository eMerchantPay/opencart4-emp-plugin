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
use Genesis\API\Constants\Payment\Methods;
use Genesis\API\Constants\Transaction\States;
use Genesis\API\Constants\Transaction\Types;
use Genesis\Config;
use Genesis\Exceptions\ErrorAPI;
use Genesis\Exceptions\InvalidArgument;
use Genesis\Genesis;
use Genesis\Utils\Common as CommonUtils;
use Opencart\Catalog\Model\Extension\Emerchantpay\Payment\Emerchantpay\BaseModel;
use Opencart\Extension\Emerchantpay\System\EmerchantpayHelper;

/**
 * Front-end model for the "emerchantpay Checkout" module
 *
 * @package EMerchantPayCheckout
 */
class EmerchantpayCheckout extends BaseModel
{
	/**
	 * Module Name
	 *
	 * @var string
	 */
	protected $module_name = 'emerchantpay_checkout';

	/**
	 * Module Code used in the payment process
	 */
	const METHOD_CODE = 'emerchantpay_checkout.emerchantpay_checkout';

	/**
	 * Main method
	 *
	 * @param $address //Order Address
	 *
	 * @return array
	 */
	public function getMethods($address): array {
		$this->load->language('extension/emerchantpay/payment/emerchantpay_checkout');

		if (!$this->config->get('emerchantpay_checkout_geo_zone_id')) {
			$status = true;
		} elseif (!$this->config->get('config_checkout_payment_address')) {
			// this is "Billing Address required" from store settings. If unchecked, no further checks are needed
			$status = true;
		} else {
			$status = $this->checkGeoZoneAvailability($address);
		}

		$method_data = array();

		if ($status) {
			$option_data = array();
			$option_data['emerchantpay_checkout'] = [
				'code' => self::METHOD_CODE,
				'name' => $this->language->get('text_title')
			];

			$method_data = array(
				'code'       => 'emerchantpay_checkout',
				'name'       => $this->language->get('text_title'),
				'option'     => $option_data,
				'sort_order' => $this->config->get('emerchantpay_checkout_sort_order')
			);
		}

		return $method_data;
	}

	/**
	 * @param $email
	 *
	 * @return null|string
	 */
	public function getConsumerId($email): null|string {
		$query = $this->db->query("
			SELECT * FROM
				`" . DB_PREFIX . "emerchantpay_checkout_consumers`
			WHERE
				`customer_email` = '" . $this->db->escape($email) . "' LIMIT 1
		");

		if ($query->num_rows) {
			return $query->rows[0]['consumer_id'];
		}

		return $this->retrieveConsumerIdFromGenesisGateway($email);
	}

	/**
	 * @param $email
	 * @param $consumer_id
	 */
	public function addConsumer($email, $consumer_id): void {
		try {
			$this->db->query("
				INSERT INTO
					`" . DB_PREFIX . "emerchantpay_checkout_consumers` (`customer_email`, `consumer_id`)
				VALUES
					('" . $this->db->escape($email) . "', '" . $this->db->escape($consumer_id) . "')
			");
		} catch (\Exception $exception) {
			$this->logEx($exception);
		}
	}

	/**
	 * Get saved transaction (from DB) by id
	 *
	 * @param $unique_id
	 *
	 * @return bool|mixed
	 */
	public function getTransactionById($unique_id): mixed {
		if (isset($unique_id) && !empty($unique_id)) {
			$query = $this->db->query("
				SELECT * FROM `" . DB_PREFIX . "emerchantpay_checkout_transactions`
				WHERE `unique_id` = '" . $this->db->escape($unique_id) . "' LIMIT 1
			");

			if ($query->num_rows) {
				return reset($query->rows);
			}
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
	 * @throws \Exception
	 * @throws ErrorAPI
	 */
	public function create($data): mixed {
		try {
			$this->bootstrap();

			$genesis = new Genesis('WPF\Create');

			$genesis
				->request()
				->setTransactionId($data['transaction_id'])
				// Financial
				->setCurrency($data['currency'])
				->setAmount($data['amount'])
				->setUsage($data['usage'])
				->setDescription($data['description'])
				// Personal
				->setCustomerEmail($data['customer_email'])
				->setCustomerPhone($data['customer_phone'])
				// URL
				->setNotificationUrl($data['notification_url'])
				->setReturnSuccessUrl($data['return_success_url'])
				->setReturnFailureUrl($data['return_failure_url'])
				->setReturnCancelUrl($data['return_cancel_url'])
				->setReturnPendingUrl($data['return_success_url'])
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
				->setShippingCountry($data['shipping']['country'])
				->setLanguage($data['language']);

			$this->addTransactionTypesToGatewayRequest($genesis, $data);

			if ($this->isWpfTokenizationEnabled()) {
				$this->prepareWpfRequestTokenization($genesis);
			}

			if ($this->isThreedsAllowed()) {
				$this->addThreedsParamsToRequest($genesis, $data);
			}

			$genesis->execute();

			$this->saveWpfTokenizationData($genesis);

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
	 * @throws \Exception
	 * @throws ErrorAPI
	 */
	public function reconcile($unique_id): mixed {
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
	 * @throws InvalidArgument
	 *
	 * @return void
	 */
	public function bootstrap(): void {
		parent::bootstrap();
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

		return $prefix . substr($hash, -(strlen($hash) - strlen($prefix)));
	}

	/**
	 * Get the Order Totals stored in the Database
	 *
	 * @param $order_id
	 *
	 * @return mixed
	 */
	public function getOrderTotals($order_id): mixed {
		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total WHERE	order_id = '" . (int)$order_id . "' ORDER BY sort_order");

		return $query->rows;
	}

	/**
	 * Get the selected transaction types in array
	 *
	 * @return array
	 */
	public function getTransactionTypes(): array {
		$processed_list = array();
		$alias_map = array();

		$selected_types = $this->orderCardTransactionTypes(
			$this->config->get('emerchantpay_checkout_transaction_type')
		);
		$methods = Methods::getMethods();

		foreach ($methods as $method) {
			$alias_map[$method . self::PPRO_TRANSACTION_SUFFIX] = Types::PPRO;
		}

		$alias_map = array_merge($alias_map, [
			self::GOOGLE_PAY_TRANSACTION_PREFIX . self::GOOGLE_PAY_PAYMENT_TYPE_AUTHORIZE =>
				Types::GOOGLE_PAY,
			self::GOOGLE_PAY_TRANSACTION_PREFIX . self::GOOGLE_PAY_PAYMENT_TYPE_SALE      =>
				Types::GOOGLE_PAY,
			self::PAYPAL_TRANSACTION_PREFIX . self::PAYPAL_PAYMENT_TYPE_AUTHORIZE         =>
				Types::PAY_PAL,
			self::PAYPAL_TRANSACTION_PREFIX . self::PAYPAL_PAYMENT_TYPE_SALE              =>
				Types::PAY_PAL,
			self::PAYPAL_TRANSACTION_PREFIX . self::PAYPAL_PAYMENT_TYPE_EXPRESS           =>
				Types::PAY_PAL,
			self::APPLE_PAY_TRANSACTION_PREFIX . self::APPLE_PAY_PAYMENT_TYPE_AUTHORIZE   =>
				Types::APPLE_PAY,
			self::APPLE_PAY_TRANSACTION_PREFIX . self::APPLE_PAY_PAYMENT_TYPE_SALE        =>
				Types::APPLE_PAY,
		]);

		foreach ($selected_types as $selected_type) {
			if (array_key_exists($selected_type, $alias_map)) {
				$transaction_type = $alias_map[$selected_type];

				$processed_list[$transaction_type]['name'] = $transaction_type;

				// WPF Custom Attribute
				$key = $this->getCustomParameterKey($transaction_type);

				$processed_list[$transaction_type]['parameters'][] = array(
					$key => str_replace(
						[
							self::PPRO_TRANSACTION_SUFFIX,
							self::GOOGLE_PAY_TRANSACTION_PREFIX,
							self::PAYPAL_TRANSACTION_PREFIX,
							self::APPLE_PAY_TRANSACTION_PREFIX
						],
						'',
						$selected_type
					)
				);
			} else {
				$processed_list[] = $selected_type;
			}
		}

		return $processed_list;
	}

	/**
	 * @param Genesis $genesis
	 * @param $order
	 *
	 * @return void
	 *
	 * @throws \Genesis\Exceptions\ErrorParameter
	 */
	public function addTransactionTypesToGatewayRequest(Genesis $genesis, $order): void {
		$types = $this->isRecurringOrder() ? $this->getRecurringTransactionTypes() : $this->getTransactionTypes();

		foreach ($types as $type) {
			if (is_array($type)) {
				$genesis
					->request()
					->addTransactionType($type['name'], $type['parameters']);

				continue;
			}

			$parameters = $this->getCustomRequiredAttributes($type, $order);

			if (!isset($parameters)) {
				$parameters = array();
			}

			$genesis
				->request()
				->addTransactionType(
					$type,
					$parameters
				);
			unset($parameters);
		}
	}

	/**
	 * @param string $type Transaction Type
	 * @param array $order Transformed Order Array
	 *
	 * @return array
	 *
	 * @throws \Genesis\Exceptions\ErrorParameter
	 */
	public function getCustomRequiredAttributes($type, $order): array {
		$parameters = array();
		switch ($type) {
			case Types::IDEBIT_PAYIN:
			case Types::INSTA_DEBIT_PAYIN:
				$parameters = array(
					'customer_account_id' => $order['additional']['user_hash']
				);
				break;
			case Types::KLARNA_AUTHORIZE:
				$parameters = EmerchantpayHelper::getKlarnaCustomParamItems($order)->toArray();
				break;
			case Types::TRUSTLY_SALE:
				$current_user_id = $order['additional']['user_id'];
				$user_id = ($current_user_id > 0) ? $current_user_id : $order['additional']['user_hash'];
				$parameters = array(
					'user_id' => $user_id
				);
				break;
			case Types::ONLINE_BANKING_PAYIN:
				$selected_bank_codes = $this->config->get('emerchantpay_checkout_bank_codes');

				if (CommonUtils::isValidArray($selected_bank_codes)) {
					$parameters['bank_codes'] = array_map(
						function ($value) {
							return ['bank_code' => $value];
						},
						$selected_bank_codes
					);
				}
				break;
			case Types::PAYSAFECARD:
				$user_id = $order['additional']['user_id'];
				$customer_id = ($user_id > 0) ? $user_id : $order['additional']['user_hash'];
				$parameters = array(
					'customer_id' => $customer_id
				);
				break;

		}

		return $parameters;
	}

	/**
	 * Get the selected transaction types in array
	 *
	 * @return array
	 */
	public function getRecurringTransactionTypes(): array {
		return $this->config->get('emerchantpay_checkout_recurring_transaction_type');
	}

	/**
	 * Get a Usage string with the Store Name
	 *
	 * @return string
	 */
	public function getUsage(): string {
		return sprintf('%s checkout transaction', $this->config->get('config_name'));
	}

	/**
	 * Retrieve the current logged user ID
	 *
	 * @return int
	 */
	public function getCurrentUserId(): int {
		return array_key_exists('user_id', $this->session->data) ? $this->session->data['user_id'] : 0;
	}

	/**
	 * Get the current front-end language
	 *
	 * @return string
	 */
	public function getLanguage(): string {
		$language = isset($this->session->data['language']) ? $this->session->data['language'] : $this->config->get('config_language');
		$language_code = substr($language, 0, 2);

		$this->bootstrap();

		$constant_name = '\Genesis\API\Constants\i18n::' . strtoupper($language_code);
		if (defined($constant_name) && constant($constant_name)) {
			return strtolower($language_code);
		}

		return 'en';
	}

	/**
	 * @param string $email
	 *
	 * @return null|string
	 */
	protected function retrieveConsumerIdFromGenesisGateway($email): null|string {
		try {
			$genesis = new Genesis('NonFinancial\Consumers\Retrieve');
			$genesis->request()->setEmail($email);

			$genesis->execute();

			$response = $genesis->response()->getResponseObject();

			if ($this->isErrorResponse($response)) {
				return null;
			}

			return $response->consumer_id;
		} catch (\Exception $exception) {
			return null;
		}
	}

	/**
	 * @param $response
	 *
	 * @return bool
	 */
	protected function isErrorResponse($response): bool {
		$state = new States($response->status);

		return $state->isError();
	}

	/**
	 * @param Genesis $genesis
	 *
	 * @return void
	 */
	protected function prepareWpfRequestTokenization(Genesis $genesis): void {
		$genesis->request()->setRememberCard(true);

		$consumer_id = $this->getConsumerId($genesis->request()->getCustomerEmail());

		if ($consumer_id) {
			$genesis->request()->setConsumerId($consumer_id);
		}
	}

	/**
	 * Return true if WPF tokenization is enabled
	 *
	 * @return bool
	 */
	protected function isWpfTokenizationEnabled(): bool {
		return (bool)$this->config->get('emerchantpay_checkout_wpf_tokenization');
	}

	/**
	 * Save WPF tokenization data into Database
	 *
	 * @param $genesis
	 *
	 * @return void
	 */
	protected function saveWpfTokenizationData($genesis): void {
		if (!empty($genesis->response()->getResponseObject()->consumer_id)) {
			$this->addConsumer(
				$genesis->request()->getCustomerEmail(),
				$genesis->response()->getResponseObject()->consumer_id
			);
		}
	}

	/**
	 * @param $transaction_type
	 *
	 * @return string
	 */
	private function getCustomParameterKey($transaction_type): string {
		switch ($transaction_type) {
			case Types::PPRO:
				$result = 'payment_method';
				break;
			case Types::PAY_PAL:
				$result = 'payment_type';
				break;
			case Types::GOOGLE_PAY:
			case Types::APPLE_PAY:
				$result = 'payment_subtype';
				break;
			default:
				$result = 'unknown';
		}

		return $result;
	}

	/**
	 * Order transaction types with Card Transaction types in front
	 *
	 * @param array $selected_types Selected transaction types
	 * @return array
	 */
	private function orderCardTransactionTypes($selected_types) {
		$custom_order = Types::getCardTransactionTypes();

		asort($selected_types);

		$sorted_array = array_intersect($custom_order, $selected_types);

		return array_merge($sorted_array, array_diff($selected_types, $sorted_array));
	}
}
