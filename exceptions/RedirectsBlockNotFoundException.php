<?php

namespace Bolt\Extension\Soapbox\RedirectsManager\Exceptions;

class RedirectsBlockNotFoundException extends \Exception
{

    public function __construct($message = "", $code = 0, \Exception $previous = null)
    {

        $message = 'The redirects block has not been defined';

        parent::__construct($message, $code, $previous);
    }
}
