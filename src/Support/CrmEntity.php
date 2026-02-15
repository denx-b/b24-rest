<?php

namespace B24Rest\Support;

use InvalidArgumentException;

final class CrmEntity
{
    // System CRM entity type IDs.
    // @see https://apidocs.bitrix24.ru/api-reference/crm/data-types.html#object_type
    public const TYPE_LEAD = 1;
    public const TYPE_DEAL = 2;
    public const TYPE_CONTACT = 3;
    public const TYPE_COMPANY = 4;
    public const TYPE_INVOICE = 5; // Legacy invoice.
    public const TYPE_QUOTE = 7;
    public const TYPE_REQUISITE = 8;
    public const TYPE_ORDER = 14;
    public const TYPE_SMART_INVOICE = 31;

    // Dynamic entity type ID ranges.
    // Active: 128..191 or >=1030 and even.
    // Deleted to recycle bin: 192..255 or >=1030 and odd.
    // @see https://apidocs.bitrix24.ru/api-reference/crm/data-types.html#object_type
    public const TYPE_DYNAMIC_ACTIVE_MIN = 128;
    public const TYPE_DYNAMIC_ACTIVE_MAX = 191;
    public const TYPE_DYNAMIC_TRASH_MIN = 192;
    public const TYPE_DYNAMIC_TRASH_MAX = 255;
    public const TYPE_DYNAMIC_EXTENDED_MIN = 1030;

    public const SYSTEM_ENTITY_TYPE_IDS = [
        self::TYPE_LEAD,
        self::TYPE_DEAL,
        self::TYPE_CONTACT,
        self::TYPE_COMPANY,
        self::TYPE_INVOICE,
        self::TYPE_QUOTE,
        self::TYPE_REQUISITE,
        self::TYPE_ORDER,
        self::TYPE_SMART_INVOICE,
    ];

    // Short symbolic codes (entityTypeAbbr).
    // @see https://apidocs.bitrix24.ru/api-reference/crm/data-types.html#object_type
    public const TYPE_ABBR_LEAD = 'L';
    public const TYPE_ABBR_DEAL = 'D';
    public const TYPE_ABBR_CONTACT = 'C';
    public const TYPE_ABBR_COMPANY = 'CO';
    public const TYPE_ABBR_INVOICE = 'I'; // Legacy invoice.
    public const TYPE_ABBR_QUOTE = 'Q';
    public const TYPE_ABBR_REQUISITE = 'RQ';
    public const TYPE_ABBR_ORDER = 'O';
    public const TYPE_ABBR_SMART_INVOICE = 'SI';

    public const ENTITY_TYPE_ABBRS = [
        self::TYPE_LEAD => self::TYPE_ABBR_LEAD,
        self::TYPE_DEAL => self::TYPE_ABBR_DEAL,
        self::TYPE_CONTACT => self::TYPE_ABBR_CONTACT,
        self::TYPE_COMPANY => self::TYPE_ABBR_COMPANY,
        self::TYPE_INVOICE => self::TYPE_ABBR_INVOICE,
        self::TYPE_QUOTE => self::TYPE_ABBR_QUOTE,
        self::TYPE_REQUISITE => self::TYPE_ABBR_REQUISITE,
        self::TYPE_ORDER => self::TYPE_ABBR_ORDER,
        self::TYPE_SMART_INVOICE => self::TYPE_ABBR_SMART_INVOICE,
    ];

    // String identifiers for user fields (userfieldconfig field.entityId).
    // @see https://apidocs.bitrix24.ru/api-reference/crm/universal/userfieldconfig/entity-id.html
    // @see https://apidocs.bitrix24.ru/api-reference/crm/data-types.html#object_type
    public const FIELD_ENTITY_ID_LEAD = 'CRM_LEAD';
    public const FIELD_ENTITY_ID_DEAL = 'CRM_DEAL';
    public const FIELD_ENTITY_ID_CONTACT = 'CRM_CONTACT';
    public const FIELD_ENTITY_ID_COMPANY = 'CRM_COMPANY';
    public const FIELD_ENTITY_ID_QUOTE = 'CRM_QUOTE';
    public const FIELD_ENTITY_ID_INVOICE = 'CRM_INVOICE';
    public const FIELD_ENTITY_ID_SMART_INVOICE = 'CRM_SMART_INVOICE';
    public const FIELD_ENTITY_ID_REQUISITE = 'CRM_REQUISITE';
    public const FIELD_ENTITY_ID_ORDER = 'ORDER';
    public const FIELD_ENTITY_ID_DYNAMIC_PREFIX = 'CRM_'; // CRM_{smartProcessId}
    public const FIELD_ENTITY_ID_RPA_PREFIX = 'RPA_'; // RPA_{processId}

