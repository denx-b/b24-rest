<?php

namespace B24Rest\Rest\Contract;

interface AllOperationInterface
{
    public function all(array $params = []): array;
}
