<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;

class DataAuthors extends AbstractRunner implements InterfaceRunner
{

    private $contextId;

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
            fputcsv($file, array("ID envío", "ID author", "Nombre", "Apellidos", "Institución", "Correo electrónico"));

            $submissions = $this->getSubmissions(array($this->contextId, $dateFrom, $dateTo));
            foreach ($submissions as $value) {
                $submissionItem = get_object_vars($value);
                $submissionDao = \DAORegistry::getDAO('SubmissionDAO');
                $submission = $submissionDao->getById($submissionItem['id']);
                $authors = $submission->getAuthors();

                foreach ($authors as $author) {
                    fputcsv($file, array(
                        $submissionItem['id'],
                        $author->getId(),
                        $author->getData('givenName')[$locale],
                        $author->getData('familyName')[$locale],
                        $author->getData('affiliation')[$locale],
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

    public function getSubmissions($params)
    {
        $submissionDao = \DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao \SubmissionDAO */

        return $submissionDao->retrieve(
            "SELECT DISTINCT s.submission_id as id
	        FROM submissions s
            LEFT JOIN publications p on p.publication_id = (
                SELECT p2.publication_id
                FROM publications as p2
                WHERE p2.submission_id = s.submission_id
                  AND p2.status = " . STATUS_PUBLISHED . "
                ORDER BY p2.date_published ASC
                LIMIT 1)
            WHERE s.context_id = ?
              AND s.submission_progress = 0
              AND (p.date_published IS NULL OR s.date_submitted < p.date_published)
              AND s.date_submitted >= ?
              AND s.date_submitted <= ?
            GROUP BY s.submission_id",
            $params
        );
    }

}
