<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\HTTPUtils;
use APP\facades\Repo;
use APP\i18n\AppLocale;

class SubmissionInfo extends AbstractRunner implements InterfaceRunner
{
    private int $contextId;

    public function run(&$params)
    {


        $context = $params["context"] ?? null;
        $dirFiles = $params['temporaryFullFilePath'] ?? '';

        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        try {
            $locale = AppLocale::getLocale();
            $text = '';

            $authorGuidelines = strip_tags($context->getData('authorGuidelines', $locale));
            if ($authorGuidelines) {
                $text .= __('about.authorGuidelines') . "\n\n" . $authorGuidelines . "\n";
                $text .= "\n*************************\n\n";
            }

            $dataCheckList = $this->getSubmissionChecklist($context, $locale);
            if ($dataCheckList) {
                $text .= __('about.submissionPreparationChecklist') . "\n\n" . $dataCheckList . "\n";
                $text .= "\n*************************\n\n";
            }

            $dataSection = '';
            $sections = Repo::section()
                ->getCollector()
                ->filterByContextIds([$this->contextId])
                ->getMany();

            foreach ($sections as $section) {
                $dataSection .= "-" . $section->getLocalizedTitle() . "\n" . strip_tags($section->getLocalizedPolicy()) . "\n\n";
            }

            if ($dataSection) {
                $text .= "Secciones\n\n";
                $text .= $dataSection . "\n";
                $text .= "\n*************************\n\n";
            }

            $copyrightNotice = strip_tags($context->getData('copyrightNotice', $locale));
            if ($copyrightNotice) {
                $text .= __('about.copyrightNotice') . "\n\n" . $copyrightNotice . "\n";
                $text .= "\n*************************\n\n";
            }

            $privacyStatement = strip_tags($context->getData('privacyStatement', $locale));
            if ($privacyStatement) {
                $text .= __('about.privacyStatement') . "\n\n" . $privacyStatement . "\n";
            }

            if (isset($params['exportAll'])) {
                file_put_contents($dirFiles . '/envios.txt', $text);
            } else {
                HTTPUtils::sendStringAsFile($text, "text/plain", "envios.txt");
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error: ' . $e->getMessage());
        }
    }

    private function getSubmissionChecklist($context, $locale)
    {
        $checklist = $context->getData('submissionChecklist', $locale);
        if (!$checklist || !is_array($checklist)) {
            return '';
        }

        $content = '';
        foreach ($checklist as $item) {
            if (!empty($item['content'])) {
                $content .= '-' . strip_tags($item['content']) . "\n\n";
            }
        }

        return $content;
    }
}