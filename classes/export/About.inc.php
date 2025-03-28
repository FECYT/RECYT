<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\HTTPUtils;
use APP\i18n\AppLocale;
class About extends AbstractRunner implements InterfaceRunner
{

    public function run(&$params)
    {
        $context = $params["context"];
        $dirFiles = $params['temporaryFullFilePath'];
        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }

        try {
            $withoutTags = strip_tags($context->getData('about', AppLocale::getLocale()));

            if (isset($params['exportAll'])) {
                file_put_contents($dirFiles . '/about.txt', $withoutTags);
            } else {
                HTTPUtils::sendStringAsFile($withoutTags, "text/plain", "about.txt");
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error:' . $e->getMessage());
        }
    }
}