<?php

namespace B24Rest\Rest\Contract;

interface UpdateManyOperationInterface
{
    public function updateMany(array $items, array $params = []): array;
}
