<?php

namespace Taki47\Otpsimplepay;

use Illuminate\Support\Facades\Log;
use Exception;

class SimplePayStart {
    public $config = [];
    public $sdkVersion = 'SimplePay_PHP_SDK_1.0.0';
    public $logOrderRef = 'N/A';
    public $logTransactionId = 'N/A';
    public $logChannel = "";
    public $logSeparator = '|';

    protected $hashAlgo = 'sha384';
    protected $currentInterface = 'start';
    protected $phpVersion = "";
    protected $api = [
        'sandbox' => 'https://sandbox.simplepay.hu/payment',
        'live' => 'https://secure.simplepay.hu/payment'
        ];
    protected $apiInterface = [
        'start' => '/v2/start',
        'finish' => '/v2/finish',
        'refund' => '/v2/refund',
        'query' => '/v2/query',
        ];
    public $transactionBase = [
        'salt' => '',
        'merchant' => '',
        'orderRef' => '',
        'currency' => '',
        'sdkVersion' => '',
        'methods' => [],
        ];

    public function __construct()
    {
        $this->logChannel = getEnv("OTP_PAYMENT_LOG_CHANNEL") ? getEnv("OTP_PAYMENT_LOG_CHANNEL") : "daily";
        $this->phpVersion = (int)phpversion();
        
        $this->config["HUF_MERCHANT"]   = getEnv("OTP_PAYMENT_HUF_MERCHANT");
        $this->config["HUF_SECRET_KEY"] = getEnv("OTP_PAYMENT_HUF_SECRET_KEY");
        $this->config["SANDBOX"]        = getEnv("OTP_PAYMENT_SANDBOX");
        $this->config["LOG"]            = getEnv("OTP_PAYMENT_LOG");

        $this->transactionBase["currency"] = getEnv("OTP_PAYMENT_CURRENCY");
        $this->transactionBase["timeout"]  = @date("c", time() + getEnv("OTP_PAYMENT_TIMEOUT_IN_SEC"));
        $this->transactionBase["url"]      = getEnv("OTP_PAYMENT_RETURN_URL");
        $this->transactionBase["language"] = getEnv("OTP_PAYMENT_LANG");
    }

     /**
      * Send initial data to SimplePay API for validation
      * The result is the payment link to where website has to redirect customer
      *
      * @return void
      */
    public function runStart()
    {
        $this->execApiCall();
    }

    /**
     * Execute API call and returns with result
     *
     * @return array $result
     */
    protected function execApiCall()
    {
        $this->prepare();
        $transaction = [];

        $this->logContent['callState2'] = 'REQUEST';
        $this->logContent['sendApiUrl'] = $this->config['apiUrl'];
        $this->logContent['sendContent'] = $this->content;
        $this->logContent['sendSignature'] = $this->config['computedHash'];

        $commRresult = $this->runCommunication($this->config['apiUrl'], $this->content, $this->headers);

        $this->logContent['callState3'] = 'RESULT';

        //call result
        $result = explode("\r\n", $commRresult);
        $transaction['responseBody'] = end($result);

        //signature
        foreach ($result as $resultItem) {
            $headerElement = explode(":", $resultItem);
            if (isset($headerElement[0]) && isset($headerElement[1])) {
                $header[$headerElement[0]] = $headerElement[1];
            }
        }
        $transaction['responseSignature'] = $this->getSignatureFromHeader($header);

        //check transaction validity
        $transaction['responseSignatureValid'] = false;
        if ($this->isCheckSignature($transaction['responseBody'], $transaction['responseSignature'])) {
            $transaction['responseSignatureValid'] = true;
        }

        //fill transaction data
        if (is_object(json_decode($transaction['responseBody']))) {
            foreach (json_decode($transaction['responseBody']) as $key => $value) {
                   $transaction[$key] = $value;
            }
        }

        if (isset($transaction['transactionId'])) {
            $this->logTransactionId = $transaction['transactionId'];
        } elseif (isset($transaction['cardId'])) {
            $this->logTransactionId = $transaction['cardId'];
        }
        if (isset($transaction['orderRef'])) {
            $this->logOrderRef = $transaction['orderRef'];
        }

        $this->returnData = $transaction;
        $this->logContent = array_merge($this->logContent, $transaction);
        $this->logContent = array_merge($this->logContent, $this->getTransactionBase());
        $this->logContent = array_merge($this->logContent, $this->getReturnData());
        $this->writeLog();

        if ( isset($transaction["errorCodes"]) )
            throw new Exception("Return with error code: ".$transaction["errorCodes"][0]);

        return $transaction;
    }

