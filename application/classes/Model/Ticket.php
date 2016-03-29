<?php

class Model_Ticket extends ORM {
	
	const STATUS_RESERVED = 'reserved';
	const sTATUS_PROCESSING = 'processing';
	const STATUS_AUTHORIZED = 'authorized';
	const STATUS_CANCELLED = 'cancelled';
	
	protected $_belongs_to = [
			'user' => [],
			'timeslot' => [],
			'sale' => [],
	];
	
	protected $_columns = [
			'id' => [],
			// foreign keys
			'user_id' => [],
			'timeslot_id' => [],
			'sale_id' => [],
			// data fields
			'amount' => [],
			'status' => [ 'type' => 'enum', 'values' => [ 'reserved', 'processing', 'authorized', 'cancelled' ]],
	];
	
	public static function persist(Model_Timeslot $timeslot, Model_User $user, int $amount = 1) : Model_Ticket {
		$o = new Model_Ticket();
		$o->user = $user;
		$o->timeslot = $timeslot;
		$o->status = self::STATUS_RESERVED;
		$p->amount = $amount;
		return $o->save();
	}
	
	/**
	 * Count the number of tickets locked (reserved, in process or sold) for a time slot
	 * @param Model_Timeslot $timeslot
	 * @return int number of tickets
	 */
	public static function countForTimeslot(Model_Timeslot $timeslot) : int {
		// no caching, always return all tickets as of now
		return DB::select([DB::expr('SUM(`amount`)'), 'total_tickets'])->
				from((new Model_Ticket())->table_name())->
				where('timeslot_id', '=', $this->pk())->
				where('status','<>', self::STATUS_CANCELLED)->
				execute()->get('total_tickets');
	}
	
};
