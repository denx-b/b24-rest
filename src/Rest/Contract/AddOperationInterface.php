<?php

namespace B24Rest\Rest\Contract;

interface AddOperationInterface
{
    public function add(array $fields, array $params = []): array;
}
