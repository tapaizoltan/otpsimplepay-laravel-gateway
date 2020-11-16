<?php

namespace Taki47\Otpsimplepay;

use Illuminate\Support\Facades\Log;
use Exception;

trait Logger {

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

    /**
     * Write log into file
     *
     * @param array $logFile Log file
     * @param array $logText Log content
     *
     * @return boolean
     */
    protected function logToFile($logFile = '', $logText = '')
    {
        try {
            if (!file_put_contents($logFile, $logText, FILE_APPEND | LOCK_EX)) {
                throw new Exception('Log write error');
            }
        } catch (Exception $e) {
            $this->logContent['logToFile'] = $e->getMessage();
        }
        unset($logFile, $logText);
    }
}