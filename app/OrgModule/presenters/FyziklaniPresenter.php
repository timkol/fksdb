<?php

namespace OrgModule;

use Kdyby\BootstrapFormRenderer\BootstrapRenderer;
use \Nette\Application\UI\Form;
use \Nette\Diagnostics\Debugger;
use \FKSDB\Components\Forms\Factories\FyziklaniFactory;
use \FKSDB\Components\Grids\Fyziklani\FyziklaniTeamsGrid;
use \FKSDB\Components\Grids\Fyziklani\FyziklaniSubmitsGrid;
use \FKSDB\Components\Grids\Fyziklani\FyziklaniTaskGrid;

class FyziklaniPresenter extends \OrgModule\BasePresenter {

    const EVENT_TYPE_ID = 1;
    const IMPORT_STATE_UPDATE_N_INSERT = 1;
    const IMPORT_STATE_REMOVE_N_INSERT = 2;
    const IMPORT_STATE_INSERT = 3;

    /**
     *
     * @var Nette\Database\Context 
     */
    public $database;
    public $event;
    public $eventID;
    public $eventYear;

    /**
     * @var FyziklaniFactory
     */
    private $fyziklaniFactory;

    /**
     *
     * @var \Nette\DI\Container
     */
    public $container;

    public function __construct(\Nette\Database\Connection $database,FyziklaniFactory $pointsFactory,\Nette\DI\Container $container) {
        parent::__construct();
        $this->container = $container;
        //Debugger::barDump($container->parameters['fyziklani']);

        $this->fyziklaniFactory = $pointsFactory;
        $this->database = $database;
    }

    public function startup() {

        if(!$this->eventExist()){
            throw new \Nette\Application\BadRequestException('Pre tento ročník nebolo najduté Fyzikláni',404);
        }
        $this->event = $this->getActualEvent();
        $this->eventYear = $this->event->event_year;
        $this->eventID = $this->getCurrentEventID();
        parent::startup();
    }

    public function renderResults() {
        if($this->isAjax()){
            $result = [];
            $type = $this->getHttpRequest()->getQuery('type');


            if($type == 'init'){
                foreach ($this->database->table(\DbNames::TAB_FYZIKLANI_TASK)->where('event_id',$this->eventID)->order('label') as $row) {
                    $result['tasks'][] = ['label' => $row->label,'name' => $row->name,'task_id' => $row->fyziklani_task_id];
                }
                foreach ($this->database->table(\DbNames::TAB_E_FYZIKLANI_TEAM)->where('event_id',$this->eventID) as $row) {
                    $result['teams'][] = ['category' => $row->category,'room' => $row->room,'name' => $row->name,'team_id' => $row->e_fyziklani_team_id];
                }
            }elseif($type == 'refresh'){
                $submits = $this->presenter->database->table(\DbNames::TAB_FYZIKLANI_SUBMIT)
                        ->where('e_fyziklani_team.event_id',$this->eventID);
                foreach ($submits as $submit) {
                    $result['submits'][] = ['points' => $submit->points,'team_id' => $submit->e_fyziklani_team_id,'task_id' => $submit->fyziklani_task_id];
                }
            }else{
                throw new \Nette\Application\BadRequestException('error',404);
            }

            $result['times'] = ['toStart' => strtotime($this->container->parameters['fyziklani']['start']) - time(),'toEnd' => strtotime($this->container->parameters['fyziklani']['end']) - time(),'visible' => $this->isResultsVisible()];

            $this->sendResponse(new \Nette\Application\Responses\JsonResponse($result));
        }else{
            $this->template->rooms = ['M1','M2','S1','S6','F1','F2'];
            $this->template->category = $this->getHttpRequest()->getQuery('category');
        }
    }

    private function isResultsVisible() {
        return (time() < strtotime($this->container->parameters['fyziklani']['results']['hidde'])) && (time() > strtotime($this->container->parameters['fyziklani']['results']['display']));
    }

