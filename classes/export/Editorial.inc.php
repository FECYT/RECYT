<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use PKP\file\FileManager;
use PKP\workflow\WorkflowStageDAO;
use APP\facades\Repo;
use PKP\submission\PKPSubmission;
use APP\i18n\AppLocale;
use PKP\submission\SubmissionComment;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\log\EmailLogDAO;
class Editorial extends AbstractRunner implements InterfaceRunner
{
    private int $contextId;
    private int $submissionId;

    public function run(&$params)
    {

        $fileManager = new FileManager();
        $context = $params["context"] ?? null;
        $submission = $params["submission"] ?? null;
        $dirFiles = $params['temporaryFullFilePath'] ?? '';

        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        if (!$submission) {
            throw new \Exception("Envío no encontrado");
        }
        $this->submissionId = $submission;

        try {
            AppLocale::requireComponents(LOCALE_COMPONENT_PKP_SUBMISSION, LOCALE_COMPONENT_PKP_USER, LOCALE_COMPONENT_APP_SUBMISSION);

            $submissionObj = Repo::submission()->get($this->submissionId);
            if (!$submissionObj) {
                throw new \Exception("No se pudo cargar el envío");
            }
            $publication = $submissionObj->getCurrentPublication();
            if (!$publication || $submissionObj->getStatus() !== PKPSubmission::STATUS_PUBLISHED) {
                throw new \Exception("El envío no tiene una publicación válida o no está publicado");
            }

            $this->generateReviewReport($submissionObj, $dirFiles);
            $this->generateHistoryFile($submissionObj, $dirFiles);
            $this->getReview($submissionObj, $context, $dirFiles);
            if (method_exists($this, 'getSubmissionsFiles')) {
                $this->getSubmissionsFiles($submissionObj, $fileManager, $dirFiles);
            } else {
                error_log("Método getSubmissionsFiles no definido en Editorial");
                throw new \Exception("Método getSubmissionsFiles no encontrado");
            }

            $zipFilename = $dirFiles . '/flujo_editorial_envio_' . $this->submissionId . '.zip';
            ZipUtils::zip([], [$dirFiles], $zipFilename);
            $fileManager->downloadByPath($zipFilename);
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error: ' . $e->getMessage());
        }
    }

    public function getNameMethod($method)
    {
        define('SUBMISSION_REVIEW_METHOD_BLIND', 1);
        define('SUBMISSION_REVIEW_METHOD_DOUBLEBLIND', 2);
        define('SUBMISSION_REVIEW_METHOD_OPEN', 3);

        switch ($method) {
            case SUBMISSION_REVIEW_METHOD_OPEN:
                return 'Abrir';
            case SUBMISSION_REVIEW_METHOD_BLIND:
                return 'Ciego';
            case SUBMISSION_REVIEW_METHOD_DOUBLEBLIND:
                return 'Doble ciego';
            default:
                return '';
        }
    }

