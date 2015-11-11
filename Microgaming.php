<?php

/**
 * Manages the logic of methods, which initiated from API requests from MGS
 */
class Bo_GameService_Vegas_Microgaming implements Bo_GameService_Vegas_Interface
{
    const MAX_SAVED_TOKENS = 10;

    const TOKEN_INVALID_ERROR_CODE = 6001;
    const TOKEN_INVALID_ERROR_DESC = 'The player token is invalid';
    const TOKEN_EXPIRED_ERROR_CODE = 6002;
    const TOKEN_EXPIRED_ERROR_DESC = 'The player token expired';
    const API_AUTH_INCORRECT_ERROR_CODE = 6003;
    const API_AUTH_INCORRECT_ERROR_DESC = 'The authentication credentials for the API are incorrect';
    const INSUFFICIENT_FUNDS_ERROR_CODE = 6503;
    const INSUFFICIENT_FUNDS_ERROR_DESC = 'Player has insufficient funds';
    const GAMEREFERENCE_NOT_EXIST_ERROR_CODE = 6511;
    const GAMEREFERENCE_NOT_EXIST_ERROR_DESC = 'The external system name does not exist (gamereference)';

    const BET_GAME_ACTION_TYPE_ID = 1;
    const BET_GAME_ACTION_TYPE = 'bet';
    const WIN_GAME_ACTION_TYPE_ID = 2;
    const WIN_GAME_ACTION_TYPE = 'win';
    const PROGRESSIVEWIN_GAME_ACTION_TYPE_ID = 3;
    const PROGRESSIVEWIN_GAME_ACTION_TYPE = 'progressivewin';
    // There is no id for 'refund' game_action_type (to prevent new db record with existing game_action_id) - in db used 'refunded' flag instead
    const REFUND_GAME_ACTION_TYPE = 'refund';

    protected $options;

    public function setOptions(array $options)
    {
        $this->options = $options;
    }

    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Generates token for specified $userId
     * @param int $userId
     * @return string Token like "$userId_<hash>"
     */
    private function generateToken($userId)
    {
        $hash = Bo_User_Manager::generateCode();
        return "{$userId}_$hash";
    }

    /**
     * Saves specified $value for the $key into the Redis
     * @param string $key
     * @param mixed $value
     * @param null $lifeTime (optional) Value lifetime in seconds
     * @return mixed Saved value
     */
    private function saveToCache($key, $value, $lifeTime = null)
    {
        $fastStorage = Bo_ConnectionsDistributor::getDefaultFastStorageAdapter();
        try
        {
            $fastStorage->setValue($key, $value, $lifeTime);
            return $value;
        }
        catch(App_FastStorage_Adapter_Exception $e){}
    }

    /**
     * Return value saved in the Redis for specified $key
     * @param string $key
     * @return mixed
     */
    private function getFromCache($key)
    {
        $fastStorage = Bo_ConnectionsDistributor::getDefaultFastStorageAdapter();
        try
        {
            return $fastStorage->getValue($key);
        }
        catch(App_FastStorage_Adapter_Exception $e){}
    }

    /**
     * Checks API authorization credentials
     * @param string $login
     * @param string $password
     * @throws Bo_Rpc_Exception If wrong credentials
     * @return void
     */
    public function checkApiAuth($login, $password)
    {
        if ($login != $this->options['api_user'] || $password != $this->options['api_pass'])
        {
            throw new Bo_Rpc_Exception(self::API_AUTH_INCORRECT_ERROR_DESC, self::API_AUTH_INCORRECT_ERROR_CODE);
        }
    }

    /**
     * Generates new token, prepends it to the previous tokens and saves tokens into the Redis for specified $userId
     * Also saves prev token as old and saves current token as prev (to allow retry request)
     * @param int $userId
     * @param int $lifeTime (optional) Token lifetime in seconds
     * @return string Current token
     */
    public function saveToken($userId, $lifeTime = null)
    {
        $tokens = $this->getFromCache("MG_TOKENS_FOR_USER_$userId");
        if (!$tokens)
        {
            $tokens = array();
        }

        // Generate new token and prepends it to the front of the $tokens
        $newToken = $this->generateToken($userId);
        array_unshift($tokens, $newToken);

        // Keep MAX_SAVED_TOKENS only
        $tokens = array_slice($tokens, 0, self::MAX_SAVED_TOKENS);

        // Save tokens into the Redis
        $this->saveToCache("MG_TOKENS_FOR_USER_$userId", $tokens, $lifeTime);

        return $newToken;
    }

