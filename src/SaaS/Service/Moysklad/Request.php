<?php

/**
 * PHP version 5.6
 *
 * @category ApiClient
 * @package  SaaS\Service\Moysklad
 * @author   Andrey Artahanov <azgalot9@gmail.com>
 * @license  http://opensource.org/licenses/MIT MIT License
 * @link     http://github.com/gwinn/saas-connector
 * @see      https://online.moysklad.ru/api/remap/1.1/doc/index.html
 */

namespace SaaS\Service\Moysklad;

use SaaS\Exception\CurlException;
use SaaS\Exception\MoySkladException;
use SaaS\Http\Response;

/**
 * MoyskladRequest
 *
 * @category ApiClient
 * @package  SaaS\Service\Moysklad
 * @author   Andrey Artahanov <azgalot9@gmail.com>
 * @license  http://opensource.org/licenses/MIT MIT License
 * @link     http://github.com/gwinn/saas-connector
 * @see      https://online.moysklad.ru/api/remap/1.1/doc/index.html
 */
class Request
{
    /**
     * URL from JsonAPI
     */
    const URL = 'https://online.moysklad.ru/api/remap/';

    /**
     * Version from JsonAPI
     */
    const VERSION = '1.1';

    /**
     * Methods
     */
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_DELETE = 'DELETE';

    /**
     * Filters
     */
    const FILTER_OPERANDS = array('=', '>', '<', '>=', '<=', '!=');

    /**
     * Restrictions
     */
    const MAX_DATA_VALUE = 10 * 1024 * 1024;

    /**
     * Login access to API
     * @var string
     * @access protected
     */
    protected $login;

    /**
     * Password access to API
     * @var string
     * @access protected
     */
    protected $password;

    /**
     * Curl timeout
     * @var integer
     * @access protected
     */
    protected $timeout = 60;

    /**
     * Curl retry
     * @var integer
     * @access protected
     */
    protected $retry;

    /**
     * Request constructor.
     *
     * @param string $login set of login access to API
     * @param string $password set of password access to API
     */
    public function __construct($login, $password)
    {
        $this->login = $login;
        $this->password = $password;
        $this->retry = 0;
    }

    /**
     * Execution of the request
     *
     * @param string $url
     * @param string $method
     * @param array $parameters
     *
     * @throws \InvalidArgumentException
     * @throws CurlException
     * @throws MoySkladException
     *
     * @access public
     *
     * @return Response
     */
    public function makeRequest($url, $method = 'GET', $parameters = array())
    {
        time_nanosleep(0, 250000000);

        $allowedMethods = array(self::METHOD_GET, self::METHOD_POST, self::METHOD_PUT, self::METHOD_DELETE);

        if (!in_array($method, $allowedMethods, false)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Method "%s" is not valid. Allowed methods are %s',
                    $method,
                    implode(', ', $allowedMethods)
                )
            );
        }

        $curlUrl = self::URL . self::VERSION . '/' . $url;

        if ($method === self::METHOD_GET && count($parameters)) {
            $curlUrl .= $this->httpBuildQuery($parameters);
        }

        $curlHandler = curl_init();
        curl_setopt($curlHandler, CURLOPT_USERPWD, "{$this->login}:{$this->password}");
        curl_setopt($curlHandler, CURLOPT_URL, $curlUrl);
        curl_setopt($curlHandler, CURLOPT_FAILONERROR, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandler, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($curlHandler, CURLOPT_CONNECTTIMEOUT, 60);

        if (!is_null($parameters) &&
            in_array($method, array(self::METHOD_POST, self::METHOD_PUT)) &&
            !empty($parameters['data'])
        ) {
            if (strlen(json_encode($parameters['data'])) > self::MAX_DATA_VALUE) {
                throw new MoySkladException(
                    sprintf(
                        'The POST data size should not exceed `%s` bytes',
                        self::MAX_DATA_VALUE
                    )
                );
            }
            curl_setopt($curlHandler, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json'
            ));
            curl_setopt($curlHandler, CURLOPT_POSTFIELDS, json_encode($parameters['data']));
            if ($method == self::METHOD_PUT) {
                curl_setopt($curlHandler, CURLOPT_CUSTOMREQUEST, $method);
            }
            if ($method == self::METHOD_POST) {
                curl_setopt($curlHandler, CURLOPT_POST, true);
            }
        }

        if (in_array($method, array(self::METHOD_DELETE))) {
            curl_setopt($curlHandler, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curlHandler, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json'
            ));
        }

        $responseBody = curl_exec($curlHandler);
        $statusCode = curl_getinfo($curlHandler, CURLINFO_HTTP_CODE);
        $errno = curl_errno($curlHandler);
        $error = curl_error($curlHandler);

        curl_close($curlHandler);

        if ($statusCode >= 400) {
            $result = json_decode($responseBody, true);
            throw new MoySkladException($this->getError($result), $statusCode);
        }

        if ($errno && in_array($errno, array(6, 7, 28, 34, 35)) && $this->retry < 3) {
            $errno = null;
            $error = null;
            $this->retry += 1;
            $this->makeRequest(
                $url,
                $method,
                $parameters
            );
        }

        if ($errno) {
            throw new CurlException($error, $errno);
        }

        return new Response($statusCode, $responseBody);
    }

    /**
     * Http build query.
     *
     * @param array $parameters
     * @return string
     * @access private
     */
    private function httpBuildQuery($parameters)
    {
        if (is_array($parameters)) {
            $params = array();
            $filter = '';
            $filters = array();
            foreach ($parameters as $name => $value) {
                if ($name == 'filters') {
                    if (!empty($value) & is_array($value)) {
                        $filter = $this->buildFilter($value) . '&';
                    }
                    continue;
                }
                $filters[$name] = $value;
            }
            unset($name, $value);
            $params = array_merge($params, $filters);
        }

        return '?' . (
                !empty($params) ?
                (http_build_query($params) . '&') :
                ''
            ) . trim($filter, '&');
    }

    /**
     * build filter.
     *
     * @param array $filter
     * @return string
     * @access private
     */
    private function buildFilter($filters)
    {
        $params = '';
        foreach ($filters as $filter) {
            if (!in_array($filter['operand'], self::FILTER_OPERANDS)) {
                continue;
            }
            $params .= $filter['name'] . $filter['operand'] . $filter['value'] . ';';
        }
        unset($filter);
        $params = trim($params, ';');

        return 'filter=' . $params;
    }

    /**
     * Get error.
     *
     * @param array
     * @return string
     * @access private
     */
    private function getError($result)
    {
        $error = "";
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                if (!empty($err['parameter'])) {
                    $error .= "[".date("Y-m-d H:i:s")."] Error ".$err['parameter'].": ".$err['error']."\n";
                } else {
                    $error .= "[".date("Y-m-d H:i:s")."] Error: ".$err['error']."\n";
                }
            }
        } else {
            $error = "[".date("Y-m-d H:i:s")."] Internal server error";
        }

        return $error;
    }
}
