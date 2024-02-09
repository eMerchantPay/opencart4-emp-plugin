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

namespace Opencart\Admin\Model\Extension\Emerchantpay\Payment\emerchantpay;

use Genesis\API\Constants\Transaction\Parameters\ScaExemptions;
use Opencart\System\Engine\Model;

/**
 * Common model methods for Direct and Checkout
 *
 * @package BaseModel
 */
class BaseModel extends Model
{
	/**
	 * Holds the current module version
	 * Will be displayed on Admin Settings Form
	 *
	 * @var string
	 */
	protected $module_version = '1.1.4';

	/**
	 * Returns formatted array with available SCA Exemptions
	 *
	 * @return array
	 */
	public function getScaExemptions(): array
	{
		$data           = [];
		$sca_exemptions = [
			ScaExemptions::EXEMPTION_LOW_RISK  => 'Low risk',
			ScaExemptions::EXEMPTION_LOW_VALUE => 'Low value',
		];

		foreach ($sca_exemptions as $value => $label) {
			$data[] = [
				'id'   => $value,
				'name' => $label
			];
		}

		return $data;
	}
}
