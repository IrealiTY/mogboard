<?php

namespace App\Exceptions;

class InvalidAlertCreationException extends \Exception
{
    const CODE    = 400;
    const MESSAGE = 'Invalid alert creation data.';
    
    public function __construct()
    {
        parent::__construct(self::CODE, self::MESSAGE);
    }
}
