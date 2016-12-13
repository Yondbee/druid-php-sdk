<?php
namespace Genetsis\Identity\Services;

use Genetsis\core\Logger\Contracts\LoggerServiceInterface;
use Genetsis\core\OAuth\Contracts\OAuthServiceInterface;
use Genetsis\core\FileCache;
use Genetsis\core\OAuth\Beans\ClientToken;
use Genetsis\core\OAuth\Collections\TokenTypes as TokenTypesCollection;
use Genetsis\core\OAuth\Exceptions\InvalidGrantException;
use Genetsis\core\OAuth\Services\OAuth;
use Genetsis\core\User\Beans\Things;
use Genetsis\core\User\Collections\LoginStatusTypes as LoginStatusTypesCollection;
use Genetsis\DruID;
use Genetsis\Identity\Contracts\IdentityServiceInterface;

class Identity implements IdentityServiceInterface {

    /** @var OAuthServiceInterface $oauth */
    protected $oauth;
    /** @var LoggerServiceInterface $logger */
    protected $logger;
    
    /** @var boolean $synchronized Indicates if the service has been sync with the server. */
    private $synchronized = false;
    /** @var Things $gid_things Object to store Genetsis ID's session data. */
    private $gid_things;

    /**
     * @param OAuthServiceInterface $oauth
     * @param LoggerServiceInterface $logger
     */
    public function __construct(OAuthServiceInterface $oauth, LoggerServiceInterface $logger)
    {
        $this->oauth = $oauth;
        $this->logger = $logger;
    }

