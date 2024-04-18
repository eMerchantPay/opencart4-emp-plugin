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
 * @author	  emerchantpay
 * @copyright   2018 emerchantpay Ltd.
 * @license	 http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2 (GPL-2.0)
 */

namespace Opencart\Catalog\Controller\Extension\Emerchantpay\Payment;

use Genesis\API\Constants\Transaction\States;
use Genesis\API\Notification;
use Opencart\Extension\Emerchantpay\System\Catalog\BaseController;
use Exception;
use Opencart\System\Engine\Controller;

/**
 * Front-end controller for the "emerchantpay Checkout" module
 *
 * @package EMerchantPayCheckout
 */
class EmerchantpayCheckout extends BaseController
{

	/**
	 * Module Name
	 *
	 * @var string
	 */
	protected $module_name = 'emerchantpay_checkout';

	/**
	 * Init
	 *
	 * @param $registry
	 */
	public function __construct($registry) {
		parent::__construct($registry);
	}

	/**
	 * Entry-point
	 *
	 * @return mixed
	 */
	public function index(): mixed {
		$this->load->language('extension/emerchantpay/payment/emerchantpay_checkout');
		$this->load->model('extension/emerchantpay/payment/emerchantpay_checkout');
		$this->document->addStyle(HTTP_SERVER . '/extension/emerchantpay/catalog/view/stylesheet/emerchantpay/emerchantpay.css');

		if ($this->model_extension_emerchantpay_payment_emerchantpay_checkout->isCartContentMixed()) {
			$template = 'emerchantpay_disabled';
			$data = $this->prepareViewDataMixedCart();

		} else {
			$template = 'emerchantpay_checkout';

			$data = $this->prepareViewData();
		}

		return $this->load->view('extension/emerchantpay/payment/' . $template, $data);
	}

	/**
	 * Prepares data for the view
	 *
	 * @return array
	 */
	public function prepareViewData(): array {
		$data = array(
			'text_title'     => $this->language->get('text_title'),
			'text_loading'   => $this->language->get('text_loading'),

			'button_confirm' => $this->language->get('button_confirm'),
			'button_target'  => $this->buildUrl(
				'extension/emerchantpay/payment/emerchantpay_checkout',
				'send'
			),

			'scripts'        => $this->document->getScripts(),
			'styles'         => $this->document->getStyles()
		);

		return $data;
	}