    /**
     * Shows transaction base data
     *
     * @return array $this->transactionBase Transaction data
     */
    public function getTransactionBase()
    {
        return $this->transactionBase;
    }

    /**
     * Shows API call return data
     *
     * @return array $this->returnData Return data
     */
    public function getReturnData()
    {
        return $this->convertToArray($this->returnData);
    }

    /**
     * Prepare log content before write in into log
     *
     * @param array $log Optional content of log. Default is $this->logContent
     *
     * @return boolean
     */
    public function writeLog($log = [])
    {
        if (count($log) == 0) {
            $log = $this->logContent;
        }
        $logText = '';
        $flat    = $this->getFlatArray($log);
        foreach ($flat as $key => $value) {
            $logText .= $this->logOrderRef . $this->logSeparator;
            $logText .= $this->logTransactionId . $this->logSeparator;
            $logText .= $this->currentInterface . $this->logSeparator;
            $logText .= $this->logSeparator;
            $logText .= $this->contentFilter($key, $value) . "\n";
        }
        Log::channel($this->logChannel)->info($logText);
    }

    /**
     * Add uniq transaction field
     *
     * @param string $key   Data field name
     * @param string $value Data field value
     *
     * @return void
     */
    public function addData($key = '', $value = '')
    {
        if ($key == '') {
            $key = 'EMPTY_DATA_KEY';
        }
        $this->transactionBase[$key] = $value;
    }

    /**
     * Add item to pay
     *
     * @param string $itemData A product or service for pay
     *
     * @return void
     */
    public function addItems($itemData = [])
    {
        $item = [
            'ref' => '',
            'title' => '',
            'description' => '',
            'amount' => 0,
            'price' => 0,
            'tax' => 0,
        ];

        if (!isset($this->transactionBase['items'])) {
            $this->transactionBase['items'] = [];
        }

        foreach ($itemData as $itemKey => $itemValue) {
            $item[$itemKey] = $itemValue;
        }
        $this->transactionBase['items'][] = $item;
    }

    /**
     * Add data to a group
     *
     * @param string $group Data group name
     * @param string $key   Data field name
     * @param string $value Data field value
     *
     * @return void
     */
    public function addGroupData($group = '', $key = '', $value = '')
    {
        if (!isset($this->transactionBase[$group])) {
            $this->transactionBase[$group] = [];
        }
        $this->transactionBase[$group][$key] = $value;
    }

    /**
     * Transaction preparation
     *
     * All settings before start transaction
     *
     * @return void
     */
    protected function prepare()
    {
        $this->setConfig();
        $this->logContent['callState1'] = 'PREPARE';
        $this->setApiUrl();
        $this->transactionBase['merchant'] = $this->config['merchant'];
        $this->transactionBase['salt'] = $this->getSalt();
        $this->transactionBase['sdkVersion'] = $this->sdkVersion . ':' . hash_file('md5', __FILE__);
        $this->content = $this->getHashBase($this->transactionBase);
        $this->logContent = array_merge($this->logContent, $this->transactionBase);
        $this->config['computedHash'] = $this->getSignature($this->config['merchantKey'], $this->content);
        $this->headers = $this->getHeaders($this->config['computedHash'], 'EN');
    }

