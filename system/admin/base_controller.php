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

namespace Opencart\Extension\Emerchantpay\System\Admin;

if (!class_exists('Genesis\Genesis', false)) {
	require DIR_STORAGE . 'vendor/genesisgateway/genesis_php/vendor/autoload.php';
}

use Exception;
use Genesis\API\Constants\Transaction\Parameters\ScaExemptions;
use Genesis\API\Constants\Transaction\States;
use Genesis\API\Constants\Transaction\Types;
use Opencart\Extension\Emerchantpay\System\Catalog\SettingsHelper;
use Opencart\Extension\Emerchantpay\System\Catalog\ThreedsHelper;
use Opencart\Extension\Emerchantpay\System\EmerchantpayHelper;
use Opencart\System\Engine\Controller;

/**
 * Base Abstract Class for Method Admin Controllers
 *
 * Class BaseController
 */
abstract class BaseController extends Controller
{
	/**
	 * OpenCart constants
	 * The complete set of constants is defined in the ModelExtensionPaymentEmerchantPayBase class
	 */
	const OC_REC_TXN_CANCELLED = 5;
	const OC_ORD_STATUS_REFUNDED = 11;

	/**
	 * Error storage
	 *
	 * @var array
	 */
	protected $error = array();

	/**
	 * Module Name (Used in View - Templates)
	 *
	 * @var string
	 */
	protected $module_name = null;

	/**
	 * Prefix for route (2.3.x -> 'extension/')
	 *
	 * @var null|string
	 */
	protected $route_prefix = "extension/emerchantpay/";

	/**
	 * A watch key list for monitoring the submit errors
	 *
	 * @var array
	 */
	protected $error_field_key_list = array(
		'warning',
		'username',
		'password',
		'transaction_type',
		'order_status',
		'order_async_status',
		'order_failure_status',
		'error_sca_exemption_amount',
	);

	/**
	 * Used to find out if the payment method requires SSL
	 *
	 * @return bool
	 */
	abstract protected function isModuleRequiresSsl(): bool;

	/**
	 * BaseController constructor.
	 * @param $registry
	 *
	 * @throws Exception
	 */
	public function __construct($registry)
	{
		parent::__construct($registry);

		if (is_null($this->module_name)) {
			throw new Exception('Module name not supplied in EMerchantPay controller');
		}
	}

