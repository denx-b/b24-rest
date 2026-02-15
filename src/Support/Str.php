<?php

namespace B24Rest\Support;

class Str
{
    /**
     * Мастхэв для обработки всех строк вводимых пользователями в формах
     *     – Заменяет случайные двойные пробелы на одинарные
     *     – Удаляет теги
     *     – Делает trim
     *     – Опционально преобразует спец символы в HTML-сущности, чтобы предотвратить XSS-атаки (Cross-Site Scripting)
     *
     * @param $str
     * @param bool $specialchars
     * @param $allowed_tags
     * @return string
     */
    public static function filterString($str, bool $specialchars = false, $allowed_tags = null): string
    {
        $str = strip_tags($str ?? '', $allowed_tags);
        $str = preg_replace('/ {2,}/', ' ', $str);
        $str = trim($str);

        if ($specialchars === true) {
            return htmlspecialchars($str);
        }

        return $str;
    }

    public static function normalizeSort(mixed $value, int $default = 500): int
    {
        $stringValue = self::filterString((string) $value);
        if ($stringValue === '' || !ctype_digit($stringValue)) {
            return $default;
        }

        return (int) $stringValue;
    }
}
