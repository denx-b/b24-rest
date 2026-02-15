<?php

namespace B24Rest\Rest\Contract;

interface UpdateOperationInterface
{
    public function update(int|string $id, array $fields, array $params = []): bool;
}
