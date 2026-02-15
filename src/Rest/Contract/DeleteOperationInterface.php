<?php

namespace B24Rest\Rest\Contract;

interface DeleteOperationInterface
{
    public function delete(int|string $id): bool;
}
