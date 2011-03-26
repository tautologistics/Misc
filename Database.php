<?php
require_once("Config.php");
require_once("Logging.php");

class Database {
	private static $instances = array();

	private static $debug_global = false;
	private $debug_local = false;

	private $server;
	private $schema;
	private $login;
	private $password;
	private $connection;

	public function __construct ($server, $schema, $login, $password) {
		$this->server		= $server;
		$this->schema		= $schema;
		$this->login		= $login;
		$this->password		= $password;

		$this->Connect();
	}

	public function __destruct () {
		$this->Close();
	}

	public function SetDebug ($value) {
		if (isset($this)) {
			$this->debug_local = $value;
		}
		else {
			self::$debug_global = $value;
		}
	}

	public static function GetInstance ($server = null, $schema = null, $login = null, $password = null) {
		$config = Config::GetInstance();
		if ($server === null)
			$server = $config->getVal(CONFIG_DATABASE, 'server');
		if ($schema === null)
			$schema = $config->getVal(CONFIG_DATABASE, 'schema');
		if ($login === null)
			$login = $config->getVal(CONFIG_DATABASE, 'login');
		if ($password === null)
			$password = $config->getVal(CONFIG_DATABASE, 'password');

		$key = '|'.$server.'|'.$schema.'|'.$login.'|'.$password.'|';
		if (!isset(self::$instances[$key])) {
			$className = __CLASS__;
			self::$instances[$key] = new $className($server, $schema, $login, $password);
		}
		return(self::$instances[$key]);
	}

	public static function Instances () {
		return(array_keys(self::$instances));
	}

	public function Escape ($value) {
		return(mysql_real_escape_string($value));
	}

	public function Quote ($value) {
		return("'".$this->Escape($value)."'");
	}

	public function SmartQuote ($value) {
		return(is_numeric($value) ?
			$this->Escape($value)
			:
			"'".$this->Escape($value)."'"
			);
	}

	public function QuoteMap ($value) {
		$result = "";
		if (is_array($value)) {
			$escaped = array();
			foreach ($value as $entry) {
				$escaped[] = $this->SmartQuote($entry);
			}
			$result = implode(', ', $escaped);
		}
		else {
			$result = $this->Escape($value);
		}
		return($result);
	}

	public function Connect() {
		$this->connection = @mysql_connect($this->server, $this->login, $this->password);
		if ($this->connection === false) {
			Logging::GetInstance()->Log("Connection to DB server failed (" . mysql_error() . ")", PEAR_LOG_CRIT);
			return(false);
		}
		if (!@mysql_select_db($this->schema, $this->connection)) {
			Logging::GetInstance()->Log(
				"Opening of DB schema failed (" . $this->schema . ", #" . @mysql_errno($this->connection) . ": " . @mysql_error($this->connection) . ")",
				PEAR_LOG_CRIT
				);
			return(false);
		}
		return(true);
	}

	public function Close () {
		mysql_close($this->connection);
	}

	public function Prepare ($query) {
		return(new DatabaseQuery($query, $this));
	}

	public function ParseQuery () {
		$params = func_get_args();
		$query = array_shift($params);

		$escapedParams = array();
		foreach ($params as $param) {
			$escapedParams[] = $this->QuoteMap($param);
		}
		array_unshift($escapedParams, $query);
		return($this->Query(call_user_func_array("sprintf", $escapedParams)));
	}

	public function Query ($query) {
		if (self::$debug_global || $this->debug_local) {
			Logging::GetInstance()->Log("Database::Query(): ".$query, PEAR_LOG_DEBUG);
		}
		$result = mysql_query($query, $this->connection);
		if ($result === false) {
			Logging::GetInstance()->Log("Problem encountered while executing the query. (" . $query . ")", PEAR_LOG_ERR);
			return(false);
		}

		return(new DatabaseResult($result, $this->LastInsertID()));
	}

	public function ReturnedRows ($result) {
		return(mysql_num_rows($result));
	}

	public function AffectedRows ($result) {
		return(mysql_affected_rows($result));
	}

	public function GetRow (&$result) {
		return(mysql_fetch_array($result));
	}

	public function GetAllRows (&$result) {
		$rows = array();

		if ($this->ReturnedRows($result) > 0) {
			while ($row = $this->GetRow($result)) {
				$rows[] = $row;
			}
		}
		
		return($rows);
	}

	public function LastInsertID () {
		return(mysql_insert_id($this->connection));
	}

}

class DatabaseQuery {
	private $query;
	private $connection;

	public function __construct ($query, Database $connection) {
		$this->query = $query;
		$this->connection = $connection;
	}

	public function Exec () {
		$params = func_get_args();
		array_unshift($params, $this->query);
		return(call_user_func_array(array($this->connection, 'ParseQuery'), $params));
	}

}

class DatabaseResult {
	private $result;
	private $lastId;
	private $numRows = null;
	private $affectedRows = null;

	public function __construct ($result, $lastId = -1) {
		$this->result = $result;
		$this->lastId = $lastId;
	}

	public function ReturnedRows () {
		if ($this->numRows == null) {
			$this->numRows = Database::ReturnedRows($this->result);
		}
		return($this->numRows);
	}

	public function AffectedRows () {
		if ($this->affectedRows == null) {
			$this->affectedRows = Database::AffectedRows($this->result);
		}
		return($this->affectedRows);
	}

	public function GetRow () {
		return(Database::GetRow($this->result));
	}

	public function GetAllRows () {
		return(Database::GetAllRows($this->result));
	}

	public function LastInsertID () {
		return($this->lastId);
	}

}
?>