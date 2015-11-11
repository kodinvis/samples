<?php

/**
 * Class Default_ApiController
 * Implements API methods to interact with a platform
 */
class Default_ApiController extends Zend_Controller_Action
{
    const SECRET_KEY = '';
    const STATUS_OK = 'ok';
    const STATUS_ERROR = 'error';

    const BONUS_ACTION_STATUS_AVAILABLE = 'available';
    const BONUS_ACTION_STATUS_NOT_AVAILABLE = 'not available';
    const BONUS_ACTION_STATUS_ACTIVE = 'active';
    const BONUS_ACTION_STATUS_ACTIVATED = 'activated';

    const ERROR_API_METHOD_NOT_FOUND = 'API_METHOD_NOT_FOUND';
    const ERROR_API_METHOD_NOT_SPECIFIED = 'API_METHOD_NOT_SPECIFIED';
    const ERROR_EMAIL_EXISTS = 'EMAIL_ALREADY_EXISTS';
    const ERROR_INCORRECT_HASH = 'INCORRECT_HASH';
    const ERROR_INTERNAL_ERROR = 'INTERNAL_ERROR';
    const ERROR_INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    const ERROR_INVALID_PARAM = 'INVALID_PARAM';
    const ERROR_LOGIN_EXISTS = 'LOGIN_ALREADY_EXISTS';
    const ERROR_NOT_ALLOWED = 'NOT_ALLOWED';
    const ERROR_PARAM_NOT_SPECIFIED = 'PARAM_NOT_SPECIFIED';
    const ERROR_PHONE_EXISTS = 'PHONE_ALREADY_EXISTS';
    const ERROR_USER_BLOCKED = 'USER_BLOCKED';
    const ERROR_USER_INACTIVE = 'USER_INACTIVE';
    const ERROR_USER_NOT_FOUND = 'USER_NOT_FOUND';

    #region _PRIVATE
    private static function getConstants()
    {
        $oClass = new ReflectionClass(__CLASS__);
        return $oClass->getConstants();
    }

    private function prepareResponse(array $data, $status = self::STATUS_OK)
    {
        header('Content-type: application/json');
        die(json_encode(['status' => $status, 'data' => $data]));
    }

    private function decodeParams(array $params)
    {
        $res = array();
        foreach ($params as $key => $param) {
            $res[$key] = urldecode($param);
        }
        return $res;
    }

    private function commonCheck(array $params, array $requiredParams)
    {
        # Check required parameters
        $requiredParamsValues = [];
        foreach ($requiredParams as $requiredParam) {
            if (!isset($params[$requiredParam])) {
                throw new App_Exception(self::ERROR_PARAM_NOT_SPECIFIED . "||$requiredParam not specified");
            }
            $requiredParamsValues[] = $params[$requiredParam];
        }
        # Check hash
        $hash = md5(implode('', $requiredParamsValues) . self::SECRET_KEY);
        if ($params['hash'] != $hash) {
            throw new App_Exception(self::ERROR_INCORRECT_HASH . "||Incorrect hash: {$params['hash']} != $hash");
        }
    }

    /**
     * @param int $userId
     * @return Users_Portal_Model
     * @throws App_Exception
     */
    private function getUserById($userId)
    {
        $user = Bo_User_Portal_Manager::getUserById($userId);
        if (empty($user)) {
            throw new App_Exception(self::ERROR_USER_NOT_FOUND);
        }
        return $user;
    }
    #endregion

    #region ACCOUNTS