    public function generateReviewReport($submission, $dirFiles)
    {
        $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO');
        $reviewAssignments = $reviewAssignmentDao->getBySubmissionId($submission->getId());

        $comments = $this->getComments($submission->getId());
        $interests = $this->getReviewerInterests($submission->getId());

        $recommendations = [
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_ACCEPT => 'reviewer.article.decision.accept',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_PENDING_REVISIONS => 'reviewer.article.decision.pendingRevisions',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_RESUBMIT_HERE => 'reviewer.article.decision.resubmitHere',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_RESUBMIT_ELSEWHERE => 'reviewer.article.decision.resubmitElsewhere',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_DECLINE => 'reviewer.article.decision.decline',
            ReviewAssignment::SUBMISSION_REVIEWER_RECOMMENDATION_SEE_COMMENTS => 'reviewer.article.decision.seeComments'
        ];

        $columns = [
            'stage_id' => __('workflow.stage'),
            'round' => 'Ronda',
            'submission' => 'Título del envío',
            'submission_id' => 'ID del envío',
            'reviewer' => 'Revisor/a',
            'user_given' => __('user.givenName'),
            'user_family' => __('user.familyName'),
            'orcid' => __('user.orcid'),
            'country' => __('common.country'),
            'affiliation' => __('user.affiliation'),
            'email' => __('user.email'),
            'interests' => __('user.interests'),
            'dateassigned' => 'Fecha asignada',
            'datenotified' => 'Fecha notificada',
            'dateconfirmed' => 'Fecha confirmada',
            'datecompleted' => 'Fecha completada',
            'dateacknowledged' => 'Fecha de reconocimiento',
            'unconsidered' => 'Sin considerar',
            'datereminded' => 'Fecha recordatorio',
            'dateresponsedue' => __('reviewer.submission.responseDueDate'),
            'overdueresponse' => 'Días de vencimiento de la respuesta',
            'datedue' => __('reviewer.submission.reviewDueDate'),
            'overdue' => 'Días de vencimiento de la revisión',
            'declined' => __('submissions.declined'),
            'recommendation' => 'Recomendación',
            'comments' => 'Comentarios sobre el envío'
        ];

        $file = fopen($dirFiles . "/Informes_evaluacion.csv", "w");
        fputcsv($file, array_keys($columns));

        $locale = AppLocale::getLocale(); // Get the current locale

        foreach ($reviewAssignments as $review) {
            $reviewer = Repo::user()->get($review->getReviewerId());
            $overdue = $this->getOverdueDays($review);

            $row = [
                'stage_id' => __(WorkflowStageDAO::getTranslationKeyFromId($review->getStageId())),
                'round' => $review->getRound(),
                'submission' => $submission->getLocalizedTitle(),
                'submission_id' => $submission->getId(),
                'reviewer' => $reviewer ? $reviewer->getFullName() : '',
                'user_given' => $reviewer ? $reviewer->getGivenName($locale) : '',
                'user_family' => $reviewer ? $reviewer->getFamilyName($locale) : '',
                'orcid' => $reviewer ? $reviewer->getOrcid() : '',
                'country' => $reviewer ? $reviewer->getCountry() : '',
                'affiliation' => $reviewer ? $reviewer->getAffiliation($locale) : '',
                'email' => $reviewer ? $reviewer->getEmail() : '',
                'interests' => $interests[$review->getReviewerId()] ?? '',
                'dateassigned' => $review->getDateAssigned(),
                'datenotified' => $review->getDateNotified(),
                'dateconfirmed' => $review->getDateConfirmed(),
                'datecompleted' => $review->getDateCompleted(),
                'dateacknowledged' => $review->getDateAcknowledged(),
                'unconsidered' => '', // Removed getUnconsidered()
                'datereminded' => $review->getDateReminded(),
                'dateresponsedue' => $review->getDateResponseDue(),
                'overdueresponse' => $overdue[0],
                'datedue' => $review->getDateDue(),
                'overdue' => $overdue[1],
                'declined' => $review->getDeclined() ? __('common.yes') : __('common.no'),
                'recommendation' => $review->getRecommendation() ? __($recommendations[$review->getRecommendation()]) : '',
                'comments' => $comments[$review->getReviewerId()] ?? ''
            ];

            fputcsv($file, $row);
        }
        fclose($file);
    }
    private function getComments($submissionId)
    {
        $submissionCommentDao = \DAORegistry::getDAO('SubmissionCommentDAO');
        $commentsIterator = $submissionCommentDao->getSubmissionComments($submissionId, SubmissionComment::COMMENT_TYPE_PEER_REVIEW);

        $result = [];
        foreach ($commentsIterator->toArray() as $comment) {
            $result[$comment->getAuthorId()] = $result[$comment->getAuthorId()] ?? '';
            $result[$comment->getAuthorId()] .= ($result[$comment->getAuthorId()] ? '; ' : '') . $comment->getComments();
        }
        return $result;
    }

