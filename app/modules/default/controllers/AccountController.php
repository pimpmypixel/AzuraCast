<?php
use \Entity\User;
use \Entity\UserExternal;

class AccountController extends \DF\Controller\Action
{
    public function indexAction()
    {
    }
    
    public function registerAction()
    {
        $request = $this->getRequest();
        $form = new \DF\Form($this->current_module_config->forms->register);
        
        if ($_POST)
        {
            if ($form->isValid($_POST))
            {
                $data = $form->getValues();
                
                $existing_user = User::getRepository()->findOneBy(array('email' => $data['email']));
                
                if ($existing_user instanceof User)
                {
                    $this->alert('A user with that e-mail address already exists!', 'red');
                }
                else
                {
                    $new_user = new User;
                    $new_user->fromArray($data);
                    $new_user->save();
                    
                    $login_credentials = array(
                        'username'  => $data['email'],
                        'password'  => $data['auth_password'],
                    );
                    $login_success = $this->auth->authenticate($login_credentials);
                    
                    $this->alert('<b>Your account has been successfully created.</b><br>You have been automatically logged in to your new account.', 'green');

                    $default_url = \DF\Url::route(array('module' => 'default'));
                    $this->redirectToStoredReferrer('login', $default_url);
                    return;
                }
            }
        }
        else
        {
            $this->storeReferrer('login');
        }

        $this->view->form = $form;
    }

    public function loginAction()
    {
        $form = new \DF\Form($this->current_module_config->forms->login);

        if ($this->hasParam('provider'))
        {
            $provider_name = $this->getParam('provider');
 
            try
            {
                $ha_config = $this->_getHybridConfig();
                $hybridauth = new \Hybrid_Auth($ha_config);
     
                // try to authenticate with the selected provider
                $adapter = $hybridauth->authenticate($provider_name);

                if ($hybridauth->isConnectedWith($provider_name))
                {
                    $user_profile = $adapter->getUserProfile();

                    $user = UserExternal::processExternal($provider_name, $user_profile);
                    $this->auth->setUser($user);
                }
            }
            catch(\Exception $e)
            {
                $this->alert($e->getMessage(), 'red');
            }
        }
        else if ($_POST)
        {
            if ($form->isValid($_POST))
            {
                $login_success = $this->auth->authenticate($form->getValues());
                
                if($login_success)
                {
                    $user = $this->auth->getLoggedInUser();
                    
                    $this->alert('<b>Logged in successfully. Welcome back, '.$user->name.'!</b><br>For security purposes, log off when your session is complete.', 'green');

                    if ($this->acl->isAllowed('view administration'))
                        $default_url = \DF\Url::route(array('module' => 'admin'));
                    else
                        $default_url = \DF\Url::route(array('module' => 'default'));

                    $this->redirectToStoredReferrer('login', $default_url);
                    return;
                }
            }
        }
        else
        {
            $this->storeReferrer('login');
        }

        // Auto-bounce back if logged in.
        if ($this->auth->isLoggedIn())
            $this->redirectToStoredReferrer('login', $default_url);

        $this->view->form = $form;
    }

    public function hybridAction()
    {
        $ha_config = $this->_getHybridConfig();

        \Hybrid_Auth::initialize($ha_config);
        \Hybrid_Endpoint::process();
    }

    public function forgotAction()
    {
        $form = new \DF\Form($this->current_module_config->forms->forgot);

        if ($_POST && $form->isValid($_POST))
        {
            $data = $form->getValues();

            $user = User::getRepository()->findOneBy(array('email' => $data['contact_email']));
            if ($user instanceof User)
            {
                $user->generateAuthRecoveryCode();
                $user->save();

                \DF\Messenger::send(array(
                    'to'        => $user->email,
                    'subject'   => 'Password Recovery Code',
                    'template'  => 'forgotpw',
                    'vars'      => array(
                        'record' => $user,
                    ),
                ));
            }

            $this->alert('<b>A password recovery link has been sent to your e-mail address.</b><br>Click the link in the e-mail to reset your password.', 'green');
            $this->redirectHome();
            return;
        }

        $this->view->headTitle('Forgot My Password');
        $this->renderForm($form);
    }