	/**
	 * Entry-point
	 *
	 * @return mixed|void
	 */
	public function index()
	{
		if ($this->isInstallRequest()) {
			$this->install();

			return true;
		} elseif ($this->isUninstallRequest()) {
			$this->uninstall();

			return true;
		} elseif ($this->isOrderInfoRequest()) {
			return $this->orderAction();
		} else if ($this->isModuleSubActionRequest(['getModalForm', 'capture', 'refund', 'void'])) {
			$method = $this->request->get['action'];
			call_user_func(array($this, $method));

			return true;
		}

		$this->loadLanguage();
		$this->load->model('setting/setting');

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			$this->processPostIndexAction();
		} else {
			$this->processGetIndexAction();
		}
	}

	/**
	 * Get token param
	 *
	 * @return string
	 */
	public function getToken(): string
	{
		return $this->session->data['user_token'];
	}

	/**
	 * Get token param name
	 *
	 * @return string
	 */
	public function getTokenParam(): string
	{
		return 'user_token';
	}

	/**
	 * Get transactions list
	 *
	 * @return mixed
	 */
	public function order()
	{
		if ($this->config->get("{$this->module_name}_status")) {

			$this->loadLanguage();

			$this->loadPaymentMethodModel();

			// TODO do we need separate method for this?
			$this->addExternalResources(array(
				'treeGrid',
				'bootstrapValidator',
				'jQueryNumber',
				'commonStyleSheet'
			));

			$order_id = $this->request->get['order_id'];
			$transactions = $this->getModelInstance()->getTransactionsByOrder($this->request->get['order_id']);

			$has_currency_method = method_exists($this->currency, 'has');

			if ($transactions) {
				// Process individual fields
				foreach ($transactions as &$transaction) {
					/* OpenCart 2.2.x Fix (Cart\Currency does not check if the given currency code exists */
					if (($has_currency_method && $transaction['currency'] && $this->currency->has($transaction['currency'])) ||
						(!$has_currency_method && !empty($transaction['currency'])))
						$transaction['amount'] = $this->currency->format($transaction['amount'], $transaction['currency']);
					else /* No Currency Code is stored on Void Transaction */
						$transaction['amount'] = "";

					$transaction['timestamp'] = date('H:i:s m/d/Y', strtotime($transaction['timestamp']));
					$transaction['can_capture'] = $this->canCaptureTransaction($transaction);
					$transaction['can_refund'] = $this->canRefundTransaction($transaction);
					$transaction['can_void'] = $this->canVoidTransaction($transaction);
					$transaction['void_exists'] = $this->isVoidTransactionExist($order_id, $transaction);
				}

				// Sort the transactions list in the following order:
				//
				// 1. Sort by timestamp (date), i.e. most-recent transactions on top
				// 2. Sort by relations, i.e. every parent has the child nodes immediately after

				// Ascending Date/Timestamp sorting
				uasort($transactions, function ($element1, $element2) {
					$timestamp1 = $element1['timestamp'] ?? null;
					$timestamp2 = $element2['timestamp'] ?? null;

					return $timestamp1 <=> $timestamp2;
				});

				// Create the parent/child relations from a flat array
				$array_asc = array();

				foreach ($transactions as $key => $val) {
					// create an array with ids as keys and children
					// with the assumption that parents are created earlier.
					// store the original key
					if (isset($array_asc[$val['unique_id']])) {
						$array_asc[$val['unique_id']]['org_key'] = $key;

						$array_asc[$val['unique_id']] = array_merge($val, $array_asc[$val['unique_id']]);
					} else {
						$array_asc[$val['unique_id']] = array_merge($val, array('org_key' => $key));
					}

					if ($val['reference_id']) {
						$array_asc[$val['reference_id']]['children'][] = $val['unique_id'];
					}
				}

				// Order the parent/child entries
				$transactions = array();

				foreach ($array_asc as $val) {
					if (isset($val['reference_id']) && $val['reference_id']) {
						continue;
					}

					$this->sortTransactionByRelation($transactions, $val, $array_asc);
				}

				$data = array(
					'text_payment_info'          => $this->language->get('text_payment_info'),
					'text_transaction_id'        => $this->language->get('text_transaction_id'),
					'text_transaction_timestamp' => $this->language->get('text_transaction_timestamp'),
					'text_transaction_amount'    => $this->language->get('text_transaction_amount'),
					'text_transaction_status'    => $this->language->get('text_transaction_status'),
					'text_transaction_type'      => $this->language->get('text_transaction_type'),
					'text_transaction_message'   => $this->language->get('text_transaction_message'),
					'text_transaction_mode'      => $this->language->get('text_transaction_mode'),
					'text_transaction_action'    => $this->language->get('text_transaction_action'),

					'help_transaction_option_capture_partial_denied' => $this->language->get('help_transaction_option_capture_partial_denied'),
					'help_transaction_option_refund_partial_denied'  => $this->language->get('help_transaction_option_refund_partial_denied'),
					'help_transaction_option_cancel_denied'          => $this->language->get('help_transaction_option_cancel_denied'),

					"{$this->module_name}_supports_void"      => $this->config->get("{$this->module_name}_supports_void"),
					"{$this->module_name}_supports_recurring" => $this->config->get("{$this->module_name}_supports_recurring"),

					'order_id'     => $order_id,
					'token'        => $this->request->get[$this->getTokenParam()],
					'url_modal'    => htmlspecialchars_decode($this->getModalFormLink($this->getToken())),
					'module_name'  => $this->module_name,
					'currency'     => $this->getTemplateCurrencyArray(),
					'transactions' => $transactions,
				);

				return $this->load->view("../{$this->route_prefix}extension/payment/{$this->module_name}_order", $data);
			}
		}

		return false;
	}

	/**
	 * Get transaction's modal form
	 *
	 * @return void
	 */
	public function getModalForm(): void
	{
		if (isset($this->request->post['reference_id']) && isset($this->request->post['type'])) {
			$this->loadLanguage();
			$this->loadPaymentMethodModel();

			$reference_id = $this->request->post['reference_id'];
			$type = $this->request->post['type'];
			$order_id = $this->request->post['order_id'];

			$transaction = $this->getModelInstance()->getTransactionById($reference_id);

			if ($type == 'capture') {
				$total_authorized_amount = $this->getModelInstance()->getTransactionsSumAmount(
					$order_id,
					$transaction['reference_id'],
					array(
						Types::AUTHORIZE,
						Types::AUTHORIZE_3D,
						Types::GOOGLE_PAY,
						Types::PAY_PAL,
						Types::APPLE_PAY,
					),
					States::APPROVED
				);
				$total_captured_amount = $this->getModelInstance()->getTransactionsSumAmount($order_id, $transaction['unique_id'], 'capture', 'approved');
				$transaction['available_amount'] = $total_authorized_amount - $total_captured_amount;
				$text_title = $this->language->get('text_modal_title_capture');
				$text_button_proceed = $this->language->get('text_button_capture_partial');
			} else if ($type == 'refund') {
				$has_void_transaction = $this->getModelInstance()->getTransactionsByTypeAndStatus($order_id, $transaction['unique_id'], 'void', 'approved');
				if (!$has_void_transaction) {
					$total_captured_amount = $transaction['amount'];
					$total_refunded_amount = $this->getModelInstance()->getTransactionsSumAmount($order_id, $transaction['unique_id'], 'refund', 'approved');
					$transaction['available_amount'] = $total_captured_amount - $total_refunded_amount;
				} else {
					$transaction['available_amount'] = 0;
				}
				$text_title = $this->language->get('text_modal_title_refund');
				$text_button_proceed = $this->language->get('text_button_refund_partial');
			} else if ($type == 'void') {
				$transaction['is_allowed'] = $this->getModelInstance()->getTransactionsByTypeAndStatus($order_id, $transaction['unique_id'], 'void', 'approved') == false;
				$text_title = $this->language->get('text_modal_title_void');
				$text_button_proceed = $this->language->get('text_button_void');
			}

			$url_action = $this->url->link(
				"{$this->route_prefix}payment/{$this->module_name}", "action={$type}&{$this->getTokenParam()}={$this->getToken()}", true
			);

			$data = array(
				'type'        => $type,
				'transaction' => $transaction,
				'currency'    => $this->getTemplateCurrencyArray(),
				'url_action'  => $url_action,
				'module_name' => $this->module_name,

				'text_button_close'   => $this->language->get('text_button_close'),
				'text_title'          => $text_title,
				'text_button_proceed' => $text_button_proceed,

				'help_transaction_option_capture_partial_denied' => $this->language->get('help_transaction_option_capture_partial_denied'),
				'help_transaction_option_refund_partial_denied'  => $this->language->get('help_transaction_option_refund_partial_denied'),
				'help_transaction_option_cancel_denied'          => $this->language->get('help_transaction_option_cancel_denied'),

				"{$this->module_name}_supports_partial_capture" => $this->config->get("{$this->module_name}_supports_partial_capture"),
				"{$this->module_name}_supports_partial_refund"  => $this->config->get("{$this->module_name}_supports_partial_refund"),
				"{$this->module_name}_supports_void"            => $this->config->get("{$this->module_name}_supports_void"),
				"{$this->module_name}_supports_recurring"       => $this->config->get("{$this->module_name}_supports_recurring")
			);

			$this->response->setOutput($this->load->view("../{$this->route_prefix}extension/payment/{$this->module_name}_order_modal", $data));
		}
	}

	/**
	 * Perform a Capture transaction
	 *
	 * @return void
	 */
	public function capture(): void
	{
		$this->loadLanguage();

		if (isset($this->request->post['reference_id']) && trim($this->request->post['reference_id']) != '') {
			$this->loadPaymentMethodModel();

			$transaction = $this->getModelInstance()->getTransactionById($this->request->post['reference_id']);

			$terminal_token =
				array_key_exists('terminal_token', $transaction) ? $transaction['terminal_token'] : null;

			if (isset($transaction['order_id']) && abs((int)$transaction['order_id']) > 0) {
				$amount = $this->request->post['amount'];
				$message = isset($this->request->post['message']) ? $this->request->post['message'] : '';
				$capture = $this->getModelInstance()->capture(
					$transaction['type'],
					$transaction['unique_id'],
					$amount,
					$transaction['currency'],
					empty($message) ? 'Capture Opencart Transaction' : $message,
					$transaction['order_id'],
					$terminal_token
				);

				if (isset($capture->unique_id)) {
					$timestamp = ($capture->timestamp instanceof \DateTime) ? $capture->timestamp->format('c') : $capture->timestamp;

					$data = array(
						'order_id'          => $transaction['order_id'],
						'reference_id'      => $transaction['unique_id'],
						'unique_id'         => $capture->unique_id,
						'type'              => $capture->transaction_type,
						'status'            => $capture->status,
						'amount'            => $capture->amount,
						'currency'          => $capture->currency,
						'timestamp'         => $timestamp,
						'message'           => isset($capture->message) ? $capture->message : '',
						'technical_message' => isset($capture->technical_message) ? $capture->technical_message : '',
					);

					if (array_key_exists('terminal_token', $transaction)) {
						$data['terminal_token'] = $transaction['terminal_token'];
					} elseif (isset($capture->terminal_token)) {
						$data['terminal_token'] = $capture->terminal_token;
					}

					$this->getModelInstance()->populateTransaction($data);

					$json = array(
						'error' => false,
						'text'  => isset($capture->message) ? $capture->message : $this->language->get('text_response_success')
					);
				} else {
					$json = array(
						'error' => true,
						'text'  => isset($capture->message) ? $capture->message : $this->language->get('text_response_failure')
					);
				}
			} else {
				$json = array(
					'error' => true,
					'text'  => $this->language->get('text_invalid_reference_id'),
				);
			}
		} else {
			$json = array(
				'error' => true,
				'text'  => $this->language->get('text_invalid_request')
			);
		}

		if (isset($json['error']) && $json['error']) {
			$this->response->addHeader('HTTP/1.0 500 Internal Server Error');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Perform a Refund transaction
	 *
	 * @return void
	 */
	public function refund(): void
	{
		$this->loadLanguage();

		if (isset($this->request->post['reference_id']) && trim($this->request->post['reference_id']) != '') {
			$this->loadPaymentMethodModel();

			$transaction    = $this->getModelInstance()->getTransactionById($this->request->post['reference_id']);
			$terminal_token = array_key_exists('terminal_token', $transaction) ? $transaction['terminal_token'] : null;

			if (isset($transaction['order_id']) && intval($transaction['order_id']) > 0) {
				$amount = $this->request->post['amount'];
				$message = isset($this->request->post['message']) ? $this->request->post['message'] : '';
				$refund = $this->getModelInstance()->refund(
					$transaction['type'],
					$transaction['unique_id'],
					$amount,
					$transaction['currency'],
					empty($message) ? 'Refund Opencart Transaction' : $message,
					$terminal_token,
					$transaction['order_id']
				);

				if (isset($refund->unique_id)) {
					$timestamp = ($refund->timestamp instanceof \DateTime) ? $refund->timestamp->format('c') : $refund->timestamp;
					$data      = array(
						'order_id'          => $transaction['order_id'],
						'reference_id'      => $transaction['unique_id'],
						'unique_id'         => $refund->unique_id,
						'type'              => $refund->transaction_type,
						'status'            => $refund->status,
						'amount'            => $refund->amount,
						'currency'          => $refund->currency,
						'timestamp'         => $timestamp,
						'message'           => $refund->message ?? '',
						'technical_message' => $refund->technical_message ?? '',
					);

					if (array_key_exists('terminal_token', $transaction)) {
						$data['terminal_token'] = $transaction['terminal_token'];
					} elseif (isset($refund->terminal_token)) {
						$data['terminal_token'] = $refund->terminal_token;
					}

					$this->getModelInstance()->populateTransaction($data);

					if ($this->isInitialRecurringTransaction($transaction['type'])) {
						$total_captured_amount = $transaction['amount'];
						$total_refunded_amount = $this->getModelInstance()->getTransactionsSumAmount($transaction['order_id'], $transaction['unique_id'], 'refund', 'approved');
						if ($total_captured_amount == $total_refunded_amount) {//is fully refunded?
							$this->cancelOrderRecurring($transaction);

							// Create 'Cancelled' recurring order transaction with the total refunded amount
							$oc_txn_type = self::OC_REC_TXN_CANCELLED;
							$data['amount'] = $total_refunded_amount;
							$this->addRecurringTransaction($data, $oc_txn_type);

							// Update order status to 'Refunded'
							$order_status_id = self::OC_ORD_STATUS_REFUNDED;
							$this->updateOrder(
								$transaction['order_id'],
								$order_status_id,
								$this->language->get('text_recurring_fully_refunded'),
								false
							);
						}
					}

					$json = array(
						'error' => false,
						'text'  => isset($refund->message) ? $refund->message : $this->language->get('text_response_success')
					);
				} else {
					$json = array(
						'error' => true,
						'text'  => isset($refund->message) ? $refund->message : $this->language->get('text_response_failure')
					);
				}
			} else {
				$json = array(
					'error' => true,
					'text'  => $this->language->get('text_invalid_reference_id'),
				);
			}
		} else {
			$json = array(
				'error' => true,
				'text'  => $this->language->get('text_invalid_request')
			);
		}

		if (isset($json['error']) && $json['error']) {
			$this->response->addHeader('HTTP/1.0 500 Internal Server Error');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Perform a Void transaction
	 *
	 * @return void
	 */
	public function void(): void
	{
		$this->loadLanguage();

		if (isset($this->request->post['reference_id']) && trim($this->request->post['reference_id']) != '') {
			$this->loadPaymentMethodModel();

			$transaction = $this->getModelInstance()->getTransactionById($this->request->post['reference_id']);

			$terminal_token =
				array_key_exists('terminal_token', $transaction) ? $transaction['terminal_token'] : null;

			if (isset($transaction['order_id']) && abs((int)$transaction['order_id']) > 0) {
				$message = isset($this->request->post['message']) ? $this->request->post['message'] : '';

				$void = $this->getModelInstance()->void(
					$transaction['unique_id'],
					empty($message) ? 'Void Opencart Transaction' : $message,
					$terminal_token
				);

				if (isset($void->unique_id)) {
					$timestamp = ($void->timestamp instanceof \DateTime) ? $void->timestamp->format('c') : $void->timestamp;

					$data = array(
						'order_id'          => $transaction['order_id'],
						'reference_id'      => $transaction['unique_id'],
						'unique_id'         => $void->unique_id,
						'type'              => $void->transaction_type,
						'status'            => $void->status,
						'timestamp'         => $timestamp,
						'message'           => isset($void->message) ? $void->message : '',
						'technical_message' => isset($void->technical_message) ? $void->technical_message : '',
					);

					if (array_key_exists('terminal_token', $transaction)) {
						$data['terminal_token'] = $transaction['terminal_token'];
					} elseif (isset($void->terminal_token)) {
						$data['terminal_token'] = $void->terminal_token;
					}

					$this->getModelInstance()->populateTransaction($data);

					$json = array(
						'error' => false,
						'text'  => isset($void->message) ? $void->message : $this->language->get('text_response_success')
					);
				} else {
					$json = array(
						'error' => true,
						'text'  => isset($void->message) ? $void->message : $this->language->get('text_response_failure')
					);
				}
			} else {
				$json = array(
					'error' => true,
					'text'  => $this->language->get('text_invalid_reference_id'),
				);
			}
		} else {
			$json = array(
				'error' => true,
				'text'  => $this->language->get('text_invalid_request')
			);
		}

		// Add 500 header to trigger jQuery's AJAX Error handling
		if (isset($json['error']) && $json['error']) {
			$this->response->addHeader('HTTP/1.0 500 Internal Server Error');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Add/Install Module Handling
	 *
	 * @return void
	 */
	public function install(): void
	{
		$this->loadPaymentMethodModel();
		$this->getModelInstance()->install();
	}

	/**
	 * Remove/Uninstall Module Handling
	 *
	 * @return void
	 */
	public function uninstall(): void
	{
		$this->loadPaymentMethodModel();
		$this->getModelInstance()->uninstall();
	}

	/**
	 * Cancels order recurring
	 * @param array $data
	 * @return bool
	 */
	public function cancelOrderRecurring($data): bool
	{
		$recurring_status = 3; //Cancelled

		$order_recurring_id = $this->getOrderRecurringId($data['order_id']);

		$this->db->query("UPDATE " . DB_PREFIX . "order_recurring SET status = '" . (int)$recurring_status . "'" . " WHERE order_recurring_id = '" . (int)$order_recurring_id . "'");

		return ($this->db->countAffected() > 0);
	}

	/**
	 * Gets order recurring id by order id
	 * @param string $order_id
	 * @return string
	 */
	public function getOrderRecurringId($order_id): string
	{
		$query = $this->db->query("SELECT order_recurring_id FROM `" . DB_PREFIX . "order_recurring` WHERE order_id = '" . (int)$order_id . "'");

		return $query->row['order_recurring_id'];
	}

	/**
	 * Is transaction INIT_RECURRING_SALE or INIT_RECURRING_SALE_3D?
	 *
	 * @param const $transaction_type
	 *
	 * @return bool
	 */
	public function isInitialRecurringTransaction($transaction_type): bool
	{
		return in_array($transaction_type, array(
			Types::INIT_RECURRING_SALE,
			Types::INIT_RECURRING_SALE_3D
		));
	}

	/**
	 * Adds recurring transaction to the DB table order_recurring_transaction
	 *
	 * @param array $data
	 * @param int $oc_txn_type
	 *
	 * @return bool
	 */
	public function addRecurringTransaction($data, $oc_txn_type): bool
	{
		$result = false;

		if (!array_key_exists('order_recurring_id', $data)) {
			$data['order_recurring_id'] = $this->getOrderRecurringId($data['order_id']);
		}
		if (!empty($data['order_recurring_id'])) {
			$result = $this->db->query("INSERT INTO `" . DB_PREFIX . "order_recurring_transaction` SET `order_recurring_id` = '" . (int)$data['order_recurring_id'] . "', `reference` = '" . $data['unique_id'] . "', `type` = '" . $oc_txn_type . "', `amount` = '" . $data['amount'] . "', `date_added` = NOW()");
		}

		return $result;
	}

	/**
	 * Updates the order and adds it to the order history
	 *
	 * @param string $order_id
	 * @param string $order_status_id
	 * @param string $comment
	 * @param bool $notify
	 *
	 * @return bool
	 */
	public function updateOrder($order_id, $order_status_id, $comment = '', $notify = false): bool
	{
		$result = $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . (int)$order_status_id . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");
		if ($result) {
			$result = $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$order_id . "', order_status_id = '" . (int)$order_status_id . "', notify = '" . (int)$notify . "', comment = '" . $this->db->escape($comment) . "', date_added = NOW()");
		}

		return $result;
	}

	/**
	 * Can Capture Transaction
	 *
	 * @param array $transaction
	 *
	 * @return bool
	 */
	public function canCaptureTransaction($transaction): bool
	{
		if (!$this->hasApprovedState($transaction['status'])) {
			return false;
		}

		if ($this->isTransactionWithCustomAttribute($transaction['type'])) {
			return $this->checkReferenceActionByCustomAttr(
				EmerchantpayHelper::REFERENCE_ACTION_CAPTURE,
				$transaction['type']
			);
		}

		return Types::canCapture($transaction['type']);
	}

	/**
	 * Can Refund Transaction
	 *
	 * @param array $transaction
	 *
	 * @return bool
	 */
	public function canRefundTransaction($transaction): bool
	{
		if (!$this->hasApprovedState($transaction['status'])) {
			return false;
		}

		if ($this->isTransactionWithCustomAttribute($transaction['type'])) {
			return $this->checkReferenceActionByCustomAttr(
				EmerchantpayHelper::REFERENCE_ACTION_REFUND,
				$transaction['type']
			);
		}

		return Types::canRefund($transaction['type']);
	}

	/**
	 * Can Void Transaction
	 *
	 * @param array $transaction
	 *
	 * @return bool
	 */
	public function canVoidTransaction($transaction): bool
	{
		return Types::canVoid($transaction['type']) &&
			$this->hasApprovedState($transaction['status']);
	}

	/**
	 * Is approved Void transaction exist
	 *
	 * @param string $order_id
	 * @param array $transaction
	 *
	 * @return bool
	 */
	public function isVoidTransactionExist($order_id, $transaction): bool
	{
		return $this->getModelInstance()->getTransactionsByTypeAndStatus(
				$order_id,
				$transaction['unique_id'],
				Types::VOID,
				States::APPROVED
			) !== false;
	}

	/**
	 * Gets the content for generating the recurring log table
	 *
	 * @return array two-dimensional array where the first element is the index of the log/transaction entry
	 * and the second one is an array containing the following elements used in the respective templates:
	 *
	 * log_entry_id
	 * ref_log_entry_id
	 * order_id
	 * order_link
	 * order_link_title
	 * date
	 * amount
	 * order_recurring_id
	 * order_recurring_btn_link
	 * order_recurring_btn_title
	 * status
	 */
	public function getRecurringLog(): array
	{
		$result = array();

		$query = $this->db->query('SELECT *, ort.`date_added` as `transaction_date` FROM `' . DB_PREFIX . $this->module_name . '_cronlog` '
			. 'JOIN `' . DB_PREFIX . $this->module_name . '_cronlog_transactions` as plugin_log USING(`log_entry_id`) '
			. 'JOIN `' . DB_PREFIX . 'subscription` as ort ON `ort`.subscription_id = `plugin_log`.subscription_transaction_id '
			. 'JOIN `' . DB_PREFIX . 'order` ON `plugin_log`.order_id = `' . DB_PREFIX . 'order`.order_id '
			. 'ORDER BY `log_entry_id` DESC,`subscription_transaction_id` DESC ');

		if ($query->num_rows) {
			$log_entry_line = null;
			$report_line = 0;
			$tmp = array();
			foreach ($query->rows as $row) {
				if (!empty($row['order_recurring_id'])) {
					if (is_null($log_entry_line) || ($row['log_entry_id'] !== $tmp[$log_entry_line]['log_entry_id'])) {
						$log_entry_line = $report_line++;

						$tmp[$log_entry_line] = array(
							'log_entry_id'       => $row['log_entry_id'],
							'ref_log_entry_id'   => '',
							'order_id'           => $row['order_id'],
							'date'               => $row['start_time'],
							'amount'             => 0,
							'currency_code'      => $row['currency_code'],
							'order_recurring_id' => 0,
							'status'             => $this->getLogEntryStatus($row['run_time'], $row['pid']),
						);
					}

					$tmp[$report_line++] = array(
						'log_entry_id'       => $row['reference'],
						'ref_log_entry_id'   => $row['log_entry_id'],
						'order_id'           => $row['order_id'],
						'date'               => $row['transaction_date'],
						'amount'             => $row['amount'],
						'currency_code'      => $row['currency_code'],
						'order_recurring_id' => $row['order_recurring_id'],
						'status'             => $this->getRecurringTransactionType((int)$row['type']),
					);

					$tmp[$log_entry_line]['amount'] += $row['amount'];
					$tmp[$log_entry_line]['order_recurring_id']++;
				}
			}

			foreach ($tmp as $row) {
				$log_entry = array(
					'log_entry_id'              => $row['log_entry_id'],
					'ref_log_entry_id'          => $row['ref_log_entry_id'],
					'order_id'                  => '',
					'order_link'                => '',
					'order_link_title'          => '',
					'date'                      => $row['date'],
					'amount'                    => $this->currency->format($row['amount'], $row['currency_code']),
					'order_recurring_id'        => '',
					'order_recurring_btn_link'  => '',
					'order_recurring_btn_title' => '',
					'status'                    => $row['status']
				);

				if (empty($row['ref_log_entry_id'])) {// Log entry summary
					$log_entry['order_recurring_id'] = sprintf(
						$this->language->get('order_recurring_total'),
						$row['order_recurring_id']
					);
				} else {// Transaction entry
					$log_entry['order_id'] = $row['order_id'];
					$log_entry['order_link'] = $this->url->link(
						'sale/order/info',
						$this->getTokenParam() . '=' . $this->getToken() . '&order_id=' . $row['order_id'],
						true
					);
					$log_entry['order_link_title'] = sprintf(
						$this->language->get('order_link_title'),
						$row['order_id']
					);

					$log_entry['order_recurring_btn_link'] = $this->url->link(
						'sale/recurring/info',
						$this->getTokenParam() . '=' . $this->getToken() . '&order_recurring_id=' . $row['order_recurring_id'],
						true
					);
					$log_entry['order_recurring_btn_title'] = sprintf(
						$this->language->get('order_recurring_btn_title'),
						$row['order_recurring_id']
					);
				}
				$result[] = $log_entry;
			}
		}

		return $result;
	}

	/**
	 * Determines if the controller is loaded from the Backend Order Info Action
	 *
	 * @return bool
	 */
	protected function isOrderInfoRequest(): bool
	{
		return
			($this->request->server['REQUEST_METHOD'] == 'GET') &&
			($this->request->get['route'] == 'sale/order/info');
	}

	/**
	 * Determines if the controller is called from the Extension Module to install the Payment Module
	 *
	 * @return bool
	 */
	protected function isInstallRequest(): bool
	{
		return
			($this->request->server['REQUEST_METHOD'] == 'GET') &&
			($this->request->get['route'] == 'extension/extension/payment/install');
	}

	/**
	 * Determines if the controller is called from the Extension Module to uninstall the Payment Module
	 *
	 * @return bool
	 */
	protected function isUninstallRequest(): bool
	{
		return
			($this->request->server['REQUEST_METHOD'] == 'GET') &&
			($this->request->get['route'] == 'extension/extension/payment/uninstall');
	}

	/**
	 * Determines if the controller is loaded from the Backend Order Panel
	 *   - Displaying Backend Transaction Popup Dialog for Capture, Refund and Void
	 *
	 * @param array $actions
	 *
	 * @return bool
	 */
	protected function isModuleSubActionRequest(array $actions): bool
	{
		return
			($this->request->server['REQUEST_METHOD'] == 'POST') &&
			($this->request->get['route'] == "{$this->route_prefix}payment/{$this->module_name}") &&
			array_key_exists('action', $this->request->get) &&
			in_array($this->request->get['action'], $actions);
	}

	/**
	 * Loads the language Model in the controller
	 *
	 * @return void
	 */
	protected function loadLanguage(): void
	{
		$this->load->language("{$this->route_prefix}payment/{$this->module_name}");
	}

	/**
	 * Loads the Payment Method Model in the controller
	 *
	 * @return void
	 */
	protected function loadPaymentMethodModel(): void
	{
		$this->load->model("{$this->route_prefix}payment/{$this->module_name}");
	}

	/**
	 * Retrieves an instance of the backend method model
	 *
	 * @return object
	 */
	protected function getModelInstance(): object
	{
		$method = "model_extension_emerchantpay_payment_{$this->module_name}";

		return $this->{$method};
	}

	/**
	 * Processes HTTP POST Index action
	 *
	 * @return void
	 */
	protected function processPostIndexAction(): void
	{
		try {
			if ($this->validate()) {
				$this->model_setting_setting->editSetting($this->module_name, $this->request->post);

				// As from 3.x they changed settings name in db.
				// Save status and sort_order settigns in new format. Other settings are not used from opencart core
				$settings = array(
					"payment_{$this->module_name}_status"     => $this->request->post["{$this->module_name}_status"],
					"payment_{$this->module_name}_sort_order" => $this->request->post["{$this->module_name}_sort_order"]
				);
				$this->model_setting_setting->editSetting("payment_{$this->module_name}", $settings);

				$json = array(
					'success' => 1,
					'text'    => $this->language->get('text_success'),
				);
			} else {
				$error_message = "";

				foreach ($this->error_field_key_list as $error_field_key)
					if (isset($this->error[$error_field_key]))
						$error_message .= sprintf("<li>%s</li>", $this->error[$error_field_key]);

				$error_message = sprintf("<ul>%s</ul>", $error_message);

				$json = array(
					'success' => 0,
					'text'    => $error_message
				);
			}

			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));
		} catch (Exception $e) {
			$this->response->addHeader('HTTP/1.0 500 Internal Server Error');
		}
	}

	/**
	 * Processes HTTP GET Index action
	 *
	 * @return void
	 */
	protected function processGetIndexAction(): void
	{
		// TODO do we need separate method for this?
		$this->addExternalResources(array(
			'treeGrid',
			'bootstrapValidator',
			'commonStyleSheet'
		));

		if ($this->isModuleRequiresSsl() && !EmerchantpayHelper::isSecureConnection($this->request)) {
			$this->error['warning'] = $this->language->get('error_https');
		}

		$heading_title = $this->language->get('heading_title');
		$this->document->setTitle($heading_title);
		$this->load->model('localisation/geo_zone');
		$this->load->model('localisation/order_status');
		$this->loadPaymentMethodModel();

		$threedshelper        = new ThreedsHelper();
		$challenge_indicators = $threedshelper->getThreedsChallengeIndicators();

		$data = $this->buildLanguagePhrases();

		$data += array(
			'module_version'                                  => $this->getModelInstance()->getVersion(),
			'geo_zones'                                       => $this->model_localisation_geo_zone->getGeoZones(),
			'order_statuses'                                  => $this->model_localisation_order_status->getOrderStatuses(),
			'transaction_types'                               => $this->getModelInstance()->getTransactionTypes(),
			'recurring_transaction_types'                     => $this->getModelInstance()->getRecurringTransactionTypes(),
			'error_warning'                                   => isset($this->error['warning']) ? $this->error['warning'] : '',
			'enable_recurring_tab'                            => true,

			'action'      => $this->url->link("{$this->route_prefix}payment/{$this->module_name}", $this->getTokenParam() . '=' . $this->getToken(), true),
			// TODO I'm not sure if this is used somewhere
			'cancel'      => $this->getPaymentLink($this->getToken()),
			'header'      => $this->load->controller('common/header'),
			'column_left' => $this->load->controller('common/column_left'),
			'footer'      => $this->load->controller('common/footer'),

			'recurring_log_entries'      => $this->getRecurringLog(),
			'cron_last_execution'        => $this->getLastCronExecTime(),
			'cron_last_execution_status' => $this->getCronExecStatus(),

			'module_name'                  => $this->module_name,
			'threeds_challenge_indicators' => $challenge_indicators,
			'sca_exemptions'               => $this->getModelInstance()->getScaExemptions()
		);

		$settings = new SettingsHelper($this);

		$data = array_merge($data, $settings->getBaseSettings($this->module_name));
		$data = array_merge($data, $settings->getModuleSettings($this->module_name));

		if ($this->module_name == 'emerchantpay_checkout') {
			$data += [
				'bank_codes' => $this->getModelInstance()->getBankCodes(),
			];
		}

		$data = $settings->setDefaultOptions($data, $this->module_name);

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', $this->getTokenParam() . '=' . $this->getToken(), true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_payment'),
			'href' => $this->getPaymentLink($this->getToken())
		);

		$data['breadcrumbs'][] = array(
			'text' => $heading_title,
			'href' => $this->url->link("{$this->route_prefix}payment/{$this->module_name}", $this->getTokenParam() . '=' . $this->getToken(), true)
		);

		$data['back'] = $this->getPaymentLink($this->getToken());

		$this->response->setOutput(
			$this->load->view("../{$this->route_prefix}extension/payment/{$this->module_name}", $data)
		);
	}

	/**
	 * Builds an array with the language phrases used on the templates
	 *
	 * @return array
	 */
	protected function buildLanguagePhrases(): array
	{
		$result = array();

		$phrases = array(
			'heading_title',
			'tab_general',
			'tab_recurring',
			'text_edit',
			'text_enabled',
			'text_disabled',
			'text_all_zones',
			'text_yes',
			'text_no',
			'text_success',
			'text_failed',
			'text_select_status',

			'text_log_entry_id',
			'text_log_order_id',
			'text_log_date_time',
			'text_log_status_completed',
			'text_log_rebilled_amount',
			'text_log_recurring_order_id',
			'text_log_status',
			'text_log_btn_show',
			'text_log_btn_hide',

			'entry_username',
			'entry_password',
			'entry_token',
			'entry_sandbox',
			'entry_transaction_type',
			'entry_recurring_transaction_type',
			'entry_recurring_log',
			'entry_recurring_token',
			'entry_cron_time_limit',
			'entry_cron_allowed_ip',
			'entry_cron_last_execution',
			'entry_bank_codes',
			'entry_threeds_allowed',
			'entry_threeds_challenge_indicator',
			'entry_sca_exemption',
			'entry_sca_exemption_value',

			'entry_order_status',
			'entry_async_order_status',
			'entry_order_status_failure',
			'entry_total',
			'entry_geo_zone',
			'entry_status',
			'entry_debug',
			'entry_sort_order',
			'entry_supports_partial_capture',
			'entry_supports_partial_refund',
			'entry_supports_void',
			'entry_supports_recurring',

			'help_sandbox',
			'help_total',
			'help_order_status',
			'help_async_order_status',
			'help_failure_order_status',
			'help_supports_partial_capture',
			'help_supports_partial_refund',
			'help_supports_void',
			'help_supports_recurring',
			'help_recurring_transaction_types',
			'help_recurring_log',
			'help_cron_time_limit',
			'help_cron_allowed_ip',
			'help_cron_last_execution',
			'help_threeds_allowed',
			'help_threeds_challenge_indicator',
			'help_sca_exemption',
			'help_sca_exemption_value',

			'button_save',
			'button_cancel',

			'error_username',
			'error_password',
			'error_token',
			'error_transaction_type',
			'error_controls_invalidated',
			'error_order_status',
			'error_order_failure_status',
			'error_async_order_status',

			'alert_disable_recurring',
		);

		foreach ($phrases as $phrase) {
			$result[$phrase] = $this->language->get($phrase);
		}

		return $result;
	}

	/**
	 * Ensure that the current user has permissions to see/modify this module
	 *
	 * @return bool
	 */
	protected function validate(): bool
	{
		$this->validateRequiredFields();

		if (!$this->user->hasPermission('modify', "{$this->route_prefix}payment/{$this->module_name}")) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if ((float)$this->request->post["{$this->module_name}_sca_exemption_amount"] < 0) {
			$this->error['error_sca_exemption_amount'] = $this->language->get('error_sca_exemption_amount');
		}

		return !$this->error;
	}

	/**
	 * Check if the current visitor is logged in and has permission to access
	 * this page
	 *
	 * @return void
	 */
	protected function isUserLoggedInAndAuthorized(): void
	{
		$is_logged_in = $this->user->isLogged();

		$has_access = $this->user->hasPermission('access', "{$this->route_prefix}payment/{$this->module_name}");

		if (!$is_logged_in || !$has_access) {
			$this->response->redirect(
				$this->url->link('account/login', 'language=' . $this->config->get('config_language'))
			);
		}
	}

	/**
	 * Recursive function used in the process of sorting
	 * the Transactions list
	 *
	 * @param $array_out array
	 * @param $val array
	 * @param $array_asc array
	 *
	 * @return void
	 */
	protected function sortTransactionByRelation(&$array_out, $val, $array_asc): void
	{
		if (isset($val['org_key'])) {
			$array_out[$val['org_key']] = $val;

			if (isset($val['children']) && sizeof($val['children'])) {
				foreach ($val['children'] as $id) {
					$this->sortTransactionByRelation($array_out, $array_asc[$id], $array_asc);
				}
			}

			unset($array_out[$val['org_key']]['children'], $array_out[$val['org_key']]['org_key']);
		}
	}

	/**
	 * Get current Currency Code
	 *
	 * @return string
	 */
	protected function getCurrencyCode(): string
	{
		return $this->session->data['currency'] ?? $this->config->get('config_currency');
	}

	/**
	 * Creates an array from a currency code, in order to be given to the template
	 *
	 * @param string $currency_code
	 *
	 * @return array
	 */
	protected function getTemplateCurrencyArray($currency_code = null): array
	{
		if (empty($currency_code))
			$currency_code = $this->getCurrencyCode();

		$this->load->model('localisation/currency');
		$currency = $this->model_localisation_currency->getCurrencyByCode($currency_code);

		$currency_symbol = ($currency['symbol_left']) ? $currency['symbol_left'] : $currency['symbol_right'];
		if (empty($currency_symbol))
			$currency_symbol = $currency['code'];

		return array(
			'iso_code'          => $currency['code'],
			'sign'              => $currency_symbol,
			'decimalPlaces'     => 2,
			'decimalSeparator'  => '.',
			'thousandSeparator' => '' /* must be empty, otherwise exception could be thrown from Genesis */
		);
	}

	/**
	 * Add External Resources (JS & CSS)
	 *
	 * @param $resource_names array
	 *
	 * @return bool
	 */
	protected function addExternalResources($resource_names): bool
	{
		$resources_loaded = (bool)count($resource_names) > 0;

		foreach ($resource_names as $resource_name)
			$resources_loaded = $this->addExternalResource($resource_name) && $resources_loaded;

		return $resources_loaded;
	}

	/**
	 * Add External Resource (JS & CSS)
	 *
	 * @param $resource_name string
	 *
	 * @return bool
	 */
	protected function addExternalResource($resource_name): bool
	{
		// TODO: I suggest to load all resources at once and conditionally only jQueryNumber
		$resource_loaded = true;

		if ($resource_name == 'treeGrid') {
			$this->document->addStyle(HTTP_CATALOG . $this->route_prefix . 'admin/view/javascript/emerchantpay/treegrid/css/jquery.treegrid.css');
			$this->document->addScript(HTTP_CATALOG . $this->route_prefix . 'admin/view/javascript/emerchantpay/treegrid/js/jquery.treegrid.js');
			$this->document->addScript(HTTP_CATALOG . $this->route_prefix . 'admin/view/javascript/emerchantpay/treegrid/js/jquery.treegrid.bootstrap3.js');
		} else if ($resource_name == 'bootstrapValidator') {
			$this->document->addStyle(HTTP_CATALOG . $this->route_prefix . 'admin/view/javascript/emerchantpay/bootstrap/css/bootstrapValidator.min.css');
			$this->document->addScript(HTTP_CATALOG . $this->route_prefix . 'admin/view/javascript/emerchantpay/bootstrap/js/bootstrapValidator.min.js');
		} else if ($resource_name == 'jQueryNumber') {
			$this->document->addScript(HTTP_CATALOG . $this->route_prefix . 'admin/view/javascript/emerchantpay/jQueryExtensions/js/jquery.number.min.js');
		} else if ($resource_name == 'commonStyleSheet') {
			$this->document->addStyle(HTTP_CATALOG . $this->route_prefix . 'admin/view/stylesheet/emerchantpay/emerchantpay-admin.css');
		} else
			$resource_loaded = false;

		return $resource_loaded;
	}

	/**
	 * Gets the last execution time of the cron
	 *
	 * @return string
	 */
	protected function getLastCronExecTime(): string
	{
		$result = $this->language->get('alert_cron_not_run_yet');

		$query = $this->db->query('SELECT `start_time` FROM `' . DB_PREFIX . $this->module_name . '_cronlog` ORDER BY `log_entry_id` DESC LIMIT 1');

		if ($query->num_rows == 1) {
			$data = array_pop($query->rows);
			$result = $data['start_time'];
		}

		return $result;
	}

	/**
	 * Gets the cron execution status used in the styles in the templates
	 *
	 * @return string
	 */
	protected function getCronExecStatus(): string
	{
		$result = 'danger';

		$time_diff = (microtime(true) - strtotime($this->getLastCronExecTime()));

		if ($time_diff < 3600) {// 1 hour
			$result = 'success';
		} elseif ($time_diff < 12 * 3600) {// 12 hours
			$result = 'warning';
		}

		return $result;
	}

	/**
	 * Gets the Log Entry Status
	 *
	 * @param string $run_time
	 * @param string $pid
	 *
	 * @return string
	 */
	protected function getLogEntryStatus($run_time, $pid): string
	{
		$status = null;

		if (is_null($run_time)) {
			if (posix_getpgid($pid) == $pid) {
				$status = sprintf($this->language->get('text_log_status_running'), $pid);
			} else {
				$status = $this->language->get('text_log_status_terminated');
			}
		} else {
			$status = sprintf($this->language->get('text_log_status_completed'), $run_time);
		}

		return $status;
	}

	/**
	 * Gets recurring transaction type
	 *
	 * @param int $type_id
	 *
	 * @return array
	 */
	protected function getRecurringTransactionType($type_id): array
	{
		$result = '';

		$this->load->language('sale/recurring');

		$transaction_types = array(
			0 => 'text_transaction_date_added',
			1 => 'text_transaction_payment',
			2 => 'text_transaction_outstanding_payment',
			3 => 'text_transaction_skipped',
			4 => 'text_transaction_failed',
			5 => 'text_transaction_cancelled',
			6 => 'text_transaction_suspended',
			7 => 'text_transaction_suspended_failed',
			8 => 'text_transaction_outstanding_failed',
			9 => 'text_transaction_expired'
		);

		if (array_key_exists($type_id, $transaction_types)) {
			$result = $this->language->get($transaction_types[$type_id]);
		}

		return $result;
	}

	/**
	 * Gets a modal form link
	 *
	 * @param string $token
	 *
	 * @return string
	 */
	protected function getModalFormLink($token): string
	{
		$link_parameters = [
			'route'  => "{$this->route_prefix}payment/{$this->module_name}",
			'args'   => 'action=getModalForm&user_token=' . $token,
			'secure' => 'SSL'
		];

		return $this->getLink($link_parameters);
	}

	/**
	 * Gets a payment link
	 *
	 * @param string $token
	 *
	 * @return string
	 */
	protected function getPaymentLink($token)
	{
		$link = [
			'route'  => 'marketplace/extension',
			'args'   => 'type=payment&user_token=' . $token,
			'secure' => 'SSL'
		];

		return $this->getLink($link);
	}

	/**
	 * Creates a link based on the link parameters and OpenCart version
	 *
	 * @param array $link_parameters
	 *
	 * @return string
	 */
	protected function getLink($link_parameters): string
	{
		return $this->url->link(
			$link_parameters['route'],
			$link_parameters['args'],
			true
		);
	}

	/**
	 * Determine if Google Pay, PayPal ot Apple Pay Method is chosen inside the Payment settings
	 *
	 * @param string $transaction_type GooglePay or PayPal Method
	 *
	 * @return bool
	 */
	protected function isTransactionWithCustomAttribute($transaction_type): bool
	{
		$transaction_types = [
			Types::GOOGLE_PAY,
			Types::PAY_PAL,
			Types::APPLE_PAY,
		];

		return in_array($transaction_type, $transaction_types);
	}

	/**
	 * Check if canCapture
	 *
	 * @param $action
	 * @param $transaction_type
	 *
	 * @return bool
	 */
	protected function checkReferenceActionByCustomAttr($action, $transaction_type): bool
	{
		$selected_types = $this->config->get("{$this->module_name}_transaction_type");

		if (!is_array($selected_types)) {
			return false;
		}

		switch ($transaction_type) {
			case Types::GOOGLE_PAY:
				if (EmerchantpayHelper::REFERENCE_ACTION_CAPTURE === $action) {
					return in_array(
						EmerchantpayHelper::GOOGLE_PAY_TRANSACTION_PREFIX .
						EmerchantpayHelper::GOOGLE_PAY_PAYMENT_TYPE_AUTHORIZE,
						$selected_types
					);
				}

				if (EmerchantpayHelper::REFERENCE_ACTION_REFUND === $action) {
					return in_array(
						EmerchantpayHelper::GOOGLE_PAY_TRANSACTION_PREFIX .
						EmerchantpayHelper::GOOGLE_PAY_PAYMENT_TYPE_SALE,
						$selected_types
					);
				}
				break;
			case Types::PAY_PAL:
				if (EmerchantpayHelper::REFERENCE_ACTION_CAPTURE === $action) {
					return in_array(
						EmerchantpayHelper::PAYPAL_TRANSACTION_PREFIX .
						EmerchantpayHelper::PAYPAL_PAYMENT_TYPE_AUTHORIZE,
						$selected_types
					);
				}

				if (EmerchantpayHelper::REFERENCE_ACTION_REFUND === $action) {
					$refundable_types = [
						EmerchantpayHelper::PAYPAL_TRANSACTION_PREFIX .
						EmerchantpayHelper::PAYPAL_PAYMENT_TYPE_SALE,
						EmerchantpayHelper::PAYPAL_TRANSACTION_PREFIX .
						EmerchantpayHelper::PAYPAL_PAYMENT_TYPE_EXPRESS
					];

					return (count(array_intersect($refundable_types, $selected_types)) > 0);
				}
				break;
			case Types::APPLE_PAY:
				if (EmerchantpayHelper::REFERENCE_ACTION_CAPTURE === $action) {
					return in_array(
						EmerchantpayHelper::APPLE_PAY_TRANSACTION_PREFIX .
						EmerchantpayHelper::APPLE_PAY_PAYMENT_TYPE_AUTHORIZE,
						$selected_types
					);
				}

				if (EmerchantpayHelper::REFERENCE_ACTION_REFUND === $action) {
					return in_array(
						EmerchantpayHelper::APPLE_PAY_TRANSACTION_PREFIX .
						EmerchantpayHelper::APPLE_PAY_PAYMENT_TYPE_SALE,
						$selected_types
					);
				}
				break;
			default:
				return false;
		} // end Switch
	}

	/**
	 * Check if the Genesis Transaction state is APPROVED
	 *
	 * @param $transaction_type
	 *
	 * @return bool
	 */
	protected function hasApprovedState($transaction_type): bool
	{
		if (empty($transaction_type)) {
			return false;
		}

		$state = new States($transaction_type);

		return $state->isApproved();
	}

	/**
	 * Check if any of the required fields is empty
	 *
	 * @return void
	 */
	private function validateRequiredFields(): void
	{
		$required_fields = [
			"{$this->module_name}_username"                => 'username',
			"{$this->module_name}_password"                => 'password',
			"{$this->module_name}_transaction_type"        => 'transaction_type',
			"{$this->module_name}_order_status_id"         => 'order_status',
			"{$this->module_name}_order_failure_status_id" => 'order_failure_status',
		];

		if ($this->module_name === 'emerchantpay_direct') {
			$required_fields["{$this->module_name}_async_order_status_id"] = 'order_async_status';
		}

		foreach ($required_fields as $field => $error_key) {
			if (empty($this->request->post[$field])) {
				$this->error[$error_key] = $this->language->get("error_$error_key");
			}
		}
	}
}