    private function getReviewerInterests($submissionId)
    {
        $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO');
        $reviewAssignments = $reviewAssignmentDao->getBySubmissionId($submissionId);
        $controlledVocabDao = \DAORegistry::getDAO('ControlledVocabDAO');
        $interestEntryDao = \DAORegistry::getDAO('InterestEntryDAO');

        $interestsVocab = $controlledVocabDao->getBySymbolic('interest', ASSOC_TYPE_USER);

        $interests = [];
        foreach ($reviewAssignments as $review) {
            $reviewer = Repo::user()->get($review->getReviewerId());
            if ($reviewer && $interestsVocab) {
                $userInterests = $interestEntryDao->getByControlledVocabId($interestsVocab->getId(), $reviewer->getId());
                $interestArray = $userInterests ? $userInterests->toArray() : [];
                $interestString = !empty($interestArray) ? implode(', ', array_map(function ($interest) {
                    return $interest->getInterest();
                }, $interestArray)) : '';

                if ($interestString) {
                    $interests[$reviewer->getId()] = $interestString;
                }
            }
        }
        return $interests;
    }

    private function getOverdueDays($review)
    {
        $responseDueTime = strtotime($review->getDateResponseDue());
        $reviewDueTime = strtotime($review->getDateDue());
        $overdueResponseDays = $overdueDays = '';

        if (!$review->getDateConfirmed()) {
            if ($responseDueTime < time()) {
                $overdueResponseDays = round((time() - $responseDueTime) / (60 * 60 * 24));
            } elseif ($reviewDueTime < time()) {
                $overdueDays = round((time() - $reviewDueTime) / (60 * 60 * 24));
            }
        } elseif (!$review->getDateCompleted() && $reviewDueTime < time()) {
            $overdueDays = round((time() - $reviewDueTime) / (60 * 60 * 24));
        }
        return [$overdueResponseDays, $overdueDays];
    }

    public function getReview($submission, $context, $dirFiles)
    {
        $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO');
        $reviewAssignments = $reviewAssignmentDao->getBySubmissionId($submission->getId());

        $text = "Tipo de revisión de la revista: " . $this->getNameMethod($context->getData('defaultReviewMode')) . "\n\n";
        $text .= "Revisión por pares acorde a indicaciones\nEnvío: " . $submission->getId() . "\n";

        foreach ($reviewAssignments as $review) {
            $text .= "- Revisión " . $review->getId() . " de la ronda " . $review->getRound() . ": " . $this->getNameMethod($review->getReviewMethod()) . "\n";
        }

        file_put_contents($dirFiles . "/Tipologia_revision.txt", $text);
    }

    public function generateHistoryFile($submission, $dirFiles)
    {
        $eventLogs = Repo::eventLog()
            ->getCollector()
            ->filterByAssoc(ASSOC_TYPE_SUBMISSION, [$submission->getId()])
            ->getMany();

        $emailLogDao = new EmailLogDAO();
        $emailLogs = $emailLogDao->getByAssoc(ASSOC_TYPE_SUBMISSION, $submission->getId())->toArray();

        $entries = array_merge(iterator_to_array($eventLogs), $emailLogs);
        usort($entries, function ($a, $b) {
            $dateA = $a instanceof \PKP\log\EmailLogEntry ? $a->getDateSent() : $a->getDateLogged();
            $dateB = $b instanceof \PKP\log\EmailLogEntry ? $b->getDateSent() : $b->getDateLogged();
            return strcmp($dateB, $dateA);
        });

        $file = fopen($dirFiles . "/Historial.csv", "w");
        fputcsv($file, ['ID', 'Usuario', 'Fecha', 'Evento']);

        foreach ($entries as $entry) {

            $userId = $entry instanceof \PKP\log\EmailLogEntry ? $entry->getSenderId() : $entry->getUserId();
            $user = Repo::user()->get($userId);

            if ($entry instanceof \PKP\log\EmailLogEntry) {
                fputcsv($file, [
                    $entry->getId(),
                    $user ? $user->getFullName() : '',
                    $entry->getDateSent(),
                    __('submission.event.subjectPrefix') . ' ' . $entry->getSubject() . ': ' . strip_tags($entry->getBody())
                ]);
            } elseif ($entry instanceof \PKP\log\SubmissionEventLogEntry) {
                $params = $entry->getParams();

                error_log("Message: " . $entry->getMessage() . " | Params: " . json_encode($params));
                $defaultParams = [
                    'authorName' => '',
                    'editorName' => '',
                    'submissionId' => '',
                    'decision' => '',
                    'round' => '',
                    'reviewerName' => '',
                    'fileId' => '',
                    'username' => '',
                    'name' => '',
                    'originalFileName' => '',
                    'title' => '',
                    'userGroupName' => '',
                    'fileRevision' => '',
                    'userName' => '',
                    'submissionFileId' => '',
                    'submissionFile' => $params['originalFileName'] ?? $params['name'] ?? 'Archivo no especificado' // Más fallbacks
                ];
                $combinedParams = array_merge($defaultParams, $params);

                error_log("Combined Params: " . json_encode($combinedParams));
                try {
                    $message = __($entry->getMessage(), $combinedParams);
                } catch (\Exception $e) {
                    $message = $entry->getMessage() . ' (Error: ' . $e->getMessage() . ' | Params: ' . json_encode($combinedParams) . ')';
                }
                fputcsv($file, [$entry->getId(), $user ? $user->getFullName() : '', $entry->getDateLogged(), $message]);
            } else {
                fputcsv($file, [
                    $entry->getId(),
                    $user ? $user->getFullName() : '',
                    $entry->getDateLogged(),
                    'Evento genérico: ' . $entry->getEventType()
                ]);
            }
        }
        fclose($file);
    }

