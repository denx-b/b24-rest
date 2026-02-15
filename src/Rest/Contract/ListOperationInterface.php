<?php

namespace B24Rest\Rest\Contract;

interface ListOperationInterface
{
    public function list(array $params = [], int $page = 1): array;
}
