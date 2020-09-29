<?php

class unitpayPayment extends waPayment implements waIPayment
{
    const DELIMITER = ':';

    protected $order_id;

    public function allowedCurrency()
    {
        $default = array(
            'RUB',
            'USD',
            'EUR',
        );
        return $default;
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $result = null;
        $order = waOrder::factory($order_data);

        $domain = $this->unit_domain;
        $public_key = $this->unit_public_key;
        $secret_key = $this->unit_secret_key;
        $sum = $order->total;
        $account = $order->id . unitpayPayment::DELIMITER . $this->merchant_id;
        $desc = $order->description;
        $signature = hash('sha256', join('{up}', array(
            $account,
            $desc,
            $sum,
            $secret_key
        )));


        $view = wa()->getView();
        $view->assign('domain', $domain);
        $view->assign('public_key', $public_key);
        $view->assign('sum', $sum);
        $view->assign('account', $account);
        $view->assign('desc', urlencode($desc));
        $view->assign('signature', $signature);

        $has_phone = $order->contact_phone && (strlen($order->contact_phone) > 0);
        $has_email = $order->contact_email && (strlen($order->contact_email) > 0);

        if ($has_phone) {
            $contact_phone = preg_replace('/\D/', '', $order->contact_phone);
            $view->assign('customerPhone', $contact_phone);
        }

        if ($has_email) {
            $view->assign('customerEmail', $order->contact_email);
        }

        if ($has_phone || $has_email) {
            $cashItems = $this->getCashItems($order);
            $view->assign('cashItems', $cashItems);
        }

        if ($has_phone && !$has_email) {
            $template = $this->path . '/templates/payment_with_phone.html';
        } elseif (!$has_phone && $has_email) {
            $template = $this->path . '/templates/payment_with_email.html';
        } elseif ($has_phone && $has_email) {
            $template = $this->path . '/templates/payment_with_both.html';
        } else {
            $template = $this->path . '/templates/payment.html';
        }

        return $view->fetch($template);
    }

    protected function callbackInit($request)
    {
        $params = $request['params'];
        list($this->order_id, $this->merchant_id) = explode(unitpayPayment::DELIMITER, $params['account'], 2);
        return parent::callbackInit($request);
    }

    protected function callbackHandler($data)
    {
        if (!isset($data['params']) || !isset($data['method']) || !isset($data['params']['signature'])) {
            $result = array('error' => array('message' => 'Не переданы обязательные параметры запроса'));
            return $this->returnJson($result);
        }

        $params = $data['params'];
        $method = $data['method'];

        if (!$this->verifySignature($params, $method)) {
            $result = array('error' => array('message' => 'Неверная сигнатура'));
            return $this->returnJson($result);
        }

        switch ($method) {
            case 'check':
                $result = array('result' => array('message' => 'Запрос успешно обработан'));
                break;
            case 'pay':
                $result = $this->pay($params);
                break;
            case 'error':
                $result = array('result' => array('message' => 'Произошла ошибка при обработке платежа'));
                break;
            default:
                $result = array('error' => array('message' => 'Неверный метод'));
                break;
        }

        return $this->returnJson($result);
    }

    public function pay($params)
    {
        $transaction_data = $this->formalizeData($params);

        $transaction_data = $this->saveTransaction($transaction_data, $params);

        $result = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);

        if (empty($result['result'])) {
            $message = !empty($result['error']) ? $result['error'] : 'wa transaction error';
            return array('error' => array('message' => $message));
        }

        return array('result' => array('message' => 'Запрос успешно обработан'));
    }

    public function formalizeData($params)
    {
        $transaction_data = parent::formalizeData($params);
        $transaction_data['order_id'] = $this->order_id;
        $transaction_data['amount'] = $params['sum'];
        $transaction_data['native_id'] = $params['unitpayId'];
        $transaction_data['type'] = self::OPERATION_CAPTURE;
        $transaction_data['state'] = self::STATE_CAPTURED;
        $transaction_data['currency_id'] = $params['orderCurrency'];

        $isTest = ifempty($params['test']);
        if ($isTest) {
            $transaction_data['view_data'] = 'Тестовый режим';
        }

        return $transaction_data;
    }

    public function verifySignature($params, $method)
    {
        return $params['signature'] == $this->getSignature($this->unit_secret_key, $params, $method);
    }

    public function getSignature($secretKey, array $params, $method)
    {
        ksort($params);
        unset($params['sign']);
        unset($params['signature']);
        array_push($params, $secretKey);
        array_unshift($params, $method);

        return hash('sha256', join('{up}', $params));
    }

    protected function returnJson($message)
    {
        return array(
            'header'  => array(
                'Content-Type' => 'application/json'
            ),
            'message' => json_encode($message)
        );
    }

    private function getCashItems($order)
    {
        $currencyCode = $order['currency'];

        $orderProducts = array_map(function ($item) use ($currencyCode, $order) {
            $vat = 'none';

            if (isset($order['tax_included']) && $order['tax_included']) {
                $vat = 'vat20';
            }

            return array(
                'name'     => $item['name'],
                'count'    => $item['quantity'],
                'price'    => round($item['price'], 2),
                'currency' => $currencyCode,
                'type'     => 'commodity',
                'nds'      => $vat,
            );
        }, $order['items']);

        if (isset($order['shipping']) && ($order['shipping'] > 0) && isset($order['shipping_name'])) {
            $vat = 'none';

            if (isset($order['tax_included']) && $order['tax_included']) {
                $vat = 'vat20';
            }

            $orderProducts[] = array(
                'name'     => $order['shipping_name'],
                'count'    => 1,
                'price'    => round($order['shipping'], 2),
                'currency' => $currencyCode,
                'type'     => 'service',
                'nds'      => $vat,
            );
        }

        return base64_encode(json_encode($orderProducts));
    }
}
