<?php

/**
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NON-INFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @author      emerchantpay
 * @copyright   Copyright (C) 2015-2025 emerchantpay Ltd.
 * @license     http://opensource.org/licenses/MIT The MIT License
 */

namespace Genesis\Network;

use Genesis\Builder;
use Genesis\Exceptions\InvalidArgument;

/**
 * Class Base
 * @package Genesis\Network
 */
abstract class Base
{
    /**
     * Storing the full incoming response
     *
     * @var string
     */
    protected $response;

    /**
     * Storing body from an incoming response
     *
     * @var string
     */
    protected $responseBody;

    /**
     * Storing headers from an incoming response
     *
     * @var string
     */
    protected $responseHeaders;

    /**
     * Get Body/Headers from an incoming response
     *
     * @return mixed
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Get Headers from an incoming response
     *
     * @return mixed
     */
    public function getResponseHeaders()
    {
        return $this->responseHeaders;
    }

    /**
     * Get Body from an incoming response
     *
     * @return mixed
     */
    public function getResponseBody()
    {
        return $this->responseBody;
    }

    /**
     * @param $requestDataFormat
     *
     * @return string
     * @throws InvalidArgument
     */
    protected function getRequestContentType($requestDataFormat)
    {
        switch ($requestDataFormat) {
            case Builder::XML:
                return 'text/xml';
            case Builder::JSON:
                return 'application/json';
            case Builder::FORM:
                return 'application/x-www-form-urlencoded';
            default:
                throw new InvalidArgument('Invalid request format type. Allowed are XML and JSON.');
        }
    }

    /**
     * Get HTTP Status code
     *
     * @return mixed
     */
    abstract public function getStatus();

    /**
     * Set the request parameters
     *
     * @param $requestData
     *
     * @return mixed
     */
    abstract public function prepareRequestBody($requestData);

    /**
     * Execute pre-set request
     *
     * @return mixed
     */
    abstract public function execute();

    /**
     * @return mixed
     */
    abstract protected function authorization($requestData);
}
