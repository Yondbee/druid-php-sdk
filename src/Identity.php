<?php
/**
 * GID library for PHP
 *
 * @package    GIDSdk
 * @copyright  Copyright (c) 2012 Genetsis
 * @version    2.0
 * @see       http://developers.dru-id.com
 */
namespace Genetsis;

require_once(dirname(__FILE__) . "/Autoloader.php");

use Exception;
use Genetsis\core\Logger\Contracts\LoggerServiceInterface;
use Genetsis\core\Logger\Services\DruIDLogger;
use Genetsis\core\Logger\Services\EmptyLogger;
use Genetsis\core\OAuth\Beans\ClientToken;
use Genetsis\core\Things;
use Genetsis\core\FileCache;
use Genetsis\core\InvalidGrantException;
use Genetsis\core\OAuth\Collections\TokenTypes as TokenTypesCollection;
use Genetsis\core\LogConfig;
use Genetsis\core\Logger\Collections\LogLevels as LogLevelsCollection;
use Genetsis\core\LoginStatusType;
use Genetsis\core\OAuth\Contracts\OAuthInterface;
use Genetsis\core\OAuth;
use Genetsis\core\OAuthConfig;
use Genetsis\core\ServiceContainer\Services\ServiceContainer;




if (session_id() === '') {
    session_start();
}

/**
 * This is the main class of the DRUID library.
 *
 * It's the class that wraps the whole set of classes of the library and
 * that you'll have to use the most. With it, you'll be able to check if a
 * user is logged, log them out, obtain the personal data of any user,
 * and check if a user has enough data to take part in a promotion, upload
 * content or carry out an action that requires a specific set of personal
 * data.
 *
 * Sample usage:
 * <code>
 *    Identity::init();
 *    // ...
 * </code>
 *
 * @package  Genetsis
 * @version  2.0
 * @access   public
 */
class Identity
{
    /** @var \Genetsis\core\OAuth\Contracts\OAuthInterface $oauth */
    private static $oauth;
    /** @var Things Object to store Genetsis ID's session data. */
    private static $gid_things;
    /** @var boolean Inidicates if Identity has been initialized */
    private static $initialized = false;
    /** @var boolean Inidicates if synchronizeSessionWithServer has been called */
    private static $synchronized = false;


    /**
     * When you initialize the library, the configuration defined in "oauthconf.xml" file of the gid_client is loaded
     * and by default this method auto-sync data (client_token, access_token,...) with server
     *
     * @param array $settings Accepts:
     *      - 'app': (string) The app to load. The default app is 'default'.
     *      - 'sync': (boolean) Indicates whether to automatically synchronize data against the server. Default is TRUE.
     *      - 'ini_path': (string) If defined should be the internal path to the configuration file of the library (the druid.ini file).
     *          If this property is not defined then the library will search for this file at the default folder.
     *      - 'logger': (\Genetsis\core\Logger\Contracts\LoggerServiceInterface) If you want to define the logger for you application.
     * @return void
     * @throws \Exception
     */
    public static function initialize(array $settings = [])
    {
        try {
            if (!static::$initialized) {
                if (!isset($settings['ini_path'])) {
                    $settings['ini_path'] = dirname(__FILE__) . '/../config/druid.ini';
                }
                Config::$ini_path = $settings['ini_path'];
                self::$initialized = true;
                AutoloaderClass::init();

                // Init config library
                if (!isset($settings['app'])) { $settings['app'] = 'default'; }
                if (!isset($settings['sync'])) { $settings['sync'] = true; }
                Config::init($settings['app'], $settings['ini_path']);

                if (isset($settings['logger']) && ($settings['logger'] instanceof LoggerServiceInterface)) { // Custom logger.
                    ServiceContainer::setLogger($settings['logger']);
                } else { // Default logger based on configuration.
                    if ((Config::logLevel() === 'OFF') && (!isset($_COOKIE['gidlog']))) {
                        ServiceContainer::setLogger(new EmptyLogger());
                    } else {
                        ServiceContainer::setLogger(new DruIDLogger($_SERVER['DOCUMENT_ROOT'] . '/' . Config::logPath(), LogLevelsCollection::DEBUG));
                        if (!class_exists('Logger')) {
                            include_once dirname(__FILE__) . '/core/log4php/Logger.php';
                        }
                        \Logger::configure('main', new LogConfig(Config::logLevel(), Config::logPath()));
                    }
                }

                // Initialize Cache.
                FileCache::init(Config::cachePath(), Config::environment());
                // Initialize OAuth Config
                OAuthConfig::init();
                static::$oauth = new OAuth();

                self::$gid_things = new Things();

                if ($settings['sync']) {
                    self::synchronizeSessionWithServer();
                }
            }
        } catch (Exception $e) {
            ServiceContainer::getLogger()->error($e, __METHOD__, __LINE__);
            throw $e;
        }
    }

