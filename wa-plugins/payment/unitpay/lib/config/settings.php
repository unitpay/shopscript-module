<?php

return array(
    'unit_domain' => array(
        'value'        => '',
        'title'        => 'DOMAIN',
        'description'  => 'Вставьте ваш рабочий домен UnitPay',
        'control_type' => 'input',
        'class'        => 'keys',
    ),
	'unit_public_key' => array(
		'value'        => '',
		'title'        => 'PUBLIC KEY',
		'description'  => 'Скопируйте PUBLIC KEY со страницы проекта в системе Unitpay',
		'control_type' => 'input',
		'class'        => 'keys',
	),
	'unit_secret_key' => array(
		'value'        => '',
		'title'        => 'SECRET KEY',
		'description'  => 'Скопируйте SECRET KEY со страницы проекта в системе Unitpay',
		'control_type' => 'input',
		'class'        => 'keys',
	),
	'unit_item_type' => array(
		'value'        => 'commodity',
		'title'        => 'Тип товара',
		'description'  => '',
		'control_type' => 'input',
		'class'        => 'keys',
	)
);
