<?php

class DatenhubAPI {

    private static $instance = null;

    const MAX_RETRIES = 5;
    private $currentRetries = 0;

    // date format for exxcellent api. example: 2019-07-19T15:00:00Z
    private $dateFormat = 'Y-m-d\TH:i:s\Z';
    private $config;
    private $defaultOptions;
    private $apiBaseUrl = 'https://datenhub.ulm.de/api/datasets';
    private $tokenBaseUrl = 'https://datenhub.ulm.de/auth/realms/datenhubulm/protocol/openid-connect/token';
    private $defaultGrantType = 'password';

    private $username;
    private $password;

    private $log;

    public static function create(string $username, string $password, $logger): DatenhubAPI {
        if (self::$instance == null) {
            self::$instance = new self($username, $password, $logger);
        }
        return self::$instance;
    }

    public function __construct(string $username, string $password, $logger) {

        $this->username = $username;
        $this->password = $password;
        $this->log = $logger;

        // define a custom timeout of only one second. if the exxcellent API does not response within 1s, they probably never will.
        // we then use our last cached response.
        $this->defaultOptions = array(
            'timeout' => 10.0,
            'connect_timeout' => 10.0
        );
    }

    //**************************
    // Token handling
    //**************************

    /**
     * Refresh the API OAuth token.
     * @throws Exception
     */
    public function refreshOauthToken() {
        $this->log->debug('Refreshing API token...');

        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $data = [
            'grant_type' => $this->defaultGrantType,
            'client_id' => 'admin-cli',
            'username' => $this->username,
            'password' => $this->password,
        ];

        try {

            $response = Requests::post($this->tokenBaseUrl, $headers, $data); // raw json string for json in body. no form.

            if ($response->status_code == 200) {

                $tokenData = json_decode($response->body, true);
                $token = $tokenData['access_token'];
                $expiresIn = $tokenData['expires_in'];

                $this->log->debug('Got a new token: ' . substr($token, 0, 20) . '...');

                $now = new DateTime();
                $expiresAt = $now->add(new DateInterval('PT' . $expiresIn . 'S'))->format('Y-m-d H:i:s');
                $this->log->debug('This new token expires at ' . $expiresAt);

                // save API key into apcu cache
                apcu_store('token__' . $this->username, $token, 60 * 60 * 72);
                apcu_store('token_expires__' . $this->username, $expiresAt, 60 * 60 * 72);


            } else {
                $this->log->warn('Token API call failed: ' . $response->status_code);
                if ($response->body) $this->log->warn($response->body);
            }

        } catch (Requests_Exception $e) { // timeouts, etc.
            $this->log->error($e->getMessage());
        }
    }

    //**************************
    // Generic API call
    //**************************

    /**
     * Generic API call. Invokes the callback given as the parameter on error for retries.
     * The cacheKey defines where apcu saves this data.
     *
     * @param $url
     * @param $callback
     * @param $cacheKey
     * @param array $cbArgs Callback arguments
     * @param $transformFunc
     * @throws Exception
     */
    private function callAPI($url, $callback, $cacheKey, $cbArgs = array(), $transformFunc = []) {

        $token = $this->getAPIToken($callback, $cbArgs);

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json'
        ];