    /**
     * When you initialize the library with this method, only the configuration defined in "oauthconf.xml" file of the gid_client is loaded
     * You must synchronize data with server if you need access to client or access token. This method is used for example, in ckactions.php actions.
     *
     * @param array $settings See {@link Identity::initialize} for further information.
     * @return void
     * @throws \Exception
     */
    public static function initializeConfig(array $settings = [])
    {
        $settings['sync'] = false;
        static::initialize($settings);
    }

    /**
     * Deprecated. See {@link Identity::initialize}
     *
     * @deprecated
     */
    public static function init($gid_client = 'default', $sync = true, $ini_path = null)
    {
        static::initialize([
            'app' => $gid_client,
            'sync' => $sync,
            'ini_path' => $ini_path
        ]);
    }

    /**
     * Deprecated. See {@link Identity::initialize}
     *
     * @deprecated
     */
    public static function initConfig($gid_client = 'default')
    {
        static::initialize([
            'app' => $gid_client,
            'sync' => false,
        ]);
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
     */
    public static function synchronizeSessionWithServer()
    {
        if (!self::$synchronized) {
            self::$synchronized = true;

            try {
                ServiceContainer::getLogger()->debug('Synchronizing session with server', __METHOD__, __LINE__);
                self::checkAndUpdateClientToken();

                self::loadUserTokenFromPersistence();

                if (self::$gid_things->getAccessToken() == null) {
                    ServiceContainer::getLogger()->debug('User is not logged, check SSO', __METHOD__, __LINE__);
                    self::checkSSO();
                    if (self::$gid_things->getRefreshToken() != null) {
                        ServiceContainer::getLogger()->debug('User not logged but has Refresh Token', __METHOD__, __LINE__);
                        self::checkAndRefreshAccessToken();
                    }
                } else {
                    if (self::isExpired(self::$gid_things->getAccessToken()->getExpiresAt())) {
                        ServiceContainer::getLogger()->debug('User logged but Access Token is expires', __METHOD__, __LINE__);
                        self::checkAndRefreshAccessToken();
                    } else {
                        ServiceContainer::getLogger()->debug('User logged - check Validate Bearer', __METHOD__, __LINE__);
                        self::checkLoginStatus();
                    }
                    if (!self::isConnected()) {
                        ServiceContainer::getLogger()->warn('User logged but is not connected (something wrong) - clear session data', __METHOD__, __LINE__);
                        self::clearLocalSessionData();
                    }
                }
            } catch (Exception $e) {
                ServiceContainer::getLogger()->error($e->getMessage(), __METHOD__, __LINE__);
            }
            $_SESSION['Things'] = @serialize(self::$gid_things);
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
    private static function checkAndUpdateClientToken()
    {
        try {
            ServiceContainer::getLogger()->debug('Checking and update client_token.', __METHOD__, __LINE__);
            if (!(($client_token = unserialize(FileCache::get('client_token'))) instanceof ClientToken) || ($client_token->getValue() == '')) {
                ServiceContainer::getLogger()->debug('Get Client token', __METHOD__, __LINE__);

                if ((self::$gid_things->getClientToken() == null) || (static::$oauth->getStoredToken(TokenTypesCollection::CLIENT_TOKEN) == null)) {
                    ServiceContainer::getLogger()->debug('Not has clientToken in session or cookie', __METHOD__, __LINE__);

                    if (!$client_token = static::$oauth->getStoredToken(TokenTypesCollection::CLIENT_TOKEN)) {
                        ServiceContainer::getLogger()->debug('Token Cookie does not exists. Requesting a new one.', __METHOD__, __LINE__);
                        $client_token = static::$oauth->doGetClientToken(OauthConfig::getEndpointUrl('token_endpoint'));
                    }
                    self::$gid_things->setClientToken($client_token);
                } else {
                    ServiceContainer::getLogger()->debug('Client Token from session', __METHOD__, __LINE__);
                }
                FileCache::set('client_token', serialize(self::$gid_things->getClientToken()), self::$gid_things->getClientToken()->getExpiresIn());
            } else {
                ServiceContainer::getLogger()->debug('Client Token from cache', __METHOD__, __LINE__);
                self::$gid_things->setClientToken($client_token);
            }
        } catch (Exception $e) {
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
     * @throws /Exception
     */
    private static function checkSSO()
    {
        try {
            if ((isset($_COOKIE['datr']) && ($_COOKIE['datr'] != ''))||(isset($_COOKIE['datr_']) && ($_COOKIE['datr_'] != ''))) {
                ServiceContainer::getLogger()->info('DATR cookie was found.', __METHOD__, __LINE__);

                $response = static::$oauth->doExchangeSession(OauthConfig::getEndpointUrl('token_endpoint'), ($_COOKIE['datr'] != '') ? $_COOKIE['datr'] : $_COOKIE['datr_']);
                self::$gid_things->setAccessToken($response['access_token']);
                self::$gid_things->setRefreshToken($response['refresh_token']);
                self::$gid_things->setLoginStatus($response['login_status']);
            } else {
                ServiceContainer::getLogger()->debug('DATR cookie not exist, user is not logged', __METHOD__, __LINE__);
            }
        } catch (InvalidGrantException $e) {
            unset($_COOKIE[static::$oauth->SSO_COOKIE_NAME]);
            setcookie(OAuth::SSO_COOKIE_NAME, null, -1, null, '.cocacola.es');

            ServiceContainer::getLogger()->warn('Invalid Grant, check an invalid DATR', __METHOD__, __LINE__);
            throw $e;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Checks if a token has expired.
     *
     * @param integer $expiresAt The expiration date. In UNIX timestamp.
     * @return boolean TRUE if is expired or FALSE otherwise.
     */
    private static function isExpired($expiresAt)
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
     * @throws /Exception
     */
    private static function checkAndRefreshAccessToken()
    {
        try {
            ServiceContainer::getLogger()->debug('Checking and refreshing the AccessToken.', __METHOD__, __LINE__);

            $response = OAuth::doRefreshToken(OauthConfig::getEndpointUrl('token_endpoint'));
            self::$gid_things->setAccessToken($response['access_token']);
            self::$gid_things->setRefreshToken($response['refresh_token']);
            self::$gid_things->setLoginStatus($response['login_status']);
        } catch (InvalidGrantException $e) {
            self::clearLocalSessionData();
            throw $e;
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Deletes the local data of the user's session.
     *
     * @return void
     */
    private static function clearLocalSessionData()
    {
        ServiceContainer::getLogger()->debug('Clear Session Data', __METHOD__, __LINE__);
        self::$gid_things->setAccessToken(null);
        self::$gid_things->setRefreshToken(null);
        self::$gid_things->setLoginStatus(null);

        OAuth::deleteStoredToken(TokenTypesCollection::ACCESS_TOKEN);
        OAuth::deleteStoredToken(TokenTypesCollection::REFRESH_TOKEN);

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
     * @throws /Exception
     */
    private static function checkLoginStatus()
    {
        try {
            ServiceContainer::getLogger()->debug('Checking login status', __METHOD__, __LINE__);
            if (self::$gid_things->getLoginStatus()->getConnectState() == LoginStatusType::connected) {
                ServiceContainer::getLogger()->debug('User is connected, check access token', __METHOD__, __LINE__);
                $loginStatus = OAuth::doValidateBearer(OauthConfig::getEndpointUrl('token_endpoint'));
                self::$gid_things->setLoginStatus($loginStatus);
            }
        } catch (InvalidGrantException $e) {
            ServiceContainer::getLogger()->warn('Invalid Grant, maybe access token is expires and sdk not checkit - call to refresh token', __METHOD__, __LINE__);
            self::checkAndRefreshAccessToken();
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Helper to check if the user is connected (logged on Genetsis ID)
     *
     * @return boolean TRUE if is logged, FALSE otherwise.
     */
    public static function isConnected()
    {
        if ((!is_null(self::getThings())) && (!is_null(self::getThings()->getAccessToken())) &&
            (!is_null(self::getThings()->getLoginStatus()) && (self::getThings()->getLoginStatus()->getConnectState() == LoginStatusType::connected))
        ) {
            return true;
        }
        return false;
    }

    /**
     * Helper to access library data
     *
     * @return \Genetsis\core\Things
     */
    public static function getThings()
    {
        return self::$gid_things;
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
     * @throws /Exception
     */
    public static function authorizeUser($code)
    {
        try {
            ServiceContainer::getLogger()->debug('Authorize user', __METHOD__, __LINE__);

            if ($code == '') {
                throw new Exception('Authorize Code is empty');
            }

            $response = OAuth::doGetAccessToken(OauthConfig::getEndpointUrl('token_endpoint'), $code, OauthConfig::getRedirectUrl('postLogin'));
            self::$gid_things->setAccessToken($response['access_token']);
            self::$gid_things->setRefreshToken($response['refresh_token']);
            self::$gid_things->setLoginStatus($response['login_status']);

            $_SESSION['Things'] = @serialize(self::$gid_things);

        } catch ( \Genetsis\core\InvalidGrantException $e) {
            ServiceContainer::getLogger()->error($e->getMessage(), __METHOD__, __LINE__);
        } catch (Exception $e) {
            ServiceContainer::getLogger()->error($e->getMessage(), __METHOD__, __LINE__);
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
    public static function checkUserComplete($scope)
    {
        $userCompleted = false;
        try {
            ServiceContainer::getLogger()->info('Checking if the user has filled its data out for this section:' . $scope, __METHOD__, __LINE__);

            if (self::isConnected()) {
                $userCompleted = OAuth::doCheckUserCompleted(OAuthConfig::getApiUrl('api.user', 'base_url') . OauthConfig::getApiUrl('api.user', 'user'), $scope);
            }
        } catch (Exception $e) {
            ServiceContainer::getLogger()->error($e->getMessage(), __METHOD__, __LINE__);
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
    public static function checkUserNeedAcceptTerms($scope)
    {
        $status = false;
        try {
            ServiceContainer::getLogger()->info('Checking if the user has accepted terms and conditions for this section:' . $scope, __METHOD__, __LINE__);

            if (self::isConnected()) {
                $status = OAuth::doCheckUserNeedAcceptTerms(OAuthConfig::getApiUrl('api.user', 'base_url') . OauthConfig::getApiUrl('api.user', 'user'), $scope);
            }
        } catch (Exception $e) {
            ServiceContainer::getLogger()->error($e->getMessage(), __METHOD__, __LINE__);
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
     * @throws Exception
     */
    public static function logoutUser()
    {
        try {
            if ((self::$gid_things->getAccessToken() != null) && (self::$gid_things->getRefreshToken() != null)) {
                ServiceContainer::getLogger()->info('User Single Sign Logout', __METHOD__, __LINE__);
                UserApi::deleteCacheUser(self::$gid_things->getLoginStatus()->getCkUsid());

                OAuth::doLogout(OauthConfig::getEndpointUrl('logout_endpoint'));
                self::clearLocalSessionData();
            }
        } catch (Exception $e) {
            ServiceContainer::getLogger()->error($e->getMessage(), __METHOD__, __LINE__);
        }
    }

    /**
     * Returns a clientToken if user is not logged, and accessToken if user is logged
     *
     * @return mixed An instance of {@link AccessToken} or
     *     {@link ClientToken}
     * @throws \Exception if we have not a valid Token
     */
    private static function getTokenUser()
    {
        try {
            if (!is_null(self::$gid_things->getAccessToken())) {
                ServiceContainer::getLogger()->debug('Get AccessToken, user logged', __METHOD__, __LINE__);
                return self::$gid_things->getAccessToken();
            } else {
                ServiceContainer::getLogger()->debug('Get ClientToken, user is NOT logged', __METHOD__, __LINE__);
                return self::$gid_things->getClientToken();
            }
        } catch (Exception $e) {
            ServiceContainer::getLogger()->error($e->getMessage(), __METHOD__, __LINE__);
            throw new Exception('Not valid token');
        }
    }

    /**
     * Helper to access an static instance of Logger
     *
     * @return \Logger
     * @deprecated
     */
    public static function getLogger()
    {
        return ServiceContainer::getLogger();
    }

    /**
     * Update the user's "access_token" from persistent data (SESSION or
     * COOKIE)
     *
     * @return void
     */
    private static function loadUserTokenFromPersistence ()
    {
        try {
            if (is_null(self::$gid_things->getAccessToken())){
                ServiceContainer::getLogger()->debug('Load access token from cookie', __METHOD__, __LINE__);

                if (OAuth::hasToken(TokenTypesCollection::ACCESS_TOKEN)) {
                    self::$gid_things->setAccessToken(OAuth::getStoredToken(TokenTypesCollection::ACCESS_TOKEN));
                }
                if (OAuth::hasToken(TokenTypesCollection::REFRESH_TOKEN)) {
                    self::$gid_things->setRefreshToken(OAuth::getStoredToken(TokenTypesCollection::REFRESH_TOKEN));
                }
            }


        } catch (Exception $e) {
            ServiceContainer::getLogger()->error('['.__CLASS__.']['.__FUNCTION__.']['.__LINE__.']'.$e->getMessage(), __METHOD__, __LINE__);
        }
    }
}