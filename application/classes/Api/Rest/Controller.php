<?php

abstract class Api_Rest_Controller extends Api_Controller {
	
	protected $convention = null;
	protected $user = null;
	
	public function action_index() {
		$this->convention = $this->verifyConventionKey();
		try {
			$this->user = $this->verifyAuthentication()->user;
		} catch (Api_Exception_Unauthorized $e) {
			// some APIs allow no user auth
		}
		
		switch ($this->request->method()) {
			case 'POST':
				$obj = $this->create();
				if (is_null($obj))
					$this->send([ 'status' => false ]);
				else
					$this->send([ 'status' => true, 'id' => $obj->pk() ]);
				return;
			case 'GET':
				if ($this->request->param('id')) {
					$this->send(
						$this->retrieve($this->request->param('id'))
					);
				} else {
					$this->send($this->catalog());
				}
				return;
			case 'PUT':
				$this->send([
					'status' => $this->update($this->request->param('id'))
				]);
				return;
			case 'DELETE':
				$this->send([
					'status' => $this->delete($this->request->param('id'))
				]);
				return;
			default:
				throw new Exception("Invalid operation {$this->request->method()}");
		}
	}
	
	/**
	 * Create a new record
	 * @param stdClass $data Data to create the record
	 * @return ORM Model object created
	 */
	abstract protected function create();
	
	/**
	 * Retrieve an existing record by ID
	 * @param int $id record ID
	 * @return stdClass Record data
	 */	
	abstract protected function retrieve($id);
	
	/**
	 * Update an existing record
	 * @param int $id record ID
	 * @param stdClass $data Data to update the record
	 * @return boolean Whether the create succeeded
	 */
	abstract protected function update($id);
	
	/**
	 * Delete an existing record
	 * @param int $id record ID
	 * @return boolean Whether the delete succeeded
	 */
	abstract protected function delete($id);
	
	/**
	 * Retrieve the catalog of entities
	 * @return array
	 */
	abstract protected function catalog();
}
