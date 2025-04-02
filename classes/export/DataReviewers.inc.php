<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;

class DataReviewers extends AbstractRunner implements InterfaceRunner
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
            $dateFromSql = date('Y-m-d', strtotime($dateFrom));
            $dateToSql = date('Y-m-d', strtotime($dateTo));

            $file = fopen($dirFiles . "/revisores_" . $dateFrom . "_" . $dateTo . ".csv", "w");
            fputcsv($file, array("ID", "Nombre", "Apellidos", "Institución", "País", "Correo electrónico"));

            $reviewers = $this->getReviewers(array($dateFromSql, $dateToSql, $this->contextId));

            foreach ($reviewers as $value) {
                $reviewer = get_object_vars($value);
                fputcsv($file, array(
                    $reviewer['id'],
                    $reviewer['givenName'],
                    $reviewer['familyName'],
                    $reviewer['affiliation'],
                    $reviewer['country'],
                    $reviewer['email']
                ));
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

    function getReviewers($params)
    {
        $userDao = \DAORegistry::getDAO('UserDAO');
        return $userDao->retrieve(
            "SELECT
                u.user_id as id,
                u.username,
                MAX(giv.setting_value) givenName,
                MAX(fam.setting_value) familyName,
                MAX(aff.setting_value) affiliation,
                u.email,
                u.country
            FROM
                users u
                LEFT OUTER JOIN user_settings giv ON (u.user_id = giv.user_id AND giv.setting_name = 'givenName')
                LEFT OUTER JOIN user_settings fam ON (u.user_id = fam.user_id AND fam.setting_name = 'familyName')
                LEFT OUTER JOIN user_settings aff ON (u.user_id = aff.user_id AND aff.setting_name = 'affiliation')
                LEFT JOIN user_user_groups grp ON (u.user_id = grp.user_id)
                LEFT JOIN user_group_settings ugs ON (grp.user_group_id = ugs.user_group_id)
                LEFT JOIN review_assignments ra ON (u.user_id = ra.reviewer_id)
                LEFT JOIN submissions s ON (s.submission_id = ra.submission_id)
            WHERE
                AND ra.date_completed IS NOT NULL
                AND ra.date_completed >= ?
                AND ra.date_completed <= ?
                AND s.context_id = ?
            GROUP BY
                u.user_id,
                u.username,
                u.email;",
            $params
        );
    }
}