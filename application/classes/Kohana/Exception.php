<?php

class Kohana_Exception extends Kohana_Kohana_Exception {
	
	public static function handler($e)
	{
		Logger::fatal("Error: $e");
		if ($e instanceof Exception)
			return Kohana_Kohana_Exception::handler($e);
		elseif ($e instanceof Error)
			return Kohana_Kohana_Exception::handler(new ErrorException($e->getMessage(), $e->getCode(), 1, $e->getFile(), $e->getLine(), $e->getPrevious()));
		else {
			var_dump($e);
			return Kohana_Kohana_Exception::handler(new Exception("Invalid type sent to handler(): '".get_class($e)."'"));
		}
	}
	
	public static function log($e, $level = \Psr\Log\LogLevel::EMERGENCY) {
		Rollbar::report_exception($e);
		return Kohana_Kohana_Exception::log($e, $level);
	}
}
