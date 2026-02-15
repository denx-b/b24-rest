<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Support\CrmEntity;

class InvoiceService extends AbstractCrmItemWithProductRowsService
{
    public function __construct()
    {
        parent::__construct(CrmEntity::TYPE_SMART_INVOICE);
    }

    protected function productRowOwnerTypeAbbr(): string
    {
        return CrmEntity::TYPE_ABBR_SMART_INVOICE;
    }
}
