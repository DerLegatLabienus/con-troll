<?php

class Controller_Entities_Tickets extends Api_Rest_Controller {
	
	public function create() {
		$data = $this->input();
		$usePasses = $this->convention->usePasses();
		
		Logger::debug("Loading timeslot '".$data->timeslot."'for ticket creation");
		$timeslot = new Model_Timeslot($data->timeslot);
		if (!$timeslot->loaded())
			throw new Api_Exception_InvalidInput($this, "No time slot specified for the sell");

		if ($usePasses) {
			if (!$data->user_passes)
				throw new Api_Exception_InvalidInput($this, "Convention uses passes, and no 'user_passes' provided");
			$passes = array_map(function($id) use ($timeslot) {
				$pass = new Model_User_Pass($id);
				if (!$pass->loaded() || $pass->user != $this->getValidUser() || !$pass->isValid())
					throw new Api_Exception_InvalidInput($this, "Invalid user pass '$id' provided");
				if (!$pass->availableDuring($timeslot->start_time, $timeslot->end_time))
					throw new Api_Exception_InvalidInput($this, "Pass '$id' is not available for timeslot '{$timeslot->id}'", [ 'conflict' => $id ]);
				return $pass;
			}, is_array($data->user_passes) ? $data->user_passes : [ $data->user_passes ]);
			
			$amount = count($passes);
			if ($timeslot->available_tickets < $amount)
				throw new Api_Exception_InvalidInput($this, "Not enough tickets left");
			
			// start the reservation
			Database::instance()->begin();
			$tickets = array_map(function($pass) use ($timeslot) {
				return Model_Ticket::forPass($timeslot, $pass);
			}, $passes);
			if ($timeslot->available_tickets < 0) { // someone took our tickets first
				Database::instance()->rollback();
				throw new Api_Exception_InvalidInput($this, "Not enough tickets left");
			}
			Database::instance()->commit();
			
			// verify sanity after I finish the transaction
			if ($timeslot->available_tickets < 0) {
				Logger::error("Not enough available tickets after commit - this shouldn't happen");
				foreach ($tickets as $ticket)
					$ticket->delete();
				throw new Api_Exception_InvalidInput($this, "Not enough tickets left");
			}
			
			return [ 'tickets' => ORM::result_for_json($tickets) ];
		}
		
		$amount = $data->amount ?: 1;
		if ($timeslot->available_tickets < $amount)
			throw new Api_Exception_Duplicate($this, "Not enough tickets left");
		
		// start the reservation
		Database::instance()->begin();
		$ticket = Model_Ticket::persist($timeslot, $this->getValidUser());
		if ($timeslot->available_tickets < 0) { // someone took our tickets first
			Database::instance()->rollback();
			throw new Api_Exception_InvalidInput($this, "Not enough tickets left");
		}
		Database::instance()->commit();
		
		// verify sanity after I finish the transaction
		if ($timeslot->available_tickets < 0) {
			$ticket->delete();
			throw new Api_Exception_InvalidInput($this, "Not enough tickets left");
		}
		
		return $ticket->for_json_with_coupons();
	}
	
	public function retrieve($id) {
		$ticket = new Model_Ticket($id);
		if ($ticket->loaded() && ($ticket->user == $this->getValidUser() || $this->systemAccessAllowed()))
			return $ticket->for_json_with_coupons();
		throw new Api_Exception_InvalidInput($this, "No valid tickets found to display");
	}
	
	public function update($id) {
		// allow user to update the amount of tickets they want to buy, if there are tickets available
		$amount = $this->input()->amount;
		if (!is_numeric($amount))
			throw new Api_Exception_InvalidInput($this, "Amount must be a numerical value");
		$ticket = new Model_Ticket($id);
		if (!$ticket->loaded())
			throw new Api_Exception_InvalidInput($this, "No ticket found");
		if (!$this->systemAccessAllowed() and $ticket->user != $this->getValidUser())
			throw new Api_Exception_InvalidInput($this, "No ticket found");
		if ($ticket->isAuthorized() or $ticket->isCancelled())
			throw new Api_Exception_InvalidInput($this, "Cannot update a ticket that has been payed for or cancelled");
		if ($amount - $ticket->amount > $ticket->timeslot->available_tickets)
			throw new Api_Exception_InvalidInput($this, "No more tickets left");
		Database::instance()->begin();
		$ticket->setAmount($amount);
		
		if ($ticket->amount <= 0) { // cancel the ticket
			$ticket->cancel("user-deleted");
			Database::instance()->commit();
			return $ticket->for_json_with_coupons();
		}

		$ticket->save();
		// check if we're still OK
		if ($ticket->timeslot->available_tickets < 0) {
			// cancel the transaction
			Database::instance()->rollback();
			throw new Api_Exception_InvalidInput($this, "No more tickets left");
		}
		
		Database::instance()->commit();
		return $ticket->for_json_with_coupons();
	}
	
	public function delete($id) {
		$usePasses = $this->convention->usePasses();
		// when tickets are bought, only system access can cancel them, while users with passes can cancel at any time
		if (!$this->systemAccessAllowed() && !$usePasses)
			throw new Api_Exception_Unauthorized($this, "Not authorized to delete tickets");
		$ticket = new Model_Ticket((int)$id);
		if (!$ticket->loaded())
			throw new Api_Exception_InvalidInput($this, "No ticket found for '$id'");
		$data = $this->input();
		if ($data->delete) {
			if ($ticket->isAuthorized())
				throw new Api_Exception_InvalidInput($this, "Can't delete authorized tickets, cancel it first");
			$ticket->returnCoupons();
			$ticket->delete();
		} else { // caller doesn't really want to delete, try to cancel or refund
			$reason = $data->reason ?: "User " . $this->user . " cancelled";
			if ($usePasses)
				$ticket->cancel($reason);
			elseif ($ticket->isAuthorized()) {
				$refundType = new Model_Coupon_Type($data->refund_coupon_type);
				Logger::debug("Starting refunding {$ticket} by {$this->user} using {$refundType}");
				if ($ticket->price > 0 and !$refundType->loaded())
					throw new Api_Exception_InvalidInput($this, "Ticket already authorized, and no \"refund-coupon-type\" specified");
				$ticket->refund($refundType, $reason);
			} else {
				$ticket->cancel($reason);
			}
		}
		return true;
	}
	
	public function catalog() {
		$data = $this->input();
		// two different base modes - user and admin/convention
		$filters = [];
		if ($data->all and $this->systemAccessAllowed()) {
			// ehmm.. no default filters, unless the caller asked for a user filter
			if ($data->by_user) {
				if (is_numeric($data->by_user))
					$filters['ticket.user_id'] = $data->by_user;
				else
					$filters['email'] = $data->by_user;
			}
		} else {
			// verify user and filter by them
			$filters['ticket.user_id'] = $this->getValidUser()->pk();
		}
		
		if ($data->by_event)
			$filters['event_id'] = $data->by_event;
		if ($data->by_timeslot)
			$filters['timeslot_id'] = $data->by_timeslot;
		if ($data->is_valid)
			$filters['valid'] = 1;
		return ORM::result_for_json($this->convention->getTickets($filters), 'for_json_with_coupons');
	}
	
	private function getValidUser() {
		if ($this->systemAccessAllowed() and $this->input()->user)
			return $this->loadUserByIdOrEmail($this->input()->user);
		if ($this->user) // user authenticated themselves - fine
			return $this->user;
		throw new Api_Exception_InvalidInput($this, "User must be authenticated or specified by an authorized convention");
	}
	
}
