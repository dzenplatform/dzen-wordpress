<?php
namespace DzenChat;

defined('ABSPATH') || exit;

/** Service timestamps displayed using WordPress locale and site settings. */
final class Dates
{
    private static function local(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || !preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value)) return null;
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) return null;
            return $date->setTimezone(wp_timezone());
        } catch (\Exception $error) {
            return null;
        }
    }

    public static function format(mixed $value): string
    {
        $date = self::local($value);
        if ($date === null) return '—';
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'),
            $date->getTimestamp(), $date->getTimezone());
    }

    /** Calendar day for filtering, without locale-dependent month names. */
    public static function day(mixed $value): ?string
    {
        return self::local($value)?->format('Y-m-d');
    }
}
