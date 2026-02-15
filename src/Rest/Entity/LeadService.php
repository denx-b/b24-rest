<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Support\CrmEntity;

class LeadService extends AbstractCrmItemWithProductRowsService
{
    public function __construct()
    {
        parent::__construct(CrmEntity::TYPE_LEAD);
    }

    protected function productRowOwnerTypeAbbr(): string
    {
        return CrmEntity::TYPE_ABBR_LEAD;
    }
}
