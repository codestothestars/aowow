<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


$cat       = Util::extractURLParams($pageParam)[0];
$path      = [0, 8];
$validCats = [0, 1, 2];
$title     = [Util::ucFirst(Lang::$game['pets'])];
$cacheKey  = implode('_', [CACHETYPE_PAGE, TYPE_PET, -1, isset($cat) ? $cat : -1, User::$localeId]);

if (!Util::isValidPage($validCats, $cat))
    $smarty->error();

$path[] = $cat;                                             // should be only one parameter anyway

if (isset($cat))
    array_unshift($title, Lang::$pet['cat'][$cat]);

if (!$smarty->loadCache($cacheKey, $pageData))
{
    $pets = new PetList(isset($cat) ? array(['type', (int)$cat]) : []);

    $pageData = array(
        'listviews' => []
    );

    $lvPet = array(
        'file'   => 'pet',
        'data'   => $pets->getListviewData(),
        'params' => array(
            'visibleCols' => "$['abilities']"
        )
    );

    if (($mask = $pets->hasDiffFields(['type'])) == 0x0)
        $lvPet['params']['hiddenCols'] = "$['type']";

    $pageData['listviews'][] = $lvPet;

    $pets->addGlobalsToJscript($smarty, GLOBALINFO_RELATED);

    $smarty->saveCache($cacheKey, $pageData);
}


// menuId 8: Pets     g_initPath()
//  tabid 0: Database g_initHeader()
$smarty->updatePageVars(array(
    'title' => implode(" - ", $title),
    'path'  => "[".implode(", ", $path)."]",
    'tab'   => 0
));
$smarty->assign('lang', Lang::$main);
$smarty->assign('lvData', $pageData);

// load the page
$smarty->display('generic-no-filter.tpl');

?>