    /**
     * This method verifies the authorization tokens (client_token,
     * access_token and refresh_token). Also updates the web client status,
     * storing the client_token, access_token and refresh tokend and
     * login_status in Things {@link Things}.
     *
     * Is INVOKE ON EACH REQUEST in order to check and update
     * the status of the user (not logged, logged or connected), and
     * verify that every token that you are gonna use before is going to be
     * valid.
     *
     * @return void
     * @throws \Exception
     */
    public function synchronizeSessionWithServer()
    {
        if (!$this->synchronized) {
            $this->synchronized = true;

            try {
                $this->logger->debug('Synchronizing session with server', __METHOD__, __LINE__);
                $this->checkAndUpdateClientToken();

                $this->loadUserTokenFromPersistence();

                if ($this->gid_things->getAccessToken() == null) {
                    $this->logger->debug('User is not logged, check SSO', __METHOD__, __LINE__);
                    $this->checkSSO();
                    if ($this->gid_things->getRefreshToken() != null) {
                        $this->logger->debug('User not logged but has Refresh Token', __METHOD__, __LINE__);
                        $this->checkAndRefreshAccessToken();
                    }
                } else {
                    if ($this->isExpired($this->gid_things->getAccessToken()->getExpiresAt())) {
                        $this->logger->debug('User logged but Access Token is expires', __METHOD__, __LINE__);
                        $this->checkAndRefreshAccessToken();
                    } else {
                        $this->logger->debug('User logged - check Validate Bearer', __METHOD__, __LINE__);
                        $this->checkLoginStatus();
                    }
                    if (!$this->isConnected()) {
                        $this->logger->warn('User logged but is not connected (something wrong) - clear session data', __METHOD__, __LINE__);
                        $this->clearLocalSessionData();
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage(), __METHOD__, __LINE__);
            }
            $_SESSION['Things'] = @serialize($this->gid_things);
        }
    }

    /**
     * Checks and updates the "client_token" and cache if we have a valid one
     * If we don not have a Client Token in session, we check if we have a cookie
     * If we don not have a client Token in session or in a cookie, We request a new Client Token.
     * This method set the Client Token in Things
     *
     * @return void
     * @throws \Exception
     */
    private function checkAndUpdateClientToken()
    {
        try {
            $this->logger->debug('Checking and update client_token.', __METHOD__, __LINE__);
            if (!(($client_token = unserialize(FileCache::get('client_token'))) instanceof ClientToken) || ($client_token->getValue() == '')) {
                $this->logger->debug('Get Client token', __METHOD__, __LINE__);

                if (($this->gid_things->getClientToken() == null) || ($this->oauth->getStoredToken(TokenTypesCollection::CLIENT_TOKEN) == null)) {
                    $this->logger->debug('Not has clientToken in session or cookie', __METHOD__, __LINE__);

                    if (!$client_token = $this->oauth->getStoredToken(TokenTypesCollection::CLIENT_TOKEN)) {
                        $this->logger->debug('Token Cookie does not exists. Requesting a new one.', __METHOD__, __LINE__);
                        $client_token = $this->oauth->doGetClientToken((string)$this->oauth->getConfig()->getEndPoint('token_endpoint'));
                    }
                    $this->gid_things->setClientToken($client_token);
                } else {
                    $this->logger->debug('Client Token from session', __METHOD__, __LINE__);
                }
                FileCache::set('client_token', serialize($this->gid_things->getClientToken()), $this->gid_things->getClientToken()->getExpiresIn());
            } else {
                $this->logger->debug('Client Token from cache', __METHOD__, __LINE__);
                $this->gid_things->setClientToken($client_token);
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Checks if user is logged via SSO (datr cookie) - Single Sign On
     *
     * The method obtain the "access_token" of the logged user in
     * "*.cocacola.es" through the cookie, with Grant Type EXCHANGE_SESSION
     * To SSO on domains that are not under .cocacola.es the site must include this file
     * <script type="text/javascript" src="https://register.cocacola.es/login/sso"></script>
     *
     * @return void
     * @throws \Exception
     */
    private function checkSSO()
    {
        try {
            $datr = call_user_func(function(){
                if (!isset($_COOKIE) || !is_array($_COOKIE)) {
                    return false;
                }

                if (isset($_COOKIE['datr']) && !empty($_COOKIE['datr'])) {
                    return $_COOKIE['datr'];
                }

                foreach ($_COOKIE as $key => $val) {
                    if (strpos($key, 'datr_') === 0) {
                        return $val;
                    }
                }

                return false;
            });

            if ($datr) {
                $this->logger->info('DATR cookie was found.', __METHOD__, __LINE__);

                $response = $this->oauth->doExchangeSession(
                    (string)$this->oauth->getConfig()->getEndPoint('token_endpoint'),
                    $datr
                );

                $this->gid_things->setAccessToken($response['access_token']);
                $this->gid_things->setRefreshToken($response['refresh_token']);
                $this->gid_things->setLoginStatus($response['login_status']);
            } else {
                $this->logger->debug('DATR cookie not exist, user is not logged', __METHOD__, __LINE__);
            }
        } catch (InvalidGrantException $e) {
            unset($_COOKIE[OAuth::SSO_COOKIE_NAME]);
            setcookie(OAuth::SSO_COOKIE_NAME, null, -1, null);

            $this->logger->warn('Invalid Grant, check an invalid DATR', __METHOD__, __LINE__);
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Checks if a token has expired.
     *
     * @param integer $expiresAt The expiration date. In UNIX timestamp.
     * @return boolean TRUE if is expired or FALSE otherwise.
     */
    private function isExpired($expiresAt)
    {
        if (!is_null($expiresAt)) {
            return (time() > $expiresAt);
        }
        return true;
    }

    /**
     * Checks and refresh the user's "access_token".
     *
     * @return void
     * @throws \Exception
     */
    private function checkAndRefreshAccessToken()
    {
        try {
            $this->logger->debug('Checking and refreshing the AccessToken.', __METHOD__, __LINE__);
            $response = $this->oauth->doRefreshToken((string)$this->oauth->getConfig()->getEndPoint('token_endpoint'));
            $this->gid_things->setAccessToken($response['access_token']);
            $this->gid_things->setRefreshToken($response['refresh_token']);
            $this->gid_things->setLoginStatus($response['login_status']);
        } catch (InvalidGrantException $e) {
            $this->clearLocalSessionData();
            throw $e;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Deletes the local data of the user's session.
     *
     * @return void
     */
    private function clearLocalSessionData()
    {
        $this->logger->debug('Clear Session Data', __METHOD__, __LINE__);
        $this->gid_things->setAccessToken(null);
        $this->gid_things->setRefreshToken(null);
        $this->gid_things->setLoginStatus(null);

        $this->oauth->deleteStoredToken(TokenTypesCollection::ACCESS_TOKEN);
        $this->oauth->deleteStoredToken(TokenTypesCollection::REFRESH_TOKEN);

        if (isset($_SESSION)) {
            unset($_SESSION['Things']);
            foreach ($_SESSION as $key => $val) {
                if (preg_match('#^headerAuth#Ui', $key) || in_array($key, array('nickUserLogged', 'isConnected'))) {
                    unset($_SESSION[$key]);
                }
            }
        }
    }

    /**
     * Checks the user's status from Validate Bearer.
     * Update Things {@link Things} login status
     *
     * @return void
     * @throws \Exception
     */
    private function checkLoginStatus()
    {
        try {
            $this->logger->debug('Checking login status', __METHOD__, __LINE__);
            if ($this->gid_things->getLoginStatus()->getConnectState() == LoginStatusTypesCollection::CONNECTED) {
                $this->logger->debug('User is connected, check access token', __METHOD__, __LINE__);
                $loginStatus = $this->oauth->doValidateBearer((string)$this->oauth->getConfig()->getEndPoint('token_endpoint'));
                $this->gid_things->setLoginStatus($loginStatus);
            }
        } catch (InvalidGrantException $e) {
            $this->logger->warn('Invalid Grant, maybe access token is expires and sdk not checkit - call to refresh token', __METHOD__, __LINE__);
            $this->checkAndRefreshAccessToken();
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Helper to check if the user is connected (logged on Genetsis ID)
     *
     * @return boolean TRUE if is logged, FALSE otherwise.
     */
    public function isConnected()
    {
        if ((!is_null($this->getThings())) && (!is_null($this->getThings()->getAccessToken())) &&
            (!is_null($this->getThings()->getLoginStatus()) && ($this->getThings()->getLoginStatus()->getConnectState() == LoginStatusTypesCollection::CONNECTED))
        ) {
            return true;
        }
        return false;
    }

    /**
     * Helper to access library data
     *
     * @return Things
     */
    public function getThings()
    {
        return $this->gid_things;
    }

    /**
     * In that case, the url of "post-login" will retrieve an authorization
     * code as a GET parameter.
     *
     * Once the authorization code is provided to the web client, the SDK
     * will send it again to Genetsis ID at "token_endpoint" to obtain the
     * "access_token" of the user and create the cookie.
     *
     * This method is needed to authorize user when the web client takes
     * back the control of the browser.
     *
     * @param string $code Authorization code returned by Genetsis ID.
     * @return void
     * @throws \Exception
     */
    public function authorizeUser($code)
    {
        try {
            $this->logger->debug('Authorize user', __METHOD__, __LINE__);

            if ($code == '') {
                throw new \Exception('Authorize Code is empty');
            }

            $response = $this->oauth->doGetAccessToken((string)$this->oauth->getConfig()->getEndPoint('token_endpoint'), $code, (string)$this->oauth->getConfig()->getRedirect('postLogin'));
            $this->gid_things->setAccessToken($response['access_token']);
            $this->gid_things->setRefreshToken($response['refresh_token']);
            $this->gid_things->setLoginStatus($response['login_status']);

            $_SESSION['Things'] = @serialize($this->gid_things);

        } catch (InvalidGrantException $e) {
            $this->logger->error($e->getMessage(), __METHOD__, __LINE__);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), __METHOD__, __LINE__);
        }
    }

    /**
     * Checks if the user have been completed all required fields for that
     * section.
     *
     * The "scope" (section) is a group of fields configured in Genetsis ID for
     * a web client.
     *
     * A section can be also defined as a "part" (section) of the website
     * (web client) that only can be accesed by a user who have filled a
     * set of personal information configured in Genetsis ID (all of the fields
     * required for that section).
     *
     * This method is commonly used for promotions or sweepstakes: if a
     * user wants to participate in a promotion, the web client must
     * ensure that the user have all the fields filled in order to let him
     * participate.
     *
     * @param $scope string Section-key identifier of the web client. The
     *     section-key is located in "oauthconf.xml" file.
     * @throws \Exception
     * @return boolean TRUE if the user have already completed all the
     *     fields needed for that section, false in otherwise
     */
    public function checkUserComplete($scope)
    {
        $userCompleted = false;
        try {
            $this->logger->info('Checking if the user has filled its data out for this section:' . $scope, __METHOD__, __LINE__);

            if ($this->isConnected()) {
                $userCompleted = $this->oauth->doCheckUserCompleted($this->oauth->getConfig()->getApi('api.user')->getEndpoint('user', true), $scope);
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), __METHOD__, __LINE__);
        }
        return $userCompleted;
    }

    /**
     * Checks if the user needs to accept terms and conditions for that section.
     *
     * The "scope" (section) is a group of fields configured in DruID for
     * a web client.
     *
     * A section can be also defined as a "part" (section) of the website
     * (web client) that only can be accessed by a user who have filled a
     * set of personal information configured in DruID.
     *
     * @param $scope string Section-key identifier of the web client. The
     *     section-key is located in "oauthconf.xml" file.
     * @throws \Exception
     * @return boolean TRUE if the user need to accept terms and conditions, FALSE if it has
     *      already accepted them.
     */
    public function checkUserNeedAcceptTerms($scope)
    {
        $status = false;
        try {
            $this->logger->info('Checking if the user has accepted terms and conditions for this section:' . $scope, __METHOD__, __LINE__);

            if ($this->isConnected()) {
                $status = $this->oauth->doCheckUserNeedAcceptTerms($this->oauth->getConfig()->getApi('api.user')->getEndpoint('user', true), $scope);
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), __METHOD__, __LINE__);
        }
        return $status;
    }

    /**
     * Performs the logout process.
     *
     * It makes:
     * - The logout call to Genetsis ID
     * - Clear cookies
     * - Purge Tokens and local data for the logged user
     *
     * @return void
     * @throws \Exception
     */
    public function logoutUser()
    {
        try {
            if (($this->gid_things->getAccessToken() != null) && ($this->gid_things->getRefreshToken() != null)) {
                $this->logger->info('User Single Sign Logout', __METHOD__, __LINE__);
                DruID::userApi()->deleteCacheUser($this->gid_things->getLoginStatus()->getCkUsid());

                $this->oauth->doLogout((string)$this->oauth->getConfig()->getEndPoint('logout_endpoint'));
                $this->clearLocalSessionData();
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), __METHOD__, __LINE__);
        }
    }

    /**
     * Returns a clientToken if user is not logged, and accessToken if user is logged
     *
     * @return mixed An instance of {@link AccessToken} or
     *     {@link ClientToken}
     * @throws \Exception if we have not a valid Token
     */
    private function getTokenUser()
    {
        try {
            if (!is_null($this->gid_things->getAccessToken())) {
                $this->logger->debug('Get AccessToken, user logged', __METHOD__, __LINE__);
                return $this->gid_things->getAccessToken();
            } else {
                $this->logger->debug('Get ClientToken, user is NOT logged', __METHOD__, __LINE__);
                return $this->gid_things->getClientToken();
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), __METHOD__, __LINE__);
            throw new \Exception('Not valid token');
        }
    }

    /**
     * Update the user's "access_token" from persistent data (SESSION or
     * COOKIE)
     *
     * @return void
     */
    private function loadUserTokenFromPersistence ()
    {
        try {
            if (is_null($this->gid_things->getAccessToken())){
                $this->logger->debug('Load access token from cookie', __METHOD__, __LINE__);

                if ($this->oauth->hasToken(TokenTypesCollection::ACCESS_TOKEN)) {
                    $this->gid_things->setAccessToken($this->oauth->getStoredToken(TokenTypesCollection::ACCESS_TOKEN));
                }
                if ($this->oauth->hasToken(TokenTypesCollection::REFRESH_TOKEN)) {
                    $this->gid_things->setRefreshToken($this->oauth->getStoredToken(TokenTypesCollection::REFRESH_TOKEN));
                }
            }


        } catch (\Exception $e) {
            $this->logger->error('['.__CLASS__.']['.__FUNCTION__.']['.__LINE__.']'.$e->getMessage(), __METHOD__, __LINE__);
        }
    }

}