    public function recoverAction()
    {
        $id = (int)$this->_getParam('id');
        $code = $this->_getParam('code');

        $user = User::getRepository()->findOneBy(array('id' => $id, 'auth_recovery_code' => $code));

        if (!($user instanceof User))
            throw new \DF\Exception\DisplayOnly('Invalid ID or recovery code provided!');

        $temp_pw = substr(sha1(mt_rand()), 0, 8);

        $user->setAuthPassword($temp_pw);
        $user->auth_recovery_code = '';
        $user->save();

        $this->auth->authenticate(array('username' => $user->email, 'password' => $temp_pw));

        $this->alert('<b>Logged in successfully.</b><br>Your account password has been reset. Please change your password using the form below.', 'green');
        $this->redirectToRoute(array('controller' => 'account', 'action' => 'editprofile'));
        return;
    }

    public function logoutAction()
    {
        $this->auth->logout();
        $this->redirectToRoute(array('module' => 'default'));
    }

    public function endimpersonateAction()
    {
        $this->auth->endMasquerade();

        $this->alert('<b>Switched back to main account successfully.</b>', 'green');
        $this->redirectHome();
    }

    public function profileAction()
    {
        $form_config = $this->current_module_config->forms->register->toArray();
        
        $user = $this->auth->getLoggedInUser();
        $form = new \DF\Form($form_config);
        $form->setDefaults($user->toArray());
        $this->view->form = $form;
    }
    
    public function editprofileAction()
    {
        $user = $this->auth->getLoggedInUser();
        $form = new \DF\Form($this->current_module_config->forms->profile);
        $form->setDefaults($user->toArray());
        
        if($_POST && $form->isValid($_POST))
        {
            $data = $form->getValues();
            
            if (!empty($data['new_password']))
                $user['auth_password'] = $data['new_password'];
            
            $user->fromArray($data);
            $user->save();
            
            $this->alert('Profile saved!', 'green');
            $this->redirectHome();
            return;
        }

        $this->view->headTitle('Edit Profile');
        $this->renderForm($form);
    }

    /**
     * Site Customization
     */

    public function themeAction()
    {
        $skin = $this->_getParam('skin', 'toggle');

        $current_skin = \PVL\Customization::get('theme');

        if ($skin == "toggle")
            $new_skin = ($current_skin == "dark") ? 'light' : 'dark';
        else
            $new_skin = $skin;

        \PVL\Customization::set('theme', $new_skin);

        $this->redirectToReferrer();
        return;
    }

    public function timezoneAction()
    {
        $form = new \DF\Form($this->current_module_config->forms->timezone);
        $form->setDefaults(array(
            'timezone'      => \PVL\Customization::get('timezone'),
        ));
        
        if($_POST && $form->isValid($_POST))
        {
            $data = $form->getValues();

            \PVL\Customization::set('timezone', $data['timezone']);
            
            $this->alert('Time zone updated!', 'green');
            $this->redirectToStoredReferrer('customization');
            return;
        }

        $this->storeReferrer('customization');

        $this->view->headTitle('Set Time Zone');
        $this->renderForm($form);
    }

    protected function _getHybridConfig()
    {
        $ha_config = $this->config->apis->hybrid_auth->toArray();
        $ha_config['base_url'] = $this->view->routeFromHere(array('action' => 'hybrid'));

        return $ha_config;
    }

    public function mergeAction()
    {
        set_time_limit(600);

        $this->acl->checkPermission('administer all');
        $this->doNotRender();

        // Remove all accounts with no e-mail.
        $this->em->createQuery('DELETE FROM Entity\User u WHERE u.email IS NULL OR u.email = :empty')
            ->setParameter('empty', '')
            ->execute();

        // Get all accounts with external auth.
        $external_accounts_raw = $this->em->createQuery('SELECT u FROM Entity\User u WHERE u.auth_external_provider IS NOT NULL')
            ->getArrayResult();

        // Delete all accounts with external auth.
        $this->em->createQuery('DELETE FROM Entity\User u WHERE u.auth_external_provider IS NOT NULL')->execute();

        // Loop through all external accounts and call External Auth to get/create proper associations.
        foreach($external_accounts_raw as $account)
        {
            $provider = $account['auth_external_provider'];

            $profile = new \stdClass;
            $profile->email = $account['email'];
            $profile->displayName = $account['name'];
            $profile->photoURL = $account['avatar_url'];
            $profile->identifier = $account['auth_external_id'];

            $user = UserExternal::processExternal($provider, $profile);

            if (!empty($account['customization']) && empty($user->customization))
            {
                $user->customization = $account['customization'];
                $user->save();
            }

            $this->em->clear();
        }

        echo 'All accounts migrated!';
        exit;
    }
}
