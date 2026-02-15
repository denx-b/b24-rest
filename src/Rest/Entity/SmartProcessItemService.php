<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Support\CrmEntity;
use InvalidArgumentException;

class SmartProcessItemService extends AbstractCrmItemWithProductRowsService
{
    public function __construct(int $entityTypeId)
    {
        if (!CrmEntity::isDynamicEntityTypeId($entityTypeId)) {
            throw new InvalidArgumentException(
                'Smart process entityTypeId must be a dynamic type ID (128..191 or >=1030 and even).'
            );
        }

        parent::__construct($entityTypeId);
    }

    protected function productRowOwnerTypeAbbr(): string
    {
        return CrmEntity::entityTypeAbbrById($this->entityTypeId());
    }
}
