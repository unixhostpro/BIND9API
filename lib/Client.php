<?php
namespace UnixHost\DNS;

class Client {

	protected $url = false;
	protected $user = false;
	protected $chuser = false;
	protected $token = false;
	protected $ssl_verify_peer = false;
	protected $ssl_verify_host = false;
	private $errors = [];
	private $response_code = false;
	
	public function setCredentials($url, $user, $token, $ssl_verify_peer = false, $ssl_verify_host = false) {
		$this->url = $url;
		$this->user = $user;
		$this->token = $token;
		$this->ssl_verify_peer = $ssl_verify_peer;
		$this->ssl_verify_host = $ssl_verify_host;
	}
	
	public function changeUser(string $username) {
		$this->chuser = $username;	
	}
	
	public function get(string $path, array $params = []) {
		return $this->query('GET', $path . '?' . http_build_query($params));
	}
	
	public function put(string $path, array $data) {
		return $this->query('PUT', $path, $data);
	}	

	public function post(string $path, array $data) {
		return $this->query('POST', $path, $data);
	}	
		
	public function delete(string $path) {
		return $this->query('DELETE', $path);
	}
	
	public function getErrors() {
		return $this->errors;
	}
	
	public function getResponseCode() {
		return $this->response_code;
	}
	
	private function query(string $method, string $path, array $data = []) {
		$path = ($path[0] != '/') ? '/' . $path : $path;
		$headers = ['DNS-API-USER: ' . $this->user, 'DNS-API-TOKEN: ' . $this->token];
		if ($this->chuser) {
			$headers[] = 'DNS-API-CHUSER: ' . $this->chuser;
		}
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->url  . $path);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		if (is_array($data)) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
		}
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->ssl_verify_peer);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->ssl_verify_host);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		$result = curl_exec($ch);
		$response = curl_getinfo($ch);
		curl_close ($ch);
		$this->response_code = $response['http_code'];
		$array = json_decode($result, true);
		if ($response['http_code'] == 200) {
			return $array;
		}
		if (isset($array['errors'])) {
			$this->errors = $array['errors'];
		}
		return false;
	}
}
