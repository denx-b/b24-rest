<?php

namespace B24Rest\Rest;

use B24Rest\Bridge\Bitrix24Gateway;
use B24Rest\Rest\Entity\CompanyService;
use B24Rest\Rest\Entity\ContactService;
use B24Rest\Rest\Entity\DealCategoryService;
use B24Rest\Rest\Entity\DealCategoryStageService;
use B24Rest\Rest\Entity\DealService;
use B24Rest\Rest\Entity\DepartmentService;
use B24Rest\Rest\Entity\InvoiceService;
use B24Rest\Rest\Entity\LeadService;
use B24Rest\Rest\Entity\QuoteService;
use B24Rest\Rest\Entity\SmartProcessItemService;
use B24Rest\Rest\Entity\TaskService;

class Bitrix24RestFactory
{
    private ?DealService $dealService = null;
    private ?DealCategoryService $dealCategoryService = null;
    private ?DealCategoryStageService $dealCategoryStageService = null;
    private ?DepartmentService $departmentService = null;
    private ?LeadService $leadService = null;
    private ?ContactService $contactService = null;
    private ?CompanyService $companyService = null;
    private ?InvoiceService $invoiceService = null;
    private ?QuoteService $quoteService = null;
    private ?TaskService $taskService = null;
    /** @var array<int, SmartProcessItemService> */
    private array $smartProcessItemServices = [];

    public static function fromWebhook(string $webhookUrl): self
    {
        Bitrix24Gateway::useWebhook($webhookUrl);
        return new self();
    }

    public static function fromCurrentBitrix24(string $memberId): self
    {
        Bitrix24Gateway::clearWebhook();
        Bitrix24Gateway::setCurrentBitrix24($memberId);
        return new self();
    }

    public function deals(): DealService
    {
        if ($this->dealService === null) {
            $this->dealService = new DealService();
        }

        return $this->dealService;
    }

    public function dealCategories(): DealCategoryService
    {
        if ($this->dealCategoryService === null) {
            $this->dealCategoryService = new DealCategoryService();
        }

        return $this->dealCategoryService;
    }

    public function dealCategoryStages(): DealCategoryStageService
    {
        if ($this->dealCategoryStageService === null) {
            $this->dealCategoryStageService = new DealCategoryStageService();
        }

        return $this->dealCategoryStageService;
    }

    public function departments(): DepartmentService
    {
        if ($this->departmentService === null) {
            $this->departmentService = new DepartmentService();
        }

        return $this->departmentService;
    }

    public function leads(): LeadService
    {
        if ($this->leadService === null) {
            $this->leadService = new LeadService();
        }

        return $this->leadService;
    }

    public function contacts(): ContactService
    {
        if ($this->contactService === null) {
            $this->contactService = new ContactService();
        }

        return $this->contactService;
    }

    public function companies(): CompanyService
    {
        if ($this->companyService === null) {
            $this->companyService = new CompanyService();
        }

        return $this->companyService;
    }

    public function invoices(): InvoiceService
    {
        if ($this->invoiceService === null) {
            $this->invoiceService = new InvoiceService();
        }

        return $this->invoiceService;
    }

    public function quotes(): QuoteService
    {
        if ($this->quoteService === null) {
            $this->quoteService = new QuoteService();
        }

        return $this->quoteService;
    }

    public function tasks(): TaskService
    {
        if ($this->taskService === null) {
            $this->taskService = new TaskService();
        }

        return $this->taskService;
    }

    public function smartItems(int $entityTypeId): SmartProcessItemService
    {
        if (!isset($this->smartProcessItemServices[$entityTypeId])) {
            $this->smartProcessItemServices[$entityTypeId] = new SmartProcessItemService($entityTypeId);
        }

        return $this->smartProcessItemServices[$entityTypeId];
    }
}
