<?php

namespace B24Rest\Rest\Exception;

use RuntimeException;

class Bitrix24RestException extends RuntimeException
{
    private array $response;

    public function __construct(string $message, int $code = 0, array $response = [])
    {
        parent::__construct($message, $code);
        $this->response = $response;
    }

    public function getResponse(): array
    {
        return $this->response;
    }
}
