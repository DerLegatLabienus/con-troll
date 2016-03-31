<?php

/**
 * Pelepay payment processor adapter
 * See: https://pelepay.co.il/Pages/API/Developers.aspx for the documentation of the "API"
 *
 * @author odeda
 */
class Payment_Processor_Pelepay extends Payment_Processor {
	
	public function createTransactionHTML(Model_Sale $sale, $okurl, $failurl) {
		$callback_data = [ 'ok' => $okurl, 'fail' => $failurl ];
		
		$sale->processor_data = $callback_data;
		$sale->save();
		$view = Twig::factory('payment/pelepay-form');
		$view->config = $this->config;
		$view->price = $sale->getTotal();
		$view->orderid = $sale->pk();
		// the description field is displayed as is to the user, and then sent back in the query string
		// without encoding, so be careful what you put there
		$view->description = "עסקה-" . $sale->pk() . " בשביל " . $sale->convention->title;
		$view->onsuccess = $this->generateCallbackURL(['status' => 'success' ]);
		$view->onfail = $this->generateCallbackURL(['status' => 'fail' ]);
		$view->oncancel = $this->generateCallbackURL(['status' => 'cancel' ]);
		$view->onb2bcomplete = $this->generateCallbackURL(['status' => 'b2b' ]);
		
		// pre-fill user data in pelepay
		$view->additional_fields = [];
		if ($sale->user->name) {
			list($first, $last) = @explode(" ", $sale->user->name, 2);
			$view->additional_fields["firstname"] = $first;
			$view->additional_fields["lastname"] = $last;
		}
		if ($sale->user->phone)
			$view->additional_fields["phone"] = $sale->user->phone;
		if ($sale->user->email)
			$view->additional_fields["email"] = $sale->user->email;
		
		return $view->render();
	}

	public function handleCallback(Input $request, $fields) {
		Logger::debug("Got pelepay callback: ". print_r($request,true));
		$sale = new Model_Sale($request->orderid);
		if (!$sale->loaded())
			throw new Exception("Failed to locate sale id ".$request->orderid);
		$sale_data = [
				'response' => $request->Response,
				'confirmation-code' => $request->ConfirmationCode,
				'index' => $request->index,
				'amount' => $request->amount,
				'firstname' => $request->firstname,
				'lastname' => $request->lastname,
				'email' => $request->email,
				'phone' => $request->phone,
				'payfor' => $request->payfor,
				'custom' => $request->custom,
				'orderid' => $request->orderid,
		];
		$callback_data = $sale->processor_data;
		$callback_data['pelepay-response'] = $sale_data;
		$sale->processor_data = $callback_data;
		switch ($fields['status']) {
			case 'success':
				$sale->authorized($request->index . ':' . $request->ConfirmationCode);
				return $callback_data['ok'];
			case 'fail':
				$sale->failed($request->Response);
				return $callback_data['fail'];
			case 'cancel':
				$sale->cancelled();
				return $callback_data['fail'];
			default:
				throw new Exception("Invalid status '{$fields['status']}'");
		}
	}
}