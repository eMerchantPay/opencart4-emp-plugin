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

use Genesis\Api\Constants\Transaction\Parameters\Threeds\V2\CardHolderAccount\RegistrationIndicators;
use Genesis\Api\Constants\Transaction\Parameters\Threeds\V2\MerchantRisk\DeliveryTimeframes;
use Genesis\Api\Constants\Transaction\Parameters\Threeds\V2\Purchase\Categories;
use Opencart\Catalog\Model\Extension\Emerchantpay\Payment\EmerchantpayCheckout as ModelEmerchantpayCheckout;
use Opencart\Extension\Emerchantpay\System\EmerchantpayHelper;
use Opencart\System\Engine\Controller;
use Opencart\System\Engine\Model;

/**
 * Base Abstract Class for Method Front Controllers
 *
 * Class BaseController
 *
 * @SuppressWarnings(PHPMD.LongVariable)
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
	protected function buildUrl($controller, $action = ''): string {
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
	protected function populateAddresses($order_info, &$data): void {
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

	/**
	 * Populate order data with 3DSv2 parameters
	 *
	 * @param Controller $controller
	 * @param array $product_info
	 * @param array $order_info
	 * @param $data
	 *
	 * @return array
	 */
	protected function populateTreedsParams($controller, $product_info, $order_info): array {
		$model_account_order             = $controller->model_account_order;
		$model_account_customer          = $controller->model_account_customer;

		$emerchantpay_threeds_helper     = new ThreedsHelper();

		/**
		 * Get all customer's orders
		 * Default limit is 20, and they are sorted in descending order
		 */
		$customer_orders                 = $emerchantpay_threeds_helper->getCustomerOrders(
			$controller->db,
			$this->getCustomerId($controller),
			(int)$controller->config->get('config_store_id'),
			(int)$controller->config->get('config_language_id'),
			ModelEmerchantpayCheckout::METHOD_CODE
		);

		$is_guest                        = !$controller->customer->isLogged();
		$has_physical_products           = $emerchantpay_threeds_helper->hasPhysicalProduct($product_info);
		$threeds_challenge_indicator     = $controller->config->get('emerchantpay_checkout_threeds_challenge_indicator');

		$threeds_purchase_category       = $emerchantpay_threeds_helper->hasPhysicalProduct($product_info) ? Categories::GOODS : Categories::SERVICE;
		$threeds_delivery_timeframe      = ($has_physical_products) ? DeliveryTimeframes::ANOTHER_DAY : DeliveryTimeframes::ELECTRONICS;
		$threeds_shipping_indicator      = $emerchantpay_threeds_helper->getShippingIndicator($has_physical_products, $order_info, $is_guest);
		$threeds_reorder_items_indicator = $emerchantpay_threeds_helper->getReorderItemsIndicator(
			$model_account_order,
			$is_guest, $product_info,
			$customer_orders
		);
		$threeds_registration_date       = null;
		$threeds_registration_indicator  = RegistrationIndicators::GUEST_CHECKOUT;

		if (!$is_guest) {
			$threeds_registration_date                = $emerchantpay_threeds_helper->findFirstCustomerOrderDate($customer_orders);
			$threeds_registration_indicator           = $emerchantpay_threeds_helper->getRegistrationIndicator($threeds_registration_date);
			$threeds_creation_date                    = $emerchantpay_threeds_helper->getCreationDate($model_account_customer, $order_info['customer_id']);

			$shipping_address_date_first_used         = $emerchantpay_threeds_helper->findShippingAddressDateFirstUsed(
				$model_account_order,
				$order_info,
				$customer_orders
			);
			$threads_shipping_address_date_first_used = $shipping_address_date_first_used;
			$threeds_shipping_address_usage_indicator = $emerchantpay_threeds_helper->getShippingAddressUsageIndicator($shipping_address_date_first_used);

			$orders_for_a_period                      = $emerchantpay_threeds_helper->findNumberOfOrdersForaPeriod(
				$model_account_order,
				$customer_orders
			);
			$transactions_activity_last_24_hours      = $orders_for_a_period['last_24h'];
			$transactions_activity_previous_year      = $orders_for_a_period['last_year'];
			$purchases_count_last_6_months            = $orders_for_a_period['last_6m'];
		}

		$data = [
			'is_guest'                        => $is_guest,
			'threeds_challenge_indicator'     => $threeds_challenge_indicator,
			'threeds_purchase_category'       => $threeds_purchase_category,
			'threeds_delivery_timeframe'      => $threeds_delivery_timeframe,
			'threeds_shipping_indicator'      => $threeds_shipping_indicator,
			'threeds_reorder_items_indicator' => $threeds_reorder_items_indicator,
			'threeds_registration_indicator'  => $threeds_registration_indicator,
			'threeds_registration_date'       => $threeds_registration_date,
			'sca_exemption_value'             => $controller->config->get($controller->module_name . '_sca_exemption'),
			'sca_exemption_amount'            => $controller->config->get($controller->module_name . '_sca_exemption_amount'),
		];

		if (!$is_guest) {
			$data['threeds_creation_date']                    = $threeds_creation_date;
			$data['threads_shipping_address_date_first_used'] = $threads_shipping_address_date_first_used;
			$data['threeds_shipping_address_usage_indicator'] = $threeds_shipping_address_usage_indicator;
			$data['transactions_activity_last_24_hours']      = $transactions_activity_last_24_hours;
			$data['transactions_activity_previous_year']      = $transactions_activity_previous_year;
			$data['purchases_count_last_6_months']            = $purchases_count_last_6_months;
		}

		return $data;
	}

	/**
	 * Return 0 if guest or customerId if customer is logged on
	 *
	 * @param $controller
	 *
	 * @return int
	 */
	protected function getCustomerId($controller): int {
		if ($controller->customer->isLogged()) {
			return $controller->customer->getId();
		}

		return 0;
	}

    /**
     * Create the return urls
     *
     * @param $module_name
     *
     * @return array
     */
	protected function buildActionUrls($module_name): array {
		return [
			'notification_url'   =>
				$this->buildUrl(
					'extension/emerchantpay/payment/' . $module_name,
					'callback'
				),
			'return_success_url' =>
				$this->buildUrl(
					'extension/emerchantpay/payment/' . $module_name,
					'success'
				),
			'return_failure_url' =>
				$this->buildUrl(
					'extension/emerchantpay/payment/' . $module_name,
					'failure'
				),
		];
	}

	/**
	 * Respond json error message
	 *
	 * @param $message
	 *
	 * @return void
	 */
	protected function respondWithError($message): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(['error' => $message]));
	}

	/**
	 * Return common request data
	 *
	 * @param Model $model
	 * @param $order_info
	 *
	 * @return array
	 */
	protected function populateCommonData($model, $order_info): array {
		return [
			'transaction_id'     => $model->genTransactionId(self::PLATFORM_TRANSACTION_PREFIX),

			'remote_address'     => EmerchantpayHelper::getFirstRemoteAddress($this->request->server['REMOTE_ADDR']),

			'usage'              => $model->getUsage(),
			'description'        => $model->getOrderProducts($this->session->data['order_id']),

			'currency'           => $model->getCurrencyCode(),
			'amount'             => (float)$order_info['total'],

			'customer_email'     => $order_info['email'],
			'customer_phone'     => $order_info['telephone'],
		];
	}

	/**
	 * Get $data values upon UniqId
	 *
	 * @param $transaction
	 * @param $order_info
	 *
	 * @return array
	 */
	protected function populateDataUniqIdTrx($transaction, $order_info): array {
		$timestamp = ($transaction->timestamp instanceof \DateTime) ? $transaction->timestamp->format('c') : $transaction->timestamp;

		return [
			'reference_id'      => '0',
			'order_id'          => $order_info['order_id'],
			'unique_id'         => $transaction->unique_id,
			'type'              => $transaction->transaction_type ?? 'checkout',
			'status'            => $transaction->status,
			'message'           => $transaction->message ?? '',
			'technical_message' => $transaction->technical_message ?? '',
			'amount'            => $transaction->amount,
			'currency'          => $transaction->currency,
			'timestamp'         => $timestamp,
		];
	}

	/**
	 * Prepares data for the view when cart content is mixed
	 *
	 * @return array
	 */
	protected function prepareViewDataMixedCart(): array {
		return [
			'text_loading'                    => $this->language->get('text_loading'),
			'text_payment_mixed_cart_content' => $this->language->get('text_payment_mixed_cart_content'),
			'button_shopping_cart'            => $this->language->get('button_shopping_cart'),
			'button_target'                   => $this->buildUrl('checkout/cart')
		];
	}

	/**
	 * Adds recurring order
	 *
	 * @param string $payment_reference
	 * @param Model $model
	 *
	 * @return void
	 */
	public function addOrderRecurring($payment_reference, $model): void {
		$recurring_products = $this->cart->getRecurringProducts();
		if (!empty($recurring_products)) {
			$model->addOrderRecurring(
				$recurring_products,
				$payment_reference
			);
		}
	}
}
