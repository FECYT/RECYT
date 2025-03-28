<?php

require_once(__DIR__ . '/vendor/autoload.php');
import('lib.pkp.classes.plugins.GenericPlugin');

use CalidadFECYT\classes\main\CalidadFECYT;

class CalidadFECYTPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = NULL)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return true;
        }
        $this->addLocaleData();
        if ($success && $this->getEnabled($mainContextId)) {

            return $success;
        }
        return $success;
    }

    public function addLocaleData($locale = null)
    {
        $locale = $locale ?? AppLocale::getLocale();
        if ($localeFilenames = $this->getLocaleFilename($locale)) {
            foreach ((array) $localeFilenames as $localeFilename) {
                AppLocale::registerLocaleFile($locale, $localeFilename);
            }
            return true;
        }
        return false;
    }

    public function getName()
    {
        return 'CalidadFECYTPlugin';
    }

    public function getDisplayName()
    {
        return __('plugins.generic.calidadfecyt.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.calidadfecyt.description');
    }

    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        return array_merge($this->getEnabled() ? array(
            new LinkAction('settings', new AjaxModal($router->url($request, null, null, 'manage', null, array(
                'verb' => 'settings',
                'plugin' => $this->getName(),
                'category' => 'generic',
            )), $this->getDisplayName()), __('manager.plugins.settings'), null)
        ) : array(), parent::getActions($request, $verb));
    }

    public function manage($args, $request)
    {
        error_log("manage() called with verb: " . $request->getUserVar('verb'));
        $this->import('classes.main.CalidadFECYT');
        $templateMgr = TemplateManager::getManager($request);
        $context = $request->getContext();
        $router = $request->getRouter();

        switch ($request->getUserVar('verb')) {
            case 'settings':
                $templateParams = array(
                    "journalTitle" => $context->getLocalizedName(),
                    "defaultDateFrom" => date('Y-m-d', strtotime("-1 year")),
                    "defaultDateTo" => date('Y-m-d', strtotime("-1 day")),
                    "baseUrl" => $router->url($request, null, null, 'manage', null, array(
                        'plugin' => $this->getName(),
                        'category' => 'generic'
                    ))
                );

                $calidadFECYT = new CalidadFECYT(array('request' => $request, 'context' => $context));
                $linkActions = array();
                $index = 0;
                foreach ($calidadFECYT->getExportClasses() as $export) {
                    $exportAction = new stdClass();
                    $exportAction->name = $export;
                    $exportAction->index = $index;
                    $linkActions[] = $exportAction;
                    $index++;
                }

                $templateParams['submissions'] = $this->getSubmissions($context->getId());
                $templateParams['exportAllAction'] = true;
                $templateParams['linkActions'] = $linkActions;
                $templateMgr->assign($templateParams);

                return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('settings_form.tpl')));
            case 'export':
                try {
                    $request->checkCSRF();
                    $params = array(
                        'request' => $request,
                        'context' => $context,
                        'dateFrom' => $request->getUserVar('dateFrom') ? date('Ymd', strtotime($request->getUserVar('dateFrom'))) : null,
                        'dateTo' => $request->getUserVar('dateTo') ? date('Ymd', strtotime($request->getUserVar('dateTo'))) : null
                    );
                    $calidadFECYT = new CalidadFECYT($params);
                    $calidadFECYT->export();
                } catch (Exception $e) {
                    $dispatcher = $request->getDispatcher();
                    $dispatcher->handle404();
                }
                return;
            case 'exportAll':
                try {
                    $request->checkCSRF();
                    $params = array(
                        'request' => $request,
                        'context' => $context,
                        'dateFrom' => $request->getUserVar('dateFrom') ? date('Ymd', strtotime($request->getUserVar('dateFrom'))) : null,
                        'dateTo' => $request->getUserVar('dateTo') ? date('Ymd', strtotime($request->getUserVar('dateTo'))) : null
                    );
                    $calidadFECYT = new CalidadFECYT($params);
                    $calidadFECYT->exportAll();
                } catch (Exception $e) {
                    $dispatcher = $request->getDispatcher();
                    $dispatcher->handle404();
                }
                return;
            case 'editorial':
                try {
                    $request->checkCSRF();
                    $params = array(
                        'request' => $request,
                        'context' => $context,
                        'dateFrom' => $request->getUserVar('dateFrom') ? date('Ymd', strtotime($request->getUserVar('dateFrom'))) : null,
                        'dateTo' => $request->getUserVar('dateTo') ? date('Ymd', strtotime($request->getUserVar('dateTo'))) : null
                    );
                    $calidadFECYT = new CalidadFECYT($params);
                    $calidadFECYT->editorial($request->getUserVar('submission'));
                } catch (Exception $e) {
                    $dispatcher = $request->getDispatcher();
                    $dispatcher->handle404();
                }
                return;
            default:
                $dispatcher = $request->getDispatcher();
                $dispatcher->handle404();
                return;
        }

        return parent::manage($args, $request);
    }

    public function getSubmissions($contextId)
    {
        $locale = AppLocale::getLocale();
        $submissionDao = \DAORegistry::getDAO('SubmissionDAO');
        $query = $submissionDao->retrieve(
            "SELECT s.submission_id, pp_title.setting_value AS title
                FROM submissions s
                         INNER JOIN publications p ON p.publication_id = s.current_publication_id
                         INNER JOIN publication_settings pp_issue ON p.publication_id = pp_issue.publication_id
                         INNER JOIN publication_settings pp_title ON p.publication_id = pp_title.publication_id
                         INNER JOIN (
                    SELECT issue_id
                    FROM issues
                    WHERE journal_id = " . $contextId . "
                      AND published = 1
                    ORDER BY date_published DESC
                    LIMIT 4
                ) AS latest_issues ON pp_issue.setting_value = latest_issues.issue_id
                WHERE pp_issue.setting_name = 'issueId'
                  AND pp_title.setting_name = 'title'
                  AND pp_title.locale='" . $locale . "'"
        );

        $submissions = array();
        foreach ($query as $value) {
            $row = get_object_vars($value);
            $title = $row['title'];
            $submissions[] = [
                'id' => $row['submission_id'],
                'title' => (strlen($title) > 80) ? mb_substr($title, 0, 77, 'UTF-8') . '...' : $title,
            ];
        }
        return $submissions;
    }
}