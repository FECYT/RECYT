<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;

class CountArticles extends AbstractRunner implements InterfaceRunner
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
      $submissionDao = \DAORegistry::getDAO('SubmissionDAO');
      $dateTo = $params['dateTo'] ?? date('Ymd', strtotime("-1 day"));
      $dateFrom = $params['dateFrom'] ?? date("Ymd", strtotime("-1 year", strtotime($dateTo)));
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

  private function generateCsv($submissions, $type, $dirFiles)
  {
    $file = fopen($dirFiles . "/envios_" . $type . ".csv", "w");
    fputcsv($file, array("ID", "DOI", "Título", "Fecha"));

    foreach ($submissions as $submission) {
      $submissionDao = \DAORegistry::getDAO('SubmissionDAO');
      $submissionObj = $submissionDao->getById($submission['submission_id']);
      $publication = $submissionObj->getCurrentPublication();
      $doi = $publication->getStoredPubId('doi') ?? 'N/A';

      fputcsv($file, array(
        $submission['submission_id'],
        $doi,
        $submission['title'],
        $submission['date']
      ));
    }
    fclose($file);
  }

  private function countSubmissionsReceived($submissionDao, $params)
  {
    $submissions = $this->getSubmissionsReceived($submissionDao, $params);
    return count($submissions);
  }

  private function countSubmissionsAccepted($submissionDao, $params)
  {
    $submissions = $this->getSubmissionsAccepted($submissionDao, $params);
    return count($submissions);
  }

  private function countSubmissionsDeclined($submissionDao, $params)
  {
    $submissions = $this->getSubmissionsDeclined($submissionDao, $params);
    return count($submissions);
  }

  private function countSubmissionsPublished($submissionDao, $params)
  {
    $submissions = $this->getSubmissionsPublished($submissionDao, $params);
    return count($submissions);
  }

  private function getSubmissionsReceived($submissionDao, $params)
  {
    $contextId = $params[0];
    $dateFrom = $params[1];
    $dateTo = $params[2];
    $locale = \AppLocale::getLocale();

    $submissions = $submissionDao->getByContextId($contextId);
    $filteredSubmissions = [];
    while ($submission = $submissions->next()) {
      $dateSubmitted = strtotime($submission->getDateSubmitted());
      if ($dateSubmitted >= strtotime($dateFrom) && $dateSubmitted <= strtotime($dateTo)) {
        $publication = $submission->getCurrentPublication();
        $filteredSubmissions[] = [
          'submission_id' => $submission->getId(),
          'title' => $publication->getLocalizedData('title', $locale),
          'date' => $submission->getDateSubmitted()
        ];
      }
    }
    return $filteredSubmissions;
  }

  private function getSubmissionsAccepted($submissionDao, $params)
  {
    $contextId = $params[0];
    $dateFrom = $params[1];
    $dateTo = $params[2];
    $locale = \AppLocale::getLocale();

    $submissions = $submissionDao->getByContextId($contextId);
    $filteredSubmissions = [];
    while ($submission = $submissions->next()) {
      $dateSubmitted = strtotime($submission->getDateSubmitted());
      if ($dateSubmitted >= strtotime($dateFrom) && $dateSubmitted <= strtotime($dateTo) && $submission->getStatus() == STATUS_PUBLISHED) {
        $publication = $submission->getCurrentPublication();
        $filteredSubmissions[] = [
          'submission_id' => $submission->getId(),
          'title' => $publication->getLocalizedData('title', $locale),
          'date' => $submission->getDateSubmitted()
        ];
      }
    }
    return $filteredSubmissions;
  }

  private function getSubmissionsDeclined($submissionDao, $params)
  {
    $contextId = $params[0];
    $dateFrom = $params[1];
    $dateTo = $params[2];
    $locale = \AppLocale::getLocale();

    $submissions = $submissionDao->getByContextId($contextId);
    $filteredSubmissions = [];
    while ($submission = $submissions->next()) {
      $dateSubmitted = strtotime($submission->getDateSubmitted());
      if ($dateSubmitted >= strtotime($dateFrom) && $dateSubmitted <= strtotime($dateTo) && $submission->getStatus() == STATUS_DECLINED) {
        $publication = $submission->getCurrentPublication();
        $filteredSubmissions[] = [
          'submission_id' => $submission->getId(),
          'title' => $publication->getLocalizedData('title', $locale),
          'date' => $submission->getDateSubmitted()
        ];
      }
    }
    return $filteredSubmissions;
  }

  private function getSubmissionsPublished($submissionDao, $params)
  {
    $contextId = $params[0];
    $dateFrom = $params[1];
    $dateTo = $params[2];
    $locale = \AppLocale::getLocale();

    $submissions = $submissionDao->getByContextId($contextId);
    $filteredSubmissions = [];
    while ($submission = $submissions->next()) {
      $publication = $submission->getCurrentPublication();
      $datePublished = $publication ? $publication->getData('datePublished') : null; // Corregido aquí
      if ($datePublished && strtotime($datePublished) >= strtotime($dateFrom) && strtotime($datePublished) <= strtotime($dateTo) && $submission->getStatus() == STATUS_PUBLISHED) {
        $filteredSubmissions[] = [
          'submission_id' => $submission->getId(),
          'title' => $publication->getLocalizedData('title', $locale),
          'date' => $datePublished
        ];
      }
    }
    return $filteredSubmissions;
  }
}