<?php

namespace CalidadFECYT\classes\main;

use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\FileUtils;
use CalidadFECYT\classes\utils\LogUtils;
use CalidadFECYT\classes\utils\ZipUtils;

define("OJS_FILES_TEMPORARY_DIRECTORY", \Config::getVar('files', 'files_dir') . '/temp/calidadfecyt');

class CalidadFECYT
{
    private $params;
    private $exportClasses = [
        "About",
        "CountArticles",
        "DataAuthors",
        "DataReviewers",
        "Issues",
        "JournalIdentity",
        "SubmissionInfo",
        "URLs",
    ];

    private function imports()
    {
        import('plugins.generic.calidadfecyt.classes.utils.LogUtils');
        import('plugins.generic.calidadfecyt.classes.utils.ZipUtils');
        import('plugins.generic.calidadfecyt.classes.utils.HTTPUtils');
        import('plugins.generic.calidadfecyt.classes.utils.FileUtils');
        import('plugins.generic.calidadfecyt.classes.abstracts.AbstractRunner');
        import('plugins.generic.calidadfecyt.classes.interfaces.InterfaceRunner');
        import('plugins.generic.calidadfecyt.classes.export.Editorial');

        foreach ($this->exportClasses as $exportClass) {
            import('plugins.generic.calidadfecyt.classes.export.' . $exportClass);
        }
    }

    public function __construct($params)
    {
        $this->imports();
        LogUtils::setLogType("WEB");
        $this->params = $params;
    }

    public function getExportClasses()
    {
        return $this->exportClasses;
    }

    public function export()
    {
        $fileManager = new \FileManager();
        try {
            $this->validateExportParams();

            $timestamp = date('YmdHis');
            $request = $this->params['request'];
            $context = $this->params['context'];
            $exportIndex = $request->getUserVar('exportIndex');
            $exportClass = $this->exportClasses[$exportIndex];

            $exporter = new \ReflectionClass("CalidadFECYT\\classes\\export\\" . $exportClass);
            if ($exporter != null) {
                $exporterInstance = $exporter->newInstanceArgs(array());

                $tempDirectoryName = FileUtils::filterFilename($context->getId() . '_' . $timestamp . '_' . $exportClass);
                $this->params['timestamp'] = $timestamp;
                $this->params['temporaryFullFilePath'] = OJS_FILES_TEMPORARY_DIRECTORY . '/' . $tempDirectoryName;

                if (!$fileManager->fileExists($this->params['temporaryFullFilePath'])) {
                    $fileManager->mkdirtree($this->params['temporaryFullFilePath']);
                }

                if ($exporterInstance != null && $exporterInstance instanceof InterfaceRunner) {
                    $exporterInstance->run($this->params);
                } else {
                    throw new \Exception("Null or unexpected class: " . $exportClass);
                }
            } else {
                throw new \Exception("Cannot find class: " . $exportClass);
            }

        } catch (\Exception $e) {
            LogUtils::error($e->getMessage());
            LogUtils::log($e->getTraceAsString());
            throw $e;
        } finally {
            if (
                $this->params['temporaryFullFilePath'] != null
                && $fileManager->fileExists($this->params['temporaryFullFilePath'])
            ) {
                $fileManager->rmtree($this->params['temporaryFullFilePath']);
            }
        }
    }

