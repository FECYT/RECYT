<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use PKP\file\FileManager;
use PKP\core\PKPApplication;
use APP\facades\Repo;
use APP\i18n\AppLocale;

class DataReviewers extends AbstractRunner implements InterfaceRunner
{
    private int $contextId;

    public function run(&$params)
    {
        $fileManager = new FileManager();
        $context = $params["context"] ?? null;
        $dirFiles = $params['temporaryFullFilePath'] ?? '';

        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }

        $this->contextId = $context->getId();

        try {
            $dateTo = date('Ymd', strtotime("-1 day"));
            $dateFrom = date("Ymd", strtotime("-1 year", strtotime($dateTo)));
            $locale = AppLocale::getLocale();

            $file = fopen($dirFiles . "/revisores_" . $dateFrom . "_" . $dateTo . ".csv", "w");
            fputcsv($file, ["ID", "Nombre", "Apellidos", "Institución", "Correo electrónico"]);

            $reviewers = $this->getReviewers([$dateFrom, $dateTo, $this->contextId]);
            foreach ($reviewers as $reviewer) {
                fputcsv($file, [
                    $reviewer->getId(),
                    $reviewer->getData('givenName', $locale) ?? '',
                    $reviewer->getData('familyName', $locale) ?? '',
                    $reviewer->getData('affiliation', $locale) ?? '',
                    $reviewer->getData('email') ?? ''
                ]);
            }

            fclose($file);

            if (!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/dataReviewers.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error: ' . $e->getMessage());
        }
    }

    public function getReviewers(array $params): iterable
    {
        $collector = Repo::user()
            ->getCollector()
            ->filterByRoleIds([\ROLE_ID_REVIEWER])
            ->filterByContextIds([$params[2]]);

        $query = $collector->getQueryBuilder()
            ->join('review_assignments as ra', 'ra.reviewer_id', '=', 'u.user_id')
            ->join('submissions as s', 's.submission_id', '=', 'ra.submission_id')
            ->where('ra.date_completed', '>=', $params[0])
            ->where('ra.date_completed', '<=', $params[1])
            ->where('s.context_id', '=', $params[2])
            ->distinct('u.user_id');

        return $collector->getMany($query);
    }
}