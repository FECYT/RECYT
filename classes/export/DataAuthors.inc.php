<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use CalidadFECYT\classes\utils\LocaleUtils;


class DataAuthors extends AbstractRunner implements InterfaceRunner
{
    protected $contextId;

    public function run(&$params)
    {
        $fileManager = new \FileManager();
        $context = $params["context"];
        $dirFiles = $params['temporaryFullFilePath'];
        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        try {
            $dateTo = $params['dateTo'] ?? date('Ymd', strtotime("-1 day"));
            $dateFrom = $params['dateFrom'] ?? date("Ymd", strtotime("-1 year", strtotime($dateTo)));
            $locale = \AppLocale::getLocale();

            $file = fopen($dirFiles . "/autores_" . $dateFrom . "_" . $dateTo . ".csv", "w");
            fputcsv($file, array("ID envío", "DOI", "ID autor", "Nombre", "Apellidos", "Institución", "País", "Correo electrónico"));

            $submissions = $this->getSubmissions($dateFrom, $dateTo);
            foreach ($submissions as $submission) {
                $submissionDao = \DAORegistry::getDAO('SubmissionDAO');
                $submissionObj = $submissionDao->getById($submission->submission_id);
                $publication = $submissionObj->getCurrentPublication();
                $doi = $publication->getStoredPubId('doi') ?? 'N/A';
                $authors = $submissionObj->getAuthors();

                foreach ($authors as $author) {

                    fputcsv($file, array(
                        $submissionObj->getId(),
                        $doi,
                        $author->getId(),
                        LocaleUtils::getLocalizedDataWithFallback($author, 'givenName', $locale),
                        LocaleUtils::getLocalizedDataWithFallback($author, 'familyName', $locale),
                        LocaleUtils::getLocalizedDataWithFallback($author, 'affiliation', $locale),
                        $author->getData('country'),
                        $author->getData('email'),
                    ));
                }
            }

            fclose($file);

            if (!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/dataAuthors.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error:' . $e->getMessage());
        }
    }

    private function getSubmissions($dateFrom, $dateTo)
    {
        $submissionDao = \DAORegistry::getDAO('SubmissionDAO');
        $rangeInfo = null; // Para paginación, si es necesario
        $submissions = $submissionDao->getByContextId($this->contextId, $rangeInfo);

        $filteredSubmissions = [];
        while ($submission = $submissions->next()) {
            $dateSubmitted = strtotime($submission->getDateSubmitted());
            if ($dateSubmitted >= strtotime($dateFrom) && $dateSubmitted <= strtotime($dateTo)) {
                $filteredSubmissions[] = (object) [
                    'submission_id' => $submission->getId()
                ];
            }
        }
        return $filteredSubmissions;
    }
}