    public function getSubmissionsFiles($submission, $fileManager, $dirFiles)
    {
        $submissionFiles = Repo::submissionFile()->getCollector()->getMany([
            'submissionIds' => [$submission->getId()],
            'includeDependentFiles' => true,
        ]);

        if ($submissionFiles->count()) {
            $mainFolder = $dirFiles . '/Archivos';
            $fileManager->mkdir($mainFolder);
            $listId = "";

            foreach ($submissionFiles as $file) {
                $id = $file->getId();
                $path = $file->getData('path');
                $fullPath = \Config::getVar('files', 'files_dir') . '/' . $path;

                $folder = $mainFolder . '/';
                switch ($file->getData('fileStage')) {
                    case SUBMISSION_FILE_SUBMISSION:
                        $folder .= 'submission';
                        break;
                    case SUBMISSION_FILE_NOTE:
                        $folder .= 'note';
                        break;
                    case SUBMISSION_FILE_REVIEW_FILE:
                        $folder .= 'submission/review';
                        break;
                    case SUBMISSION_FILE_REVIEW_ATTACHMENT:
                        $folder .= 'submission/review/attachment';
                        break;
                    case SUBMISSION_FILE_REVIEW_REVISION:
                        $folder .= 'submission/review/revision';
                        break;
                    case SUBMISSION_FILE_FINAL:
                        $folder .= 'submission/final';
                        break;
                    case SUBMISSION_FILE_COPYEDIT:
                        $folder .= 'submission/copyedit';
                        break;
                    case SUBMISSION_FILE_DEPENDENT:
                        $folder .= 'submission/proof';
                        break;
                    case SUBMISSION_FILE_PROOF:
                        $folder .= 'submission/proof';
                        break;
                    case SUBMISSION_FILE_PRODUCTION_READY:
                        $folder .= 'submission/productionReady';
                        break;
                    case SUBMISSION_FILE_ATTACHMENT:
                        $folder .= 'attachment';
                        break;
                    case SUBMISSION_FILE_QUERY:
                        $folder .= 'submission/query';
                        break;
                }

                if (file_exists($fullPath)) {
                    $listId .= $id . "\n";
                    $fileManager->mkdir($folder);
                    copy($fullPath, $folder . '/' . $id . '_' . $file->getLocalizedData('name'));
                } else {
                    $listId .= $id . "\t Archivo no encontrado\n";
                }
            }

            file_put_contents($mainFolder . '/ID_archivos.txt', $listId);
        }
    }

    public function getDeclinedSubmissions($issues)
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByStatus([PKPSubmission::STATUS_DECLINED])
            ->filterByContextIds([$this->contextId]);

        $submissions = $collector->getMany()->filter(function ($submission) use ($issues) {
            $publication = $submission->getCurrentPublication();
            return in_array($publication->getData('issueId'), explode(',', $issues));
        });

        $submissionArray = $submissions->keys()->toArray();
        return $submissionArray[array_rand($submissionArray)];
    }
}