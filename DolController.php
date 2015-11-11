<?php

/**
 * Class Default_DolController
 * Processes API requests from DengiOnline payment service
 */
class Default_DolController extends Zend_Controller_Action
{
    const XML_ROOT_TAG = 'result';
    const SECRET_KEY = '';
    const CODE_OK = 'YES';
    const CODE_ERROR = 'NO';
    const EXTERNAL_PAYMENT_ID_PREFIX = 'dol_';

    private function prepareResponse(array $responseTagData)
    {
        # Prepare response tag
        $responseTag = array();
        foreach ($responseTagData as $key => $value) {
            $responseTag[$key] = array(Bo_Dom::CONTENT => $value);
        }

        $res = Bo_Dom::arrayToXMLString($responseTag, self::XML_ROOT_TAG, true, true);

        header('Content-type: text/xml');
        die($res);
    }

    /**
     * Check if exists specified user
     * @return void
     */
    public function checkAction()
    {
        try {
            Zend_Controller_Front::getInstance()->setParam('noViewRenderer', TRUE);
            Zend_Controller_Front::getInstance()->unregisterPlugin('Zend_Layout_Controller_Plugin_Layout');

            $params = $this->getRequest()->getParams();

            # Check required params
            $requiredParams = ['amount', 'userid', 'paymentid', 'key'];
            foreach ($requiredParams as $requiredParam) {
                if (!isset($params[$requiredParam])) {
                    throw new App_Exception("Parameter '$requiredParam' not specified");
                }
            }

            # Compare key
            $key = md5(0 . $params['userid'] . 0 . self::SECRET_KEY);
            if ($key != $params['key']) {
                throw new App_Exception("Invalid key");
            }

            # Get user
            $user = Bo_User_Portal_Manager::getUserById($params['userid']);
            if (empty($user)) {
                throw new App_Exception("User not found");
            }
            if ($user->status_id == Bo_Auth_Manager::USER_STATUS_BLOCKED) {
                throw new App_Exception("User blocked");
            }
            
            self::prepareResponse(array('code' => self::CODE_OK));
        } catch (App_Exception $e) {
            Bo_Log_Manager::log('DOL: ' . $e->getMessage());
            self::prepareResponse(array('code' => self::CODE_ERROR, 'comment' => $e->getMessage()));
        }
    }

    /**
     * Deposit specified amount to the user's finance account
     * @return void
     */
    public function depositAction()
    {
        try {
            Zend_Controller_Front::getInstance()->setParam('noViewRenderer', TRUE);
            Zend_Controller_Front::getInstance()->unregisterPlugin('Zend_Layout_Controller_Plugin_Layout');

            $params = $this->getRequest()->getParams();

            # Check required params
            $requiredParams = ['amount', 'userid', 'paymentid', 'key', 'paymode'];
            foreach ($requiredParams as $requiredParam) {
                if (!isset($params[$requiredParam])) {
                    throw new App_Exception("Parameter '$requiredParam' not specified");
                }
            }

            # Check if specified payment id already has been processed
            /** @var Finance_DepositsHistory_Mapper $mapper */
            $mapper = Bo_Mapper_Factory::get('Finance_DepositsHistory_Mapper');
            $existingDepositRecord = $mapper->getListByPattern(new Finance_DepositsHistory_Model(
                array('external_payment_id' => self::EXTERNAL_PAYMENT_ID_PREFIX . $params['paymentid'])));
            if (count($existingDepositRecord)) {
                self::prepareResponse(array('code' => self::CODE_OK));
            }

            # Compare key
            $key = md5($params['amount'] . $params['userid'] . $params['paymentid'] . self::SECRET_KEY);
            if ($key != $params['key']) {
                throw new App_Exception("Invalid key");
            }

            # Get user
            $user = Bo_User_Portal_Manager::getUserById($params['userid']);
            if (empty($user)) {
                throw new App_Exception("User not found");
            }
            
            # Allow deposit only for currency RUB
            if ($user->currency_id != Bo_Currency::CURRENCY_RUB_ID) {
                throw new App_Exception("User has not RUB currency");
            }

            # Process deposit
            $financeAccount = Bo_GameService_Finance_Manager::getCheckedFinanceAccount($user, Bo_GameService_Casino_Manager::GAME_SERVICE_ID);
            Bo_Finance_Account_Manager::addFunds($financeAccount, $params['amount'], TRUE, self::EXTERNAL_PAYMENT_ID_PREFIX . $params['paymentid']);

            self::prepareResponse(array('code' => self::CODE_OK));
        } catch (App_Exception $e) {
            Bo_Log_Manager::log('DOL: ' . $e->getMessage());
            self::prepareResponse(array('code' => self::CODE_ERROR, 'comment' => $e->getMessage()));
        }
    }
}