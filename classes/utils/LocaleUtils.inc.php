<?php

namespace CalidadFECYT\classes\utils;

class LocaleUtils
{
    public static function getLocalizedDataWithFallback($object, string $field, string $defaultLocale = null): ?string
    {
        $locale = $defaultLocale ?? \AppLocale::getLocale();
        $value = $object->getData($field, $locale);

        if (!empty($value)) {
            return $value;
        }

        $context = \Application::get()->getRequest()->getContext();
        $supportedLocales = $context ? $context->getSupportedLocales() : [AppLocale::DEFAULT_LOCALE];

        foreach ($supportedLocales as $fallbackLocale) {
            if ($fallbackLocale !== $locale) {
                $value = $object->getData($field, $fallbackLocale);
                if (!empty($value)) {
                    return $value;
                }
            }
        }
        return '';
    }
}