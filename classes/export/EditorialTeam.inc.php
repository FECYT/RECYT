<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\HTTPUtils;

class EditorialTeam extends AbstractRunner implements InterfaceRunner
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
            $withoutTags = strip_tags($context->getData('editorialTeam', \AppLocale::getLocale()));

            if (isset($params['exportAll'])) {
                file_put_contents($dirFiles . '/equipos.txt', $withoutTags);
            } else {
                HTTPUtils::sendStringAsFile($withoutTags, "text/plain", "equipos.txt");
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error:' . $e->getMessage());
        }
    }
}