    public function renderClose($id) {
        $this->template->id = $id;
        if($id){
            $this->template->submits = $this->database->table(\DbNames::TAB_FYZIKLANI_SUBMIT)->where('e_fyziklani_team_id',$id);
        }
    }

    public function createComponentSubmitsGrid() {
        $grid = new FyziklaniSubmitsGrid($this);
        return $grid;
    }

    public function createComponentCloseGrid() {
        $grid = new FyziklaniTeamsGrid($this->database);
        return $grid;
    }

    public function createComponentCloseForm() {
        $form = new Form();
        $form->setRenderer(new BootstrapRenderer());
        $form->addHidden('e_fyziklani_team_id',0);
        $form->addCheckbox('submit_task_correct',_('Úlohy a počty bodov sú správne'))
                ->setRequired(_('Skontrolujte prosím správnosť zadania bodov!'));
        $form->addText('next_task',_('Úloha u vydávačov'))->setDisabled();
        $form->addCheckbox('next_task_correct',_('Úloha u vydávačov sa zhaduje'))
                ->setRequired(_('Skontrolujte prosím zhodnosť úlohy u vydávačov'));


        $form->addSubmit('send','Potvrdiť spravnosť');
        $form->onSuccess[] = [$this,'closeFormSucceeded'];
        return $form;
    }

    public function closeFormSucceeded(Form $form) {

        $values = $form->getValues();
        $submits = $this->database->table(\DbNames::TAB_FYZIKLANI_SUBMIT)
                ->select('*')
                ->where('e_fyziklani_team_id',$values->e_fyziklani_team_id);
        Debugger::barDump($submits);
        $sum = 0;
        foreach ($submits as $submit) {
            $sum += $submit->points;
        }
        if($this->database->query('UPDATE '.\DbNames::TAB_E_FYZIKLANI_TEAM.' SET ? WHERE e_fyziklani_team_id=? ',['points' => $sum],$values->e_fyziklani_team_id)){
            $this->redirect(':Org:Fyziklani:close');
        }
    }

    /**
     * @TODO ak je už uzavreté vyfakovať 
     * @param type $id
     */
    public function actionClose($id) {

        if($id){
            if($this->isOpenSubmit($id)){
                if(!$this->teamExist($id)){
                    $this->flashMessage('Tým neexistuje','danger');
                    $this->redirect(':org:fyziklani:close');
                }
                $this['closeForm']->setDefaults(['e_fyziklani_team_id' => $id,'next_task' => $this->getNextTask($id)->nextTask]);
            }else{
                $this->flashMessage('tento tým má už uzavreté bodovanie','danger');
                $this->redirect(':org:fyziklani:close');
            }
        }
    }

    public function getNextTask($teamID) {

        $return = [];
        $submits = $this->database->table(\DbNames::TAB_FYZIKLANI_SUBMIT)->where('e_fyziklani_team_id=?',$teamID)->count();
        $allTask = $this->database->query('SELECT * FROM '.\DbNames::TAB_FYZIKLANI_TASK.' WHERE event_id = ? ORDER BY label',$this->eventID)->fetchAll();
        $lastID = $submits + $this->container->parameters['fyziklani']['taskOnBoard'] - 1;/** @because index start with 0; */
        $nextID = $lastID + 1;
        if(array_key_exists($nextID,$allTask)){
            $return['nextTask'] = $allTask[$nextID]->label;
        }else{
            $return['nextTask'] = null;
        }
        if(array_key_exists($lastID,$allTask)){
            $return['lastTask'] = $allTask[$lastID]->label;
        }else{
            $return['lastTask'] = null;
        }
        return (object) $return;
    }

    public function createComponentFyziklaniEditForm() {
        $form = $this->fyziklaniFactory->createEditForm();
        $form->setRenderer(new BootstrapRenderer());

        $form->addSubmit('send','Uložit');
        $form->onSuccess[] = [$this,'editFormSucceeded'];
        return $form;
    }

