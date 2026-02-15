<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Support\CrmEntity;

class QuoteService extends AbstractCrmItemWithProductRowsService
{
    public function __construct()
    {
        parent::__construct(CrmEntity::TYPE_QUOTE);
    }

    protected function productRowOwnerTypeAbbr(): string
    {
        return CrmEntity::TYPE_ABBR_QUOTE;
    }
}
