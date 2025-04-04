<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\HTTPUtils;

class SubmissionInfo extends AbstractRunner implements InterfaceRunner
{

    private $contextId;

    public function run(&$params)
    {
        $context = $params["context"];
        $dirFiles = $params['temporaryFullFilePath'];
        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        try {
            $locale = \AppLocale::getLocale();
            $text = '';

            $authorGuidelines = strip_tags($context->getData('authorGuidelines', $locale));
            if ($authorGuidelines) {
                $text .= __('about.authorGuidelines') . "\n\n" . $authorGuidelines . "\n";
                $text .= "\n*************************\n\n";
            }

            $dataCheckList = $this->getSubmissionChecklist($locale);
            if ($dataCheckList) {
                $text .= __('about.submissionPreparationChecklist') . "\n\n" . $dataCheckList . "\n";
                $text .= "\n*************************\n\n";
            }

            $dataSection = '';
            $sections = \Application::getSectionDAO()->getByContextId($context->getId())->toArray();
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
            throw new \Exception('Se ha producido un error:' . $e->getMessage());
        }
    }

    public function getSubmissionChecklist($locale)
    {
        $journalDao = \DAORegistry::getDAO('JournalDAO'); /* @var $journalDao \JournalDAO */
        $query = $journalDao->retrieve("
            SELECT setting_value
            FROM journal_settings
            WHERE setting_name='submissionChecklist'
            AND journal_id=" . $this->contextId . "
            AND locale='" . $locale . "';
        ");

        $content = '';
        while ($query->valid()) {
            $row = get_object_vars($query->current());
            $fixedValue = $this->fixSerializedString($row['setting_value']);
            $data = unserialize($fixedValue);

            foreach ($data as $value) {
                if (!empty($value['content']))
                    $content .= '-' . strip_tags($value['content']) . "\n\n";
            }

            $query->next();
        }

        return $content;
    }

    public function fixSerializedString($serializedString)
    {
        $serializedString = trim($serializedString);
        return preg_replace_callback(
            '/s:(\d+):"(.*?)";/s',
            function ($matches) {
                $length = strlen($matches[2]);
                return 's:' . $length . ':"' . $matches[2] . '";';
            },
            $serializedString
        );
    }
}