<?php

namespace FKSDB\Components\Controls;

use ModelContest;
use ModelRole;
use Nette\Application\BadRequestException;
use Nette\Application\UI\Control;
use Nette\Http\Session;
use ServiceContest;
use YearCalculator;

/**
 * Due to author's laziness there's no class doc (or it's self explaining).
 * 
 * @author Michal Koutný <michal@fykos.cz>
 */
class ContestChooser extends Control {

    const SOURCE_SESSION = 0x1;
    const SOURCE_URL = 0x2;
    const SESSION_PREFIX = 'contestPreset';
    const CONTESTS_ALL = '__*';
    const YEARS_ALL = '__*';
    /** @obsolete (no first contest anymore) */
    const DEFAULT_FIRST = 'first';
    const DEFAULT_SMART_FIRST = 'smfirst';
    const DEFAULT_NULL = 'null';

    /**
     * @var mixed
     */
    private $contestsDefinition;

    /**
     * @var mixed
     */
    private $yearDefinition;

    /**
     * @var ModelContest[]
     */
    private $contests;

    /**
     * @var Session
     */
    private $session;

    /**
     * @var YearCalculator
     */
    private $yearCalculator;

    /**
     * @var ServiceContest
     */
    private $serviceContest;

    /**
     * @var ModelContest
     */
    private $contest;

    /**
     * @var int
     */
    private $year;

    /**
     * @var boolean
     */
    private $valid;
    private $initialized = false;

    /**
     * @var enum DEFAULT_*
     */
    private $defaultContest = self::DEFAULT_SMART_FIRST;

    /**
     * @var int bitmask of what "sources" are used to infer selected contest
     */
    private $contestSource = 0xffffffff;

    /**
     * 

     * @param Session $session
     * @param YearCalculator $yearCalculator
     * @param ServiceContest $serviceContest
     */
    function __construct(Session $session, YearCalculator $yearCalculator, ServiceContest $serviceContest) {
        $this->session = $session;
        $this->yearCalculator = $yearCalculator;
        $this->serviceContest = $serviceContest;
    }

    /**
     * @param mixed $contestsDefinition role enum|CONTESTS_ALL|array of contests
     */
    public function setContests($contestsDefinition) {
        $this->contestsDefinition = $contestsDefinition;
    }

    /**
     * 
     * @param mixed $yearDefinition enum
     */
    public function setYears($yearDefinition) {
        $this->yearDefinition = $yearDefinition;
    }

    public function getDefaultContest() {
        return $this->defaultContest;
    }

    public function setDefaultContest($defaultContest) {
        $this->defaultContest = $defaultContest;
    }

    public function getContestSource() {
        return $this->contestSource;
    }

    public function setContestSource($contestSource) {
        $this->contestSource = $contestSource;
    }

    public function isValid() {
        $this->init();
        return $this->valid;
    }

    /**
     * Redirect to corrrect address according to the resolved values.
     */
    public function syncRedirect() {
        $this->init();

        $presenter = $this->getPresenter();

        $contestId = isset($this->contest) ? $this->contest->contest_id : null;
        if ($this->year != $presenter->year || $contestId != $presenter->contestId) {
            $presenter->redirect('this', array(
                'contestId' => $contestId,
                'year' => $this->year
            ));
        }
    }

    public function getContest() {
        $this->init();
        return $this->contest;
    }

    public function getYear() {
        $this->init();
        return $this->year;
    }

    private function init() {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        $contestIds = array_keys($this->getContests());
        if (count($contestIds) == 0) {
            $this->valid = false;
            return;
        }
        $this->valid = true;

        $session = $this->session->getSection(self::SESSION_PREFIX);
        $presenter = $this->getPresenter();

        /* CONTEST */

        $contestId = null;
        // session
        if (($this->contestSource & self::SOURCE_SESSION) && isset($session->contestId)) {
            $contestId = $session->contestId;
        }
        // URL
        if (($this->contestSource & self::SOURCE_URL) && $presenter->contestId) {
            $contestId = $presenter->contestId;
        }

        // final check
        if (!in_array($contestId, $contestIds)) {
            switch ($this->defaultContest) {
                case self::DEFAULT_FIRST:
                    $contestId = reset($contestIds);
                    break;
                case self::DEFAULT_SMART_FIRST:
                    /* No contest is not prioritized when all should be shown.
                     * On the other hand, usually declarative definition leads to only one contest
                     * available, so use the first available.
                     */
                    if ($this->contestsDefinition === self::CONTESTS_ALL) {
                        return null;
                    } else {
                        $contestId = reset($contestIds);
                    }
                    break;
                case self::DEFAULT_NULL:
                    $contestId = null;
                    break;
            }
        }

        $this->contest = $this->serviceContest->findByPrimary($contestId);

        if ($this->contest === null) {
            $this->year = null;
        } else {
            /* YEAR */
            $year = $this->calculateYear($session, $this->contest);
            $this->year = $year;


            // remember
            $session->contestId = $this->contest->contest_id;
            $session->year = $this->year;
        }
    }

