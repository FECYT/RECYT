<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\ZipUtils;

class Issues extends AbstractRunner implements InterfaceRunner {

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
            foreach ($this->getIssues() as $issueItem) {
                $data = $this->getData($issueItem['id']);
                $submissions = $data['results'];
                $countAuthors = $data['count'];
                $volume = $issueItem['volume'] ? "Vol.".$issueItem['volume']." " : '';
                $number = $issueItem['number'] ? "Num.".$issueItem['number']." " : '';
                $year = $issueItem['year'] ? "(".$issueItem['year'].")" : '';
                $nameFile = "/".$volume.$number.$year;
                $file = fopen($dirFiles . $nameFile.".csv", "w");

                if (! empty($data['results'])) {
                    $columns = ["Sección", "Título"];
                    for ($a = 1; $a <= $countAuthors; $a++) {
                        $columns = array_merge($columns, [
                            "Nombre (autor " . $a . ")",
                            "Apellidos (autor " . $a . ")",
                            "Institución (autor " . $a . ")",
                            "Rol (autor " . $a . ")",
                        ]);
                    }
                    fputcsv($file, array_values($columns));

                    foreach ($submissions as $submission) {
                        $results = [$submission['section'], $submission['title']];

                        for ($a = 1; $a <= count($submission['authors']); $a++) {
                            $results = array_merge($results, [
                                $submission['authors'][$a - 1]['givenName'],
                                $submission['authors'][$a - 1]['familyName'],
                                $submission['authors'][$a - 1]['affiliation'],
                                $submission['authors'][$a - 1]['userGroup']
                            ]);

                        }
                        fputcsv($file, array_values($results));
                    }
                } else {
                    fputcsv($file, array("Este envío no tiene artículos"));
                }

                fclose($file);
            }

            if(!isset($params['exportAll'])) {
                $zipFilename = $dirFiles . '/issues.zip';
                ZipUtils::zip([], [$dirFiles], $zipFilename);
                $fileManager->downloadByPath($zipFilename);
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error:' . $e->getMessage());
        }
    }

    public function getData($issue)
    {
        $issueSubmissions = iterator_to_array(\Services::get('submission')->getMany([
            'contextId' => $this->contextId,
            'issueIds' => [$issue],
            'status' => 3,
            'orderBy' => 'seq',
            'orderDirection' => 'ASC',
        ]));
        $maxAuthors = 0;
        $results = array();

        foreach ($issueSubmissions as $submission) {
            $publication = $submission->getCurrentPublication();
            $maxAuthors = max($maxAuthors, count($publication->getData('authors')));

            $sectionId = $submission->getCurrentPublication()->getData('sectionId');
            $section = \Application::get()->getSectionDao()->getById($sectionId);

            $results[] = [
                'title' => $submission->getCurrentPublication()->getLocalizedData('title'),
                'section' => $section->getData('hideTitle') ? '' : $section->getLocalizedData('title'),
                'authors' => array_map(function($author) {
                $userGroupDao = \DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao \UserGroupDAO */
                $userGroup = $userGroupDao->getById($author->getData('userGroupId'))->getLocalizedData('name');

                return [
                    'givenName' => $author->getLocalizedGivenName(),
                    'familyName' => $author->getLocalizedFamilyName(),
                    'affiliation' => $author->getLocalizedData('affiliation'),
                    'userGroup' => $userGroup ?? ''
                ];
                }, $publication->getData('authors'))
                ];
        }

        return array(
            "count" => $maxAuthors,
            'results' => $results
        );
    }

    public function getIssues()
    {
        $data = array();
        $issueDao = \DAORegistry::getDAO('IssueDAO'); /* @var $issueDao \IssueDAO */
        $query = $issueDao->retrieve(
            "SELECT issue_id as id, volume, year, number
            FROM issues
            WHERE journal_id = ".$this->contextId."
            AND published = 1
            ORDER BY date_published DESC
            LIMIT 4"
            );

        while (!$query->EOF) {
            $row = $query->getRowAssoc(false);
            $data[] = [
                'id' => $row['id'],
                'volume' => $row['volume'],
                'year' => $row['year'],
                'number' => $row['number'],
            ];
            $query->MoveNext();
        }

        $query->Close();
        return $data;
    }
}