    private function register(array $params)
    {
        $this->commonCheck($params, ['currency', 'email', 'language']);

        # Get language id
        /** @var Language_Model $language */
        $language = Bo_Language_Manager::getLanguageByCode(strtolower($params['language']));
        if (empty($language)) {
            $language = Bo_Language_Manager::getLanguageById(Bo_Language_Manager::LANGUAGE_EN_ID);
        }
        $params['language_id'] = $language->language_id;

        # The first email validation
        if (strpos($params['email'], '@') === false) {
            throw new App_Exception(self::ERROR_INVALID_PARAM . "||Incorrect email");
        }

        # Get currency by code
        $currencyId = array_search($params['currency'], Bo_Currency::$currencies);
        if (!$currencyId) {
            throw new App_Exception(self::ERROR_INVALID_PARAM . "||Incorrect currency");
        }
        $params['currency_id'] = $currencyId;

        # Prepare login (specified parameter or the first part of email)
        if (isset($params['login'])) {
            $login = $params['login'];
        } else {
            $login = substr($params['email'], 0, strpos($params['email'], "@"));
        }

        # Check for unique login
        $userLoginCheck = Bo_User_Portal_Manager::getUserByLogin($login);
        if (isset($userLoginCheck->user_id)) {
            if (isset($params['login'])) {
                throw new App_Exception(self::ERROR_LOGIN_EXISTS);
            }
            # make login = login . N+1
            $i = 1;
            while (isset($userLoginCheck->user_id)) {
                $login = $login.$i;
                $userLoginCheck = Bo_User_Portal_Manager::getUserByLogin($login);
                $i++;
            }
        }
        $params['login'] = $login;

        # Prepare password (specified parameter or random string of 6..10 chars)
        if (isset($params['password'])) {
            $password = $params['password'];
        } else {
            $password = substr(md5(rand().rand()), 0, rand(6, 10));
        }
        $params["password"] = $password;

        # Create new user
        try {
            $authManager = Bo_Auth_Manager::getInstance();
            $authModules = $authManager->getModules();
            /** @var Bo_Auth_Component_Registration_Portal $authModule */
            $authModule = current($authModules);
            $user = $authModule->fastRegister($params);
        } catch (Bo_Input_Validationfailed_Exception $e) {
            throw new App_Exception(self::ERROR_INVALID_PARAM . "||" . $e->getMessage());
        } catch (Exception $e) {
            if ($e->getMessage() == self::ERROR_EMAIL_EXISTS) {
                throw new App_Exception(self::ERROR_EMAIL_EXISTS);
            } else {
                throw new App_Exception(self::ERROR_INTERNAL_ERROR . "||" . $e->getMessage());
            }
        }

        return ['user_id' => $user->user_id, 'login' => $login, 'password' => $password, 'activation_code' => $user->activation_code];
    }

    private function changeuserstatus(array $params)
    {
        $this->commonCheck($params, ['status_id', 'user_id']);

        if (!in_array($params['status_id'], [Bo_Auth_Manager::USER_STATUS_ACTIVE, Bo_Auth_Manager::USER_STATUS_BLOCKED, Bo_Auth_Manager::USER_STATUS_TEMP_BLOCKED])) {
            throw new App_Exception(self::ERROR_INVALID_PARAM . "||Incorrect status");
        }
        $user = $this->getUserById($params['user_id']);
        if ($user->status_id != $params['status_id']) {
            Bo_User_Manager::changeUserStatus($user, $params['status_id']);
        }

        return ['user_id' => $user->user_id];
    }

    private function checkauth(array $params)
    {
        $this->commonCheck($params, ['login', 'password']);

        /** @var Users_Portal_Model $user */
        if (strpos($params['login'], '@')) {
            $user = Bo_User_Portal_Manager::getUserByEmail($params['login']);
        } else {
            $user = Bo_User_Portal_Manager::getUserByLogin($params['login']);
        }
        if (!isset($user->user_id) || $user->password != md5($params['password'])) {
            throw new App_Exception(self::ERROR_INVALID_CREDENTIALS);
        }
        if (in_array($user->status_id, [Bo_Auth_Manager::USER_STATUS_BLOCKED, Bo_Auth_Manager::USER_STATUS_TEMP_BLOCKED])) {
            throw new App_Exception(self::ERROR_USER_BLOCKED);
        }

        Bo_User_Portal_Manager::updateLastLogin($user);

        return ['user_id' => $user->user_id];
    }

    private function getuserbyunique(array $params)
    {
        $this->commonCheck($params, ['number_is_phone', 'unique']);

        # Check if specified user exists
        /** @var Users_Portal_Model $user */
        if (strpos($params['unique'], '@')) {
            $user = Bo_User_Portal_Manager::getUserByEmail($params['unique']);
        } elseif (is_numeric($params['unique']) && $params['number_is_phone']) {
            $user = Bo_User_Portal_Manager::getUserByPhone($params['unique']);
        } else {
            $user = Bo_User_Portal_Manager::getUserByLogin($params['unique']);
        }
        if (empty($user)) {
            throw new App_Exception(self::ERROR_USER_NOT_FOUND);
        }

        return ['user' => $this->getUserData($user)];
    }

    private function getprofiledata(array $params)
    {
        $this->commonCheck($params, ['user_id']);

        $user = $this->getUserById($params['user_id']);

        return ['user' => $this->getUserData($user)];
    }

