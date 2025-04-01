<?php

namespace CalidadFECYT\classes\export;

use CalidadFECYT\classes\abstracts\AbstractRunner;
use CalidadFECYT\classes\interfaces\InterfaceRunner;
use CalidadFECYT\classes\utils\HTTPUtils;

class URLs extends AbstractRunner implements InterfaceRunner
{
    private $contextId;

    public function run(&$params)
    {
        $context = $params["context"];
        $request = $params["request"];
        $dirFiles = $params['temporaryFullFilePath'];
        if (!$context) {
            throw new \Exception("Revista no encontrada");
        }
        $this->contextId = $context->getId();

        try {
            $router = $request->getRouter();
            $dispatcher = $router->getDispatcher();

            $text = __("navigation.home") . "\n" . $dispatcher->url($request, ROUTE_PAGE, null, 'index', null, null) . "\n\n";
            $text .= __("plugins.generic.calidadfecyt.editorialTeam") . "\n" . $dispatcher->url($request, ROUTE_PAGE, null, 'about', 'editorialTeam') . "\n\n";
            $text .= __("navigation.submissions") . "\n" . $dispatcher->url($request, ROUTE_PAGE, null, 'about', 'submissions') . "\n\n";
            $text .= __("plugins.generic.calidadfecyt.editorialProcess") . "\n" . $dispatcher->url($request, ROUTE_PAGE, null, 'about');

            $defaultUrls = [
                $dispatcher->url($request, ROUTE_PAGE, null, 'index', null, null),
                $dispatcher->url($request, ROUTE_PAGE, null, 'about', 'editorialTeam'),
                $dispatcher->url($request, ROUTE_PAGE, null, 'about', 'submissions'),
                $dispatcher->url($request, ROUTE_PAGE, null, 'about')
            ];

            $aboutSubItems = $this->getAboutSubItems($request);

            foreach ($aboutSubItems as $subItem) {
                $url = $subItem['url'];
                if (!in_array($url, $defaultUrls) && !empty($url)) {
                    $text .= "\n\n" . $subItem['title'] . "\n" . $url;
                }
            }

            if (isset($params['exportAll'])) {
                $file = fopen($dirFiles . "/urls.txt", "w");
                fwrite($file, $text);
                fclose($file);
            } else {
                HTTPUtils::sendStringAsFile($text, "text/plain", "urls.txt");
            }
        } catch (\Exception $e) {
            throw new \Exception('Se ha producido un error:' . $e->getMessage());
        }
    }

    /**
     * Obtiene los subelementos de "Acerca de" desde el menú primary
     * @param $request
     * @return array
     */
    private function getAboutSubItems($request)
    {
        import('lib.pkp.classes.navigationMenu.NavigationMenuDAO');
        import('lib.pkp.classes.navigationMenu.NavigationMenuItemAssignmentDAO');
        import('lib.pkp.classes.navigationMenu.NavigationMenuItemDAO');


        $context = $request->getContext();
        $contextId = $context ? $context->getId() : CONTEXT_ID_NONE;


        $navigationMenuDao = \DAORegistry::getDAO('NavigationMenuDAO');
        $navigationMenuItemAssignmentDao = \DAORegistry::getDAO('NavigationMenuItemAssignmentDAO');
        $navigationMenuItemDao = \DAORegistry::getDAO('NavigationMenuItemDAO');


        $primaryMenus = $navigationMenuDao->getByArea($contextId, 'primary')->toArray();
        if (empty($primaryMenus)) {
            return [];
        }

        $primaryMenu = $primaryMenus[0];
        $primaryMenuId = $primaryMenu->getId();

        $assignments = $navigationMenuItemAssignmentDao->getByMenuId($primaryMenuId);

        $aboutMenuItem = null;
        $aboutSubItems = [];

        while ($assignment = $assignments->next()) {
            $menuItemId = $assignment->getMenuItemId();
            $menuItem = $navigationMenuItemDao->getById($menuItemId);

            if ($menuItem->getType() === NMI_TYPE_ABOUT && !$assignment->getParentId()) {
                $aboutMenuItem = [
                    'id' => $menuItem->getId(),
                    'title' => $menuItem->getLocalizedTitle(),
                    'assignmentId' => $assignment->getId()
                ];
            }
        }


        $typeToPathMap = [
            NMI_TYPE_ABOUT => 'about',
            NMI_TYPE_SUBMISSIONS => 'submissions',
            NMI_TYPE_EDITORIAL_TEAM => 'editorialTeam',
            NMI_TYPE_CONTACT => 'contact',
            NMI_TYPE_ANNOUNCEMENTS => 'announcements',
            NMI_TYPE_USER_PROFILE => 'profile',
            NMI_TYPE_USER_REGISTER => 'register',
            NMI_TYPE_USER_LOGIN => 'login',
            NMI_TYPE_SEARCH => 'search',
            NMI_TYPE_PRIVACY => 'privacy'
        ];

        if ($aboutMenuItem) {
            $assignments = $navigationMenuItemAssignmentDao->getByMenuId($primaryMenuId);
            while ($assignment = $assignments->next()) {
                if ($assignment->getParentId() == $aboutMenuItem['id']) {
                    $subMenuItem = $navigationMenuItemDao->getById($assignment->getMenuItemId());
                    $type = $subMenuItem->getType();

                    if ($type === NMI_TYPE_CUSTOM) {
                        $path = $subMenuItem->getPath();
                    } elseif (isset($typeToPathMap[$type])) {
                        $path = $typeToPathMap[$type];
                    } else {
                        $path = strtolower(str_replace('NMI_TYPE_', '', $type));
                    }


                    if ($path === 'about') {
                        continue;
                    }


                    if ($type === NMI_TYPE_CUSTOM) {
                        $url = $subMenuItem->getUrl()
                            ? $subMenuItem->getUrl()
                            : $request->getRouter()->getDispatcher()->url(
                                $request,
                                ROUTE_PAGE,
                                null,
                                $path,
                                null
                            );
                    } else {
                        $url = $request->getRouter()->getDispatcher()->url(
                            $request,
                            ROUTE_PAGE,
                            null,
                            'about',
                            $path
                        );
                    }

                    $title = $subMenuItem->getLocalizedTitle();
                    if (empty($title)) {
                        $titleLocaleKey = $subMenuItem->getTitleLocaleKey();
                        $title = $titleLocaleKey ? __($titleLocaleKey) : ucfirst($path);
                    }

                    $aboutSubItems[] = [
                        'id' => $subMenuItem->getId(),
                        'title' => $title,
                        'url' => $url,
                        'path' => $path,
                        'type' => $type
                    ];
                }
            }
        }

        return $aboutSubItems;
    }
}