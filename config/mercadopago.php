<?php

return [

    'url' => env('MP_URL', 'https://api.mercadopago.com/'),
    'token' => env('MP_TOKEN', 'TEST-1963147828445709-052222-3ab1f18bc72827756c825693867919c9-32577613'),
    'notif_url' => env('MP_NOTIF_URL', 'empty'),
    'redirect_url' => env('MP_REDIRECT_URL', 'https://turnosrtogc.reviturnos.com.ar/confirmed'),
    'excluded_payment_methods' => [
        "excluded_payment_methods" => [
            [
                "id" => "bapropagos"
            ],
            [
                "id" => "rapipago"
            ],
            [
                "id" => "pagofacil"
            ],
            [
                "id" => "cargavirtual"
            ],
            [
                "id" => "redlink"
            ],
            [
                "id" => "cobroexpress"
            ]
        ]
    ],
    'cash_excluded_payment_methods' => [
        "excluded_payment_methods" => [
            [
                "id" => "bapropagos"
            ],
            [
                "id" => "cargavirtual"
            ],
            [
                "id" => "redlink"
            ],
            [
                "id" => "cobroexpress"
            ]
        ]
    ],

];
