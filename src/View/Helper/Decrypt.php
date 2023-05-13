<?php

namespace FwsDoctrineCrypt\View\Helper;

use FwsDoctrineCrypt\Model\Crypt;
use Laminas\View\Helper\AbstractHelper;

class Decrypt extends AbstractHelper
{

    public function __construct(private Crypt $crypt)
    {
    }

    /**
     * @param float|int|string $value
     * @return string
     */
    public function __invoke(float|int|string $value): string
    {
        if ($value) {
            return $this->crypt->decrypt((string)$value) ?? $value;
        }
        return '';
    }

}