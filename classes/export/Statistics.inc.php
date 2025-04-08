<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;


class Statistics extends AbstractRunner implements InterfaceRunner
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

            $currentYear = date('Y');
            $lastCompletedYear = $currentYear - 1;
            $summaryDateFrom = date('Ymd', strtotime("$lastCompletedYear-01-01"));
            $summaryDateTo = date('Ymd', strtotime("$lastCompletedYear-12-31"));

            $submissionStats = $this->getSubmissionStats($summaryDateFrom, $summaryDateTo);
            $reviewerStats = $this->getReviewerStats($dateFrom, $dateTo);
            $reviewerDetails = $this->getReviewerDetails($summaryDateFrom, $summaryDateTo);

            $totalReceived = $submissionStats['received'];
            $totalPublished = $submissionStats['published'];
            $totalDeclined = $submissionStats['declined'];
            $rejectionRate = $totalReceived > 0 ? round(($totalDeclined / $totalReceived) * 100, 1) : 0;
            $journalName = $context->getLocalizedName();
            $totalReviewers = $reviewerDetails['totalReviewers'] ?? count($reviewerDetails['reviewers']);
            $foreignReviewers = $reviewerDetails['foreignReviewers'];
            $foreignPercentage = $totalReviewers > 0 ? round(($foreignReviewers / $totalReviewers) * 100, 1) : 0;

            $statsText = sprintf(__("plugins.generic.calidadfecyt.stats.header"), $lastCompletedYear) . "\n\n";
            $statsText .= sprintf(
                __("plugins.generic.calidadfecyt.stats.summary"),
                $totalReceived,
                $lastCompletedYear,
                $lastCompletedYear,
                $totalPublished,
                $rejectionRate,
                $totalReviewers,
                $foreignPercentage,
                $journalName
            ) . "\n\n";

            foreach ($reviewerDetails['reviewers'] as $reviewer) {
                $statsText .= sprintf(
                    "%s (%s)\n",
                    $reviewer['fullName'],
                    $reviewer['affiliation']
                );
            }

            $file = fopen($dirFiles . "/summary.txt", "w");
            fwrite($file, $statsText);
            fclose($file);

            $this->generateReviewerStatsCsv($reviewerStats['reviewers'], $dirFiles);

            if (!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/statistics.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('An error occurred: ' . $e->getMessage());
        }
    }

    private function getReviewerStats($dateFrom, $dateTo)
    {
        $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO');
        $userDao = \DAORegistry::getDAO('UserDAO');

        $reviewersResult = $reviewAssignmentDao->retrieve(
            "SELECT ra.reviewer_id, u.user_id,
                    COUNT(*) as review_count,
                    SUM(CASE WHEN ra.recommendation = " . SUBMISSION_REVIEWER_RECOMMENDATION_ACCEPT . " THEN 1 ELSE 0 END) as accepted,
                    SUM(CASE WHEN ra.recommendation = " . SUBMISSION_REVIEWER_RECOMMENDATION_DECLINE . " THEN 1 ELSE 0 END) as declined
             FROM review_assignments ra 
             JOIN submissions s ON ra.submission_id = s.submission_id 
             JOIN users u ON ra.reviewer_id = u.user_id 
             WHERE s.context_id = ? 
             AND ra.date_completed IS NOT NULL 
             AND ra.date_completed BETWEEN ? AND ? 
             GROUP BY ra.reviewer_id, u.user_id",
            [$this->contextId, date('Y-m-d', strtotime($dateFrom)), date('Y-m-d', strtotime($dateTo))]
        );

        $reviewerData = [];
        $totalReviewers = 0;
        foreach ($reviewersResult as $reviewer) {
            $user = $userDao->getById($reviewer->user_id);
            $reviewerData[] = [
                'id' => $reviewer->reviewer_id,
                'username' => $user->getUsername(),
                'fullName' => $user->getFullName(),
                'email' => $user->getEmail(),
                'review_count' => $reviewer->review_count,
                'accepted' => $reviewer->accepted,
                'declined' => $reviewer->declined
            ];
            $totalReviewers++;
        }

        return [
            'totalReviewers' => $totalReviewers,
            'reviewers' => $reviewerData
        ];
    }
    private function getSubmissionStats($dateFrom, $dateTo)
    {
        $submissionDao = \DAORegistry::getDAO('SubmissionDAO');
        $submissions = $submissionDao->getByContextId($this->contextId);

        $stats = ['received' => 0, 'accepted' => 0, 'declined' => 0, 'published' => 0];
        while ($submission = $submissions->next()) {
            $dateSubmitted = strtotime($submission->getDateSubmitted());
            $publication = $submission->getCurrentPublication();
            $datePublished = $publication ? strtotime($publication->getData('datePublished')) : null;
            $status = $submission->getStatus();

            if ($dateSubmitted >= strtotime($dateFrom) && $dateSubmitted <= strtotime($dateTo)) {
                $stats['received']++;
                if ($status == STATUS_PUBLISHED && $datePublished && $datePublished <= strtotime($dateTo)) {
                    $stats['accepted']++;
                    $stats['published']++;
                }
                if ($status == STATUS_DECLINED) {
                    $stats['declined']++;
                }
            }
        }
        return $stats;
    }

    private function getReviewerDetails($dateFrom, $dateTo)
    {
        $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO');
        $userDao = \DAORegistry::getDAO('UserDAO');

        $reviewersResult = $reviewAssignmentDao->retrieve(
            "SELECT DISTINCT ra.reviewer_id 
             FROM review_assignments ra 
             JOIN submissions s ON ra.submission_id = s.submission_id 
             WHERE s.context_id = ? 
             AND ra.date_completed IS NOT NULL 
             AND ra.date_completed BETWEEN ? AND ?",
            [$this->contextId, date('Y-m-d', strtotime($dateFrom)), date('Y-m-d', strtotime($dateTo))]
        );

        $reviewers = [];
        $foreignReviewers = 0;
        $totalReviewers = 0;

        foreach ($reviewersResult as $row) {
            $reviewerId = $row->reviewer_id;
            $user = $userDao->getById($reviewerId);
            if ($user) {
                $fullName = $user->getFullName();
                $affiliation = $user->getLocalizedAffiliation() ?: 'Unknown Affiliation';
                $country = $user->getCountry() ?: 'Unknown';
                $gender = $user->getData('gender');

                $reviewers[] = [
                    'fullName' => $fullName,
                    'affiliation' => $affiliation
                ];

                if ($country !== 'ES') {
                    $foreignReviewers++;
                }
                $totalReviewers++;
            }
        }

        return [
            'reviewers' => $reviewers,
            'foreignReviewers' => $foreignReviewers,
            'totalReviewers' => $totalReviewers
        ];
    }

    private function generateReviewerStatsCsv($reviewers, $dirFiles)
    {
        $file = fopen($dirFiles . "/reviewer_statistics.csv", "w");
        fputcsv($file, [
            __("plugins.generic.calidadfecyt.stats.csv.reviewer_id"),
            __("plugins.generic.calidadfecyt.stats.csv.username"),
            __("plugins.generic.calidadfecyt.stats.csv.full_name"),
            __("plugins.generic.calidadfecyt.stats.csv.email"),
            __("plugins.generic.calidadfecyt.stats.csv.num_reviews"),
            __("plugins.generic.calidadfecyt.stats.csv.accepted"),
            __("plugins.generic.calidadfecyt.stats.csv.declined")
        ]);
        foreach ($reviewers as $reviewer) {
            fputcsv($file, [
                $reviewer['id'],
                $reviewer['username'],
                $reviewer['fullName'],
                $reviewer['email'],
                $reviewer['review_count'],
                $reviewer['accepted'],
                $reviewer['declined']
            ]);
        }
        fclose($file);
    }
}