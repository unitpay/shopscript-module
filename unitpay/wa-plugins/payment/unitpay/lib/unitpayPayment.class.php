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
        $sum = $order->total;
        $account = $order->id;
        $desc = $order->description;

        $view = wa()->getView();
        $view->assign('public_key', $public_key);
        $view->assign('sum', $sum);
        $view->assign('account', $account);
        $view->assign('desc', $desc);

        return $view->fetch($this->path.'/templates/payment.html');
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

        if ((isset($data['params'])) && (isset($data['method'])) && (isset($data['params']['signature']))){
            $params = $data['params'];
            $method = $data['method'];
            $signature = $params['signature'];

            if (empty($signature)){
                $status_sign = false;
            }else{
                $status_sign = $this->verifySignature($params, $method);
            }

        }else{
            $status_sign = false;
        }

        if ($status_sign){
            switch ($method) {
                case 'check':
                    $result = $this->check( $params );
                    break;
                case 'pay':
                    $result = $this->pay( $params );
                    break;
                case 'error':
                    $result = $this->error( $params );

                    break;
                default:
                    $result = array('error' =>
                        array('message' => 'неверный метод')
                    );
                    break;
            }
        }else{
            $result = array('error' =>
                array('message' => 'неверная сигнатура')
            );
        }

        $this->hardReturnJson($result);

    }

    function check( $params )
    {
        require_once __DIR__.'/../../../../wa-apps/shop/lib/model/shopOrder.model.php';
        $order_model = new shopOrderModel();
        $order_id = $this->order_id;
        $order = $order_model->getById( $order_id );

        if (is_null($order_id)){
            $result = array('error' =>
                array('message' => '1заказа не существует')
            );
        }elseif ((float)$order['total'] != (float)$params['orderSum']) {
            $result = array('error' =>
                array('message' => 'не совпадает сумма заказа')
            );
        }elseif ($order['currency'] != $params['orderCurrency']) {
            $result = array('error' =>
                array('message' => 'не совпадает валюта заказа')
            );
        }
        else{
            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );
        }

        return $result;

    }

    function pay( $params )
    {

        require_once __DIR__.'/../../../../wa-apps/shop/lib/model/shopOrder.model.php';
        $order_model = new shopOrderModel();
        $order_id = $this->order_id;
        $order = $order_model->getById( $order_id );

        if (is_null($order_id)){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }elseif ((float)$order['total'] != (float)$params['orderSum']) {
            $result = array('error' =>
                array('message' => 'не совпадает сумма заказа')
            );
        }elseif ($order['currency'] != $params['orderCurrency']) {
            $result = array('error' =>
                array('message' => 'не совпадает валюта заказа')
            );
        }
        else{

            /*$transaction_data = $this->formalizeData($params);
            $transaction_data['order_id'] = $order_id;
            $transaction_data['amount'] = $order['total'];
            $transaction_data['currency_id'] = $order['currency'];
            $transaction_data['plugin'] = 'unitpay';

            $this->execAppCallback(waPayment::CALLBACK_PAYMENT, $transaction_data);*/

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
                'order_id'        => $order_id,
                'action_id'       => 'pay',
                'before_state_id' => $order['state_id'],
                'after_state_id'  => $update_order['state_id'],
                'text'            => '',
//                'params'          => array('merged_order_id' => $master_id),
            );

            #add log records
            require_once __DIR__.'/../../../../wa-apps/shop/lib/model/shopOrderLog.model.php';
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


    function error( $params )
    {
        require_once __DIR__.'/../../../../wa-apps/shop/lib/model/shopOrder.model.php';
        $order_model = new shopOrderModel();
        $order_id = $this->order_id;
        $order = $order_model->getById( $order_id );

        if (is_null($order['id'])){
            $result = array('error' =>
                array('message' => 'заказа не существует')
            );
        }
        else{

            /*$transaction_data = $this->formalizeData($params);
            $transaction_data['order_id'] = $order_id;
            $transaction_data['amount'] = $order['total'];
            $transaction_data['currency_id'] = $order['currency'];
            $transaction_data['plugin'] = 'unitpay';

            $this->execAppCallback(waPayment::CALLBACK_DECLINE, $transaction_data);*/

            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );

        }

        return $result;
    }


    function verifySignature($params, $method)
    {

        require_once __DIR__.'/../../../../wa-apps/shop/lib/model/shopPluginSettings.model.php';
        require_once __DIR__.'/../../../../wa-apps/shop/lib/model/shopSortable.model.php';
        require_once __DIR__.'/../../../../wa-apps/shop/lib/model/shopPlugin.model.php';
        $settings_model = new shopPluginSettingsModel();
        $plugin_model = new shopPluginModel();
        $plugin = $plugin_model->getByField('plugin', $this->id);

        $secret = $settings_model->get($plugin['id'], 'unit_secret_key');

        return $params['signature'] == $this->getSignature($method, $params, $secret);
    }

    function getSignature($method, array $params, $secretKey)
    {
        ksort($params);
        unset($params['sign']);
        unset($params['signature']);
        array_push($params, $secretKey);
        array_unshift($params, $method);

        return hash('sha256', join('{up}', $params));
    }

    protected function hardReturnJson( $arr )
    {
        header('Content-Type: application/json');
        $result = json_encode($arr);
        die($result);
    }
}