<?php

namespace App\trait;

use App\Models\PaymentMethodAuto;

trait PaymobData
{
    private static $instance = null;

    private $config = [];

    private function __construct()
    {
        $this->loadFromDatabase();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    private function loadFromDatabase()
    {
        $settings = PaymentMethodAuto::where('payment_method_id', 1)
            ->first();

        $this->config = [
            'PAYMOB_API_KEY' => $settings->api_key,
            'PAYMOB_SECRET_TOKEN' => $settings->api_key,
            'PAYMOB_IFRAME_ID' => $settings->iframe_id,
            'PAYMOB_INTEGRATION_ID' => $settings->integration_id,
            'PAYMOB_HMAC' => $settings->Hmac,
        ];
    }

    public function get($key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function refresh()
    {
        $this->loadFromDatabase();
    }
}
