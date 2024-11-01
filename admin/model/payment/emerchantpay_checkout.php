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

namespace Opencart\Admin\Model\Extension\Emerchantpay\Payment;

use Genesis\Api\Constants\Transaction\Names;
use Genesis\Api\Constants\Transaction\Types;
use Genesis\Api\Constants\Transaction\Parameters\Mobile\GooglePay\PaymentTypes as GooglePayPaymentTypes;
use Genesis\Api\Constants\Transaction\Parameters\Mobile\ApplePay\PaymentTypes as ApplePayPaymentTypes;
use Genesis\Api\Constants\Transaction\Parameters\Wallets\PayPal\PaymentTypes as PayPalPaymentTypes;
use Genesis\Api\Request\Financial\Alternatives\Transaction\Items as InvoiceItems;
use Genesis\Config;
use Genesis\Exceptions\InvalidArgument;
use Genesis\Genesis;
use Opencart\Admin\Model\Extension\Emerchantpay\Payment\emerchantpay\BaseModel;
use Opencart\Extension\Emerchantpay\System\DbHelper;
use Opencart\Extension\Emerchantpay\System\EmerchantpayHelper;

/**
 * Backend model for the "emerchantpay Checkout" module
 *
 * @package EMerchantpayCheckout
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EmerchantpayCheckout extends BaseModel
{
	protected string $module_name = 'emerchantpay_checkout';

	/**
	 * Perform installation logic
	 *
	 * @return void
	 */
	public function install(): void {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "emerchantpay_checkout_transactions` (
			  `unique_id` VARCHAR(255) NOT NULL,
			  `reference_id` VARCHAR(255) NOT NULL,
			  `order_id` INT(11) NOT NULL,
			  `type` CHAR(32) NOT NULL,
			  `mode` CHAR(255) NOT NULL,
			  `timestamp` DATETIME NOT NULL,
			  `status` CHAR(32) NOT NULL,
			  `message` VARCHAR(255) NULL,
			  `technical_message` VARCHAR(255) NULL,
			  `terminal_token` VARCHAR(255) NULL,
			  `amount` DECIMAL( 15, 4 ) DEFAULT NULL,
			  `currency` CHAR(3) NULL,
			  PRIMARY KEY (`unique_id`)
			) ENGINE=InnoDB DEFAULT COLLATE=utf8_general_ci;
		");
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "emerchantpay_checkout_consumers` (
			  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
			  `customer_email` varchar(255) NOT NULL,
			  `consumer_id` int(10) unsigned NOT NULL,
			  PRIMARY KEY (`id`),
			  UNIQUE KEY `customer_email` (`customer_email`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Tokenization consumers in Genesis';
		");
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "emerchantpay_checkout_cronlog` (
			  `log_entry_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `pid` INT(10) UNSIGNED NOT NULL,
			  `start_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  `run_time` VARCHAR(10) DEFAULT NULL,
			  PRIMARY KEY (`log_entry_id`)
			) ENGINE=InnoDB DEFAULT COLLATE=utf8_general_ci;
		");
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "emerchantpay_checkout_cronlog_transactions` (
			  `subscription_transaction_id` int(11) NOT NULL,
			  `order_id` INT(11) NOT NULL,
			  `log_entry_id` INT(10) UNSIGNED NOT NULL,
			  PRIMARY KEY (`subscription_transaction_id`),
			  KEY `order_id` (`order_id`),
			  KEY `log_entry_id` (`log_entry_id`)
			) ENGINE=InnoDB DEFAULT COLLATE=utf8_general_ci;
		");
	}

	/**
	 * Perform uninstall logic
	 *
	 * @return void
	 */
	public function uninstall(): void {
		// Keep transaction data
		//$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "emerchantpay_checkout_transactions`;");

		$this->load->model('setting/setting');

		$this->model_setting_setting->deleteSetting('emerchantpay_checkout');
	}

	/**
	 * Get saved transaction by id
	 *
	 * @param string $reference_id UniqueId of the transaction
	 *
	 * @return mixed bool on fail, row on success
	 */
	public function getTransactionById($reference_id): mixed {
		$query = $this->db->query("
			SELECT * FROM `" . DB_PREFIX . "emerchantpay_checkout_transactions`
			WHERE `unique_id` = '" . $this->db->escape($reference_id) . "' LIMIT 1
		");

		if ($query->num_rows) {
			return reset($query->rows);
		}

		return false;
	}

	/**
	 * Get the sum of the amount for a list of transaction types and status
	 *
	 * @param int $order_id
	 * @param string $reference_id
	 * @param array $types
	 * @param string $status
	 *
	 * @return float
	 */
	public function getTransactionsSumAmount($order_id, $reference_id, $types, $status): float {
		$transactions = $this->getTransactionsByTypeAndStatus($order_id, $reference_id, $types, $status);
		$total_amount = 0;

		if ($transactions) {
			/** @var $transaction */
			foreach ($transactions as $transaction) {
				$total_amount += $transaction['amount'];
			}
		}

		return $total_amount;
	}

	/**
	 * Get the detailed transactions list of an order for transaction types and status
	 *
	 * @param int $order_id
	 * @param string $reference_id
	 * @param array $transaction_types
	 * @param string $status
	 *
	 * @return array|false
	 */
	public function getTransactionsByTypeAndStatus($order_id, $reference_id, $transaction_types, $status): array|false {
		$query = $this->db->query("
			SELECT *
			FROM `" . DB_PREFIX . "emerchantpay_checkout_transactions` AS t
			WHERE (t.`order_id` = '" . abs(intval($order_id)) . "') AND " .
			(!empty($reference_id) ? " (t.`reference_id` = '" . $reference_id . "') AND " : "") . "
			(t.`type` in ('" . (is_array($transaction_types) ? implode("','", $transaction_types) : $transaction_types) . "')) AND
			(t.`status` = '" . $status . "')
		");

		if ($query->num_rows) {
			return $query->rows;
		}

		return false;
	}

	/**
	 * Get saved transactions by order id
	 *
	 * @param int $order_id OrderId
	 *
	 * @return mixed bool on fail, rows on success
	 */
	public function getTransactionsByOrder($order_id): mixed {
		$query = $this->db->query("
			SELECT * FROM `" . DB_PREFIX . "emerchantpay_checkout_transactions`
			WHERE `order_id` = '" . abs(intval($order_id)) . "'
		");

		if ($query->num_rows) {
			return $query->rows;
		}

		return false;
	}

	/**
	 * Send Capture transaction to the Gateway
	 *
	 * @param string $type
	 * @param string $reference_id ReferenceId
	 * @param string $amount Amount to be refunded
	 * @param string $currency Currency for the refunded amount
	 * @param string $usage Usage (optional text)
	 * @param int    $order_id
	 * @param string $token Terminal token of the initial transaction
	 *
	 * @return object|string
	 */
	public function capture($type, $reference_id, $amount, $currency, $usage, $order_id, $token = null): object|string {
		try {
			$this->bootstrap($token);

			$genesis = new Genesis(
				Types::getCaptureTransactionClass($type)
			);

			$genesis
				->request()
				->setTransactionId(
					$this->genTransactionId('ocart-')
				)
				->setRemoteIp(
					EmerchantpayHelper::getFirstRemoteAddress($this->request->server['REMOTE_ADDR'])
				)
				->setUsage($usage)
				->setReferenceId($reference_id)
				->setAmount($amount)
				->setCurrency($currency);

			if ($type === Types::INVOICE) {
				$genesis->request()->setItems($this->getInvoiceReferenceAttributes($currency, $order_id));
			}

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return $exception->getMessage();
		}
	}

	/**
	 * Send Refund transaction to the Gateway
	 *
	 * @param string $type Transaction Type
	 * @param string $reference_id ReferenceId
	 * @param string $amount Amount to be refunded
	 * @param string $currency Currency for the refunded amount
	 * @param string $usage Usage (optional text)
	 * @param string $token Terminal token of the initial transaction
	 * @param int    $order_id
	 *
	 * @return object|string
	 */
	public function refund($type, $reference_id, $amount, $currency, $usage = '', $token = null, $order_id = 0): object|string {
		try {
			$this->bootstrap($token);

			$genesis = new Genesis(
				Types::getRefundTransactionClass($type)
			);

			$genesis
				->request()
				->setTransactionId(
					$this->genTransactionId('ocart-')
				)
				->setRemoteIp(
					EmerchantpayHelper::getFirstRemoteAddress($this->request->server['REMOTE_ADDR'])
				)
				->setUsage($usage)
				->setReferenceId($reference_id)
				->setAmount($amount)
				->setCurrency($currency);

			if ($type === Types::INVOICE_CAPTURE) {
				$genesis->request()->setItems($this->getInvoiceReferenceAttributes($currency, $order_id));
			}

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return $exception->getMessage();
		}
	}

	/**
	 * Send Void transaction to the Gateway
	 *
	 * @param string $reference_id ReferenceId
	 * @param string $usage Usage (optional text)
	 * @param string $token Terminal token of the initial transaction
	 *
	 * @return object|string
	 */
	public function void($reference_id, $usage = '', $token = null): object|string {
		try {
			$this->bootstrap($token);

			$genesis = new Genesis('Financial\Void');

			$genesis
				->request()
				->setTransactionId(
					$this->genTransactionId('ocart-')
				)
				->setRemoteIp(
					EmerchantpayHelper::getFirstRemoteAddress($this->request->server['REMOTE_ADDR'])
				)
				->setUsage($usage)
				->setReferenceId($reference_id);

			$genesis->execute();

			return $genesis->response()->getResponseObject();
		} catch (\Exception $exception) {
			$this->logEx($exception);

			return $exception->getMessage();
		}
	}

	/**
	 * Get localized transaction types for Genesis
	 *
	 * @return array
	 */
	public function getTransactionTypes(): array {
		$data = array();

		$this->bootstrap();

		$this->load->language('extension/emerchantpay/payment/emerchantpay_checkout');

		$transaction_types = Types::getWPFTransactionTypes();
		$excluded_types    = EmerchantpayHelper::getRecurringTransactionTypes();

		// Exclude SDD Recurring
		array_push($excluded_types, Types::SDD_INIT_RECURRING_SALE);

		// Exclude PPRO transaction. This is not standalone transaction type
		array_push($excluded_types, Types::PPRO);

		// Exclude GooglePay transaction. In this way Google Pay Payment types will be introduced
		array_push($excluded_types, Types::GOOGLE_PAY);

		// Exclude PayPal transaction. In this way PayPal Payment types will be introduced
		array_push($excluded_types, Types::PAY_PAL);

		// Exclude Apple Pay transaction. This is not standalone transaction type
		array_push($excluded_types, Types::APPLE_PAY);

		// Exclude Transaction Types
		$transaction_types = array_diff($transaction_types, $excluded_types);

		// Add Google Payment types
		$google_pay_types = array_map(
			function ($type) {
				return EmerchantpayHelper::GOOGLE_PAY_TRANSACTION_PREFIX . $type;
			},
			[
				GooglePayPaymentTypes::AUTHORIZE,
				GooglePayPaymentTypes::SALE
			]
		);

		// Add PayPal Payment types
		$paypal_types = array_map(
			function ($type) {
				return EmerchantpayHelper::PAYPAL_TRANSACTION_PREFIX . $type;
			},
			[
				PayPalPaymentTypes::AUTHORIZE,
				PayPalPaymentTypes::SALE,
				PayPalPaymentTypes::EXPRESS
			]
		);

		// Add Apple Pay Payment types
		$apple_pay_types = array_map(
			function ($type) {
				return EmerchantpayHelper::APPLE_PAY_TRANSACTION_PREFIX . $type;
			},
			[
				ApplePayPaymentTypes::AUTHORIZE,
				ApplePayPaymentTypes::SALE
			]
		);

		$transaction_types = array_merge(
			$transaction_types,
			$google_pay_types,
			$paypal_types,
			$apple_pay_types
		);
		asort($transaction_types);

		foreach ($transaction_types as $type) {
			$name = $this->language->get('text_transaction_' . $type);

			if (strpos($name, 'text_transaction') !== false) {
				if (Types::isValidTransactionType($type)) {
					$name = Names::getName($type);
				} else {
					$name = strtoupper($type);
				}
			}

			$data[$type] = array(
				'id'   => $type,
				'name' => $name
			);
		}

		return $data;
	}

	/**
	 * Returns formatted array with available Bank codes
	 *
	 * @return array
	 */
	public function getBankCodes(): array {
		$data = [];
		$available_bank_codes = EmerchantpayHelper::getAvailableBankCodes();

		foreach ($available_bank_codes as $value => $label) {
			$data[] = [
				'id'   => $value,
				'name' => $label
			];
		}

		return $data;
	}

	/**
	 * Get localized recurring transaction types for Genesis
	 *
	 * @return array
	 */
	public function getRecurringTransactionTypes(): array {
		$data = [];

		$this->bootstrap();

		$this->load->language('extension/emerchantpay/payment/emerchantpay_checkout');

		$types = EmerchantpayHelper::getRecurringTransactionTypes();

		foreach ($types as $type) {
			$name = $this->language->get(EmerchantpayHelper::TRANSACTION_LANGUAGE_PREFIX . $type);

			if (strpos($name, EmerchantpayHelper::TRANSACTION_LANGUAGE_PREFIX) !== false) {
				$name = Names::getName($type);
			}

			$data[$type] = array(
				'id'   => $type,
				'name' => $name
			);
		}

		return $data;
		// TODO discuss this
//		return array(
//			Types::INIT_RECURRING_SALE    => array(
//				'id'   => Types::INIT_RECURRING_SALE,
//				'name' => $this->language->get(
//					EmerchantpayHelper::TRANSACTION_LANGUAGE_PREFIX .
//					Types::INIT_RECURRING_SALE
//				)
//			),
//			Types::INIT_RECURRING_SALE_3D => array(
//				'id'   => Types::INIT_RECURRING_SALE_3D,
//				'name' => $this->language->get(
//					EmerchantpayHelper::TRANSACTION_LANGUAGE_PREFIX .
//					Types::INIT_RECURRING_SALE_3D
//				)
//			),
//		);
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
	 * Proxy method for logEx
	 *
	 * @param \Exception $exception
	 *
	 * @return void
	 */
	public function logEx(\Exception $exception): void {
		$db_helper = new DbHelper($this->module_name, $this);
		$db_helper->logEx($exception);
	}

	/**
	 * Retrieves the Module Method Version
	 *
	 * @return string
	 */
	public function getVersion(): string {
		return $this->module_version;
	}

	/**
	 * Proxy method for populateTransaction
	 *
	 * @param $data
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	public function populateTransaction($data): void {
		$db_helper = new DbHelper($this->module_name, $this);
		$db_helper->populateTransaction($data);
	}

	/**
	 * @param $currency
	 * @param $order_id
	 *
	 * @return InvoiceItems
	 *
	 * @throws \Genesis\Exceptions\ErrorParameter
	 */
	protected function getInvoiceReferenceAttributes($currency, $order_id): InvoiceItems {
		$this->load->model('sale/order');

		$product_order_info = $this->model_sale_order->getOrderProducts($order_id);
		$order_totals = $this->model_sale_order->getOrderTotals($order_id);
		// TODO phpStorm tells me there is no such method $this->getProductsInfo()
		// maybe $this->model_extension_emerchantpay_payment_emerchantpay_checkout->getProductsTinfo
		$product_info = $this->getProductsInfo(
			array_map(
				function ($value) {
					return $value['product_id'];
				},
				$product_order_info
			)
		);

		return EmerchantpayHelper::getInvoiceCustomParamItems(
			array(
				'currency'   => $currency,
				'additional' => array(
					'product_order_info' => $product_order_info,
					'product_info'       => $product_info,
					'order_totals'       => $order_totals
				)
			)
		);
	}

	/**
	 * Bootstrap Genesis Library
	 *
	 * @param string|null $token Terminal token
	 *
	 * @return void
	 *
	 * @throws InvalidArgument
	 */
	protected function bootstrap(?string $token = null): void {
		parent::bootstrap();
		if (isset($token)) {
			Config::setToken((string)$token);
		}
	}
}
