<?php

namespace RetailCrm\Helpers;

use RetailCrm\ApiClient;
use RetailCrm\Component\Utils;
use RetailCrm\Exception\CurlException;

/**
 * Class ApiHelper
 * @package RetailCrm\Helpers
 */
class ApiHelper
{
    protected $api;
    protected $settings;

    /**
     * Constructor
     *
     * @param object $container
     */
    public function __construct($container)
    {
        $this->settings = $container->getSettings();
        $this->log = $container->getLog();
        $this->errorLog = $container->getErrorLog();
        $this->historyLog = $container->getHistoryLog();

        $this->api = new ApiClient($this->settings['api']['url'], $this->settings['api']['key']);
    }

    /**
     * Create order
     *
     * @param array $order
     *
     * @return bool|mixed
     */
    public function orderCreate($order)
    {
        if (isset($order['name'])) {
            $order = array_merge($order, Utils::explodeFIO($order['name']));
        }

        if (!isset($order['customerId'])) {
            $order['customerId'] = $this->setCustomerId($order);
        }

        $response = '';

        try {
            $response = $this->api->ordersCreate($order);
        } catch (CurlException $e) {
            $this->curlErrorHandler($e->getMessage(), 'OrdersCreate::Curl:');
        }

        if ($response->isSuccessful() && 201 === $response->getStatusCode()) {
            return $response->id;
        } else {
            $this->apiErrorHandler($response, 'OrdersCreate::Api::Error: ');
            return false;
        }
    }

    /**
     * Get order history from CRM
     *
     * @throws CurlException
     * @return \RetailCrm\Response\ApiResponse
     */
    public function orderHistory()
    {
        $lastSync = new \DateTime(Utils::getDate($this->historyLog));
        $response = '';

        try {
            $response = $response = $this->api->ordersHistory($lastSync);
        } catch (CurlException $e) {
            $this->curlErrorHandler($e->getMessage(), 'OrderHistory::Curl:');
        }

        if ($response->isSuccessful() && 200 === $response->getStatusCode()) {
            Utils::saveData($response->getGeneratedAt(), $this->historyLog);
            return $response->orders;
        } else {
            $this->apiErrorHandler($response, 'OrderHistory::Api::Error: ');
            return false;
        }

    }

    /**
     * Set customerId for order for deduplacate customers into CRM
     *
     * @param array $order
     *
     * @return string
     */
    private function setCustomerId($order)
    {
        $externalId = (microtime(true) * 10000) . mt_rand(1, 1000);
        $customerId = $this->searchCustomer($order);

        if (is_array($customerId) && !empty($customerId)) {
            if ($customerId['success']) {
                return $customerId['result'];
            } else {
                $this->fixCustomer($customerId['result'], $externalId);
                return $externalId;
            }
        } else {
            $this->createCustomer(
                array(
                    'externalId' => $externalId,
                    'firstName' => $order['firstName'],
                    'lastName' => isset($order['lastName']) ? $order['lastName'] : '',
                    'patronymic' => isset($order['patronymic']) ? $order['patronymic'] : '',
                    'address' => array("text" => isset($order['delivery']['address']['text']) ? $order['delivery']['address']['text'] : null),
                    'phones' => isset($order['phone']) ? array($order['phone']) : array(),
                )
            );

            return $externalId;
        }
    }

    /**
     * Search customer
     *
     * @param array $data
     * @return mixed
     */
    private function searchCustomer($data)
    {
        try {
            $customers = $this->api->customersList(array('name' => isset($data['phone']) ? $data['phone'] : $data['name']), 1, 100);

            if ($customers->isSuccessful()) {
                return (count($customers['customers']) > 0)
                    ? $this->defineCustomer($customers['customers'])
                    : false
                    ;
            } else {
                $this->apiErrorHandler($customers, 'CustomersList::Api::Error: ');
                return false;
            }
        } catch (CurlException $e) {
            $this->curlErrorHandler($e->getMessage(), 'CustomersList::Curl: ');
            return false;
        }
    }

    /**
     * Create customer if search result is empty
     *
     * @param array $customer
     */
    private function createCustomer($customer)
    {
        try {
            $this->api->customersCreate($customer);
        } catch (CurlException $e) {
            $this->curlErrorHandler($e->getMessage(), 'CustomersCreate::Curl: ');
        }
    }

    /**
     * Fix ids
     *
     * @param string $id
     * @param string $extId
     */
    private function fixCustomer($id, $extId)
    {
        try {
            $this->api->customersFixExternalIds(
                array(
                    array(
                        'id' => $id,
                        'externalId' => $extId
                    )
                )
            );
        } catch (CurlException $e) {
            $this->curlErrorHandler($e->getMessage(), 'CustomersFixExternalIds::Curl: ');
        }
    }

    /**
     * Check externalId for search result
     *
     * @param array $searchResult
     *
     * @return array
     */
    private function defineCustomer($searchResult)
    {
        $result = '';

        foreach ($searchResult as $customer) {
            if (isset($customer['externalId']) && $customer['externalId'] != '') {
                $result = $customer['externalId'];
                break;
            }
        }

        return ($result != '')
            ? array('success' => true, 'result' => $result)
            : array('success' => false, 'result' => $searchResult[0]['id'])
            ;
    }

    /**
     * API error handler
     *
     * @param mixed  $responseObject
     * @param string $method
     */
    private function apiErrorHandler($responseObject, $method)
    {
        $errors = (isset($responseObject['errors'])) ? json_encode($responseObject['errors']) : '';
        $this->log->addError($method . $responseObject->getErrorMsg() . "\n\n" . $errors);

    }

    /**
     * Curl error handler
     *
     * @param string $message
     * @param string $method
     */
    private function curlErrorHandler($message, $method)
    {
        $this->log->addError($method . $message);
    }

    /**
     * Parse QUERY_STRING for custom fields
     *
     * @param string $query
     */
    public function setAdditionalParameters($query)
    {
        if(!$query) return;

        $params = array();
        parse_str($query, $params);
        $params = array_merge($this->getAdditionalParameters(), $params);

        foreach ($params as $key => $param) {
            if (empty($param)) {
                unset($params[$key]);
            }
        }

        setcookie('retailCook', serialize($params), time() + 60 * 60 * 24 * 365, '/');
    }

    /**
     * Get parameters for custom fields
     */
    public function getAdditionalParameters()
    {
        if (!isset($_COOKIE['retailCook'])) {
            return array();
        }

        return unserialize($_COOKIE['retailCook']);
    }
}