    /**
     * Return token saved in the Redis for specified $userId
     * @param int $userId
     * @return string
     */
    public function getToken($userId)
    {
        $tokens = $this->getFromCache("MG_TOKENS_FOR_USER_$userId");
        return (isset($tokens[0])) ? $tokens[0] : '';
    }

    /**
     * Removes all saved tokens for specified user
     * @param int $userId
     * @return void
     */
    public function removeTokens($userId)
    {
        $fastStorage = Bo_ConnectionsDistributor::getDefaultFastStorageAdapter();
        try
        {
            $fastStorage->dropValue("MG_TOKENS_FOR_USER_$userId");
        }
        catch(App_FastStorage_Adapter_Exception $e){}
    }

    /**
     * Validates specified token
     * @param string $token
     * @throws Bo_Rpc_Exception If token incorrect or expired
     * @return void
     */
    public function validateToken($token)
    {
        // Check for existing user id in the token
        $user = $this->getUserByToken($token);
        if (!$user)
        {
            throw new Bo_Rpc_Exception(self::TOKEN_INVALID_ERROR_DESC, self::TOKEN_INVALID_ERROR_CODE);
        }
        // Check user token in the Redis
        $tokens = $this->getFromCache("MG_TOKENS_FOR_USER_{$user->user_id}");
        if (!$tokens || !is_array($tokens))
        {
            throw new Bo_Rpc_Exception(self::TOKEN_EXPIRED_ERROR_DESC, self::TOKEN_EXPIRED_ERROR_CODE);
        }
        // Compare with current, prev and old token (to ensure "at least 2 tokens valid per Player, at any one given time" MGS requirement)
        if (!in_array($token, $tokens))
        {
            throw new Bo_Rpc_Exception(self::TOKEN_INVALID_ERROR_DESC, self::TOKEN_INVALID_ERROR_CODE);
        }
    }

    /**
     * Returns user if specified token satisfies format "<existing_user_id>_<hash>"
     * @param string $token
     * @return Users_Portal_Model
     */
    public function getUserByToken($token)
    {
        $tokenParts = explode('_', $token);
        $userId = intval($tokenParts[0]);
        if (!$userId || strpos($token, '_') === false)
        {
            return null;
        }
        $user = Bo_User_Portal_Manager::getUserById($userId);
        if (isset($user->user_id))
        {
            return $user;
        }
        return null;
    }

    /**
     * Re-generates token for specified $userId in the Redis
     * @param int $userId
     * @return string New token
     */
    public function refreshToken($userId)
    {
        return $this->saveToken($userId, $this->options['token_lifetime']);
    }

    /**
     * Returns balance in Casino game service for specified $user
     * @param Users_Portal_Model $user
     * @return int User balance in cents
     */
    public function getBalance(Users_Portal_Model $user)
    {
        $casinoGameService = Bo_GameService_Manager::get(Bo_GameService_Casino_Manager::GAME_SERVICE_ID);
        $currency = Bo_Finance_Account_Manager::getDefaultCurrency();
        $casinoFinanceAccount = Bo_Finance_Account_Manager::getUserFinanceAccount($user, $casinoGameService, $currency);
        $balance = $casinoFinanceAccount->amount * 100; // in cents
        return $balance;
    }

    /**
     * Use microtime as transaction id
     * @return float
     */
    public function getTransactionId()
    {
        return round(microtime(true) * 10000);
    }

