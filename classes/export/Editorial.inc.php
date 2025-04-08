<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;

import('lib.pkp.classes.submission.SubmissionComment');

class Editorial extends AbstractRunner implements InterfaceRunner
{

    private $contextId;
    private $submissionId;

    public function run(&$params)
    {
        $fileManager = new \FileManager();
        $context = $params["context"];
        $submission = $params['submission'];

        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        if (!$submission) {
            throw new \Exception("Envío no encontrado");
        }

        $dirFiles = $params['temporaryFullFilePath'];

        try {
            $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao \ReviewAssignmentDAO */
            \AppLocale::requireComponents(LOCALE_COMPONENT_PKP_SUBMISSION, LOCALE_COMPONENT_PKP_USER, LOCALE_COMPONENT_APP_SUBMISSION);

            $this->generateReviewReport($reviewAssignmentDao, $context, $submission, $dirFiles);
            $this->generateHistoryFile($submission, $dirFiles);
            $this->getReview($submission, $context, $dirFiles);
            $this->getSubmissionsFiles($submission, $fileManager, $dirFiles);

            $zipFilename = $dirFiles . '/flujo_editorial_envio_' . $submission . '.zip';
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
        define('SUBMISSION_REVIEW_METHOD_ANONYMOUS', 1);
        define('SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS', 2);
        define('SUBMISSION_REVIEW_METHOD_OPEN', 3);

        switch ($method) {
            case SUBMISSION_REVIEW_METHOD_OPEN:
                return 'Abrir';
            case SUBMISSION_REVIEW_METHOD_BLIND:
            case SUBMISSION_REVIEW_METHOD_ANONYMOUS:
                return 'Ciego';
            case SUBMISSION_REVIEW_METHOD_DOUBLEBLIND:
            case SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS:
                return 'Doble ciego';
            default:
                return '';
        }
    }

    public function generateReviewReport($reviewAssignmentDao, $context, $submission, $dirFiles)
    {
        list($commentsIterator, $reviewsIterator, $interestsArray) = $this->getReviewReport($context->getId(), $submission);
        $comments = array();
        while ($row = $commentsIterator->next()) {
            if (isset($comments[$row['submission_id']][$row['author_id']])) {
                $comments[$row['submission_id']][$row['author_id']] .= "; " . $row['comments'];
            } else {
                $comments[$row['submission_id']][$row['author_id']] = $row['comments'];
            }
        }

        import('lib.pkp.classes.submission.reviewAssignment.ReviewAssignment');
        $recommendations = array(
            SUBMISSION_REVIEWER_RECOMMENDATION_ACCEPT => 'reviewer.article.decision.accept',
            SUBMISSION_REVIEWER_RECOMMENDATION_PENDING_REVISIONS => 'reviewer.article.decision.pendingRevisions',
            SUBMISSION_REVIEWER_RECOMMENDATION_RESUBMIT_HERE => 'reviewer.article.decision.resubmitHere',
            SUBMISSION_REVIEWER_RECOMMENDATION_RESUBMIT_ELSEWHERE => 'reviewer.article.decision.resubmitElsewhere',
            SUBMISSION_REVIEWER_RECOMMENDATION_DECLINE => 'reviewer.article.decision.decline',
            SUBMISSION_REVIEWER_RECOMMENDATION_SEE_COMMENTS => 'reviewer.article.decision.seeComments'
        );

        $columns = array(
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
            'date_assigned' => 'Fecha asignada',
            'date_notified' => 'Fecha notificada',
            'date_confirmed' => 'Fecha confirmada',
            'date_completed' => 'Fecha completada',
            'date_acknowledged' => 'Fecha de reconocimiento',
            'unconsidered' => 'Sin considerar',
            'date_reminded' => 'Fecha recordatorio',
            'date_response_due' => __('reviewer.submission.responseDueDate'),
            'overdue_response' => 'Días de vencimiento de la respuesta',
            'date_due' => __('reviewer.submission.reviewDueDate'),
            'overdue' => 'Días de vencimiento de la revisión',
            'declined' => __('submissions.declined'),
            'recommendation' => 'Recomendación',
            'comments' => 'Comentarios sobre el envío'
        );

        $reviewFormResponseDao = \DAORegistry::getDAO('ReviewFormResponseDAO');
        $reviewFormElementDao = \DAORegistry::getDAO('ReviewFormElementDAO');

        $file = fopen($dirFiles . "/Informes_evaluacion.csv", "w");
        if (fputcsv($file, $columns) === false)
            die("Error al escribir en el archivo CSV\n");

        foreach ($reviewsIterator as $row) {
            if (substr($row->date_response_due, 11) === '00:00:00') {
                $row->date_response_due = substr($row->date_response_due, 0, 11) . '23:59:59';
            }
            if (substr($row->date_due, 11) === '00:00:00') {
                $row->date_due = substr($row->date_due, 0, 11) . '23:59:59';
            }
            list($overdueResponseDays, $overdueDays) = $this->getOverdueDays($row);
            $row->overdue_response = $overdueResponseDays;
            $row->overdue = $overdueDays;

            foreach ($columns as $index => $junk)
                switch ($index) {
                    case 'stage_id':
                        $columns[$index] = __(\WorkflowStageDAO::getTranslationKeyFromId($row->$index));
                        break;
                    case 'declined':
                    case 'cancelled':
                        $columns[$index] = __($row->$index ? 'common.yes' : 'common.no');
                        break;
                    case 'unconsidered':
                        $columns[$index] = __($row->$index ? 'common.yes' : '');
                        break;
                    case 'recommendation':
                        if (isset($recommendations[$row->$index])) {
                            $columns[$index] = (!isset($row->$index)) ? __('common.none') : __($recommendations[$row->$index]);
                        } else {
                            $columns[$index] = '';
                        }
                        break;
                    case 'comments':
                        $reviewAssignment = $reviewAssignmentDao->getById($row->review_id);
                        $body = '';

                        if ($reviewAssignment->getDateCompleted() != null && ($reviewFormId = $reviewAssignment->getReviewFormId())) {
                            $reviewId = $reviewAssignment->getId();
                            $reviewFormElements = $reviewFormElementDao->getByReviewFormId($reviewFormId);
                            while ($reviewFormElement = $reviewFormElements->next()) {
                                if (!$reviewFormElement->getIncluded())
                                    continue;
                                $body .= \PKPString::stripUnsafeHtml($reviewFormElement->getLocalizedQuestion());
                                $reviewFormResponse = $reviewFormResponseDao->getReviewFormResponse($reviewId, $reviewFormElement->getId());
                                if ($reviewFormResponse) {
                                    $possibleResponses = $reviewFormElement->getLocalizedPossibleResponses();
                                    if (in_array($reviewFormElement->getElementType(), [REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES, REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS])) {
                                        ksort($possibleResponses);
                                        $possibleResponses = array_values($possibleResponses);
                                    }
                                    if (in_array($reviewFormElement->getElementType(), $reviewFormElement->getMultipleResponsesElementTypes())) {
                                        if ($reviewFormElement->getElementType() == REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES) {
                                            $body .= '<ul>';
                                            foreach ($reviewFormResponse->getValue() as $value) {
                                                $body .= '<li>' . \PKPString::stripUnsafeHtml($possibleResponses[$value]) . '</li>';
                                            }
                                            $body .= '</ul>';
                                        } else {
                                            $body .= '<blockquote>' . \PKPString::stripUnsafeHtml($possibleResponses[$reviewFormResponse->getValue()]) . '</blockquote>';
                                        }
                                        $body .= '<br>';
                                    } else {
                                        $body .= '<blockquote>' . nl2br(htmlspecialchars($reviewFormResponse->getValue())) . '</blockquote>';
                                    }
                                }
                            }
                        }

                        if (isset($comments[$row->submission_id][$row->reviewer_id])) {
                            $columns[$index] = $comments[$row->submission_id][$row->reviewer_id];
                        } else {
                            $columns[$index] = $body;
                        }
                        break;
                    case 'interests':
                        if (isset($interestsArray[$row->reviewer_id])) {
                            $columns[$index] = $interestsArray[$row->reviewer_id];
                        } else {
                            $columns[$index] = '';
                        }
                        break;
                    default:
                        $columns[$index] = $row->$index;
                }
            fputcsv($file, $columns);
        }

        rewind($file);
        $csvContent = stream_get_contents($file);
        fclose($file);
    }

    public function getReviewReport($contextId, $submission)
    {
        $locale = \AppLocale::getLocale();
        $submissionDao = \DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao \SubmissionDAO */

        $commentsReturner = $submissionDao->retrieve(
            'SELECT	sc.submission_id,
				sc.comments,
				sc.author_id
			FROM	submission_comments sc
				JOIN submissions s ON (s.submission_id = sc.submission_id)
			WHERE	comment_type = ?
				AND s.context_id = ?
                AND sc.submission_id IN(' . $submission . ')',
            [COMMENT_TYPE_PEER_REVIEW, (int) $contextId]
        );

        $userDao = \DAORegistry::getDAO('UserDAO');
        $site = \Application::get()->getRequest()->getSite();
        $sitePrimaryLocale = $site->getPrimaryLocale();

        $reviewsReturner = $submissionDao->retrieve(
            'SELECT	r.stage_id AS stage_id,
				r.review_id as review_id,
				r.round AS round,
				COALESCE(asl.setting_value, aspl.setting_value) AS submission,
				a.submission_id AS submission_id,
				u.user_id AS reviewer_id,
				u.username AS reviewer,
				' . $userDao->getFetchColumns() . ',
				u.email AS email,
				u.country AS country,
				us.setting_value AS orcid,
				COALESCE(uasl.setting_value, uas.setting_value) AS affiliation,
				r.date_assigned AS date_assigned,
				r.date_notified AS date_notified,
				r.date_confirmed AS date_confirmed,
				r.date_completed AS date_completed,
				r.date_acknowledged AS date_acknowledged,
				r.date_reminded AS date_reminded,
				r.date_due AS date_due,
				r.date_response_due AS date_response_due,
				(r.declined=1) AS declined,
				(r.unconsidered=1) AS unconsidered,
				(r.cancelled=1) AS cancelled,
				r.recommendation AS recommendation
			FROM	review_assignments r
				LEFT JOIN submissions a ON r.submission_id = a.submission_id
				LEFT JOIN publications p ON a.current_publication_id = p.publication_id
				LEFT JOIN publication_settings asl ON (p.publication_id = asl.publication_id AND asl.locale = ? AND asl.setting_name = ?)
				LEFT JOIN publication_settings aspl ON (p.publication_id = aspl.publication_id AND aspl.locale = a.locale AND aspl.setting_name = ?)
				LEFT JOIN users u ON (u.user_id = r.reviewer_id)
				' . $userDao->getFetchJoins() . '
				LEFT JOIN user_settings uas ON (u.user_id = uas.user_id AND uas.setting_name = ? AND uas.locale = a.locale)
				LEFT JOIN user_settings uasl ON (u.user_id = uasl.user_id AND uasl.setting_name = ? AND uasl.locale = ?)
				LEFT JOIN user_settings us ON (u.user_id = us.user_id AND us.setting_name = ?)
			WHERE	 a.context_id = ?
			ORDER BY submission',
            array_merge(
                [
                    $locale,
                    'title',
                    'title',
                ],
                $userDao->getFetchParameters(),
                [
                    'affiliation',
                    'affiliation',
                    $sitePrimaryLocale,
                    'orcid',
                    (int) $contextId
                ]
            )
        );

        import('lib.pkp.classes.user.InterestManager');
        $interestManager = new \InterestManager();
        $assignedReviewerIds = $submissionDao->retrieve(
            'SELECT	r.reviewer_id
			FROM	review_assignments r
				LEFT JOIN submissions a ON r.submission_id = a.submission_id
			WHERE	 a.context_id = ?
			ORDER BY r.reviewer_id',
            [(int) $contextId]
        );

        $interests = [];
        while ($row = $assignedReviewerIds->next()) {
            if (!array_key_exists($row['reviewer_id'], $interests)) {
                $user = $userDao->getById($row['reviewer_id']);
                $reviewerInterests = $interestManager->getInterestsString($user);
                if (!empty($reviewerInterests))
                    $interests[$row['reviewer_id']] = $reviewerInterests;
            }
        }

        return [$commentsReturner, $reviewsReturner, $interests];
    }

    public function getOverdueDays($row)
    {
        $responseDueTime = strtotime($row->date_response_due);
        $reviewDueTime = strtotime($row->date_due);
        $overdueResponseDays = $overdueDays = '';
        if (!$row->date_confirmed) { // no response
            if ($responseDueTime < time()) { // response overdue
                $datediff = time() - $responseDueTime;
                $overdueResponseDays = round($datediff / (60 * 60 * 24));
            } elseif ($reviewDueTime < time()) { // review overdue but not response
                $datediff = time() - $reviewDueTime;
                $overdueDays = round($datediff / (60 * 60 * 24));
            }
        } elseif (!$row->date_completed) { // response given, but not completed
            if ($reviewDueTime < time()) { // review due
                $datediff = time() - $reviewDueTime;
                $overdueDays = round($datediff / (60 * 60 * 24));
            }
        }
        return [$overdueResponseDays, $overdueDays];
    }

    public function getReview($submission, $context, $dirFiles)
    {
        $reviewAssignmentDao = \DAORegistry::getDAO('ReviewAssignmentDAO'); /* @var $reviewAssignmentDao \ReviewAssignmentDAO */

        $query = $reviewAssignmentDao->retrieve(
            'SELECT	r.review_id, r.submission_id, round
			FROM review_assignments r
            LEFT JOIN submissions s ON r.submission_id = s.submission_id
			WHERE s.context_id = ' . $this->contextId . '
			AND r.submission_id =' . $submission . ';'
        );

        $text = "Tipo de revisión de la revista: " . $this->getNameMethod($context->getData('defaultReviewMode')) . "\n\n";
        $text .= "Revisión por pares acorde a indicaciones\nEnvío: $submission\n";

        foreach ($result as $row) {
            $reviewAssignment = $reviewAssignmentDao->getById($row->review_id);
            $text .= "- Revisión " . $row->review_id . " de la ronda " . $row->round . ": " . $this->getNameMethod($reviewAssignment->getReviewMethod()) . "\n";
        }

        $file = fopen($dirFiles . "/Tipologia_revision.txt", "w");
        fwrite($file, $text);
        fclose($file);
    }

    public function generateHistoryFile($submissionId, $dirFiles)
    {
        $entriesEvent = $this->getEventLog($submissionId);
        $entriesEmail = $this->getEmailLog($submissionId);
        $entries = array_merge($entriesEvent, $entriesEmail);

        $queryDao = \DAORegistry::getDAO('QueryDAO');
        $queries = $queryDao->getByAssoc(ASSOC_TYPE_SUBMISSION, $submissionId)->toArray();

        $noteDao = \DAORegistry::getDAO('NoteDAO');
        foreach ($queries as $query) {
            $notes = $noteDao->getByAssoc(ASSOC_TYPE_QUERY, $query->getId())->toArray();
            $entries = array_merge($entries, $notes);
        }

        usort($entries, function ($a, $b) {

            if ($a instanceof \PKP\log\EmailLogEntry) {
                $dateA = $a->getDateSent();
            } elseif ($a instanceof \PKP\log\SubmissionEventLogEntry) {
                $dateA = $a->getDateLogged();
            } elseif ($a instanceof \PKP\note\Note) {
                $dateA = $a->getDateCreated();
            } else {
                $dateA = $a->date;
            }

            if ($b instanceof \PKP\log\EmailLogEntry) {
                $dateB = $b->getDateSent();
            } elseif ($b instanceof \PKP\log\SubmissionEventLogEntry) {
                $dateB = $b->getDateLogged();
            } elseif ($b instanceof \PKP\note\Note) {
                $dateB = $b->getDateCreated();
            } else {
                $dateB = $b->date;
            }

            return strcmp($dateB, $dateA);
        });

        $headers = [
            __('common.id'),
            __('common.user'),
            __('common.date'),
            __('common.event')
        ];


        $file = fopen($dirFiles . "/Historial.csv", "w");

        fputcsv($file, $headers);
        $userDao = \DAORegistry::getDAO('UserDAO'); /* @var $userDao \UserDAO */
        $eventLogDao = \DAORegistry::getDAO('SubmissionEventLogDAO'); /* @var $submissionEventLogDao \SubmissionEventLogDAO */

        foreach ($entries as $entry) {

            $userId = $entry instanceof \PKP\log\EmailLogEntry ? $entry->getSenderId() : $entry->user_id;

            $user = $userDao->retrieve(
                'SELECT * FROM users WHERE user_id = ?',
                [$userId]
            );
            if ($entry->message) {
                $eventLog = $eventLogDao->getById($entry->id);
                $eventParams = $eventLog->getParams();

                fputcsv($file, array(
                    $entry->id,
                    $userDao->getUserFullName($entry->user_id),
                    date("Y-m-d", strtotime($entry->date)),
                    __($eventLog->getMessage(), array(
                        'authorName' => $eventParams['authorName'],
                        'editorName' => $eventParams['editorName'],
                        'submissionId' => $eventParams['submissionId'],
                        'decision' => $eventParams['decision'],
                        'round' => $eventParams['round'],
                        'reviewerName' => $eventParams['reviewerName'],
                        'fileId' => $eventParams['fileId'],
                        'username' => $eventParams['username'],
                        'name' => $eventParams['name'],
                        'originalFileName' => $eventParams['originalFileName'],
                        'title' => $eventParams['title'],
                        'userGroupName' => $eventParams['userGroupName'],
                        'fileRevision' => $eventParams['fileRevision'],
                        'userName' => $eventParams['userName'],
                        'submissionFileId' => $eventParams['submissionFileId'],
                    )),
                ));
            } else {
                fputcsv($file, array(
                    $entry->id,
                    $userDao->getUserFullName($entry->sender_id),
                    date("Y-m-d", strtotime($entry->date)),
                    __('submission.event.subjectPrefix') . ' ' . $entry->subject,
                    strip_tags($entry->body),
                ));
            }
        }

        rewind($file);
        $csvContent = stream_get_contents($file);
        fclose($file);
    }

    public function getEventLog($submission)
    {
        $eventLogDao = \DAORegistry::getDAO('SubmissionEventLogDAO'); /* @var $eventLogDao \SubmissionEventLogDAO */
        $result = $eventLogDao->retrieve(
            "SELECT log_id as id, assoc_type as type, date_logged as date, message, user_id
            FROM event_log el
            WHERE assoc_id = " . $submission . ";"
        );

        return iterator_to_array($result);
    }

    public function getEmailLog($submission)
    {
        $submissionEmailLogDao = \DAORegistry::getDAO('SubmissionEmailLogDAO'); /* @var $submissionEmailLogDao \SubmissionEmailLogDAO */

        $result = $submissionEmailLogDao->retrieve(
            "SELECT log_id as id, assoc_type as type, date_sent as date, subject, sender_id, body
            FROM email_log el
            WHERE assoc_id = " . $submission . ";"
        );

        return iterator_to_array($result);
    }

    public function getSubmissionsFiles($submission, $fileManager, $dirFiles)
    {
        $submissionFileDao = \DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao \SubmissionFileDAO */
        $submissionFileSubmission = \Services::get('submissionFile')->getMany([
            'submissionIds' => [$submission],
            'includeDependentFiles' => true,
        ]);

        if ($submissionFileSubmission) {
            $mainFolder = $dirFiles . '/Archivos';
            if (!$fileManager->fileExists($mainFolder))
                $fileManager->mkdirtree($mainFolder);
            $listId = "";

            foreach ($submissionFileSubmission as $submissionFile) {
                $id = $submissionFile->getId();
                $path = \Config::getVar('files', 'files_dir') . '/' . $submissionFile->getData('path');
                $folder = $mainFolder . '/';

                switch ($submissionFile->getData('fileStage')) {
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

                if (file_exists($path)) {
                    $listId .= $id . "\n";

                    if (!$fileManager->fileExists($folder))
                        $fileManager->mkdirtree($folder);
                    copy($path, $folder . '/' . $id . '_' . $submissionFile->getLocalizedData('name'));
                } else {
                    $listId .= $id . "\t Archivo no encontrado\n";
                }
            }

            $file = fopen($mainFolder . '/ID_archivos.txt', "w");
            fwrite($file, $listId);
            fclose($file);
        }
    }

    public function getDeclinedSubmissions($issues)
    {
        $submissionDao = \DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao \SubmissionDAO */
        $query = $submissionDao->retrieve(
            "SELECT s.submission_id
            FROM submissions s
            INNER JOIN publications p ON p.publication_id = s.current_publication_id
            INNER JOIN publication_settings pp on p.publication_id = pp.publication_id
            WHERE s.status=" . STATUS_DECLINED . "
              AND pp.setting_name='issueId'
              AND pp.setting_value IN (" . $issues . ")"
        );

        $submissions = array();
        foreach ($query as $row) {
            $submissions[] = $row['submission_id'];
        }

        return $submissions[array_rand($submissions)];
    }
}