    public function exportAll()
    {
        $fileManager = new \FileManager();
        try {
            $this->validateExportAllParams();

            $timestamp = date('YmdHis');
            $request = $this->params['request'];
            $context = $this->params['context'];

            $tempDirectoryName = FileUtils::filterFilename($context->getId() . '_' . $timestamp . '_exportAll');
            $this->params['timestamp'] = $timestamp;
            $this->params['temporaryFullFilePath'] = OJS_FILES_TEMPORARY_DIRECTORY . '/' . $tempDirectoryName;
            $this->params['exportAll'] = true;

            if (!$fileManager->fileExists($this->params['temporaryFullFilePath'])) {
                $fileManager->mkdirtree($this->params['temporaryFullFilePath']);
            }

            foreach ($this->getExportClasses() as $exportClass) {
                $exporter = new \ReflectionClass("CalidadFECYT\\classes\\export\\" . $exportClass);
                if ($exporter != null) {
                    $exporterInstance = $exporter->newInstanceArgs(array());

                    if ($exporterInstance != null && $exporterInstance instanceof InterfaceRunner) {
                        $exporterInstance->run($this->params);
                    } else {
                        throw new \Exception("Null or unexpected class: " . $exportClass);
                    }
                } else {
                    throw new \Exception("Cannot find class: " . $exportClass);
                }
            }

            $zipFilename = $this->params['temporaryFullFilePath'] . '/exportAll.zip';
            ZipUtils::zip([], [$this->params['temporaryFullFilePath']], $zipFilename);
            $fileManager->downloadByPath($zipFilename);

        } catch (\Exception $e) {
            LogUtils::error($e->getMessage());
            LogUtils::log($e->getTraceAsString());
            throw $e;
        } finally {
            if (
                $this->params['temporaryFullFilePath'] != null
                && $fileManager->fileExists($this->params['temporaryFullFilePath'])
            ) {
                $fileManager->rmtree($this->params['temporaryFullFilePath']);
            }
        }
    }

    public function editorial($submission)
    {
        $fileManager = new \FileManager();
        try {
            $this->validateEditorialParams($submission);

            $timestamp = date('YmdHis');
            $request = $this->params['request'];
            $context = $this->params['context'];

            $exporter = new \ReflectionClass("CalidadFECYT\\classes\\export\\Editorial");
            if ($exporter != null) {
                $this->params['submission'] = $submission;

                $exporterInstance = $exporter->newInstanceArgs(array());

                $tempDirectoryName = FileUtils::filterFilename($context->getId() . '_' . $timestamp . '_Editorial');
                $this->params['timestamp'] = $timestamp;
                $this->params['temporaryFullFilePath'] = OJS_FILES_TEMPORARY_DIRECTORY . '/' . $tempDirectoryName;

                if (!$fileManager->fileExists($this->params['temporaryFullFilePath'])) {
                    $fileManager->mkdirtree($this->params['temporaryFullFilePath']);
                }

                if ($exporterInstance != null && $exporterInstance instanceof InterfaceRunner) {
                    $exporterInstance->run($this->params);
                } else {
                    throw new \Exception("Null or unexpected class: Editorial");
                }
            } else {
                throw new \Exception("Cannot find class: Editorial");
            }

        } catch (\Exception $e) {
            LogUtils::error($e->getMessage());
            LogUtils::log($e->getTraceAsString());
            throw $e;
        } finally {
            if (
                $this->params['temporaryFullFilePath'] != null
                && $fileManager->fileExists($this->params['temporaryFullFilePath'])
            ) {
                $fileManager->rmtree($this->params['temporaryFullFilePath']);
            }
        }
    }

    private function validateExportParams()
    {
        $request = $this->params['request'];
        $context = $this->params['context'];
        if ($request == null || $context == null) {
            throw new \Exception("Invalid parameters");
        }

        $exportIndex = $request->getUserVar('exportIndex');
        $exportClasses = $this->getExportClasses();
        $length = count($exportClasses);
        if ($exportIndex == null || $exportIndex < 0 || $exportIndex > ($length - 1)) {
            throw new \Exception("Invalid exportIndex parameter");
        }
    }

    private function validateExportAllParams()
    {
        $request = $this->params['request'];
        $context = $this->params['context'];
        if ($request == null || $context == null) {
            throw new \Exception("Invalid parameters");
        }
    }

    private function validateEditorialParams($submission)
    {
        $request = $this->params['request'];
        $context = $this->params['context'];

        if ($request == null || $context == null || $submission == null) {
            throw new \Exception("Invalid parameters");
        }
    }
}