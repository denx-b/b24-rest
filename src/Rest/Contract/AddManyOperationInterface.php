<?php

namespace B24Rest\Rest\Contract;

interface AddManyOperationInterface
{
    public function addMany(array $items, array $params = []): array;
}
