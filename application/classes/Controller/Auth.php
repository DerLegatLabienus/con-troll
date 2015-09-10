<?php
class Controller_Auth extends Api_Controller {

	public function action_verify() {
		$data = json_decode($this->request->body());
		if ($data ['token'] && $this->is_valid($data ['token']))
			$this->send([ 
					"status" => true 
			]);
		else
			$this->send([ 
					"status" => false 
			]);
	}

	public function action_start() {
		$data = json_decode($this->request->body()) ?  : [ ];
		$this->send([ 
				"auth-url" => Auth::getProvider(@$data ['provider'] ?  : 'google', 
						strtolower($this->action_url('callback', true)))->getAuthenticationURL()
		]);
	}

	public function action_callback() {
		// google response parameters: state, code, authuser, prompt, session_state
		try {
			$provider = Auth::getLastProvider();
			$provider->complete($this->request->query('code'), $this->request->query('state'));
			$o = Model_User::persist($provider->getName(), $provider->getEmail(), $provider->getProviderName()); 
			$this->send(['status' => true, 'object' => $o ]);
		} catch (Exception $e) {
			$this->send(['status' => false]);
		}
	}

	private function is_valid($token) {
		return false;
	}

}
