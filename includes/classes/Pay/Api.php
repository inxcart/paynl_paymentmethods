<?php
/**
 * The Pay_Api baseclass
 * All api functions extend this class
 */
class Pay_Api {

    const REQUEST_TYPE_POST = 1;
    const REQUEST_TYPE_GET = 0;

    /**
     *
     * @var string the url to the pay.nl api
     */
    protected $_apiUrl = 'http://rest-api.pay.nl';
    /**
     *
     * @var string The version af the api to use
     */
    protected $_version = 'v3';
    /**
     *
     * @var string The controller of the api to use, generally this is set by the child class
     */
    protected $_controller = '';
    /**
     *
     * @var string The action of the api to use, generally this is set by the child class
     */
    protected $_action = '';
    
    /**
     *
     * @var string The serviceid
     */
    protected $_serviceId = '';
    /**
     *
     * @var string the API token
     */
    protected $_apiToken = '';
    
    /**
     *
     * @var int The request type (POST or GET) to use when calling the api. use Pay_Api::REQUEST_TYPE_POST or  Pay_Api::REQUEST_TYPE_GET for this variable
     */
    protected $_requestType = self::REQUEST_TYPE_POST;
    
    /**
     *
     * @var array The data to post to the pay.nl server
     */
    protected $_postData = [];

    
    /**
     * Set the serviceid
     * The serviceid always starts with SL- and can be found on: https://admin.pay.nl/programs/programs
     * 
     * @param string $serviceId
     */
    public function setServiceId($serviceId) {
        $this->_serviceId = $serviceId;
    }

    /**
     * Set the API token
     * The API token is used to identify your company.
     * The API token can be found on: https://admin.pay.nl/my_merchant on the bottom
     * 
     * @param string $apiToken
     */
    public function setApiToken($apiToken) {
        $this->_apiToken = $apiToken;
    }

    protected function _getPostData() {

        return $this->_postData;
    }

    protected function _processResult($data) {
        return $data;
    }

    /**
     * Generates the api url
     * 
     * @return string The full url to the api
     * @throws Pay_Exception
     */
    private function _getApiUrl() {
        if ($this->_version == '') {
            throw new Pay_Exception('version not set', 1);
        }
        if ($this->_controller == '') {
            throw new Pay_Exception('controller not set', 1);
        }
        if ($this->_action == '') {
            throw new Pay_Exception('action not set', 1);
        }

        return $this->_apiUrl . '/' . $this->_version . '/' . $this->_controller . '/' . $this->_action . '/json/';
    }

    /**
     * Get the data to post to the api for debug use
     * 
     * @return array The post data
     */
    public function getPostData(){
        return $this->_getPostData();
    }
    /**
     * Do the request and get the result
     * 
     * @return array The result
     * @throws Pay_Exception On error generated before sending
     * @throws Pay_Api_Exception On error returned by the pay.nl api
     */
    public function doRequest() {
        if ($this->_getPostData()) {

            $url = $this->_getApiUrl();
            $data = $this->_getPostData();

            $strData = http_build_query($data);

            $apiUrl = $url;

            $ch = curl_init();
            if ($this->_requestType == self::REQUEST_TYPE_GET) {
                $apiUrl .= '?' . $strData;
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $strData);
            }
           
          
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $result = curl_exec($ch);


            if ($result == false) {
                $error = curl_error($ch);
            }
            curl_close($ch);

            $arrResult = json_decode($result, true);

            if ($this->validateResult($arrResult)) {
                return $this->_processResult($arrResult);
            }
        }
    }

    /**
     * Validate the result and throw an exception when there is an error
     * 
     * @param array $arrResult The result
     * @return boolean Result valid
     * @throws Pay_Api_Exception
     */
    protected function validateResult($arrResult) {
        if ($arrResult['request']['result'] == 1) {
            return true;
        } else {
            if(isset($arrResult['request']['errorId']) && isset($arrResult['request']['errorMessage']) ){
                throw new Pay_Api_Exception($arrResult['request']['errorId'] . ' - ' . $arrResult['request']['errorMessage']);
            } elseif(isset($arrResult['error'])){
                throw new Pay_Api_Exception($arrResult['error']);
            } else {   
                throw new Pay_Api_Exception('Unexpected api result');
            }
        }
    }
}
