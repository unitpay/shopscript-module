<?php

class unitpayPayment extends waPayment implements waIPayment
{
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

        $public_key = $this->unit_public_key;
        $secret_key = $this->unit_secret_key;
        $sum = $order->total;
        $account = $order->id;
        $desc = $order->description;
        $signature = hash('sha256', join('{up}', array(
            $account,
            $desc,
            $sum,
            $secret_key
        )));

        $view = wa()->getView();
        $view->assign('public_key', $public_key);
        $view->assign('sum', $sum);
        $view->assign('account', $account);
        $view->assign('desc', urlencode($desc));
        $view->assign('signature', $signature);

        return $view->fetch($this->path . '/templates/payment.html');
    }

    protected function callbackInit($request)
    {
        $params = $request['params'];
        $this->order_id = $params['account'];
        return parent::callbackInit($request);
    }

    protected function callbackHandler($data)
    {
        $method = '';
        $params = [];

        if ((isset($data['params'])) && (isset($data['method'])) && (isset($data['params']['signature']))) {
            $params = $data['params'];
            $method = $data['method'];
            $signature = $params['signature'];

            if (empty($signature)) {
                $status_sign = false;
            } else {
                $status_sign = $this->verifySignature($params, $method);
            }

        } else {
            $status_sign = false;
        }

        if ($status_sign) {
            switch ($method) {
                case 'check':
                    $result = $this->check($params);
                    break;
                case 'pay':
                    $result = $this->pay($params);
                    break;
                case 'error':
                    $result = $this->error($params);
                    break;
                default:
                    $result = array('error' =>
                        array('message' => 'неверный метод')
                    );
                    break;
            }
        } else {
            $result = array('error' =>
                array('message' => 'неверная сигнатура')
            );
        }

        $this->hardReturnJson($result);
    }

    public function check($params)
    {
        $order_model = new shopOrderModel();
        $order_id = $this->order_id;
        $order = $order_model->getById($order_id);

        if (is_null($order_id)) {
            $result = array('error' =>
                array('message' => '1заказа не существует')
            );
        } elseif ((float)$order['total'] != (float)$params['orderSum']) {
            $result = array('error' =>
                array('message' => 'не совпадает сумма заказа')
            );
        } elseif ($order['currency'] != $params['orderCurrency']) {
            $result = array('error' =>
                array('message' => 'не совпадает валюта заказа')
            );
        } else {
            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );
        }

        return $result;
    }

    public function pay($params)
    {
        $order_model = new shopOrderModel();
        $order_id = $this->order_id;
        $order = $order_model->getById($order_id);

        if (is_null($order_id)) {
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        } elseif ((float)$order['total'] != (float)$params['orderSum']) {
            $result = array('error' =>
                array('message' => 'не совпадает сумма заказа')
            );
        } elseif ($order['currency'] != $params['orderCurrency']) {
            $result = array('error' =>
                array('message' => 'не совпадает валюта заказа')
            );
        } else {
            $update_order = [];
            $update_order['state_id'] = 'paid';
            $update_order = array_merge($update_order, [
                'paid_date' => date('Y-m-d'),
                'paid_year' => date('Y'),
                'paid_quarter' => floor((date('m') - 1) / 3) + 1,
                'paid_month' => (int)date('m'),
            ]);

            $order_model->updateById($order_id, $update_order);

            $logs[] = array(
                'order_id' => $order_id,
                'action_id' => 'pay',
                'before_state_id' => $order['state_id'],
                'after_state_id' => $update_order['state_id'],
                'text' => '',
            );

            #add log records
            $log_model = new shopOrderLogModel();
            foreach ($logs as $log) {
                $log_model->add($log);
            }

            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );
        }

        return $result;
    }


    public function error($params)
    {
        $order_model = new shopOrderModel();
        $order_id = $this->order_id;
        $order = $order_model->getById($order_id);

        if (is_null($order['id'])) {
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        } else {
            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );
        }

        return $result;
    }


    public function verifySignature($params, $method)
    {
        $settings_model = new shopPluginSettingsModel();
        $plugin_model = new shopPluginModel();
        $plugin = $plugin_model->getByField('plugin', $this->id);

        $secret = $settings_model->get($plugin['id'], 'unit_secret_key');

        return $params['signature'] == $this->getSignature($method, $params, $secret);
    }

    public function getSignature($method, array $params, $secretKey)
    {
        ksort($params);
        unset($params['sign']);
        unset($params['signature']);
        array_push($params, $secretKey);
        array_unshift($params, $method);

        return hash('sha256', join('{up}', $params));
    }

    protected function hardReturnJson($arr)
    {
        header('Content-Type: application/json');
        $result = json_encode($arr);
        die($result);
    }
}