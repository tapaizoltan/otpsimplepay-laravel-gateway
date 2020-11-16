<?php

namespace Taki47\Otpsimplepay;

use Illuminate\Support\Facades\Log;
use Exception;

class SimplePayIpn {
    public $config = [];
    public $sdkVersion = 'SimplePay_PHP_SDK_1.0.0';
    public $logOrderRef = 'N/A';
    public $logTransactionId = 'N/A';
    public $logChannel = "";
    public $logSeparator = '|';
    protected $returnData = [];
    protected $receiveDate = '';
    protected $ipnContent = [];
    protected $responseContent = '';
    protected $ipnReturnData = [];
    public $validationResult = false;

    protected $hashAlgo = 'sha384';
    protected $currentInterface = 'ipn';
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
     * IPN validation
     *
     * @param string $content IPN content
     *
     * @return boolean
     */
    public function isIpnSignatureCheck($content = '')
    {
        if (!function_exists('getallheaders')) {
            /**
             * Getallheaders fon Nginx
             *
             * @return header
             */
            function getallheaders()
            {
                $headers = [];
                foreach ($_SERVER as $name => $value) {
                    if (substr($name, 0, 5) === 'HTTP_') {
                        $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                    }
                }
                return $headers;
            }
        }
        $signature = $this->getSignatureFromHeader(getallheaders());

        foreach (json_decode($this->checkOrSetToJson($content)) as $key => $value) {
            $this->ipnContent[$key] = $value;
        }

        if (isset($this->ipnContent['merchant'])) {
            $this->addConfigData('merchantAccount', $this->ipnContent['merchant']);
        }
        $this->setConfig();

        $this->validationResult = false;
        dd($content." - ".$signature);
        if ($this->isCheckSignature($content, $signature)) {
            $this->validationResult = true;
        }
        $this->logContent['ipnBodyToValidation'] = $content;

        $this->logTransactionId = $this->ipnContent['transactionId'];
        $this->logOrderRef = $this->ipnContent['orderRef'];

        foreach ($this->ipnContent as $contentKey => $contentValue) {
            $this->logContent[$contentKey] = $contentValue;
        }
        $this->logContent['validationResult'] = $this->validationResult;

        if (!$this->validationResult) {
            $this->logContent['validationResultMessage'] = 'UNSUCCESSFUL VALIDATION, NO CONFIRMATION';
        }
        $this->writeLog($this->logContent);

        //confirm setup
        if (!$this->validationResult) {
            $this->confirmContent = 'UNSUCCESSFUL VALIDATION';
            $this->signature = 'UNSUCCESSFUL VALIDATION';
        } elseif ($this->validationResult) {
            $this->ipnContent['receiveDate'] = @date("c", time());
            $this->confirmContent = json_encode($this->ipnContent);
            $this->signature = $this->getSignature($this->config['merchantKey'], $this->confirmContent);
        }
        $this->ipnReturnData['signature'] = $this->signature;
        $this->ipnReturnData['confirmContent'] = $this->confirmContent;
        $this->writeLog(['confirmSignature' => $this->signature, 'confirmContent' => $this->confirmContent]);

        return $this->validationResult;
    }

    /**
     * Immediate IPN confirmation
     *
     * @return boolean
     */
    public function runIpnConfirm()
    {
        try {
            header('Accept-language: EN');
            header('Content-type: application/json');
            header('Signature: ' . $this->ipnReturnData['signature']);
            print $this->ipnReturnData['confirmContent'];
        } catch (Exception $e) {
            $this->writeLog(['ipnConfirm' => $e->getMessage()]);
            return false;
        }
        $this->writeLog(['ipnConfirm' => 'Confirmed directly by runIpnConfirm']);
        return true;
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