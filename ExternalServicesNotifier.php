<?php
/**
 * Notifies external services about events on MT
 *
 * @author Oleg Kravchenko
 */
class ExternalServicesNotifier
{
    const LOG_PREFIX = 'External service notifier: ';

    //region Inner fields
    private static $config;

    private static $requiredServiceData = ['api_main_url', 'secret'];

    private static $actionTypeMethodMap = [
        PgqCommonActionsHelper::ADD_FRIEND_ACTION_TYPE_ID => 'addFriendNotify',
        PgqCommonActionsHelper::REMOVE_FRIEND_ACTION_TYPE_ID => 'removeFriendNotify',
        PgqCommonActionsHelper::BAN_USER_ACTION_TYPE_ID => 'banUserNotify',
        PgqCommonActionsHelper::UNBAN_USER_ACTION_TYPE_ID => 'unbanUserNotify',
        PgqCommonActionsHelper::CHANGE_USER_AVATAR_ACTION_TYPE_ID => 'changeUserAvatarNotify'
    ];

    /** @var ServiceBase_IDebugLogger  */
    private static $debugLogger;
    //endregion

    //region Inner functions
    private static function log($msg)
    {
        if (!empty(self::$debugLogger)) {
            self::$debugLogger->debug(self::LOG_PREFIX . $msg);
        }
    }

    private static function err($msg)
    {
        throw new Exception(self::LOG_PREFIX . "ERROR! $msg");
    }

    private static function initCheck(array $data, $requiredParams = [])
    {
        $callingFunction = debug_backtrace()[2]['function'];
        self::log("$callingFunction: " . var_export($data, true));
        foreach ($requiredParams as $requiredParam) {
            if (empty($data[$requiredParam])) {
                self::err("$callingFunction: $requiredParam not specified");
            }
        }
    }
    //endregion

    //region Matrix chat functions
    private static function getMatrixChatToken(array $params)
    {
        $tokenParams = [];
        foreach ($params as $key => $val) {
            $tokenParams[] = "$key=$val";
        }
        return md5(implode(';', $tokenParams) . ';salt=' . self::$config->external_services_notifier['matrix_chat']['secret']);
    }

    private static function notifyMatrixChat($httpMethod, $apiMethod, $objId, array $data, array $requiredParams = [])
    {
        if ($apiMethod == '_friendship') {
            $apiParams = ['friend_a' => $objId, 'friend_b' => $data['to_person_id']];
        } elseif (in_array($apiMethod, ['_new_ban', '_unban'])) {
            $apiParams = ['banner' => $objId, 'banned' => $data['to_person_id']];
        } elseif ($apiMethod == '_change_avatar') {
            $apiParams = ['user' => $objId];
        } else {
            self::err(__FUNCTION__ . ": api method '$apiMethod' not supported");
        }
        self::initCheck($data, $requiredParams);
        $apiParams['token'] = self::getMatrixChatToken($apiParams);
        $url = self::$config->external_services_notifier['matrix_chat']['api_main_url'] . $apiMethod . "?" . http_build_query($apiParams);
        self::log(__FUNCTION__ . ": API request $httpMethod: $url");
        if ($httpMethod == 'get') {
            $res = Request::doGet($url);
        } elseif ($httpMethod == 'delete') {
            $res = Request::doDelete($url);
        } else {
            self::err(__FUNCTION__ . ": http method '$httpMethod' not supported");
        }
        self::processMatrixChatRes($res);
    }

    private static function processMatrixChatRes($res)
    {
        $callingFunction = debug_backtrace()[1]['function'];
        self::log("$callingFunction: RES=" . var_export($res, true));
        $jsonRes = json_decode($res);
        if (empty($jsonRes->status) || $jsonRes->status != 200) {
            self::err("$callingFunction: bad response '" . var_export($res, true) . "'");
        }
    }
    //endregion


    //region ACTIONS

    //region Add friend
    private static function addFriendNotifyGqapi($objId, array $data)
    {
        self::initCheck($data, ['to_person_id']);
        self::log(__FUNCTION__ . ': method in developing...');
    }

    private static function addFriendNotifyMatrixChat($objId, array $data)
    {
        self::notifyMatrixChat('get', '_friendship', $objId, $data, ['to_person_id']);
    }
    //endregion

    //region Remove friend
    private static function removeFriendNotifyGqapi($objId, array $data)
    {
        self::initCheck($data, ['to_person_id']);
        self::log(__FUNCTION__ . ': method in developing...');
    }

    private static function removeFriendNotifyMatrixChat($objId, array $data)
    {
        self::notifyMatrixChat('delete', '_friendship', $objId, $data, ['to_person_id']);
    }
    //endregion

    //region Ban user
    private static function banUserNotifyMatrixChat($objId, array $data)
    {
        self::notifyMatrixChat('get', '_new_ban', $objId, $data, ['to_person_id']);
    }
    //endregion

    //region Unban user
    private static function unbanUserNotifyMatrixChat($objId, array $data)
    {
        self::notifyMatrixChat('get', '_unban', $objId, $data, ['to_person_id']);
    }
    //endregion

    //region Change user avatar
    private static function changeUserAvatarNotifyMatrixChat($objId, array $data)
    {
        self::notifyMatrixChat('get', '_change_avatar', $objId, $data);
    }
    //endregion

    //endregion

    /**
     * @param int $actionTypeId See PgqCommonActionsHelper constants
     * @param int $objId Actor obj id
     * @param array $data Action related data
     * @param ServiceBase_IDebugLogger $debugLogger
     */
    public static function notify($actionTypeId, $objId, array $data, \ServiceBase_IDebugLogger $debugLogger = null)
    {
        self::$config = Config::getInstance();
        self::$debugLogger = $debugLogger;
        if (array_key_exists($actionTypeId, self::$actionTypeMethodMap)) {
            $methodPrefix = self::$actionTypeMethodMap[$actionTypeId];
            foreach (self::$config->external_services_notifier as $externalService => $externalServiceData) {
                $method = $methodPrefix . ucfirst(str_replace('_', '', strtolower($externalService)));
                if (method_exists(__CLASS__, $method)) {
                    foreach (self::$requiredServiceData as $requiredServiceDataKey) {
                        if (empty($externalServiceData[$requiredServiceDataKey])) {
                            self::err("'$requiredServiceDataKey' not specified for external service '$externalService'");
                        }
                    }
                    call_user_func(__CLASS__ . "::$method", $objId, $data);
                } else {
                    self::log("method '$method' not implemented in external service '$externalService'");
                }
            }
        }
    }
}
