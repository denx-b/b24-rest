<?php

namespace B24Rest\Rest\Entity;

use B24Rest\Support\CrmEntity;

class ContactService extends AbstractCrmItemService
{
    public function __construct()
    {
        parent::__construct(CrmEntity::TYPE_CONTACT);
    }
}