    /**
     * @return array of contests where user is either ORG or CONTESTANT
     */
    private function getContests() {
        if ($this->contests === null) {
            if (is_array($this->contestsDefinition)) { // explicit
                $contests = array_map(function($contest) {
                            return ($contest instanceof ModelContest) ? $contest->contest_id : $contest;
                        }, $this->contestsDefinition);
            } else if ($this->contestsDefinition === self::CONTESTS_ALL) { // all
                $pk = $this->serviceContest->getPrimary();
                $contests = $this->serviceContest->fetchPairs($pk, $pk);
            } else { // implicity -- by role
                $contests = array();
                $login = $this->getLogin();
                if ($login) {
                    if ($this->contestsDefinition == ModelRole::ORG) {
                        $contests = array_keys($login->getActiveOrgs($this->yearCalculator));
                    } else if ($this->contestsDefinition == ModelRole::CONTESTANT) {
                        $person = $login->getPerson();
                        if ($person) {
                            $contests = array_keys($person->getActiveContestants($this->yearCalculator));
                        }
                    }
                }
            }
            $this->contests = array();
            foreach ($contests as $id) {
                $contest = $this->serviceContest->findByPrimary($id);
                $years = $this->getYears($contest);
                $this->contests[$id] = (object) array(
                            'contest' => $contest,
                            'years' => $years,
                            'currentYear' => $this->yearCalculator->getCurrentYear($contest),
                );
            }
        }
        return $this->contests;
    }

    private function getYears(ModelContest $contest) {
        if ($this->yearDefinition === self::YEARS_ALL || $this->contestsDefinition == ModelRole::ORG) {
            $min = $this->yearCalculator->getFirstYear($contest);
            $max = $this->yearCalculator->getLastYear($contest);
            return array_reverse(range($min, $max));
        } else {
            $login = $this->getLogin();
            $currentYear = $this->yearCalculator->getCurrentYear($contest);
            if (!$login || !$login->getPerson()) {
                return array($currentYear);
            }
            $contestants = $login->getPerson()->getContestants($contest->contest_id);
            $years = array();
            foreach ($contestants as $contestant) {
                $years[] = $contestant->year;
            }

            sort($years);
            return $years;
        }
    }

    private function getLogin() {
        return $this->getPresenter()->getUser()->getIdentity();
    }

    public function render($class = null) {
        if (!$this->isValid()) {
            throw new BadRequestException('No contests available.', 403);
        }
        $this->template->contests = $this->getContests();
        $this->template->currentContest = $this->getContest() ? $this->getContest()->contest_id : null;
        $this->template->currentYear = $this->getYear();
        $this->template->class = ($class !== null) ? $class : "nav navbar-nav navbar-right";

        $this->template->setFile(__DIR__ . DIRECTORY_SEPARATOR . 'ContestChooser.latte');
        $this->template->render();
    }

    public function handleChange($contestId) {
        $presenter = $this->getPresenter();
        $backupYear = null;
        if (isset($presenter->year)) {
            $backupYear = $presenter->year;
            $presenter->year = null;
        }
        $contest = $this->serviceContest->findByPrimary($contestId);

        $year = $this->calculateYear($this->session, $contest);
        if (isset($presenter->year)) {
            $presenter->year = $backupYear;
        }

        if ($backupYear && $backupYear != $year) {
            $presenter->redirect('this', array('contestId' => $contestId, 'year' => $year));
        } else {
            $presenter->redirect('this', array('contestId' => $contestId));
        }
    }

    public function handleChangeYear($contest, $year) {
        $presenter = $this->getPresenter();
        $presenter->redirect('this', array(
            'contestId' => $contest, //WHY? contestId should be persistent
            'year' => $year));
    }

    private function calculateYear($session, $contest, $override = null) {
        $presenter = $this->getPresenter();
        $year = null;
        // session
        if (isset($session->year)) {
            $year = $session->year;
        }
        // URL
        if (isset($presenter->year)) {
            $year = $presenter->year;
        }
        // override
        if ($override) {
            $year = $override;
        }


        $allowedYears = $this->getYears($contest);
        if (!$this->yearCalculator->isValidYear($contest, $year) || !in_array($year, $allowedYears)) {
            $currentYear = $this->yearCalculator->getCurrentYear($contest);
            $forwardYear = $currentYear + $this->yearCalculator->getForwardShift($contest);
            if (in_array($forwardYear, $allowedYears)) {
                $year = $forwardYear;
            } else {
                $year = $currentYear;
            }
        }
        return $year;
    }

}
