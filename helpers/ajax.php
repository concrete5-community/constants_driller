<? defined('C5_EXECUTE') or die('Access Denied.');

class AjaxHelper {

	/** Sends a result to the client and ends the execution.
	* @param mixed $result
	*/
	public function sendResult($result) {
		@ob_end_clean();
		header('Content-Type: application/json; charset=' . APP_CHARSET, true);
		echo Loader::helper('json')->encode($result);
		die();
	}
	
	/** Sends an error the client and ends the execution.
	* @param string|Exception $result The error to send to the client.
	*/
	public function sendError($error) {
		@ob_end_clean();
		header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
		header('Content-Type: text/plain; charset=' . APP_CHARSET, true);
		echo ($error instanceof Exception) ? $error->getMessage() : $error;
		die();
	}

}