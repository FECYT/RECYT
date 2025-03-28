<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;
use PKP\file\FileManager;
use PKP\core\PKPApplication;
use APP\facades\Repo;
use PKP\submission\PKPSubmission;
use APP\decision\Decision;
use APP\i18n\AppLocale;

class CountArticles extends AbstractRunner implements InterfaceRunner
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

            $params2 = [$this->contextId, $dateFrom, $dateTo];
            $paramsPublished = [
                $this->contextId,
                date('Y-m-d', strtotime($dateFrom)),
                date('Y-m-d', strtotime($dateTo)),
            ];

            $data = "Nº de artículos para la revista " . PKPApplication::get()->getContextDAO()->getById($this->contextId)?->getPath();
            $data .= " desde el " . date('d-m-Y', strtotime($dateFrom)) . " hasta el " . date('d-m-Y', strtotime($dateTo)) . "\n";
            $data .= "Recibidos: " . $this->countSubmissionsReceived($params2) . "\n";
            $data .= "Aceptados: " . $this->countSubmissionsAccepted($params2) . "\n";
            $data .= "Rechazados: " . $this->countSubmissionsDeclined($params2) . "\n";
            $data .= "Publicados: " . $this->countSubmissionsPublished($paramsPublished);

            $filePath = $dirFiles . "/numero_articulos.txt";
            file_put_contents($filePath, $data);

            $this->generateCsv($this->getSubmissionsReceived($params2), 'recibidos', $dirFiles);
            $this->generateCsv($this->getSubmissionsAccepted($params2), 'aceptados', $dirFiles);
            $this->generateCsv($this->getSubmissionsDeclined($params2), 'rechazados', $dirFiles);
            $this->generateCsv($this->getSubmissionsPublished($paramsPublished), 'publicados', $dirFiles);

            if (!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/countArticles.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error: ' . $e->getMessage());
        }
    }

    private function generateCsv(iterable $submissions, string $key, string $dirFiles): void
    {
        if (empty($submissions)) {
            return;
        }

        $filePath = $dirFiles . "/envios_" . $key . ".csv";
        $file = fopen($filePath, 'w');

        fputcsv($file, ["ID", "Fecha", "Título"]);

        foreach ($submissions as $submission) {
            $publication = $submission->getCurrentPublication();
            $date = $key === 'recibidos' ? $submission->getData('dateSubmitted') :
                ($key === 'publicados' ? $publication?->getData('datePublished') : $submission->getData('dateLastActivity'));

            fputcsv($file, [
                $submission->getId(),
                date("Y-m-d", strtotime($date)),
                $publication?->getLocalizedData('title', AppLocale::getLocale()) ?? '',
            ]);
        }
        fclose($file);
    }

    private function countSubmissionsReceived(array $params): int
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$params[0]])
            ->filterByStatus([PKPSubmission::STATUS_QUEUED]);

        $query = $collector->getQueryBuilder()
            ->where('po.status', '=', PKPSubmission::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('po.date_published')
                    ->orWhere('s.date_submitted', '<', 'po.date_published');
            })
            ->where('s.date_submitted', '>=', $params[1])
            ->where('s.date_submitted', '<=', $params[2]);

        return $collector->getCount($query);
    }

    private function getSubmissionsReceived(array $params): iterable
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$params[0]])
            ->filterByStatus([PKPSubmission::STATUS_QUEUED]);

        $query = $collector->getQueryBuilder()
            ->where('po.status', '=', PKPSubmission::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('po.date_published')
                    ->orWhere('s.date_submitted', '<', 'po.date_published');
            })
            ->where('s.date_submitted', '>=', $params[1])
            ->where('s.date_submitted', '<=', $params[2]);

        return $collector->getMany($query);
    }

    private function countSubmissionsAccepted(array $params): int
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$params[0]])
            ->filterByStatus([PKPSubmission::STATUS_QUEUED]);

        $query = $collector->getQueryBuilder()
            ->where('po.status', '=', PKPSubmission::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('po.date_published')
                    ->orWhere('s.date_submitted', '<', 'po.date_published');
            })
            ->join('edit_decisions as ed', 'ed.submission_id', '=', 's.submission_id')
            ->where('ed.decision', Decision::ACCEPT)
            ->where('ed.date_decided', '>=', $params[1])
            ->where('ed.date_decided', '<=', $params[2])
            ->distinct('s.submission_id');

        return $collector->getCount($query);
    }

    private function getSubmissionsAccepted(array $params): iterable
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$params[0]])
            ->filterByStatus([PKPSubmission::STATUS_QUEUED]);

        $query = $collector->getQueryBuilder()
            ->where('po.status', '=', PKPSubmission::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('po.date_published')
                    ->orWhere('s.date_submitted', '<', 'po.date_published');
            })
            ->join('edit_decisions as ed', 'ed.submission_id', '=', 's.submission_id')
            ->where('ed.decision', Decision::ACCEPT)
            ->where('ed.date_decided', '>=', $params[1])
            ->where('ed.date_decided', '<=', $params[2])
            ->distinct('s.submission_id');

        return $collector->getMany($query);
    }

    private function countSubmissionsDeclined(array $params): int
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$params[0]])
            ->filterByStatus([PKPSubmission::STATUS_DECLINED]);

        $query = $collector->getQueryBuilder()
            ->where('po.status', '=', PKPSubmission::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('po.date_published')
                    ->orWhere('s.date_submitted', '<', 'po.date_published');
            })
            ->join('edit_decisions as ed', 'ed.submission_id', '=', 's.submission_id')
            ->whereIn('ed.decision', [
                Decision::DECLINE,
                Decision::INITIAL_DECLINE
            ])
            ->where('ed.date_decided', '>=', $params[1])
            ->where('ed.date_decided', '<=', $params[2])
            ->distinct('s.submission_id');

        return $collector->getCount($query);
    }

    private function getSubmissionsDeclined(array $params): iterable
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$params[0]])
            ->filterByStatus([PKPSubmission::STATUS_DECLINED]);

        $query = $collector->getQueryBuilder()
            ->where('po.status', '=', PKPSubmission::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('po.date_published')
                    ->orWhere('s.date_submitted', '<', 'po.date_published');
            })
            ->join('edit_decisions as ed', 'ed.submission_id', '=', 's.submission_id')
            ->whereIn('ed.decision', [
                Decision::DECLINE,
                Decision::INITIAL_DECLINE
            ])
            ->where('ed.date_decided', '>=', $params[1])
            ->where('ed.date_decided', '<=', $params[2])
            ->distinct('s.submission_id');

        return $collector->getMany($query);
    }

    private function countSubmissionsPublished(array $params): int
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$params[0]])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED]);

        $query = $collector->getQueryBuilder()
            ->where('po.status', '=', PKPSubmission::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('po.date_published')
                    ->orWhere('s.date_submitted', '<', 'po.date_published');
            })
            ->where('po.date_published', '>=', $params[1])
            ->where('po.date_published', '<=', $params[2]);

        return $collector->getCount($query);
    }

    private function getSubmissionsPublished(array $params): iterable
    {
        $collector = Repo::submission()
            ->getCollector()
            ->filterByContextIds([$params[0]])
            ->filterByStatus([PKPSubmission::STATUS_PUBLISHED]);

        $query = $collector->getQueryBuilder()
            ->where('po.status', '=', PKPSubmission::STATUS_PUBLISHED)
            ->where(function ($q) {
                $q->whereNull('po.date_published')
                    ->orWhere('s.date_submitted', '<', 'po.date_published');
            })
            ->where('po.date_published', '>=', $params[1])
            ->where('po.date_published', '<=', $params[2]);

        return $collector->getMany($query);
    }
}