    // Bitrix24 language IDs.
    // @see https://apidocs.bitrix24.ru/api-reference/crm/data-types.html#lang-ids
    public const LANG_AR = 'ar';
    public const LANG_BR = 'br';
    public const LANG_DE = 'de';
    public const LANG_EN = 'en';
    public const LANG_FR = 'fr';
    public const LANG_HI = 'hi';
    public const LANG_ID = 'id';
    public const LANG_IT = 'it';
    public const LANG_JA = 'ja';
    public const LANG_LA = 'la';
    public const LANG_MS = 'ms';
    public const LANG_PL = 'pl';
    public const LANG_RU = 'ru';
    public const LANG_SC = 'sc';
    public const LANG_TC = 'tc';
    public const LANG_TH = 'th';
    public const LANG_TR = 'tr';
    public const LANG_UA = 'ua';
    public const LANG_VN = 'vn';

    public const LANGUAGE_IDS = [
        self::LANG_AR,
        self::LANG_BR,
        self::LANG_DE,
        self::LANG_EN,
        self::LANG_FR,
        self::LANG_HI,
        self::LANG_ID,
        self::LANG_IT,
        self::LANG_JA,
        self::LANG_LA,
        self::LANG_MS,
        self::LANG_PL,
        self::LANG_RU,
        self::LANG_SC,
        self::LANG_TC,
        self::LANG_TH,
        self::LANG_TR,
        self::LANG_UA,
        self::LANG_VN,
    ];

    public const FIELD_ENTITY_IDS = [
        self::FIELD_ENTITY_ID_LEAD,
        self::FIELD_ENTITY_ID_DEAL,
        self::FIELD_ENTITY_ID_CONTACT,
        self::FIELD_ENTITY_ID_COMPANY,
        self::FIELD_ENTITY_ID_QUOTE,
        self::FIELD_ENTITY_ID_INVOICE,
        self::FIELD_ENTITY_ID_SMART_INVOICE,
        self::FIELD_ENTITY_ID_REQUISITE,
        self::FIELD_ENTITY_ID_ORDER,
    ];

    // Entity IDs for crm.status.* (deal stages dictionaries).
    public const STATUS_ENTITY_ID_DEAL_STAGE = 'DEAL_STAGE';
    private const STATUS_ENTITY_ID_DEAL_STAGE_PREFIX = 'DEAL_STAGE_';

    public static function dealStageEntityIdByCategoryId(int $categoryId): string
    {
        if ($categoryId < 0) {
            throw new InvalidArgumentException('Category ID must be greater than or equal to 0.');
        }

        if ($categoryId === 0) {
            return self::STATUS_ENTITY_ID_DEAL_STAGE;
        }

        return self::STATUS_ENTITY_ID_DEAL_STAGE_PREFIX . $categoryId;
    }

    public static function dynamicFieldEntityIdBySmartProcessId(int $smartProcessId): string
    {
        if ($smartProcessId <= 0) {
            throw new InvalidArgumentException('Smart process ID must be greater than 0.');
        }

        return self::FIELD_ENTITY_ID_DYNAMIC_PREFIX . $smartProcessId;
    }

    public static function rpaFieldEntityIdByProcessId(int $processId): string
    {
        if ($processId <= 0) {
            throw new InvalidArgumentException('RPA process ID must be greater than 0.');
        }

        return self::FIELD_ENTITY_ID_RPA_PREFIX . $processId;
    }

    public static function isDynamicEntityTypeId(int $entityTypeId): bool
    {
        if ($entityTypeId >= self::TYPE_DYNAMIC_ACTIVE_MIN && $entityTypeId <= self::TYPE_DYNAMIC_ACTIVE_MAX) {
            return true;
        }

        return $entityTypeId >= self::TYPE_DYNAMIC_EXTENDED_MIN && ($entityTypeId % 2 === 0);
    }

    public static function entityTypeAbbrById(int $entityTypeId): string
    {
        if (isset(self::ENTITY_TYPE_ABBRS[$entityTypeId])) {
            return self::ENTITY_TYPE_ABBRS[$entityTypeId];
        }

        if (self::isDynamicEntityTypeId($entityTypeId)) {
            return 'T' . strtolower(dechex($entityTypeId));
        }

        throw new InvalidArgumentException("Unknown entityTypeId: {$entityTypeId}");
    }
}
