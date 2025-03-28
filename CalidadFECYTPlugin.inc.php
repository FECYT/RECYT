<?php

require_once(__DIR__ . '/vendor/autoload.php');
import('lib.pkp.classes.plugins.GenericPlugin');

use CalidadFECYT\classes\main\CalidadFECYT;
use APP\i18n\AppLocale;
use PKP\core\JSONMessage;
use Illuminate\Support\Facades\DB;
class CalidadFECYTPlugin extends GenericPlugin
{

    public function register($category, $path, $mainContextId = NULL)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return true;
        }
        $this->addLocaleData();
        if ($success && $this->getEnabled()) {
            return $success;
        }
        return $success;
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
        $this->import('classes.main.CalidadFECYT');
        import('lib.pkp.classes.linkAction.request.RedirectAction');

        $templateMgr = TemplateManager::getManager($request);

        $context = $request->getContext();
        $router = $request->getRouter();

        $calidadFECYT = new CalidadFECYT(array(
            'request' => $request,
            'context' => $context
        ));

        switch ($request->getUserVar('verb')) {
            case 'settings':
                $templateParams = array(
                    "journalTitle" => $context->getLocalizedName()
                );

                $exportAllAction = new LinkAction('exportAllLinkAction', new RedirectAction($router->url($request, null, null, 'manage', null, array(
                    'verb' => 'exportAll',
                    'plugin' => $this->getName(),
                    'category' => 'generic',
                )), '_self'), __('plugins.generic.calidadfecyt.export.all'), null);

                $linkActions = array();

                $index = 0;
                foreach ($calidadFECYT->getExportClasses() as $export) {
                    $exportAction = new stdClass();
                    $exportAction->name = $export;
                    $exportAction->linkAction = new LinkAction('export' . $export . 'LinkAction', new RedirectAction($router->url($request, null, null, 'manage', null, array(
                        'verb' => 'export',
                        'plugin' => $this->getName(),
                        'category' => 'generic',
                        'exportIndex' => $index
                    )), '_self'), __('plugins.generic.calidadfecyt.export.' . $export), null);

                    $linkActions[] = $exportAction;
                    $index++;
                }

                $templateParams['submissions'] = $this->getSubmissions($context->getId());
                $templateParams['exportAllAction'] = $exportAllAction;
                $templateParams['linkActions'] = $linkActions;
                $templateMgr->assign($templateParams);

                $templateMgr->assign('editorialUrl', $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    array()
                ));

                return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('settings_form.tpl')));
            case 'export':
                try {
                    $request->checkCSRF();
                    $calidadFECYT->export();
                } catch (Exception $e) {
                    $dispatcher = $request->getDispatcher();
                    $dispatcher->handle404();
                }
                return;
            case 'exportAll':
                try {
                    $request->checkCSRF();
                    $calidadFECYT->exportAll();
                } catch (Exception $e) {
                    $dispatcher = $request->getDispatcher();
                    $dispatcher->handle404();
                }
                return;
            case 'editorial':
                try {
                    $request->checkCSRF();
                    $calidadFECYT->editorial($request->getUserVar('submission'));
                } catch (Exception $e) {
                    $dispatcher = $request->getDispatcher();
                    $dispatcher->handle404();
                }
                return;
            case 'default':
                $dispatcher = $request->getDispatcher();
                $dispatcher->handle404();
                return;
        }

        return parent::manage($args, $request);
    }

    public function getSubmissions($contextId)
    {
        $locale = AppLocale::getLocale();

        $results = DB::table('submissions as s')
            ->select('s.submission_id', 'pp_title.setting_value as title')
            ->join('publications as p', 'p.publication_id', '=', 's.current_publication_id')
            ->join('publication_settings as pp_issue', 'p.publication_id', '=', 'pp_issue.publication_id')
            ->where('p.status', '=', 3)
            ->join('publication_settings as pp_title', 'p.publication_id', '=', 'pp_title.publication_id')
            ->joinSub(
                DB::table('issues')
                    ->select('issue_id')
                    ->where('journal_id', $contextId)
                    ->where('published', 1)
                    ->orderBy('date_published', 'desc')
                    ->limit(4),
                'latest_issues',
                function ($join) {
                    $join->on('pp_issue.setting_value', '=', 'latest_issues.issue_id');
                }
            )
            ->where('pp_issue.setting_name', 'issueId')
            ->where('pp_title.setting_name', 'title')
            ->where('pp_title.locale', $locale)
            ->get();

        $submissions = [];
        foreach ($results as $row) {
            $title = $row->title;
            $submissions[] = [
                'id' => $row->submission_id,
                'title' => (strlen($title) > 80) ? mb_substr($title, 0, 77, 'UTF-8') . '...' : $title,
            ];
        }

        return $submissions;
    }
}