    public function actionEdit($id) {
        if(!$id){
            throw new \Nette\Application\BadRequestException('ID je povinné',400);
        }
        /* Neexitujúci submit nejde editovať */
        $teamID = $this->submitToTeam($id);
        if(!$teamID){
            $this->flashMessage(_('Submit neexistuje'),'danger');
            $this->redirect(':Org:Fyziklani:submits');
        }
        /* Uzatvorené bodovanie nejde editovať; */
        if(!$this->isOpenSubmit($teamID)){
            $this->flashMessage(_('Bodovaní tohto týmu je uzvřené'),'danger');
            $this->redirect(':Org:Fyziklani:submits');
        }


        $submit = $this->database->table(\DbNames::TAB_FYZIKLANI_SUBMIT)
                        ->select('fyziklani_submit.*,fyziklani_task.label,e_fyziklani_team_id.name')
                        ->where('fyziklani_submit_id = ?',$id)->fetch();

        $this->template->fyziklani_submit_id = $submit ? true : false;
        $this['fyziklaniEditForm']->setDefaults([
            'team_id' => $submit->e_fyziklani_team_id,
            'task' => $submit->label,
            'points' => $submit->points,
            'team' => $submit->name,
            'submit_id' => $submit->fyziklani_submit_id
        ]);
    }

    public function createComponentFyziklaniEntryForm() {
        $form = $this->fyziklaniFactory->createEntryForm();
        $form->setRenderer(new BootstrapRenderer());
        $form->onSuccess[] = [$this,'entryFormSucceeded'];
        return $form;
    }

    public function titleEntry() {
        $this->setTitle(_('Zadávaní bodů'));
    }

    public function authorizedEntry() {
        $this->setAuthorized($this->getContestAuthorizator()->isAllowed('fyziklani','entry',$this->getSelectedContest()));
    }

    public function titleEdit() {
        $this->setTitle(_('Uprava bodovania'));
    }

    public function authorizedEdit() {
        $this->setAuthorized($this->getContestAuthorizator()->isAllowed('fyziklani','edit',$this->getSelectedContest()));
    }

    public function titleSubmits() {
        $this->setTitle(_('Submity'));
    }

    public function authorizedSubmits() {
        $this->setAuthorized($this->getContestAuthorizator()->isAllowed('fyziklani','submits',$this->getSelectedContest()));
    }

    public function titleClose() {
        $this->setTitle(_('Uzavierka bodovania'));
    }

    public function authorizedClose() {
        $this->setAuthorized($this->getContestAuthorizator()->isAllowed('fyziklani','close',$this->getSelectedContest()));
    }

    public function titleDefault() {
        $this->setTitle(_('Fykosí Fyzikláni'));
    }

    public function authorizedDefault() {
        $this->setAuthorized($this->getContestAuthorizator()->isAllowed('fyziklani','default',$this->getSelectedContest()));
    }

    public function titleResults() {
        $this->setTitle(_('Výsledky Fykosího Fyzikláni'));
    }

    public function authorizedResults() {
        $this->setAuthorized($this->getContestAuthorizator()->isAllowed('fyziklani','results',$this->getSelectedContest()));
    }

    public function titleTask() {
        $this->setTitle(_('Úlohy Fykosího Fyzikláni'));
    }

    public function authorizedTask() {
        $this->setAuthorized($this->getContestAuthorizator()->isAllowed('fyziklani','task',$this->getSelectedContest()));
    }

    public function titleTaskimport() {
        $this->setTitle(_('Import úloh Fykosího Fyzikláni'));
    }

    public function authorizedTaskimport() {
        $this->setAuthorized($this->getContestAuthorizator()->isAllowed('fyziklani','taskimport',$this->getSelectedContest()));
    }

