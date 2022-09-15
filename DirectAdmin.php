<?php

namespace App\Http\Controllers;

class DirectAdmin extends Controller
{
    /* Adding a pointer to the domain. */
    static function AddPointer($pointer){
        $DA_Domain = env('DIRECTADMIN_DOMAIN');
        $DA_Pointer = $pointer;
        $da = new DirectAdminAPI(env('DIRECTADMIN_HOST',null), env('DIRECTADMIN_USERNAME',null), env('DIRECTADMIN_PASSWORD',null));
        $result = $da->query("CMD_API_DOMAIN_POINTER?domain=".$DA_Domain,
            array("domain" => $DA_Domain,"action" => "add","from" => $DA_Pointer,"alias" => "yes"), "POST");
        return $result;
    }


    /* Deleting the pointer. */
    static function DeletePointer($pointer){
		$DA_Domain = env('DIRECTADMIN_DOMAIN');
        $da = new DirectAdminAPI(env('DIRECTADMIN_HOST',null), env('DIRECTADMIN_USERNAME',null), env('DIRECTADMIN_PASSWORD',null));
        $result = $da->query("CMD_API_DOMAIN_POINTER?domain=".$DA_Domain,
            array("domain" => $DA_Domain,"action" => "delete","select0" => $pointer,"alias" => "yes"), "POST");
        return $result;
    }
}

class DirectAdminAPI {
	public $handle;

	public $list_result = true;

	public $host = "";
	public $username = "";
	public $password = "";
	public $login_as = false;

	public $login = false;
	public $error = false;

	/**
     * > This function creates a new instance of the class, and connects to the server
     *
     * @param host The hostname of the server you want to connect to.
     * @param username The username to use when logging in to the API.
     * @param password The password for the user you're logging in as.
     * @param ssl If true, the connection will be made over SSL. If false, it will be made over HTTP.
     */
    public function __construct($host = null, $username = null, $password = null, $ssl = true) {
		$this->handle = curl_init();
		curl_setopt_array($this->handle, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => $ssl,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 60
		));
		if ($ssl && file_exists(__DIR__.env('DIRECTADMIN_CACERT'))) {
			curl_setopt($this->handle, CURLOPT_CAINFO, realpath(__DIR__."/cacert.pem"));
		}
		$this->connect($host)->login($username, $password);
	}

	/**
     * It sets the authorization header for the request
     *
     * @param auth The username and password to use for HTTP Basic Authentication.
     *
     * @return The object itself.
     */
    private function set_auth($auth) {
		$header = $auth ? array("Authorization: Basic ".base64_encode($auth)) : array();
		curl_setopt($this->handle, CURLOPT_HTTPHEADER, $header);
		return $this;
	}

	/**
     * It connects to the host.
     *
     * @param host The URL of the server you want to connect to.
     *
     * @return The object itself.
     */
    public function connect($host) {
		$this->host = rtrim(strval($host), "/");
		$this->login = $this->host == "" || $this->username == "" || $this->password == "" ? false : true;
		return $this;
	}

	/**
     * It sets the username, password, and login_as to false. It then sets the login to true if the host,
     * username, and password are not empty. It then sets the auth to the username and password.
     *
     * @param username The username to login with.
     * @param password The password for the user.
     *
     * @return The object itself.
     */
    public function login($username, $password) {
		$this->username = strval($username);
		$this->password = strval($password);
		$this->login_as = false;
		$this->login = $this->host == "" || $this->username == "" || $this->password == "" ? false : true;
		$this->set_auth($this->username.":".$this->password);
		return $this;
	}

	/**
     * It allows you to login as another user
     *
     * @param username The username to use for authentication.
     *
     * @return The object itself.
     */
    public function login_as($username) {
		$this->login_as = strval($username);
		$this->set_auth($this->username."|".$this->login_as.":".$this->password);
		return $this;
	}

	/**
     * It logs out the user, and if the user is logged in as another user, it logs the user back in as the
     * original user
     *
     * @param all If true, will logout of the current user and the user that was logged in as.
     *
     * @return The object itself.
     */
    public function logout($all = false) {
		if ($all || !$this->login_as) {
			$this->username = "";
			$this->password = "";
			$this->login_as = false;
			$this->login = false;
			$this->set_auth(false);
		} else {
			$this->login($this->username, $this->password);
		}
		return $this;
	}

	/**
     * It takes a command, a form, and a method, and returns the response from the server
     *
     * @param command The command to execute.
     * @param form The form data to be sent to the server. This can be an array or a string. If it's an
     * array, it will be converted to a string using PHP's http_build_query function.
     * @param method The HTTP method to use.
     *
     * @return The response from the server.
     */
    public function query($command, $form = null, $method = "GET") {
		if ($this->host == "" || $this->username == "" || $this->password == "") {
			$this->login = false;
			$this->error = true;
			return false;
		}
		$command = ltrim($command, "/");
		$form = is_array($form) ? http_build_query($form) : (is_string($form) ? $form : null);
		curl_setopt_array($this->handle, array(
			CURLOPT_URL => $this->host."/".$command.($method === "GET" && is_string($form) ? "?".$form : ""),
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_POSTFIELDS => $method !== "GET" ? $form : null
		));
		$response = curl_exec($this->handle);
		if (curl_errno($this->handle) === 0) {
			return $this->parse($response, false, $command);
		} else {
			$this->error = true;
			return $response;
		}
	}

	/**
     * It parses the response from the server and returns an array of the response
     *
     * @param response The response from the server.
     * @param force If true, the response will be parsed as if it were an API response. If false, the
     * response will be parsed as if it were a non-API response.
     * @param command The command to execute.
     *
     * @return The response from the server is being returned.
     */
    public function parse($response, $force = true, $command = "CMD_API_") {
		if ($force || substr($command, 0, 8) === "CMD_API_") {
			if (!$force && stripos($response, "<html>") !== false) {
				$this->error = true;
				return $response;
			}
			parse_str($response, $array);
			if (!isset($array["error"]) || $array["error"] === "0") {
				$this->error = false;
				if ($this->list_result && !isset($array["error"]) && isset($array["list"])) {
					$array = $array["list"];
				}
			} else {
				$this->error = true;
			}
			return $array;
		} else {
			$this->error = null;
			return $response;
		}
	}
}
