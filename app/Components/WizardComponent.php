<?php

namespace FKSDB\Components;

use Nette\Application\UI\Control;
use Nette\Application\UI\Form;

/**
 *
 * @author Michal Koutný <xm.koutny@gmail.com>
 */
class WizardComponent extends Control {

    const ID_ELEMENT = 'wizardId';

    /**
     * @var array of callback($stepName, WizardCompoent $wizard)
     */
    public $onStepInit;

    /**
     * Data to process is array each step's values stored with key from the step's name.
     * @var array of callback(WizardComponent $wizard)
     */
    public $onProcess;

    /**
     * @var array of str|callback(Form $submittedForm)
     */
    private $nextCallbacks = array();

    /**
     * @var array of array
     */
    private $stepSubmitters = array();

    /**
     * @var str
     */
    private $firstStepName;

    /**
     * @var str
     */
    private $currentStepName;

    /**
     * @var str  id that allows running more instances of wizard in different tabs
     */
    private $wizardId;

    /**
     * @param Form $form                form displayed in the step
     * @param str $name                     name of the step (for reference)
     * @param callback|str $nextCallback    name of the following step or callback
     *                                      that should return name of the following step
     */
    public function addStep(Form $form, $name, $nextCallback = null) {
        $form->addHidden(self::ID_ELEMENT);
        $form->onSuccess[] = array($this, 'stepSubmitted');
        $this->addComponent($form, $name);
        if ($nextCallback !== null) {
            $this->nextCallbacks[$name] = $nextCallback;
        }
    }

    /**
     * Register the button as the 'next' button in the wizard's current step.
     * 
     * @param str $stepName
     * @param str $buttonName
     */
    public function registerStepSubmitter($stepName, $buttonName) {
        if (!isset($this->stepSubmitters[$stepName])) {
            $this->stepSubmitters[$stepName] = array();
        }
        $this->stepSubmitters[$stepName][$buttonName] = true;
    }

    /**
     * Inverse method to registerStepSubmitter.
     * 
     * @param str $stepName
     * @param str $buttonName
     */
    public function unregisterStepSubmitter($stepName, $buttonName) {
        if (!isset($this->stepSubmitters[$stepName])) {
            return;
        }
        unset($this->stepSubmitters[$stepName][$buttonName]);
    }

    /**
     * @param str $name
     */
    public function setFirstStep($name) {
        $this->firstStepName = $name;
    }

    /**
     * @return str
     */
    public function getFirstStep() {
        return $this->firstStepName;
    }

    /**
     * @return str
     */
    public function getCurrentStep() {
        if ($this->currentStepName === null) {
            $this->currentStepName = $this->getFirstStep();
        }
        return $this->currentStepName;
    }

    /**
     * @param str $name
     */
    private function setCurrentStep($name) {
        $this->currentStepName = $name;
    }

    /**
     * 
     * @param str $name name of the step
     * @return array|null
     */
    public function getData($name) {
        $session = $this->getSession();
        return isset($session->$name) ? (array) $session->$name : null;
    }

    /**
     * Free data from session.
     * 
     * @return void
     */
    public function disposeData() {
        $this->getSession()->remove();
    }

    // -------------------------------------------
    private function getWizardId() {
        if ($this->wizardId === null) {
            $this->wizardId = uniqid('', true);
        }
        return $this->wizardId;
    }

    private function setWizardId($wizardId) {
        $this->wizardId = $wizardId;
    }

    /**
     * Render the form for the current step.
     */
    public function render() {
        $name = $this->getCurrentStep();
        $currentForm = $this->getComponent($name);
        $this->onStepInit($name, $this);
        $currentForm[self::ID_ELEMENT]->setValue($this->getWizardId());
        $currentForm->render();
    }

    /**
     * @interal
     * @param Form $form
     */
    public function stepSubmitted(Form $form) {
        // detect where we are
        $name = $form->getName();
        $this->setCurrentStep($name);

        $values = $form->getValues();
        $this->setWizardId($values[self::ID_ELEMENT]);
        unset($values[self::ID_ELEMENT]);

        // should we continue in wizard
        $submitter = $form->isSubmitted() ? $form->isSubmitted()->getName() : null;
        if (!$submitter || !isset($this->stepSubmitters[$name]) || !isset($this->stepSubmitters[$name][$submitter])) {
            return;
        }

        // store data to session
        $session = $this->getSession();
        $session->$name = $values;


        // find the next step or finish
        if (isset($this->nextCallbacks[$name])) {
            $next = $this->nextCallbacks[$name];
            if (is_string($next) && $this->getComponent($next, false)) {
                $newName = $next;
            } else {
                $newName = call_user_func($this->nextCallbacks[$name], $form);
            }
            if ($newName === null) {
                $this->onProcess($this);
            } else {
                $this->setCurrentStep($newName);
            }
        } else {// process data
            $this->onProcess($this);
        }
    }

    private function getSession() {
        return $this->getPresenter()->getSession($this->getWizardId());
    }

}