    /**
     * Validate $params, updates user balance and inserts game action record
     * @param Users_Portal_Model $user
     * @param array $params
     * @throws Bo_Rpc_Exception
     * @return int New user balance in cents
     */
    public function processGameAction(Users_Portal_Model $user, $params)
    {
        // Check whether specified game is exist
        $game = Bo_GameService_Vegas_Manager::getGameByExternalGameName($params['gamereference']);
        if (is_null($game))
        {
            throw new Bo_Rpc_Exception(self::GAMEREFERENCE_NOT_EXIST_ERROR_DESC, self::GAMEREFERENCE_NOT_EXIST_ERROR_CODE);
        }

        // Get current user balance
        $balance = $this->getBalance($user);

        // Check whether action has been already processed
        /* @var $mapper Vegas_Game_Action_Mapper */
        $mapper = Bo_Mapper_Factory::get("Vegas_Game_Action_Mapper");
        if ($params['playtype'] == self::REFUND_GAME_ACTION_TYPE)
        {
            // Corresponding bet must not been refunded yet
            $actionData = array(
                'game_action_id' => $params['actionid'],
                'game_action_type_id' => self::BET_GAME_ACTION_TYPE_ID,
                'refunded' => 'FALSE'
            );
            $action = $mapper->getListByPattern(new Vegas_Game_Action_Model($actionData))->current();
            if (!$action)
            {
                return $balance;
            }
        }
        else
        {
            // Check whether this action already exists in db
            $actionData = array('game_action_id' => $params['actionid']);
            $action = $mapper->getListByPattern(new Vegas_Game_Action_Model($actionData))->current();
            if ($action)
            {
                return $balance;
            }
        }
        // Check whether enough funds to make bet
        if ($params['playtype'] == self::BET_GAME_ACTION_TYPE && $params['amount'] > $balance)
        {
            throw new Bo_Rpc_Exception(self::INSUFFICIENT_FUNDS_ERROR_DESC, self::INSUFFICIENT_FUNDS_ERROR_CODE);
        }

        // Insert game action record or mark bet action as rolled back
        if ($params['playtype'] != self::REFUND_GAME_ACTION_TYPE)
        {
            // Get game action type id
            switch ($params['playtype']) {
                case self::BET_GAME_ACTION_TYPE:
                    $gameActionTypeId = self::BET_GAME_ACTION_TYPE_ID;
                    break;
                case self::WIN_GAME_ACTION_TYPE:
                    $gameActionTypeId = self::WIN_GAME_ACTION_TYPE_ID;
                    break;
                case self::PROGRESSIVEWIN_GAME_ACTION_TYPE:
                    $gameActionTypeId = self::PROGRESSIVEWIN_GAME_ACTION_TYPE_ID;
                    break;
                default:
                    return $balance;
            }
            $actionData = array(
                'game_action_id' => $params['actionid'],
                'game_action_type_id' => $gameActionTypeId,
                'created' => 'NOW()',
                'round_id' => $params['gameid'],
                'user_id' => $user->user_id,
                'game_id' => $game->game_id,
                'user_balance_in_game' => $balance / 100,
                'amount' => $params['amount'] / 100,
                'refunded' => 'FALSE'
            );

            //increment bets for user in redis
            Bo_User_Stat_Manager::incrementBets($actionData['user_id'],$actionData['amount']);

            // Check for freegame offer
            if (isset($params['freegame']))
            {
                /* @var $freegameOfferMapper Vegas_Freegame_Offer_Mapper */
                $freegameOfferMapper = Bo_Mapper_Factory::get("Vegas_Freegame_Offer_Mapper");
                $freegameOffer = $freegameOfferMapper->getListByPattern(new Vegas_Freegame_Offer_Model(
                    array('name' => $params['freegame'], 'game_id' => $game->game_id)))->current();
                if ($freegameOffer)
                {
                    // Get user's freegame offers and looking for specified freegame offer
                    $freegameUserOffers = Bo_GameService_Vegas_Manager::getFreegameUserOffers($user);
                    $freegameUserOfferId = null;
                    foreach ($freegameUserOffers as $freegameUserOffer)
                    {
                        if ($freegameUserOffer['freegame_offer_id'] == $freegameOffer->freegame_offer_id)
                        {
                            $freegameUserOfferId = $freegameUserOffer['freegame_user_offer_id'];
                            break;
                        }
                    }
                    if ($freegameUserOfferId)
                    {
                        $actionData['freegame_user_offer_id'] = $freegameUserOfferId;
                    }
                }
            }

            $mapper->create(new Vegas_Game_Action_Model($actionData));
        }
        else
        {
            $oldActionPattern = new Vegas_Game_Action_Model(array('game_action_id' => $params['actionid']));
            $newActionPattern = new Vegas_Game_Action_Model(array('refunded' => true));
            $mapper->update($oldActionPattern, $newActionPattern);
        }

        // Change user balance
        $financeAccountMapper = Bo_Mapper_Factory::get('Finance_Account_Mapper');
        $currency = Bo_Finance_Account_Manager::getDefaultCurrency();
        $accountPattern = new Finance_Account_Model(array(
            "user_id"                 => $user->user_id,
            "currency_id"             => $currency->currency_id,
            "game_service_id"         => Bo_GameService_Casino_Manager::GAME_SERVICE_ID,
            "finance_account_type_id" => Bo_Finance_Account_Manager::GENERAL_FINANCE_ACCOUNT_TYPE
        ));
        $accountModel = $financeAccountMapper->getListByPattern($accountPattern)->current();
        if ($params['amount'] > 0)
        {
            $amount = Bo_Finance_Math::financeRound($params['amount'] / 100);
            if ($params['playtype'] == self::BET_GAME_ACTION_TYPE)
            {
                Bo_Finance_Account_Manager::removeFunds($accountModel, $amount);

                # Add CP for bets
                Bo_Finance_Cp_Manager::addCpForBets($user->user_id, $amount, $game->game_id, Bo_GameService_Vegas_Manager::GAME_SERVICE_ID);
            }
            else
            {
                $financeOperationTypeId = ($params['playtype'] == self::REFUND_GAME_ACTION_TYPE)
                        ? Bo_Finance_Operation_Manager::MONEY_REVERSE_TRANSFER : Bo_Finance_Operation_Manager::MONEY_TRANSFER;
                Bo_Finance_Account_Manager::addFunds($accountModel, $amount, false, 0, $financeOperationTypeId);

                if ($params['playtype'] == self::REFUND_GAME_ACTION_TYPE)
                {
                    # Remove CP for bets
                    Bo_Finance_Cp_Manager::removeCpForBets($user->user_id, $amount, $game->game_id, Bo_GameService_Vegas_Manager::GAME_SERVICE_ID);
                }
            }
            $newBalance = self::getBalance($user);
        }
        else
        {
            $newBalance = $balance;
        }
        return $newBalance;
    }

