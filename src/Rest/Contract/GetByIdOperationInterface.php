<?php

namespace B24Rest\Rest\Contract;

interface GetByIdOperationInterface
{
    public function getById(int|string $id): array;
}
