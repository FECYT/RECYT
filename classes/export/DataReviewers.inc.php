<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;

class DataReviewers extends AbstractRunner implements InterfaceRunner {

    private $contextId;

    public function run(&$params)
    {
        $fileManager = new \FileManager();
        $context = $params["context"];
        $dirFiles = $params['temporaryFullFilePath'];
        if(!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        try {
            $dateTo = date('Ymd', strtotime("-1 day"));
            $dateFrom = date("Ymd", strtotime("-1 year", strtotime($dateTo)));
            $file = fopen($dirFiles . "/revisores_".$dateFrom."_".$dateTo.".csv", "w");
            fputcsv($file, array("ID", "Nombre", "Apellidos", "Institución", "Correo electrónico"));

            $reviewers = $this->getReviewers(array($dateFrom, $dateTo, $this->contextId));
            foreach ($reviewers as $reviewer) {
                fputcsv($file, array(
                    $reviewer['id'],
                    $reviewer['givenName'],
                    $reviewer['familyName'],
                    $reviewer['affiliation'],
                    $reviewer['email']
                ));
            }

            fclose($file);

            if(!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/dataReviewers.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error:' . $e->getMessage());
        }
    }

    function getReviewers($params)
    {
        $userDao = \DAORegistry::getDAO('UserDAO'); /* @var $userDao \UserDAO */

        return $userDao->retrieve(
            "SELECT
                    u.user_id as id,
                    u.username,
                    max(giv.setting_value) givenName,
                    max(fam.setting_value) familyName,
                    max(aff.setting_value) affiliation,
                    u.email
                FROM
                    users u
                        left outer join user_settings giv on (u.user_id = giv.user_id and giv.setting_name = 'givenName')
                        left outer join user_settings fam on (u.user_id = fam.user_id and fam.setting_name = 'familyName')
                        left outer join user_settings aff on (u.user_id = aff.user_id and aff.setting_name = 'affiliation')
                        left join user_user_groups grp on (u.user_id = grp.user_id)
                        left join user_group_settings ugs on (grp.user_group_id = ugs.user_group_id)
                        left join review_assignments ra ON (u.user_id = ra.reviewer_id)
                        left join submissions s ON (s.submission_id = ra.submission_id)
                WHERE
                    ugs.setting_value LIKE 'Revisor%'
                    AND ra.date_completed >= ?
                    AND ra.date_completed <= ?
                    AND s.context_id = ?
                GROUP BY
                    u.user_id,
                    u.username,
                    u.email;", $params
            )->GetRows();
    }
}