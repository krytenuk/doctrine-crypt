<?php

namespace FwsDoctrineCrypt;

use FwsDoctrineCrypt\Model\Crypt;
use FwsDoctrineCrypt\Model\Service\CryptFactory;
use FwsDoctrineCrypt\View\Helper\Decrypt;
use FwsDoctrineCrypt\View\Helper\Service\DecryptFactory;

return [
    'service_manager' => [
        'factories' => [
            Crypt::class => CryptFactory::class,
        ],
    ],
    'view_helpers' => [
        'factories' => [
            Decrypt::class => DecryptFactory::class,
        ],
        'aliases' => [
            'decrypt' => Decrypt::class,
        ],
    ],
];
