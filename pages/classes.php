<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');


// menuId 12: Class    g_initPath()
//  tabId  0: Database g_initHeader()
class ClassesPage extends GenericPage
{
    use ListPage;

    protected $type          = TYPE_CLASS;
    protected $tpl           = 'list-page-generic';
    protected $path          = [0, 12];
    protected $tabId         = 0;
    protected $mode          = CACHETYPE_PAGE;

    public function __construct()
    {
        $this->name = Util::ucFirst(Lang::$game['classes']);

        parent::__construct();
    }

    protected function generateContent()
    {
        $classes = new CharClassList();
        if (!$classes->error)
        {
            $this->lvData[] = array(
                'file'   => 'class',
                'data'   => $classes->getListviewData(),
                'params' => []
            );
        }
    }

    protected function generateTitle()
    {
        array_unshift($this->title, Util::ucFirst(Lang::$game['classes']));
    }

    protected function generatePath() {}
}

?>
