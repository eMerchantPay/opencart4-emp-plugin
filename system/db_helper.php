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

namespace Opencart\Extension\Emerchantpay\System;

use Opencart\System\Engine\Model;
use Opencart\System\Library\Log;

/**
 * Common database and logging operations
 *
 * Class DbHelper
 *
 * @package Opencart\Extension\Emerchantpay\System
 */
class DbHelper
{
	/**
	 * Holds module name
	 *
	 * @var string
	 */
	private string $module_name;

	private Model $model;

	public function __construct(string $module_name, Model $model) {
		$this->module_name = $module_name;
		$this->model = $model;
	}

	/**
	 * Sanitize transaction data and check
	 * whether an UPDATE or INSERT is required
	 *
	 * @param array $data
	 *
	 * @throws \Exception
	 *
	 * @return void
	 */
	public function populateTransaction($data): void {
		try {
			$data = $this->sanitizeData($data);

			// Check if transaction exists
			$insert_query = $this->model->db->query("
                SELECT * FROM `" . DB_PREFIX . $this->module_name . "_transactions`
                WHERE `unique_id` = '" . $data['unique_id'] . "'
            ");

			if ($insert_query->rows) {
				$this->updateTransaction($data);
			} else {
				$this->addTransaction($data);
			}
		} catch (\Exception $exception) {
			$this->logEx($exception);
		}
	}

	/**
	 * Log Exception to a log file, if enabled
	 *
	 * @param $exception
	 *
	 * @return void
	 */
	public function logEx($exception): void {
		if ($this->model->config->get($this->module_name . '_debug')) {
			$log = new Log($this->module_name . '.log');
			$log->write($this->jTraceEx($exception));
		}
	}

	/**
	 * Sanitize data before insert into DB
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	protected function sanitizeData($data): array {
		$self = $this->model;
		$result = array();

		array_walk($data, function ($value, $key) use ($self, &$result) {
			$result[$self->db->escape($key)] = $self->db->escape($value);
		});

		return $result;
	}

	/**
	 * Add transaction to the database
	 *
	 * @param $data array
	 *
	 * @return void
	 */
	protected function addTransaction($data): void {
		try {
			$fields = implode(', ', array_map(
					function ($value, $key) {
						return sprintf('`%s`', $key);
					},
					$data,
					array_keys($data)
				)
			);

			$values = implode(', ', array_map(
					function ($value) {
						return sprintf("'%s'", $value);
					},
					$data,
					array_keys($data)
				)
			);

			$this->model->db->query("
				INSERT INTO `" . DB_PREFIX . $this->module_name . "_transactions` (" . $fields . ")
				VALUES (" . $values . ")
			");
		} catch (\Exception $exception) {
			$this->logEx($exception);
		}
	}

	/**
	 * Update existing transaction in the database
	 *
	 * @param $data array
	 *
	 * @return void
	 */
	protected function updateTransaction($data): void {
		try {
			$fields = implode(', ', array_map(
					function ($value, $key) {
						return sprintf("`%s` = '%s'", $key, $value);
					},
					$data,
					array_keys($data)
				)
			);

			$this->model->db->query("
				UPDATE `" . DB_PREFIX . $this->module_name . "_transactions`
				SET " . $fields . "
				WHERE `unique_id` = '" . $data['unique_id'] . "'
			");
		} catch (\Exception $exception) {
			$this->logEx($exception);
		}
	}

	/**
	 * jTraceEx() - provide a Java style exception trace
	 *
	 * @param $exception \Exception
	 * @param $seen - array passed to recursive calls to accumulate trace lines already seen
	 *                     leave as NULL when calling this function
	 *
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD)
	 */
	private function jTraceEx($exception, $seen = null): string {
		$starter = ($seen) ? 'Caused by: ' : '';
		$result = array();

		if (!$seen) $seen = array();

		$trace = $exception->getTrace();
		$prev = $exception->getPrevious();

		$result[] = sprintf('%s%s: %s', $starter, get_class($exception), $exception->getMessage());

		$file = $exception->getFile();
		$line = $exception->getLine();

		while (true) {
			$current = "$file:$line";
			if (is_array($seen) && in_array($current, $seen)) {
				$result[] = sprintf(' ... %d more', count($trace) + 1);
				break;
			}
			$result[] = sprintf(' at %s%s%s(%s%s%s)',
				count($trace) && array_key_exists('class', $trace[0]) ? str_replace('\\', '.', $trace[0]['class']) : '',
				count($trace) && array_key_exists('class', $trace[0]) && array_key_exists('function', $trace[0]) ? '.' : '',
				count($trace) && array_key_exists('function', $trace[0]) ? str_replace('\\', '.', $trace[0]['function']) : '(main)',
				($line === null) ? $file : basename($file),
				($line === null) ? '' : ':',
				($line === null) ? '' : $line);
			if (is_array($seen))
				$seen[] = "$file:$line";
			if (!count($trace))
				break;
			$file = array_key_exists('file', $trace[0]) ? $trace[0]['file'] : 'Unknown Source';
			$line = (array_key_exists('file', $trace[0]) && array_key_exists('line', $trace[0]) && $trace[0]['line']) ? $trace[0]['line'] : null;
			array_shift($trace);
		}

		$result = join("\n", $result);

		if ($prev)
			$result .= "\n" . $this->jTraceEx($prev, $seen);

		return $result;
	}
}
