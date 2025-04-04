<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;

class CountArticles extends AbstractRunner implements InterfaceRunner
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
      $submissionDao = \DAORegistry::getDAO('SubmissionDAO');

      if (!isset($params['dateFrom']) || !isset($params['dateTo'])) {
        throw new \Exception("Date range parameters are required.");
      }
      $dateFrom = $params['dateFrom'];
      $dateTo = $params['dateTo'];

      $params2 = array($this->contextId, $dateFrom, $dateTo);
      $paramsPublished = array(
        $this->contextId,
        date('Y-m-d', strtotime($dateFrom)),
        date('Y-m-d', strtotime($dateTo)),
      );

      $data = "Nº de artículos para la revista " . \Application::getContextDAO()->getById($this->contextId)->getPath();
      $data .= " desde el " . date('d-m-Y', strtotime($dateFrom)) . " hasta el " . date('d-m-Y', strtotime($dateTo)) . "\n";
      $data .= "Recibidos: " . $this->countSubmissionsReceived($submissionDao, $params2) . "\n";
      $data .= "Aceptados: " . $this->countSubmissionsAccepted($submissionDao, $params2) . "\n";
      $data .= "Rechazados: " . $this->countSubmissionsDeclined($submissionDao, $params2) . "\n";
      $data .= "Publicados: " . $this->countSubmissionsPublished($submissionDao, $paramsPublished);

      $file = fopen($dirFiles . "/numero_articulos.txt", "w");
      fwrite($file, $data);
      fclose($file);

      $this->generateCsv($this->getSubmissionsReceived($submissionDao, $params2), 'recibidos', $dirFiles);
      $this->generateCsv($this->getSubmissionsAccepted($submissionDao, $params2), 'aceptados', $dirFiles);
      $this->generateCsv($this->getSubmissionsDeclined($submissionDao, $params2), 'rechazados', $dirFiles);
      $this->generateCsv($this->getSubmissionsPublished($submissionDao, $paramsPublished), 'publicados', $dirFiles);

      if (!isset($params['exportAll'])) {
        $zipFilename = $dirFiles . '/countArticles.zip';
        ZipUtils::zip([], [$dirFiles], $zipFilename);
        $fileManager->downloadByPath($zipFilename);
      }
    } catch (\Exception $e) {
      throw new \Exception('Se ha producido un error: ' . $e->getMessage());
    }
  }

  public function generateCsv($query, $key, $dirFiles)
  {
    if ($query) {
      $publicationDao = \DAORegistry::getDAO('PublicationDAO');

      $file = fopen($dirFiles . "/envios_" . $key . ".csv", "w");
      fputcsv($file, array("ID", "Fecha", "Título"));

      foreach ($query as $value) {
        $row = get_object_vars($value);
        $publication = $publicationDao->getById($row['pub']);

        fputcsv($file, array(
          $row['id'],
          date("Y-m-d", strtotime($row['date'])),
          $publication->getLocalizedData('title', \AppLocale::getLocale()),
        ));
      }
      fclose($file);
    }
  }

  public function countSubmissionsReceived($submissionDao, $params)
  {
    $result = $submissionDao->retrieve(
      "SELECT COUNT(DISTINCT s.submission_id) AS count
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
              AND s.date_submitted <= ?",
      $params
    )->current();

    return $result->count;
  }

  public function getSubmissionsReceived($submissionDao, $params)
  {
    return $submissionDao->retrieve(
      "SELECT DISTINCT s.submission_id as id, s.date_submitted as date, s.current_publication_id as pub
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
            GROUP BY s.submission_id, s.date_submitted, s.current_publication_id",
      $params
    );
  }

  public function countSubmissionsAccepted($submissionDao, $params)
  {
    $result = $submissionDao->retrieve(
      "SELECT COUNT(DISTINCT s.submission_id) AS count
            FROM submissions as s
            LEFT JOIN publications p on p.publication_id = (
                SELECT p2.publication_id
                FROM publications as p2
                WHERE p2.submission_id = s.submission_id
                  AND p2.status != " . STATUS_PUBLISHED . "
                ORDER BY p2.date_published ASC
                LIMIT 1)
            LEFT JOIN edit_decisions as ed on s.submission_id = ed.submission_id
            WHERE s.context_id = ?
              AND s.submission_progress = 0
              AND (p.date_published IS NULL OR s.date_submitted < p.date_published)
              AND s.status != " . STATUS_DECLINED . "
              AND ed.decision = 1
              AND ed.date_decided >= ?
              AND ed.date_decided <= ?",
      $params
    )->current();

    return $result->count;
  }

  public function getSubmissionsAccepted($submissionDao, $params)
  {
    return $submissionDao->retrieve(
      "SELECT DISTINCT s.submission_id as id, ed.date_decided as date, s.current_publication_id as pub
            FROM submissions as s
            LEFT JOIN edit_decisions as ed on s.submission_id = ed.submission_id
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
              AND s.status != " . STATUS_DECLINED . "
              AND ed.decision = 1
              AND ed.date_decided >= ?
              AND ed.date_decided <= ?
            GROUP BY s.submission_id, ed.date_decided, s.current_publication_id",
      $params
    );
  }

  public function countSubmissionsDeclined($submissionDao, $params)
  {
    $result = $submissionDao->retrieve(
      "SELECT COUNT(DISTINCT s.submission_id) as count
            FROM submissions as s
            LEFT JOIN publications p on p.publication_id = (
                SELECT p2.publication_id
                FROM publications as p2
                WHERE p2.submission_id = s.submission_id
                  AND p2.status = " . STATUS_PUBLISHED . "
                ORDER BY p2.date_published ASC
                LIMIT 1)
            LEFT JOIN edit_decisions as ed on s.submission_id = ed.submission_id
            WHERE s.context_id = ?
              AND s.submission_progress = 0
              AND (p.date_published IS NULL OR s.date_submitted < p.date_published)
              AND s.status = " . STATUS_DECLINED . "
              AND ed.decision IN(4,9)
              AND ed.date_decided >= ?
              AND ed.date_decided <= ?",
      $params
    )->current();

    return $result->count;
  }

  public function getSubmissionsDeclined($submissionDao, $params)
  {
    return $submissionDao->retrieve(
      "SELECT DISTINCT s.submission_id as id, ed.date_decided as date, s.current_publication_id as pub
            FROM submissions as s
            LEFT JOIN publications p on p.publication_id = (
                SELECT p2.publication_id
                FROM publications as p2
                WHERE p2.submission_id = s.submission_id
                  AND p2.status = " . STATUS_PUBLISHED . "
                ORDER BY p2.date_published ASC
                LIMIT 1)
            LEFT JOIN edit_decisions as ed on s.submission_id = ed.submission_id
            WHERE s.context_id = ?
              AND s.submission_progress = 0
              AND (p.date_published IS NULL OR s.date_submitted < p.date_published)
              AND s.status = " . STATUS_DECLINED . "
              AND ed.decision IN(4,9)
              AND ed.date_decided >= ?
              AND ed.date_decided <= ?
            GROUP BY s.submission_id, ed.date_decided, s.current_publication_id",
      $params
    );
  }

  public function countSubmissionsPublished($submissionDao, $params)
  {
    $result = $submissionDao->retrieve(
      "SELECT COUNT(DISTINCT s.submission_id) as count
            FROM submissions as s
            LEFT JOIN publications as p on p.publication_id = (
                SELECT p2.publication_id
                FROM publications as p2
                WHERE p2.submission_id = s.submission_id
                  AND p2.status = " . STATUS_PUBLISHED . "
                ORDER BY p2.date_published ASC
                LIMIT 1)
            LEFT JOIN edit_decisions as ed on s.submission_id = ed.submission_id
            WHERE s.context_id = ?
              AND s.submission_progress = 0
              AND (p.date_published IS NULL OR s.date_submitted < p.date_published)
              AND s.status = " . STATUS_PUBLISHED . "
              AND p.date_published >= ?
              AND p.date_published <= ?",
      $params
    )->current();

    return $result->count;
  }


  public function getSubmissionsPublished($submissionDao, $params)
  {
    return $submissionDao->retrieve(
      "SELECT DISTINCT s.submission_id as id, p.date_published as date, s.current_publication_id as pub
            FROM submissions as s
            LEFT JOIN publications as p on p.publication_id = (
                SELECT p2.publication_id
                FROM publications as p2
                WHERE p2.submission_id = s.submission_id
                  AND p2.status = " . STATUS_PUBLISHED . "
                LIMIT 1)
            LEFT JOIN edit_decisions as ed on s.submission_id = ed.submission_id
            WHERE s.context_id = ?
              AND s.submission_progress = 0
              AND (p.date_published IS NULL OR s.date_submitted < p.date_published)
              AND s.status = " . STATUS_PUBLISHED . "
              AND p.date_published >= ?
              AND p.date_published <= ?
            GROUP BY s.submission_id, p.date_published, s.current_publication_id",
      $params
    );
  }
}