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

use Genesis\API\Constants\Endpoints;
use Genesis\API\Constants\Environments;
use Genesis\API\Constants\Transaction\Names;
use Genesis\API\Constants\Transaction\Types;
use Genesis\Config;
use Genesis\Genesis;
use Opencart\Extension\Emerchantpay\System\DbHelper;
use Opencart\Extension\Emerchantpay\System\EmerchantpayHelper;
use Opencart\System\Engine\Model;

/**
 * Backend model for the "emerchantpay Direct" module
 *
 * @package EMerchantpayDirect
 */
class EmerchantpayDirect extends Model
{
	protected $module_name = "emerchantpay_direct";

	/**
	 * Holds the current module version
	 * Will be displayed on Admin Settings Form
	 *
	 * @var string
	 */
	protected $module_version = '1.1.3';

	/**
	 * Perform installation logic
	 *
	 * @return void
	 */
	public function install(): void
	{
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "emerchantpay_direct_transactions` (
			  `unique_id` VARCHAR(255) NOT NULL,
			  `reference_id` VARCHAR(255) NOT NULL,
			  `order_id` INT(11) NOT NULL,
			  `type` CHAR(32) NOT NULL,
			  `mode` CHAR(255) NOT NULL,
			  `timestamp` DATETIME NOT NULL,
			  `status` CHAR(32) NOT NULL,
			  `message` VARCHAR(255) NULL,
			  `technical_message` VARCHAR(255) NULL,
			  `amount` DECIMAL( 10, 2 ) DEFAULT NULL,
			  `currency` CHAR(3) NULL,
			  PRIMARY KEY (`unique_id`)
			) ENGINE=InnoDB DEFAULT COLLATE=utf8_general_ci;
		");
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "emerchantpay_direct_cronlog` (
			  `log_entry_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			  `pid` INT(10) UNSIGNED NOT NULL,
			  `start_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  `run_time` VARCHAR(10) DEFAULT NULL,
			  PRIMARY KEY (`log_entry_id`)
			) ENGINE=InnoDB DEFAULT COLLATE=utf8_general_ci;
		");
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "emerchantpay_direct_cronlog_transactions` (
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
	public function uninstall(): void
	{
		// Keep transaction data
		//$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "emerchantpay_direct_transactions`;");

		$this->load->model('setting/setting');

		$this->model_setting_setting->deleteSetting('emerchantpay_direct');
	}

	/**
	 * Get saved transaction by id
	 *
	 * @param string $reference_id UniqueId of the transaction
	 *
	 * @return mixed bool on fail, row on success
	 */
	public function getTransactionById($reference_id): mixed
	{
		$query = $this->db->query("
			SELECT * FROM `" . DB_PREFIX . "emerchantpay_direct_transactions`
			WHERE `unique_id` = '" . $this->db->escape($reference_id) . "' LIMIT 1
		");

		if ($query->num_rows) {
			return reset($query->rows);
		}

		return false;
	}

	/**
	 * Get the sum of the ammount for a list of transaction types and status
	 * @param int $order_id
	 * @param string $reference_id
	 * @param array $types
	 * @param string $status
	 * @return float
	 */
	public function getTransactionsSumAmount($order_id, $reference_id, $types, $status): float
	{
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

	public function getTransactionsByTypeAndStatus($order_id, $reference_id, $transaction_types, $status): array|false
	{
		$query = $this->db->query("
			SELECT *
			FROM `" . DB_PREFIX . "emerchantpay_direct_transactions` AS t
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
	public function getTransactionsByOrder($order_id): mixed
	{
		$query = $this->db->query("
			SELECT * FROM `" . DB_PREFIX . "emerchantpay_direct_transactions` 
			WHERE `order_id` = '" . intval($order_id) . "'
		");

		if ($query->num_rows) {
			return $query->rows;
		}

		return false;
	}

	/**
	 * Send Capture transaction to the Gateway
	 *
	 * @param string $reference_id ReferenceId
	 * @param string $amount Amount to be refunded
	 * @param string $currency Currency for the refunded amount
	 * @param string $usage Usage (optional text)
	 *
	 * @return object|string
	 */
	public function capture($type, $reference_id, $amount, $currency, $usage): object|string
	{
		try {
			$this->bootstrap();

			$genesis = new Genesis(
				Types::getCaptureTransactionClass($type)
			);

			$genesis
				->request()
				->setTransactionId(
					$this->genTransactionId('ocart-')
				)
				->setRemoteIp(
					$this->request->server['REMOTE_ADDR']
				)
				->setUsage($usage)
				->setReferenceId($reference_id)
				->setAmount($amount)
				->setCurrency($currency);

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
	 * @param string $reference_id ReferenceId
	 * @param string $amount Amount to be refunded
	 * @param string $currency Currency for the refunded amount
	 * @param string $usage Usage (optional text)
	 *
	 * @return object|string
	 */
	public function refund($type, $reference_id, $amount, $currency, $usage = ''): object|string
	{
		try {
			$this->bootstrap();

			$genesis = new Genesis(
				Types::getRefundTransactionClass($type)
			);

			$genesis
				->request()
				->setTransactionId(
					$this->genTransactionId('ocart-')
				)
				->setRemoteIp(
					$this->request->server['REMOTE_ADDR']
				)
				->setUsage($usage)
				->setReferenceId($reference_id)
				->setAmount($amount)
				->setCurrency($currency);

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
	 *
	 * @return object|string
	 */
	public function void($reference_id, $usage = ''): object|string
	{
		try {
			$this->bootstrap();

			$genesis = new Genesis('Financial\Void');

			$genesis
				->request()
				->setTransactionId(
					$this->genTransactionId('ocart-')
				)
				->setRemoteIp(
					$this->request->server['REMOTE_ADDR']
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
	public function getTransactionTypes(): array
	{
		return array(
			Types::AUTHORIZE    => array(
				'id'   => Types::AUTHORIZE,
				'name' => Names::getName(
					Types::AUTHORIZE
				)
			),
			Types::AUTHORIZE_3D => array(
				'id'   => Types::AUTHORIZE_3D,
				'name' => Names::getName(
					Types::AUTHORIZE_3D
				)
			),
			Types::SALE         => array(
				'id'   => Types::SALE,
				'name' => Names::getName(
					Types::SALE
				)
			),
			Types::SALE_3D      => array(
				'id'   => Types::SALE_3D,
				'name' => Names::getName(
					Types::SALE_3D
				)
			),
		);
	}

	/**
	 * Get localized recurring transaction types for Genesis
	 *
	 * @param string $module_name
	 *
	 * @return array
	 *
	 * @throws \Genesis\Exceptions\InvalidArgument
	 */
	public function getRecurringTransactionTypes(): array
	{
		// TODO: Should we move out this to a trait or another class to avoid duplicate code
		$data = array();

		$this->bootstrap();

		$this->load->language('extension/emerchantpay/payment/emerchantpay_direct');

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
	 * Bootstrap Genesis Library
	 *
	 * @return void
	 *
	 * @throws \Genesis\Exceptions\InvalidArgument
	 */
	public function bootstrap(): void
	{
		// Look for, but DO NOT try to load via Auto-loader magic methods
		if (class_exists('\Genesis\Genesis')) {

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
				($this->config->get('emerchantpay_direct_sandbox')) ? Environments::STAGING : Environments::PRODUCTION
			);
		}
	}

	/**
	 * Proxy method for logEx
	 *
	 * @param \Exception $exception
	 *
	 * @return void
	 */
	public function logEx(\Exception $exception): void
	{
		$db_helper = new DbHelper($this->module_name, $this);
		$db_helper->logEx($exception);
	}

	/**
	 * Retrieves the Module Method Version
	 *
	 * @return string
	 */
	public function getVersion(): string
	{
		return $this->module_version;
	}

	/**
	 * Proxy method for populateTransaction method
	 *
	 * @param $data
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	public function populateTransaction($data): void
	{
		$db_helper = new DbHelper($this->module_name, $this);
		$db_helper->populateTransaction($data);
	}
}
