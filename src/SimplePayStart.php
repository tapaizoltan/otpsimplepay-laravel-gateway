<?php

namespace Taki47\Otpsimplepay;

use Illuminate\Support\Facades\Log;
use Exception;

class SimplePayStart extends Base {
    protected $currentInterface = 'start';
    public $transactionBase = [
        'salt' => '',
        'merchant' => '',
        'orderRef' => '',
        'currency' => '',
        'sdkVersion' => '',
        'methods' => [],
        ];

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
}