	/**
	 * Process order confirmation
	 *
	 * @return void
	 */
	public function send(): void {
		$this->load->model('checkout/order');
		$this->load->model('account/order');
		$this->load->model('account/customer');
		$this->load->model('extension/emerchantpay/payment/emerchantpay_checkout');
		$this->load->language('extension/emerchantpay/payment/emerchantpay_checkout');

		if (!array_key_exists('order_id', $this->session->data)) {
			$this->respondWithError('Incorrect call!');

			return;
		}

		try {
			$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
			$product_order_info = $this->model_extension_emerchantpay_payment_emerchantpay_checkout
				->getDbOrderProducts($this->session->data['order_id']);
			$order_totals = $this->model_extension_emerchantpay_payment_emerchantpay_checkout
				->getOrderTotals($this->session->data['order_id']);
			$product_info = $this->model_extension_emerchantpay_payment_emerchantpay_checkout->getProductsInfo(
				array_map(
					function ($value) {
						return $value['product_id'];
					},
					$product_order_info
				)
			);

			$data = $this->populateTreedsParams($this, $product_info, $order_info);
			$data += $this->populateCommonData($this->model_extension_emerchantpay_payment_emerchantpay_checkout, $order_info);
			$data += [
				'language' => $this->model_extension_emerchantpay_payment_emerchantpay_checkout->getLanguage(),

				'additional' => array(
					'user_id'            => $this->model_extension_emerchantpay_payment_emerchantpay_checkout->getCurrentUserId(),
					'user_hash'          => $this->getCurrentUserIdHash($this),
					'product_order_info' => $product_order_info,
					'product_info'       => $product_info,
					'order_totals'       => $order_totals
				),
			];

			$data += $this->buildActionUrls($this->module_name);
			$data += [
				'return_cancel_url'  =>
					$this->buildUrl(
						'extension/emerchantpay/payment/emerchantpay_checkout',
						'cancel'
					),
			];

			$this->populateAddresses($order_info, $data);

			$transaction = $this->model_extension_emerchantpay_payment_emerchantpay_checkout->create($data);

			if (isset($transaction->unique_id)) {
				$this->prepareRedirect($transaction, $order_info);

				$json = array(
					'redirect' => $transaction->redirect_url
				);
			} else {
				$json = array(
					'error' => $this->language->get('text_payment_system_error')
				);
			}

			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));
		} catch (Exception $exception) {
			$this->respondWithError(($exception->getMessage()) ?: $this->language->get('text_payment_system_error'));
			$this->model_extension_emerchantpay_payment_emerchantpay_checkout->logEx($exception);
		}
	}

	/**
	 * Process Gateway Notification
	 *
	 * @return void
	 */
	public function callback(): void {
		$this->load->model('checkout/order');
		$this->load->model('extension/emerchantpay/payment/emerchantpay_checkout');

		$this->load->language('extension/emerchantpay/payment/emerchantpay_checkout');

		try {
			$this->model_extension_emerchantpay_payment_emerchantpay_checkout->bootstrap();

			$notification = new Notification(
				$this->request->post
			);

			if ($notification->isAuthentic()) {
				$notification->initReconciliation();

				$wpf_reconcile = $notification->getReconciliationObject();

				$timestamp = ($wpf_reconcile->timestamp instanceof \DateTime) ? $wpf_reconcile->timestamp->format('c') : $wpf_reconcile->timestamp;

				$data = array(
					'unique_id' => $wpf_reconcile->unique_id,
					'status'    => $wpf_reconcile->status,
					'currency'  => $wpf_reconcile->currency,
					'amount'    => $wpf_reconcile->amount,
					'timestamp' => $timestamp,
				);

				$this->model_extension_emerchantpay_payment_emerchantpay_checkout->populateTransaction($data);

				$transaction = $this->model_extension_emerchantpay_payment_emerchantpay_checkout->getTransactionById(
					$wpf_reconcile->unique_id
				);

				$reference = null;

				if (isset($transaction['order_id']) && abs((int)$transaction['order_id']) > 0) {
					if (isset($wpf_reconcile->payment_transaction)) {
						$payment_transaction = $this->getPaymentTransaction($wpf_reconcile);
						$timestamp = ($payment_transaction->timestamp instanceof \DateTime) ? $payment_transaction->timestamp->format('c') : $payment_transaction->timestamp;

						$data = array(
							'order_id'          => $transaction['order_id'],
							'reference_id'      => $wpf_reconcile->unique_id,
							'unique_id'         => $payment_transaction->unique_id,
							'type'              => $payment_transaction->transaction_type,
							'mode'              => $payment_transaction->mode,
							'status'            => $payment_transaction->status,
							'currency'          => $payment_transaction->currency,
							'amount'            => $payment_transaction->amount,
							'timestamp'         => $timestamp,
							'terminal_token'    => isset($payment_transaction->terminal_token) ? $payment_transaction->terminal_token : '',
							'message'           => isset($payment_transaction->message) ? $payment_transaction->message : '',
							'technical_message' => isset($payment_transaction->technical_message) ? $payment_transaction->technical_message : '',
						);

						$this->model_extension_emerchantpay_payment_emerchantpay_checkout->populateTransaction($data);

						if ($this->model_extension_emerchantpay_payment_emerchantpay_checkout->isInitialRecurringTransaction($payment_transaction->transaction_type)) {
							$reference = $payment_transaction->unique_id;
						}
					}

					switch ($wpf_reconcile->status) {
						case States::APPROVED:
							$this->model_checkout_order->addHistory(
								$transaction['order_id'],
								$this->config->get('emerchantpay_checkout_order_status_id'),
								$this->language->get('text_payment_status_successful'),
								true
							);
							break;
						case States::DECLINED:
						case States::ERROR:
							$this->model_checkout_order->addHistory(
								$transaction['order_id'],
								$this->config->get('emerchantpay_checkout_order_failure_status_id'),
								$this->language->get('text_payment_status_unsuccessful'),
								true
							);
							break;
					}
				}

				if ($this->model_extension_emerchantpay_payment_emerchantpay_checkout->isRecurringOrder()) {
					$this->model_extension_emerchantpay_payment_emerchantpay_checkout->populateRecurringTransaction($data);
					$this->model_extension_emerchantpay_payment_emerchantpay_checkout->updateOrderRecurring($data, $reference);
				}

				$this->response->addHeader('Content-Type: text/xml');
				$this->response->setOutput(
					$notification->generateResponse()
				);
			}
		} catch (Exception $exception) {
			$this->model_extension_emerchantpay_payment_emerchantpay_checkout->logEx($exception);
		}
	}

	/**
	 * Handle client redirection for successful status
	 *
	 * @return void
	 */
	public function success(): void {
		$this->response->redirect($this->buildUrl('checkout/success'));
	}

	/**
	 * Handle client redirection for failure status
	 *
	 * @return void
	 */
	public function failure(): void {
		$this->load->language('extension/emerchantpay/payment/emerchantpay_checkout');

		$this->session->data['error'] = $this->language->get('text_payment_failure');

		$this->response->redirect($this->buildUrl('checkout/checkout'));
	}

	/**
	 * Handle client redirection for cancelled status
	 *
	 * @return void
	 */
	public function cancel(): void {
		$this->load->language('extension/emerchantpay/payment/emerchantpay_checkout');

		$this->session->data['error'] = $this->language->get('text_payment_cancelled');

		$this->response->redirect($this->buildUrl('checkout/checkout'));
	}

	/**
	 * Redirect the user (to the login page), if they are not logged-in
	 *
	 * @return void
	 */
	protected function isUserLoggedIn(): void {
		$is_callback = strpos((string)$this->request->get['route'], 'callback') !== false;

		if (!$this->customer->isLogged() && !$is_callback) {
			$this->response->redirect($this->buildUrl('account/login'));
		}
	}

	/**
	 * Process the cron if the request is local
	 *
	 * @return void
	 */
	public function cron(): void {
		$this->load->model('extension/payment/emerchantpay_checkout');
		$this->model_extension_emerchantpay_payment_emerchantpay_checkout->processRecurringOrders();
	}

	/**
	 * Return logged on customer hash
	 *
	 * @param int $length
	 * @param Controller $controller
	 *
	 * @return string
	 */
	public function getCurrentUserIdHash($controller, $length = 30): string {
		$user_id = $this->getCustomerId($controller);

		$user_hash = ($user_id > 0) ? sha1($user_id) : $this->model_extension_emerchantpay_payment_emerchantpay_checkout->genTransactionId();

		return substr($user_hash, 0, $length);
	}

	/**
	 * Get the payment transaction or the first element if we have reference transaction
	 *
	 * @param \StdClass $wpf_reconcile
	 *
	 * @return \StdClass
	 */
	private function getPaymentTransaction($wpf_reconcile): \StdClass {
		if (!isset($wpf_reconcile->payment_transaction)) {
			return $wpf_reconcile;
		}

		if ($wpf_reconcile->payment_transaction instanceof \ArrayObject) {
			return $wpf_reconcile->payment_transaction[0];
		}

		return $wpf_reconcile->payment_transaction;
	}

	/**
	 * Prepare params for redirect to WPF
	 *
	 * @param $transaction
	 * @param $order_info
	 *
	 * @return void
	 */
	private function prepareRedirect($transaction, $order_info) {
		$model = $this->model_extension_emerchantpay_payment_emerchantpay_checkout;

		$data = $this->populateDataUniqIdTrx($transaction, $order_info);

		$model->populateTransaction($data);

		$this->model_checkout_order->addHistory(
			$this->session->data['order_id'],
			$this->config->get('emerchantpay_checkout_order_status_id'),
			$this->language->get('text_payment_status_initiated'),
			true
		);

		if ($model->isRecurringOrder()) {
			$this->addOrderRecurring(null, $model); // "checkout" transaction type
			$model->populateRecurringTransaction($data);
			$model->updateOrderRecurring($data);
		}
	}
}
