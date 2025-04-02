<?php

namespace CalidadFECYT\classes\utils;

class LocaleUtils
{
    /**
     * Obtiene un dato localizado con un fallback si no está disponible en el idioma actual
     * @param object $object Objeto con método getData (e.g., Author)
     * @param string $field Nombre del campo (e.g., 'givenName', 'familyName', 'affiliation')
     * @param string $locale Idioma actual (e.g., 'es_ES', 'en_US')
     * @return string
     */
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