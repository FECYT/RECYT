<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\HTTPUtils;

class URLs extends AbstractRunner implements InterfaceRunner
{

    private $contextId;

    public function run(&$params)
    {
        $context = $params["context"];
        $request = $params["request"];
        $dirFiles = $params['temporaryFullFilePath'];
        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        try {
            $router = $request->getRouter();
            $dispatcher = $router->getDispatcher();

            $text = "Home\n" . $dispatcher->url($request, ROUTE_PAGE, null, 'index', null, null) . "\n\n";
            $text .= "Equipo editorial\n" . $dispatcher->url($request, ROUTE_PAGE, null, 'about', 'editorialTeam') . "\n\n";
            $text .= "Envíos\n" . $dispatcher->url($request, ROUTE_PAGE, null, 'about', 'submissions') . "\n\n";
            $text .= "Proceso editorial, periodicidad, politica, ética, preservación\n" . $dispatcher->url($request, ROUTE_PAGE, null, 'about');

            if (isset($params['exportAll'])) {
                $file = fopen($dirFiles . "/urls.txt", "w");
                fwrite($file, $text);
                fclose($file);
            } else {
                HTTPUtils::sendStringAsFile($text, "text/plain", "urls.txt");
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error:' . $e->getMessage());
        }
    }
}