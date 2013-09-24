<?php

namespace PublicModule;

use Authenticator;
use FKSDB\Components\Forms\Factories\LoginFactory;
use FKSDB\Components\Forms\Rules\UniqueEmail;
use FKSDB\Components\Forms\Rules\UniqueEmailFactory;
use FKSDB\Components\Forms\Rules\UniqueLoginFactory;
use Nette\Application\UI\Form;
use Nette\Forms\Controls\BaseControl;
use ServiceLogin;

/**
 * Due to author's laziness there's no class doc (or it's self explaining).
 * 
 * @author Michal Koutný <michal@fykos.cz>
 */
class PersonalPresenter extends BasePresenter {

    const CONT_LOGIN = 'login';

    /**
     * @var LoginFactory
     */
    private $loginFactory;

    /**
     * @var ServiceLogin
     */
    private $loginService;

    /**
     * @var UniqueEmailFactory
     */
    private $uniqueEmailFactory;

    /**
     * @var UniqueLoginFactory
     */
    private $uniqueLoginFactory;

    public function injectLoginFactory(LoginFactory $loginFactory) {
        $this->loginFactory = $loginFactory;
    }

    public function injectLoginService(ServiceLogin $loginService) {
        $this->loginService = $loginService;
    }

    public function injectUniqueEmailFactory(UniqueEmailFactory $uniqueEmailFactory) {
        $this->uniqueEmailFactory = $uniqueEmailFactory;
    }

    public function injectUniqueLoginFactory(UniqueLoginFactory $uniqueLoginFactory) {
        $this->uniqueLoginFactory = $uniqueLoginFactory;
    }

    public function renderEdit() {
        $login = $this->getUser()->getIdentity();

        $defaults = array(
            self::CONT_LOGIN => $login->toArray(),
        );
        $this->getComponent('personalForm')->setDefaults($defaults);
    }

    protected function createComponentPersonalForm($name) {
        $form = new Form();
        $login = $this->getUser()->getIdentity();
        $tokenAuthentication = $this->isAuthenticatedByToken();

        $group = $form->addGroup('Osobní nastavení');
        $emailRule = $this->uniqueEmailFactory->create(UniqueEmail::CHECK_LOGIN, null, $login);
        $loginRule = $this->uniqueLoginFactory->create($login);

        if ($tokenAuthentication) {
            $options = LoginFactory::SHOW_PASSWORD | LoginFactory::REQUIRE_PASSWORD;
        } else {
            $options = LoginFactory::SHOW_PASSWORD | LoginFactory::VERIFY_OLD_PASSWORD;
        }
        $loginContainer = $this->loginFactory->createLogin($options, $group, $emailRule, $loginRule);
        $form->addComponent($loginContainer, self::CONT_LOGIN);

        if (!$tokenAuthentication) {
            $loginContainer['old_password']
                    ->addCondition(Form::FILLED)
                    ->addRule(function(BaseControl $control) use($login) {
                                $hash = Authenticator::calculateHash($control->getValue(), $login);
                                return $hash == $login->hash;
                            }, 'Špatně zadané staré heslo.');
        }

        $form->setCurrentGroup();

        $form->addSubmit('send', 'Uložit');

        $form->onSuccess[] = array($this, 'handlePersonalFormSuccess');
        return $form;
    }

    /**
     * @internal
     * @param \Nette\Application\UI\Form $form
     */
    public function handlePersonalFormSuccess(Form $form) {
        $values = $form->getValues();
        $tokenAuthentication = $this->isAuthenticatedByToken();
        $login = $this->getUser()->getIdentity();

        $loginData = $values[self::CONT_LOGIN];
        if ($loginData['password']) {
            $login->setHash($loginData['password']);
        }

        $this->loginService->updateModel($login, $loginData);
        $this->loginService->save($login);
        $this->flashMessage('Osobní informace upraveny.');
        $this->redirect($this);
        if ($tokenAuthentication) {
            $this->flashMessage('Heslo nastaveno.'); //TODO here may be Facebook ID            
            $this->disposeAuthToken(); // from now on same like password authentication
        }
    }

}
