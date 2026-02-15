<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Support\CrmEntity;

class DealService extends AbstractCrmItemWithProductRowsService
{
    public function __construct()
    {
        parent::__construct(CrmEntity::TYPE_DEAL);
    }

    protected function productRowOwnerTypeAbbr(): string
    {
        return CrmEntity::TYPE_ABBR_DEAL;
    }
}