    /**
     * Returns game url with substituted parameters
     * @param Vegas_Game_Model $game
     * @param string $gameLang 2-char language code to show the game
     * @param string $token Token to authorize user (null for demo mode)
     * @return string
     */
    public function getGameUrl(Vegas_Game_Model $game, $gameLang, $token)
    {
        if ($game->parent_theme_id == Bo_GameService_Vegas_Manager::THEME_MOBILE)
        {
            $gameCode = preg_replace('/^' . Bo_GameService_Vegas_Manager::MOBILE_GAME_CODE_PREFIX . '/', '', $game->code);
            $gameUrlPattern = ($token) ? $this->options['mobile_real_game_url'] : $this->options['mobile_demo_game_url'];
        }
        elseif ($game->parent_theme_id == Bo_GameService_Vegas_Manager::THEME_LIVE_DEALERS)
        {
            $gameCode = preg_replace('/^' . Bo_GameService_Vegas_Manager::LIVE_DEALERS_GAME_CODE_PREFIX . '/', '', $game->code);
            $gameUrlPattern = $this->options['ld_real_game_url'];
        }
        else
        {
            $gameCode = $game->code;
            $gameUrlPattern = ($token) ? $this->options['real_game_url'] : $this->options['demo_game_url'];
        }
        if ($gameLang == 'gr')
        {
            $gameLang = 'el'; // we use 'gr' code for Greek, but MGS uses 'el' instead - see iso639-2
        }
        $gameUrl = sprintf($gameUrlPattern, $gameLang, urlencode($gameCode), $token);
        return $gameUrl;
    }

    /**
     * Sets activation_date if specified game round used freegame_user_offer
     * @param Users_Portal_Model $user
     * @param array $params
     * @throws Bo_Rpc_Exception If game not found
     * @return void
     */
    public function activateFreegameUserOffer(Users_Portal_Model $user, $params)
    {
        // Check whether specified game is exist
        $game = Bo_GameService_Vegas_Manager::getGameByExternalGameName($params['gamereference']);
        if (is_null($game))
        {
            throw new Bo_Rpc_Exception(self::GAMEREFERENCE_NOT_EXIST_ERROR_DESC, self::GAMEREFERENCE_NOT_EXIST_ERROR_CODE);
        }

        // Check whether specified game round used freegame_user_offer
        /* @var $mapper Vegas_Game_Action_Mapper */
        $mapper = Bo_Mapper_Factory::get("Vegas_Game_Action_Mapper");
        $actionData = array(
            'user_id' => $user->user_id,
            'game_id' => $game->game_id,
            'round_id' => $params['gameid']
        );
        $action = $mapper->getListByPattern(new Vegas_Game_Action_Model($actionData))->current();
        if ($action && $action->freegame_user_offer_id)
        {
            // Set Activated status and activation_date of used freegame_user_offer
            /* @var $freegameUserOfferMapper Vegas_Freegame_User_Offer_Mapper */
            $freegameUserOfferMapper = Bo_Mapper_Factory::get("Vegas_Freegame_User_Offer_Mapper");
            $oldOfferPattern = new Vegas_Freegame_User_Offer_Model(array('freegame_user_offer_id' => $action->freegame_user_offer_id));
            $newOfferPattern = new Vegas_Freegame_User_Offer_Model(
                array('activation_date' => 'NOW()', 'status_id' => Bo_GameService_Vegas_Manager::FREEGAME_USER_OFFER_ACTIVATED_STATUS_ID));
            $freegameUserOfferMapper->update($oldOfferPattern, $newOfferPattern);
        }
    }
}