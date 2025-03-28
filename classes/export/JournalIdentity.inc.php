<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\HTTPUtils;
use APP\i18n\AppLocale;
class JournalIdentity extends AbstractRunner implements InterfaceRunner
{

    private $contextId;

    public function run(&$params)
    {
        $context = $params["context"];
        $dirFiles = $params['temporaryFullFilePath'];
        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        try {
            $text = "Datos de la revista\n";
            $text .= "Nombre: " . $context->getSetting('name', AppLocale::getLocale()) . "\n";
            $text .= "ISSN: " . $context->getSetting('printIssn') . "\n";
            $text .= "ISSN electrónico: " . $context->getSetting('onlineIssn') . "\n";
            $text .= "Entidad: " . $context->getSetting('publisherInstitution');



            if (isset($params['exportAll'])) {
                $file = fopen($dirFiles . "/identidad.txt", "w");
                fwrite($file, $text);
                fclose($file);
            } else {
                HTTPUtils::sendStringAsFile($text, "text/plain", "identidad.txt");
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error:' . $e->getMessage());
        }
    }
}