        try {
            $this->log->debug('Calling url: ' . $url);
            $response = Requests::get($url, $headers, $this->defaultOptions);

            if ($response->status_code == 200) {
                $this->log->debug('API call success: ' . $response->status_code);

                $jsonBody = json_decode($response->body, true);

                // do not cache empty result, handle them like an error
                if (empty($jsonBody)) {
                    $this->log->warn('Empty array returned from API. Using cached response.');
                    return $this->sendCachedResponseOrError($cacheKey, $transformFunc);
                }

                // saving the current state. this is reused later when the API has a timeout.
                apcu_store($cacheKey, $response->body, 60 * 60 * 72);  // ttl in seconds

                // if this API call was successfull, reset the current retries.
                $this->currentRetries = 0;

                // we can transform the data with this callback.
                // if a callback is given, it will transform the data and return
                // another, transformed, json object.
                if (is_callable($transformFunc)) {
                    $jsonBody = call_user_func($transformFunc, $jsonBody);
                }

                return $jsonBody;

            } else {

                if ($response->status_code == 401 || $response->status_code == 403) {
                    $this->log->debug('Auth failed. Probably the token expired. Refreshing and trying again...');

                    $this->currentRetries++; // limit number of retries;
                    if ($this->currentRetries <= self::MAX_RETRIES) {
                        $this->refreshOauthToken();
                        call_user_func([$this, $callback], $cbArgs); // call this API call function again
                        return;
                    }
                } else {
                    $this->log->warn($cacheKey . ': Status code ' . $response->status_code . ', sending cached response or error');
                    if ($response->body) {
                        $this->log->debug($response->body);
                    }
                    return $this->sendCachedResponseOrError($cacheKey, $transformFunc);
                }

            }

        } catch (Requests_Exception $e) {
            $this->log->warn('Timeout on API, sending last cached state...');
            return $this->sendCachedResponseOrError($cacheKey, $transformFunc);
        }

    }


    /**
     * Get the current API token from the DB or request a new one.
     *
     * @param $callback
     * @param array $cbArgs Callback Arguments
     * @return mixed
     * @throws Exception
     */
    private function getAPIToken($callback, $cbArgs = []) {

        // try to get the API token from apcu cache. give the key a unique name. a token is unique for each username.
        $token = apcu_fetch('token__' . $this->username);
        $this->log->debug('Token (from cache): ' . substr($token, 0, 20) . '...');
        if ($token) {
            return $token;
        } else {
            $this->log->debug('No token in cache found.');
            // if the token is empty, try to get a new one and call this function again.
            $this->refreshOauthToken();
            call_user_func(array($this, $callback), ...$cbArgs);
        }
    }

    /**
     * On error (API timeout, etc.), we return the latest cached response if available or an error
     *
     * @param string $cacheKey
     * @param array $transformFunc
     * @return array|null
     */
    private function sendCachedResponseOrError(string $cacheKey, array $transformFunc = []): ?array {
        // if we have a timeout (or another error), use the cached state.
        $rawBody = apcu_fetch($cacheKey);
        if ($rawBody) {
            $jsonBody = json_decode($rawBody, true);
            if (is_callable($transformFunc)) {
                $jsonBody = call_user_func($transformFunc, $jsonBody);
            }
            return $jsonBody;
        } else {
            $this->log->error('error and no cached state found');
            return null;
        }
    }

    /**
     * Helper function to generate the start/end parameter.
     *
     * Parameters for the last 24 hours, starting now.
     * Can be modified via the intervalString parameter
     *
     * @param array $parts
     * @param string $intervalString
     * @param string $startVar
     * @param string $endVar
     * @return array
     * @throws Exception
     */
    private function generateStartEndParamter(array $parts, string $intervalString = 'P1D', string $startVar = 'start', string $endVar = 'end'): array {
        $yesterday = (new DateTime())->sub(new DateInterval($intervalString))->format($this->dateFormat);
        $parts[] = $startVar . '=' . $yesterday;
        $parts[] = $endVar . '=' . (new DateTime())->format($this->dateFormat);
        return $parts;
    }

    /**
     * Helper function to generate the start/end parameter.
     *
     * Starts at midnight, counts till now.
     *
     * @param array $parts
     * @param string $startVar
     * @param string $endVar
     * @return array
     * @throws Exception
     */
    private function generateStartEndParamterForThisDay(array $parts, string $startVar = 'start', string $endVar = 'end'): array {
        $todayMidnight = (new DateTime())->setTime(0, 0);
        $parts[] = $startVar . '=' . $todayMidnight->format($this->dateFormat);
        $parts[] = $endVar . '=' . (new DateTime())->format($this->dateFormat);
        return $parts;
    }

    /**
     * Overwrite the default exxcellent API URL. No leading slash.
     *
     * @param string $url
     */
    public function setApiBaseUrl(string $url): void {
        $this->apiBaseUrl = $url;
    }

    /**
     * Overwrite the default token API URL. No leading slash.
     *
     * @param string $url
     */
    public function setTokenBaseUrl(string $url): void {
        $this->tokenBaseUrl = $url;
    }

    /**
     * Sets the grant type for the token call.
     *
     * @param string $grantType
     */
    public function setGrantType(string $grantType) {
        $this->defaultGrantType = $grantType;
    }

    /**
     * Call data from the API. Provide all needed arguments.
     *
     * Example: getData('/foo', 'wastelevel', ['id=1234'], 'P2D', ['WasteLevelTransform', 'transform'])
     *
     * @param string $urlPart The part after the base url. with leading slash. Required.
     * @param string $cacheKey The ACPU cache key. Required.
     * @param array $idArray Array of sensor IDs. Optional. Default empty array.
     * @param string $interval What time interval. Optional. Default: P2D (2 days back )
     * @param array $transformFuncArray PHP callable for Transform function. Optinal.
     * @throws Exception
     */
    public function getData(string $urlPart, string $cacheKey, array $idArray = [], string $interval = 'P2D', array $transformFuncArray = []) {
        $url = $this->apiBaseUrl . $urlPart;

        $parts = $idArray;
        $parts = $this->generateStartEndParamter($parts, $interval);
        $url .=  '?' . implode('&', $parts);

        return $this->callAPI($url, __FUNCTION__, $cacheKey, [$url, $cacheKey, $idArray, $interval, $transformFuncArray], $transformFuncArray);
    }
}