    private function setprofiledata(array $params)
    {
        $this->commonCheck($params, ['user_id']);

        $user = $this->getUserById($params['user_id']);

        # At least one parameter must be specified
        $profileFields = ['password', 'name', 'surname', 'gender_id', 'language', 'birthdate', 'phone', 'country', 'city', 'address', 'postindex', 'is_phone_verified'];
        $profileFieldsToUpdate = [];
        foreach ($profileFields as $profileField) {
            if (isset($params[$profileField])) {
                if ($profileField == 'language') {
                    try {
                        $language = Bo_Language_Manager::getLanguageByCode($params['language']);
                    } catch (App_Exception $e) {
                        throw new App_Exception(self::ERROR_INVALID_PARAM . "||Invalid language");
                    }
                    $profileFieldsToUpdate['language_id'] = $language->language_id;
                    continue;
                }
                $profileFieldsToUpdate[$profileField] = ($profileField == 'password') ? md5($params[$profileField]) : $params[$profileField];
            }
        }
        if (empty($profileFieldsToUpdate)) {
            throw new App_Exception(self::ERROR_PARAM_NOT_SPECIFIED . "||No params to update");
        }
        $profileFieldsToUpdate['is_verified'] = true;
        
        # Validate params before update
        $inputFilterObj = new Bo_Auth_Component_Registration_Portal_Datafilter_Full();
        $inputFilter = $inputFilterObj->getInputFilter();
        $inputFilter->setData($profileFieldsToUpdate);
        if (!$inputFilter->isValid()) {
            throw new App_Exception(self::ERROR_INVALID_PARAM . "||" . var_export($inputFilter->getErrors(), true));
        }

        # Check if specified phone already exists
        if (isset($profileFieldsToUpdate['phone'])) {
            $userWithPhone = Bo_User_Portal_Manager::getUserByPhone($profileFieldsToUpdate['phone']);
            if (!empty($userWithPhone) && $userWithPhone->user_id != $user->user_id) {
                throw new App_Exception(self::ERROR_PHONE_EXISTS);
            }
        }

        # Get country by code
        if (isset($profileFieldsToUpdate['country'])) {
            $country = Bo_Country::getCountryByCode($profileFieldsToUpdate['country']);
            if (!isset($country->country_id)) {
                throw new App_Exception(self::ERROR_INVALID_PARAM . "||Invalid country");
            }
            $profileFieldsToUpdate['country_id'] = $country->country_id;
            unset($profileFieldsToUpdate['country']);
        }
        Bo_User_Portal_Manager::updateUserData($user->user_id, $profileFieldsToUpdate);

        return ['user_id' => $user->user_id];
    }

    private function sendphonecode(array $params)
    {
        $this->commonCheck($params, ['from', 'message', 'phone', 'user_id']);

        $user = $this->getUserById($params['user_id']);
        $existingUser = Bo_User_Portal_Manager::getUserByPhone($params['phone']);
        if (!empty($existingUser) && $existingUser->user_id != $user->user_id) {
            throw new App_Exception(self::ERROR_PHONE_EXISTS . "||Phone already exists");
        }
        Bo_User_Portal_Manager::updateUserData($user->user_id, ['phone' => $params['phone']]);
        $code = Bo_User_Portal_Manager::getVerifyPhoneCode($user);
        Bo_Sms_Manager::send($params['phone'], $params['message'] . $code, Bo_Sms_Manager::SMS_SENDING_VERIFY_PHONE_REASON_ID, $params['from']);

        return ['user_id' => $user->user_id];
    }

    private function verifyphonecode(array $params)
    {
        $this->commonCheck($params, ['code', 'user_id']);

        $user = $this->getUserById($params['user_id']);
        $code = Bo_User_Portal_Manager::getVerifyPhoneCode($user);
        if ($params['code'] != $code) {
            throw new App_Exception(self::ERROR_INVALID_PARAM . "||Invalid code");
        }
        Bo_User_Portal_Manager::updateUserData($user->user_id, ['is_phone_verified' => true]);

        return ['user_id' => $user->user_id];
    }
    #endregion

    public function initAction()
    {
        try {
            Zend_Controller_Front::getInstance()->setParam('noViewRenderer', TRUE);
            Zend_Controller_Front::getInstance()->unregisterPlugin('Zend_Layout_Controller_Plugin_Layout');

            $rawParams = $this->getRequest()->getParams();
            $params = $this->decodeParams($rawParams);
            if (!isset($params['api_method'])) {
                throw new App_Exception(self::ERROR_API_METHOD_NOT_SPECIFIED);
            }
            if (!method_exists($this, $params['api_method'])) {
                throw new App_Exception(self::ERROR_API_METHOD_NOT_FOUND);
            }
            $res = $this->$params['api_method']($params);
            $this->prepareResponse($res);
        } catch (App_Exception $e) {
            list($errorMsg, $errorInfo) = explode('||', $e->getMessage());
            Bo_Log_Manager::log('API REQUEST: ' . var_export($rawParams, true) . PHP_EOL . 'ERROR: ' . $e->getMessage());
            if (!in_array($errorMsg, self::getConstants())) {
                $errorMsg = self::ERROR_INTERNAL_ERROR;
                $errorInfo = $e->getMessage();
            }
            $this->prepareResponse(array('msg' => $errorMsg, 'info' => $errorInfo), self::STATUS_ERROR);
        }
    }
}