    /**
     * Set config variables
     *
     * @return void
     */
    protected function setConfig()
    {
        if (isset($this->transactionBase['currency'])  && $this->transactionBase['currency'] != '') {
            $this->config['merchant'] = $this->config[$this->transactionBase['currency'] . '_MERCHANT'];
            $this->config['merchantKey'] = $this->config[$this->transactionBase['currency'] . '_SECRET_KEY'];
        } elseif (isset($this->config['merchantAccount'])) {
            foreach ($this->config as $configKey => $configValue) {
                if ($configValue === $this->config['merchantAccount']) {
                    $key = $configKey;
                    break;
                }
            }
            $this->transactionBase['currency'] = substr($key, 0, 3);
            $this->config['merchant'] = $this->config[$this->transactionBase['currency'] . '_MERCHANT'];
            $this->config['merchantKey'] = $this->config[$this->transactionBase['currency'] . '_SECRET_KEY'];
        }

        $this->config['api'] = 'live';
        if ($this->config['SANDBOX']) {
            $this->config['api'] = 'sandbox';
        }
        $this->logContent['environment'] = strtoupper($this->config['api']);

        $this->config['logger'] = false;
        if (isset($this->config['LOGGER'])) {
            $this->config['logger'] = $this->config['LOGGER'];
        }

        $this->config['logPath'] = 'log';
        if (isset($this->config['LOG_PATH'])) {
            $this->config['logPath'] = $this->config['LOG_PATH'];
        }

        $this->config['autoChallenge'] = false;
        if (isset($this->config['AUTOCHALLENGE'])) {
            $this->config['autoChallenge'] = $this->config['AUTOCHALLENGE'];
        }
    }

    /**
     * Add unique config field
     *
     * @param string $key   Config field name
     * @param string $value Vonfig field value
     *
     * @return void
     */
    public function addConfigData($key = '', $value = '')
    {
        if ($key == '') {
            $key = 'EMPTY_CONFIG_KEY';
        }
        $this->config[$key] = $value;
    }

    /**
     * API URL settings depend on function
     *
     * @return void
     */
    protected function setApiUrl()
    {
        $api = 'live';
        if (isset($this->config['api'])) {
            $api = $this->config['api'];
        }
        $this->config['apiUrl'] = $this->api[$api] . $this->apiInterface[$this->currentInterface];
    }

    /**
     * Random string generation for salt
     *
     * @param integer $length Lemgth of random string, default 32
     *
     * @return string Random string
     */
    protected function getSalt($length = 32)
    {
        $saltBase = '';
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        for ($i=0; $i <= $length; $i++) {
            $saltBase .= substr($chars, rand(1, strlen($chars)), 1);
        }
        return hash('md5', $saltBase);
    }

    /**
     * Get full JSON hash string form hash calculation base
     *
     * @param string $data Data array for checking
     *
     * @return void
     */
    public function getHashBase($data = '')
    {
        return $this->checkOrSetToJson($data);
    }

    /**
     * Check data if JSON, or set data to JSON
     *
     * @param string $data Data
     *
     * @return string JSON encoded data
     */
    public function checkOrSetToJson($data = '')
    {
        $json = '[]';
        //empty
        if ($data === '') {
            $json =  json_encode([]);
        }
        //array
        if (is_array($data)) {
            $json =  json_encode($data);
        }
        //object
        if (is_object($data)) {
            $json =  json_encode($data);
        }
        //json
        $result = @json_decode($data);
        if ($result !== null) {
            $json =  $data;
        }
        //serialized
        $result = @unserialize($data);
        if ($result !== false) {
            $json =  json_encode($result);
        }
        return $json;
    }

    /**
     * Gives HMAC signature based on key and hash string data
     *
     * @param string $key  Secret key
     * @param string $data Hash string
     *
     * @return string Signature
     */
    public function getSignature($key = '', $data = '')
    {
        if ($key == '' || $data == '') {
            $this->logContent['signatureGeneration'] = 'Empty key or data for signature';
        }
        return base64_encode(hash_hmac($this->hashAlgo, $data, trim($key), true));
    }

    /**
     * Serves header array
     *
     * @param string $hash     Signature for validation
     * @param string $language Landuage of content
     *
     * @return array Populated header array
     */
    protected function getHeaders($hash = '', $language = 'en')
    {
        $headers = [
            'Accept-language: ' . $language,
            'Content-type: application/json',
            'Signature: ' . $hash,
        ];
        return $headers;
    }