    public function entryFormSucceeded(Form $form) {
        Debugger::timer();
        $values = $form->getValues();

        /** skontroluje pratnosť kontrolu */
        if(!$this->checkContolNumber($values->taskCode)){
            $this->flashMessage(_('Chybne zadaný kód úlohy.'),'danger');
            //  $this->redirect('this');
            return;
        }
        /* Existenica týmu */
        $teamID = $this->extractTeamID($values->taskCode);
        if(!$this->teamExist($teamID)){
            $this->flashMessage('Team '.$teamID.' nexistuje','danger');
            $this->redirect('this');
            return;
        }
        /* otvorenie submitu */
        if(!$this->isOpenSubmit($teamID)){
            $this->flashMessage(_('Bodovanie tohoto týmu je uzavreté'),'danger');
            $this->redirect('this');
            return;
        }
        /* správny label */
        $taskLabel = $this->extractTaksLabel($values->taskCode);
        $taksID = $this->taskLabelToTaskID($taskLabel);
        if(!$taksID){
            $this->flashMessage('Úloha  '.$taskLabel.' nexistuje','danger');
            //$this->redirect('this');
            return;
        }
        /* Nezadal sa duplicitne toto nieje editácia */
        if($this->submitExist($taksID,$teamID)){
            $this->flashMessage('Úloha '.$taskLabel.' už bola zadaná','warning');
            //   $this->redirect('this');
            return;
        }

        $r = $this->database->query('INSERT INTO '.\DbNames::TAB_FYZIKLANI_SUBMIT,[
            'points' => $values->points,
            'fyziklani_task_id' => $taksID,
            'e_fyziklani_team_id' => $teamID
        ]);
        $t = Debugger::timer();
        if($r){
            $this->flashMessage(_('Body boli uložené. ('.$values->points.' bodů, tým ID '.$teamID.', '.$t.'s)'),'success');
            $this->redirect('this');
        }else{
            $this->flashMessage(_('Vyskytla sa chyba'),'danger');
        }
    }

    public function editFormSucceeded(Form $form) {
        $values = $form->getValues();

        $teamID = $this->submitToTeam($values->submit_id);
        if(!$teamID){
            $this->flashMessage(_('Submit neexistuje'),'danger');
            $this->redirect(':Org:Fyziklani:submits');
        }

        /* Uzatvorené bodovanie nejde editovať; */
        if(!$this->isOpenSubmit($teamID)){
            $this->flashMessage(_('Bodovanie tohoto týmu je uzavreté'),'danger');
            $this->redirect(':Org:Fyziklani:submits');
        }
        try {
            $this->database->query('UPDATE '.\DbNames::TAB_FYZIKLANI_SUBMIT.' SET ? where fyziklani_submit_id=?',[
                'points' => $values->points
                    ],$values->submit_id);
            $this->flashMessage(_('Body boli zmenené'),'success');
            $this->redirect('this');
        } catch (Exception $e) {
            $this->flashMessage('ops','danger');
            Debugger::log($e);
        }
    }

    public function submitExist($taksID,$teamID) {
        return (bool) $this->database->table(\DbNames::TAB_FYZIKLANI_SUBMIT)
                        ->where('fyziklani_task_id=?',$taksID)
                        ->where('e_fyziklani_team_id=?',$teamID)
                        ->count();
    }

    public function teamExist($teamID) {
        return $this->database->table(\DbNames::TAB_E_FYZIKLANI_TEAM)
                        ->get($teamID)->event_id == $this->eventID;
    }

    /** Vrati tru ak pre daný ročník existuje fyzikláni */
    public function eventExist() {
        return $this->getActualEvent() ? true : false;
    }

    public function extractTeamID($numLabel) {
        return (int) substr($numLabel,0,5);
    }

    public function extractTaksLabel($teamTaskLabel) {
        return (string) substr($teamTaskLabel,5,2);
    }

    public function getNumLabel($teamTaskLabel) {
        return str_replace(array('A','B','C','D','E','F','G','H'),array(1,2,3,4,5,6,7,8),$teamTaskLabel);
    }

    private function checkContolNumber($taskCode) {
        $subcode = $this->getNumLabel(substr($taskCode,0,-1));
        $control = substr($taskCode,-1);
        return ($subcode % 11) == str_replace('X',10,$control);
    }

