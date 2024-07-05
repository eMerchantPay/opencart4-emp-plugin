<?php
/*
 * Copyright (C) 2018-2024 emerchantpay Ltd.
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
 * @copyright   2018-2024 emerchantpay Ltd.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU General Public License, version 2 (GPL-2.0)
 */

namespace Opencart\Extension\Emerchantpay\System\Catalog;

if (!class_exists('Genesis\Genesis', false)) {
    require DIR_STORAGE . 'vendor/genesisgateway/genesis_php/vendor/autoload.php';
}

use Genesis\Api\Constants\Transaction\Parameters\ScaExemptions;
use Genesis\Api\Constants\Transaction\Parameters\Threeds\V2\Control\ChallengeIndicators;
use Opencart\System\Engine\Controller;

/**
 * Common settings helper class
 *
 * Class SettingsHelper
 */
class SettingsHelper
{
    /**
     * @var Controller
     */
    private $controller;

    /**
     * @param Controller $controller
     */
    public function __construct($controller) {
        $this->controller = $controller;
    }

    /**
     * @param string $module_name
     *
     * @return array
     */
    public function getBaseSettings($module_name) {
        return [
            "{$module_name}_username" => $this->getFieldValue("{$module_name}_username"),
            "{$module_name}_password" => $this->getFieldValue("{$module_name}_password"),
            "{$module_name}_token"    => $this->getFieldValue("{$module_name}_token"),
            "{$module_name}_sandbox"  => $this->getFieldValue("{$module_name}_sandbox"),
        ];

    }

    /**
     * @param string $module_name
     *
     * @return array
     */
    public function getModuleSettings($module_name) {
        return [
            "{$module_name}_transaction_type"            => $this->getFieldValue("{$module_name}_transaction_type"),
            "{$module_name}_wpf_tokenization"            => $this->getFieldValue("{$module_name}_wpf_tokenization"),
            "{$module_name}_total"                       => $this->getFieldValue("{$module_name}_total"),
            "{$module_name}_order_status_id"             => $this->getFieldValue("{$module_name}_order_status_id"),
            "{$module_name}_order_failure_status_id"     => $this->getFieldValue("{$module_name}_order_failure_status_id"),
            "{$module_name}_async_order_status_id"       => $this->getFieldValue("{$module_name}_async_order_status_id"),
            "{$module_name}_geo_zone_id"                 => $this->getFieldValue("{$module_name}_geo_zone_id"),
            "{$module_name}_status"                      => $this->getFieldValue("{$module_name}_status"),
            "{$module_name}_sort_order"                  => $this->getFieldValue("{$module_name}_sort_order"),
            "{$module_name}_debug"                       => $this->getFieldValue("{$module_name}_debug"),
            "{$module_name}_supports_partial_capture"    => $this->getFieldValue("{$module_name}_supports_partial_capture"),
            "{$module_name}_supports_partial_refund"     => $this->getFieldValue("{$module_name}_supports_partial_refund"),
            "{$module_name}_supports_void"               => $this->getFieldValue("{$module_name}_supports_void"),
            "{$module_name}_supports_recurring"          => $this->getFieldValue("{$module_name}_supports_recurring"),
            "{$module_name}_recurring_transaction_type"  => $this->getFieldValue("{$module_name}_recurring_transaction_type"),
            "{$module_name}_recurring_token"             => $this->getFieldValue("{$module_name}_recurring_token"),
            "{$module_name}_cron_allowed_ip"             => $this->getFieldValue("{$module_name}_cron_allowed_ip"),
            "{$module_name}_cron_time_limit"             => $this->getFieldValue("{$module_name}_cron_time_limit"),
            "{$module_name}_bank_codes"                  => $this->getFieldValue("{$module_name}_bank_codes"),
            "{$module_name}_threeds_allowed"             => $this->getFieldValue("{$module_name}_threeds_allowed"),
            "{$module_name}_threeds_challenge_indicator" => $this->getFieldValue("{$module_name}_threeds_challenge_indicator"),
            "{$module_name}_sca_exemption"               => $this->getFieldValue("{$module_name}_sca_exemption"),
            "{$module_name}_sca_exemption_amount"        => $this->getFieldValue("{$module_name}_sca_exemption_amount"),
        ];
    }

    /**
     * @param array $data
     * @param string $module_name
     *
     * @return mixed
     */
    public function setDefaultOptions($data, $module_name) {
        $default_param_values = array(
            "{$module_name}_sandbox"                     => 1,
            "{$module_name}_status"                      => 0,
            "{$module_name}_debug"                       => 1,
            "{$module_name}_supports_partial_capture"    => 1,
            "{$module_name}_supports_partial_refund"     => 1,
            "{$module_name}_supports_void"               => 1,
            "{$module_name}_supports_recurring"          => 0,
            "{$module_name}_cron_allowed_ip"             => $this->getServerAddress(),
            "{$module_name}_cron_time_limit"             => 25,
            "{$module_name}_threeds_allowed"             => 1,
            "{$module_name}_threeds_challenge_indicator" => ChallengeIndicators::NO_PREFERENCE,
            "{$module_name}_sca_exemption"               => ScaExemptions::EXEMPTION_LOW_RISK,
            "{$module_name}_sca_exemption_amount"        => 100,
        );

        foreach ($default_param_values as $key => $default_value)
            $data[$key] = $data[$key] ?? $default_value;

        return $data;
    }

    /**
     * @return string
     */
    private function getServerAddress(): string {
        $server_name = $this->controller->request->server['SERVER_NAME'];

        if (empty($server_name) || !function_exists('gethostbyname')) {
            return $this->controller->request->server['SERVER_ADDR'];
        }

        return gethostbyname($server_name);
    }

    /**
     * Check if there's a POST parameter or use the existing configuration value
     *
     * @param string $key
     *
     * @return mixed
     */
    public function getFieldValue($key): mixed {
        if (isset($this->controller->request->post[$key])) {
            return $this->controller->request->post[$key];
        }

        return $this->controller->config->get($key);
    }
}