    /**
     * Handler for cURL communication
     *
     * @param string $url     URL
     * @param string $data    Sending data to URL
     * @param string $headers Header information for POST
     *
     * @return array Result of cURL communication
     */
    public function runCommunication($url = '', $data = '', $headers = [])
    {
        $result = '';
        $curlData = curl_init();
        curl_setopt($curlData, CURLOPT_URL, $url);
        curl_setopt($curlData, CURLOPT_POST, true);
        curl_setopt($curlData, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curlData, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlData, CURLOPT_USERAGENT, 'curl');
        curl_setopt($curlData, CURLOPT_TIMEOUT, 60);
        curl_setopt($curlData, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curlData, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curlData, CURLOPT_HEADER, true);
        //cURL + SSL
        //curl_setopt($curlData, CURLOPT_SSL_VERIFYPEER, false);
        //curl_setopt($curlData, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($curlData);
        $this->result = $result;
        $this->curlInfo = curl_getinfo($curlData);
        try {
            if (curl_errno($curlData)) {
                throw new Exception(curl_error($curlData));
            }
        } catch (Exception $e) {
            $this->logContent['runCommunicationException'] = $e->getMessage();
        }
        curl_close($curlData);
        return $result;
    }

    /**
     * Get signature value from header
     *
     * @param array $header Header
     *
     * @return string Signature
     */
    protected function getSignatureFromHeader($header = [])
    {
        $signature = 'MISSING_HEADER_SIGNATURE';
        foreach ($header as $headerKey => $headerValue) {
            if (strtolower($headerKey) === 'signature') {
                $signature = trim($headerValue);
            }
        }
        return $signature;
    }

    /**
     * Check data based on signature
     *
     * @param string $data             Data for check
     * @param string $signatureToCheck Signature to check
     *
     * @return boolean
     */
    public function isCheckSignature($data = '', $signatureToCheck = '')
    {
        $this->config['computedSignature'] = $this->getSignature($this->config['merchantKey'], $data);
        $this->logContent['signatureToCheck'] = $signatureToCheck;
        $this->logContent['computedSignature'] = $this->config['computedSignature'];
        try {
            if ($this->phpVersion === 7) {
                if (!hash_equals($this->config['computedSignature'], $signatureToCheck)) {
                    throw new Exception('fail');
                }
            } elseif ($this->phpVersion === 5) {
                if ($this->config['computedSignature'] !== $signatureToCheck) {
                    throw new Exception('fail');
                }
            }
        } catch (Exception $e) {
            $this->logContent['hashCheckResult'] = $e->getMessage();
            return false;
        }
        $this->logContent['hashCheckResult'] = 'success';
        return true;
    }

    /**
     * Convert object to array
     *
     * @param object $obj Object to transform
     *
     * @return array $new Result array
     */
    protected function convertToArray($obj)
    {
        if (is_object($obj)) {
            $obj = (array) $obj;
        }
        $new = $obj;
        if (is_array($obj)) {
            $new = [];
            foreach ($obj as $key => $val) {
                $new[$key] = $this->convertToArray($val);
            }
        }
        return $new;
    }

    /**
     * Creates a 1-dimension array from a 2-dimension one
     *
     * @param array $arrayForProcess Array to be processed
     *
     * @return array $return          Flat array
     */
    protected function getFlatArray($arrayForProcess = [])
    {
        $array = $this->convertToArray($arrayForProcess);
        $return = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $subArray = $this->getFlatArray($value);
                foreach ($subArray as $subKey => $subValue) {
                    $return[$key . '_' . $subKey] = $subValue;
                }
            } elseif (!is_array($value)) {
                $return[$key] = $value;
            }
        }
        return $return;
    }

    /**
     * Remove card data from log content
     *
     * @param string $key   Log data key
     * @param string $value Log data value
     *
     * @return string  $logValue New log value
     */
    protected function contentFilter($key = '', $value = '')
    {
        $logValue = $value;
        $filtered = '***';
        if (in_array($key, ['content', 'sendContent'])) {
            $contentData = $this->convertToArray(json_decode($value));
            if (isset($contentData['cardData'])) {
                foreach (array_keys($contentData['cardData']) as $dataKey) {
                    $contentData['cardData'][$dataKey] = $filtered;
                }
            }
            if (isset($contentData['cardSecret'])) {
                $contentData['cardSecret'] = $filtered;
            }
            $logValue = json_encode($contentData);
        }
        if (strpos($key, 'cardData') !== false) {
            $logValue = $filtered;
        }
        if ($key === 'cardSecret') {
            $logValue = $filtered;
        }
        return $logValue;
    }
}