    public function taskLabelToTaskID($taskLabel) {
        $row = $this->database->table(\DbNames::TAB_FYZIKLANI_TASK)
                ->where('label = ?',$taskLabel)
                ->where('event_id = ?',$this->eventID)
                ->fetch();
        if($row){
            return $row->fyziklani_task_id;
        }
        return false;
    }

    public function getCurrentEventID() {
        return $this->getActualEvent()->event_id;
    }

    /** vráti paramtre daného eventu */
    public function getActualEvent() {
        return $this->database->table(\DbNames::TAB_EVENT)
                        ->where('year',$this->year)
                        ->where('event_type_id',$this->container->parameters['fyziklani']['eventTypeID'])
                        ->fetch();
    }

    private function getSubmit($submitID) {
        return $this->database->table(\DbNames::TAB_FYZIKLANI_SUBMIT)
                        ->where('fyziklani_submit_id',$submitID)
                        ->fetch();
    }

    public function submitToTeam($submitID) {
        $r = $this->getSubmit($submitID);
        return $r ? $r->e_fyziklani_team_id : $r;
    }

    public function isOpenSubmit($teamID) {
        $points = $this->database->table(\DbNames::TAB_E_FYZIKLANI_TEAM)
                        ->where('e_fyziklani_team_id',$teamID)
                        ->fetch()->points;
        return !is_numeric($points);
    }

    public function createComponentTaskImportForm() {
        $form = new Form();
        $form->setRenderer(new BootstrapRenderer);
        $form->addUpload('csvfile')->setRequired();

        $form->addSelect('state',_('Vyberte akciu'),[
            self::IMPORT_STATE_UPDATE_N_INSERT => _('Updatnuť úlohy a pridať ak neexistuje'),
            self::IMPORT_STATE_REMOVE_N_INSERT => _('Ostrániť všetky úlohy a nahrať nove'),
            self::IMPORT_STATE_INSERT => _('Pridať ak neexistuje'),
        ]);
        $form->addSubmit('import',_('importovať'));
        $form->onSuccess[] = [$this,'taskImportFormSucceeded'];
        return $form;
    }

    public function taskImportFormSucceeded(Form $form) {
        $values = $form->getValues();
        $filename = $values->csvfile->getTemporaryFile();
        if($values->state == self::IMPORT_STATE_REMOVE_N_INSERT){
            $this->database->query('DELETE FROM '.\DbNames::TAB_FYZIKLANI_TASK.' WHERE event_id=?',$this->eventID);
        }
        $parser = new \FKS\Utils\CSVParser($filename,\FKS\Utils\CSVParser::INDEX_FROM_HEADER);
        foreach ($parser as $row) {
            $taskID = $this->taskLabelToTaskID($row['label']);
            if($taskID){
                if($values->state == self::IMPORT_STATE_UPDATE_N_INSERT){
                    if($this->database->query('UPDATE '.\DbNames::TAB_FYZIKLANI_TASK.' SET ? WHERE fyziklani_task_id =?',[
                                'label' => $row['label'],
                                'name' => $row['name']
                                    ],$taskID)){
                        $this->flashMessage('Úloha '.$row['label'].' "'.$row['name'].'" bola updatnuta','info');
                    }else{
                        $this->flashMessage(_('Vyskytal sa chyba'),'danger');
                    }
                }else{
                    $this->flashMessage('Úloha '.$row['label'].' "'.$row['name'].'" nebola pozmenená','warning');
                }
            }else{
                if($this->database->query('INSERT INTO '.\DbNames::TAB_FYZIKLANI_TASK,[
                            'label' => $row['label'],
                            'name' => $row['name'],
                            'event_id' => $this->eventID
                        ])){
                    $this->flashMessage('Úloa '.$row['label'].' "'.$row['name'].'" bola vložená','success');
                }else{
                    $this->flashMessage(_('Vyskytal sa chyba'),'danger');
                }
            }
        }
        $this->redirect('this');
    }

    public function createComponentTaskGrid() {
        $grid = new FyziklaniTaskGrid($this);
        return $grid;
    }

}
