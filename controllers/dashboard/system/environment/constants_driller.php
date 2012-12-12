<?php defined('C5_EXECUTE') or die("Access Denied.");

class DashboardSystemEnvironmentConstantsDrillerController extends DashboardBaseController {

	public function on_start() {
		$this->addHeaderItem(<<<EOT
<style>
#ConstantDriller-list {
	table-layout:fixed;
}
#ConstantDriller-list tbody td.n, #ConstantDriller-list tbody td.v {
	overflow:hidden;
}
#ConstantDriller-list span.str {
	color: #cc0000;
}
#ConstantDriller-list span.num {
	color: #4e9a06;
}
#ConstantDriller-list span.bool {
	color: #75507b;
}
#ConstantDriller-list span.null {
	color:#000;
	font-style:italic;
}
#ConstantDriller-list span.other, #ConstantDriller-list span.undef {
	font-style:italic;
	color:#aaa;
}
#ConstantDriller-list a.disabled {
	color:#777;
}
</style>
EOT
		);
	}

	public function ajax_scan() {
		$ah = Loader::helper('ajax', 'constants_driller');
		try {
			if (!@ini_get('safe_mode')) {
				@set_time_limit(10 * 60);
			}
			$constants = self::scanConstants();
			$lastUpdate = self::formatLastUpdate(time());
			self::saveConstants($constants);
			self::fillCurrentValues($constants);
			$ah->sendResult(array('lastUpdate' => $lastUpdate));
		}
		catch(Exception $x) {
			$ah->sendError($x);
		}
	}
	public function ajax_getall() {
		$ah = Loader::helper('ajax', 'constants_driller');
		try {
			$constants = self::loadConstants();
			if(empty($constants)) {
				$ah->sendError(t('You have to do a first-time scan (under the options pane available clicking the upper-right "Option" label).'));
			}
			self::fillCurrentValues($constants);
			$ah->sendResult($constants);
		}
		catch(Exception $x) {
			$ah->sendError($x);
		}
	}

	private static function formatLastUpdate($timestamp) {
		if($timestamp) {
			$s = date(DATE_APP_GENERIC_MDYT, $timestamp);
		}
		else {
			$s = t('never accomplished');
		}
		return t('Last scan: %s', $s);
	}

	public function ajax_search() {
		$ah = Loader::helper('ajax', 'constants_driller');
		try {
			$filter = array();
			foreach(array('name', 'file', 'usage') as $k) {
				$f = $this->post($k);
				if(is_string($f) && strlen($f)) {
					switch($k) {
						case 'file':
							$f = str_replace('\\', '/', $f);
							break;
					}
					if(strlen($f)) {
						$filter[$k] = $f;
					}
				}
			}
			if(empty($filter)) {
				$ah->sendError(t('Please specify some search criteria.'));
			}
			$constants = self::loadConstants();
			if(empty($constants)) {
				$ah->sendError(t('You have to do a first-time scan (under the options pane available clicking the upper-right "Option" label).'));
			}
			$result = array();
			foreach($constants as $constant) {
				$ok = false;
				if((!$ok) && isset($filter['name']) && (stripos($constant['name'], $filter['name']) !== false)) {
					$ok = true;
				}
				if((!$ok) && (isset($filter['file']) || isset($filter['usage']))) {
					foreach($constant['places'] as $place) {
						if(isset($filter['file']) && (stripos($place['file'], $filter['file']) !== false)) {
							$ok = true;
							break;
						}
						if(isset($filter['usage']) && ($place['usage'] === $filter['usage'])) {
							$ok = true;
							break;
						}
					}
				}
				if($ok) {
					$result[] = $constant;
				}
			}
			self::fillCurrentValues($result);
			$ah->sendResult($result);
		}
		catch(Exception $x) {
			$ah->sendError($x);
		}
	}
	
	
	/** Fills the current values of the constants, adding a 'value' field for the currently defined constants.
	* @param array $constants
	*/
	private static function fillCurrentValues(&$constants) {
		foreach(array_keys($constants) as $i) {
			if(defined($constants[$i]['name']))
			{
				switch($constants[$i]['name']) {
					case 'DB_PASSWORD':
					case 'MANUAL_PASSWORD_SALT':
					case 'PASSWORD_SALT':
						$constants[$i]['value'] = array('type' => 'hidden');
						break;
					default:
						$value = constant($constants[$i]['name']);
						$type = gettype($value);
						switch($type = gettype($value)) {
							case 'string':
							case 'integer':
							case 'double':
							case 'boolean':
							case 'NULL':
								$constants[$i]['value'] = $value;
								break;
							case 'resource':
							default:
								$constants[$i]['value'] = array('type' => $type);
								break;
						}
						break;
				}
			}
		}
	}
	
	public static function getLastDrillDatetime() {
		$info = self::getLastDrillInfo();
		return self::formatLastUpdate($info ? $info['timestamp'] : null);
	}

	/** Returns the last scan id.
	* @return array|null If there's at least one scan returns an array with <b>int id</b> and <b>int timestamp</b>.
	* @throws Exception
	*/
	private static function getLastDrillInfo() {
		$db = Loader::db();
		$rs = $db->Query('SELECT cdID, UNIX_TIMESTAMP(cdDate) AS cdDate FROM ConstantDrills ORDER BY cdDate DESC');
		$row = $rs->FetchRow();
		$rs->Close();
		if($row) {
			return array('id' => intval($row['cdID']), 'timestamp' => intval($row['cdDate']));
		}
		else {
			return null;
		}
	}

	/** Loads the last constants saved in the database.
	* @param int|null $updateDate This optional variable will be filled with the timestamp of the scan.
	* @return array Returns a list of arrays with the following keys:<ul>
	*	<li>string <b>name</b></li>
	*	<li>array <b>places</b> This item contains a list of arrays with the following keys:
	*		<ul>
	*			<li>string <b>usage</b> 'defined' or 'used'</li>
	*			<li>string <b>file</b></li>
	*			<li>int <b>line</b></li>
	*		</ul>
	*	</li>
	* </ul>
	* @throws Exception
	*/
	private static function loadConstants(&$updateDate = null) {
		$constants = array();
		$info = self::getLastDrillInfo();
		if(!$info) {
			$updateDate = null;
		}
		else {
			$cdID = $info['id'];
			$updateDate = $info['timestamp'];
			$db = Loader::db();
			$rs = $db->Query("
				SELECT
					cdcConstant,
					cdcpUsage,
					cdcpFile,
					cdcpLine
				FROM
						ConstantDrillConstants
					INNER JOIN
						ConstantDrillConstantPlaces
					ON
						ConstantDrillConstants.cdcID = ConstantDrillConstantPlaces.cdcpCDC
				WHERE
					(ConstantDrillConstants.cdcCD = ?)
				ORDER BY
					cdcConstant,
					(CASE cdcpUsage
						WHEN 'defined' THEN 1
						WHEN 'used' THEN 2
						ELSE 99
					END),
					cdcpFile,
					cdcpLine
			", array($cdID));
			$iConstant = -1;
			while($row = $rs->FetchRow()) {
				if(is_null($updateDate)) {
					$updateDate = intval($row['cdDate']);
				}
				if(($iConstant < 0) || ($constants[$iConstant]['name'] != $row['cdcConstant'])) {
					$constants[++$iConstant] = array(
						'name' => $row['cdcConstant'],
						'places' => array()
					);
				}
				$constants[$iConstant]['places'][] = array(
					'usage' => $row['cdcpUsage'],
					'file' =>  $row['cdcpFile'],
					'line' =>  intval($row['cdcpLine'])
				);
			}
			$rs->Close();
		}
		return $constants;
	}

	/** Saves the constants retrieved by a call to scanConstants to the database.
	* @param array $constants
	* @throws Exception
	*/
	private static function saveConstants($constants) {
		$db = Loader::db();
		$db->Execute('SET FOREIGN_KEY_CHECKS = 0');
		try {
			$db->Execute('INSERT INTO ConstantDrills () VALUES ()');
			$cdID = $db->Insert_ID('ConstantDrills', 'cdID');
			try {
				foreach($constants as $constant) {
					$db->Execute('INSERT INTO ConstantDrillConstants (cdcCD, cdcConstant) VALUES (?, ?)', array($cdID, $constant['name']));
					$cdcID = $db->Insert_ID('ConstantDrillConstants', 'cdcID');
					foreach($constant['places'] as $place) {
						$db->Execute('INSERT INTO ConstantDrillConstantPlaces (cdcpCDC, cdcpUsage, cdcpFile, cdcpLine) VALUES (?, ?, ?, ?)', array($cdcID, $place['usage'], $place['file'], $place['line']));
					}
				}
			}
			catch(Exception $x) {
				try {
					$db->Execute('DELETE ConstantDrillConstantPlaces.* FROM (ConstantDrills INNER JOIN ConstantDrillConstants ON ConstantDrills.cdID = ConstantDrillConstants.cdcCD) INNER JOIN ConstantDrillConstantPlaces ON ConstantDrillConstants.cdcID = ConstantDrillConstantPlaces.cdcpCDC WHERE ConstantDrills.cdID = ?', array($cdID));
					$db->Execute('DELETE ConstantDrillConstants.* FROM ConstantDrills INNER JOIN ConstantDrillConstants ON ConstantDrills.cdID = ConstantDrillConstants.cdcCD WHERE ConstantDrills.cdID = ?', array($cdID));
					$db->Execute('DELETE FROM ConstantDrills WHERE cdID = ?', array($cdID));
				}
				catch(Exception $foo1) {
				}
				throw $x;
			}
		}
		catch(Exception $foo2) {
			try {
				$db->Execute('SET FOREIGN_KEY_CHECKS = 1');
			}
			catch(Exception $foo3) {
			}
		}
	}

	/** Collects all the php constants used in the php files of the whole website.
	* @return array Returns a list of arrays with the following keys:<ul>
	*	<li>string <b>name</b></li>
	*	<li>array <b>places</b> This item contains a list of arrays with the following keys:
	*		<ul>
	*			<li>string <b>usage</b> 'defined' or 'used'</li>
	*			<li>string <b>file</b></li>
	*			<li>int <b>line</b></li>
	*		</ul>
	*	</li>
	* </ul>
	* @throws Exception
	*/
	private static function scanConstants() {
		$files = array();
		self::scanDirectory('', DIR_BASE, $files);
		$constants = array();
		foreach($files as $file) {
			foreach(array('defined', 'used') as $op) {
				foreach($file[$op] as $op1) {
					$constants[$op1['name']] = array('defined' => array(), 'used' => array());
				}
			}
		}
		foreach($files as $file) {
			foreach(array('defined', 'used') as $op) {
				foreach($file[$op] as $op1) {
					$info = $op1;
					unset($info['name']);
					$info['file'] = $file['rel'];
					$constants[$op1['name']][$op][] = $info;
				}
			}
			foreach($file['usedMaybe'] as $u) {
				if(array_key_exists($u['name'], $constants)) {
					$info = $u;
					unset($info['name']);
					$info['file'] = $file['rel'];
					$constants[$u['name']]['used'][] = $info;
				}
			}
		}
		$result = array();
		ksort($constants);
		foreach($constants as $name => $info) {
			$result1 = array(
				'name' => $name,
				'places' => array()
			);
			foreach($info as $usage => $places) {
				foreach($places as $place) {
					$result1['places'][] = array_merge(array('usage' => $usage), $place);
				}
			}
			$result[] = $result1;
		}
		return $result;
	}

	/** Parses a direcotry looking for php files, from which extracts info about PHP constants (we'll assume that constants are upper case; constants that aren't upper-case may not be detected in the usedMaybe lists).
	* @param string $rel Relative directory name.
	* @param string $full Full directory path
	* @param array $files Collected data will be added here. Each file has the fillowing keys:<ul>
	*	<li>string <b>rel</b> Relative file name
	*	<li>string <b>full</b> Full path of the file name
	*	<li>array <b>defined</b> List of defined constants (list of array with keys <b>line</b> and <b>name</b>)
	*	<li>array <b>used</b> List of used constants (list of array with keys <b>line</b> and <b>name</b>)
	*	<li>array <b>usedMaybe</b> List of probably used constants (list of array with keys <b>line</b> and <b>name</b>)
	* </ul>
	* @throws Exception
	*/
	private static function scanDirectory($rel, $full, &$files) {
		if(is_dir($full) && is_readable($full)) {
			$hDir = @opendir($full);
			if($hDir) {
				$subDirs = array();
				$subFiles = array();
				if(strlen($rel)) {
					$rel .= '/';
				}
				$full .= DIRECTORY_SEPARATOR;
				while($i = @readdir($hDir)) {
					switch($i) {
						case '.':
						case '..':
							break;
						default:
							if(is_dir($full . $i)) {
								$subDirs[] = $i;
							}
							elseif(preg_match('/\.php$/i', $i)) {
								$subFiles[] = $i;
							}
							break;
					}
				}
				@closedir($hDir);
				natcasesort($subFiles);
				foreach($subFiles as $subFile) {
					$file = array(
						'rel' => $rel . $subFile,
						'full' => $full . $subFile,
						'defined' => array(),
						'used' => array(),
						'usedMaybe' => array()
					);
					$add = false;
					$phpCode = @file_get_contents($file['full']);
					if($phpCode === false) {
						throw new Exception('Error reading the file ' . $file['rel']);
					}
					$tokens = token_get_all($phpCode);
					$n = count($tokens);
					for($i = 0; $i < $n; $i++) {
						$op = '';
						if(is_array($tokens[$i])) {
							switch($tokens[$i][0]) {
								case T_STRING:
									$text = strtolower($tokens[$i][1]);
									switch($text) {
										case 'defined':
											$op = 'used';
											break;
										case 'define':
											$op = 'defined';
											break;
										default:
											if($tokens[$i][1] === strtoupper($tokens[$i][1])) {
												$file['usedMaybe'][] = array('line' => $tokens[$i][2], 'name' => $tokens[$i][1]);
												$add = true;
											}
											break;
									}
									break;
							}
						}
						if(strlen($op) && (($i == 0) || (!is_array($tokens[$i - 1])) || ($tokens[$i - 1][0] != T_OBJECT_OPERATOR))) {
							$line = $tokens[$i][2];
							$j = $i + 1;
							// Skip whitespaces
							while(($j < $n) && is_array($tokens[$j] && ($tokens[$j][0] == T_WHITESPACE))) {
								$j++;
							}
							// Open parenthesis?
							if(($j < $n) && ($tokens[$j] === '(')) {
								$j++;
								// Skip whitespaces
								while(($j < $n) && is_array($tokens[$j] && ($tokens[$j][0] == T_WHITESPACE))) {
									$j++;
								}
								// Constant string?
								if(($j < $n) && (is_array($tokens[$j])) && ($tokens[$j][0] == T_CONSTANT_ENCAPSED_STRING) && preg_match('/["\']\w+["\']/', $tokens[$j][1])) {
									$name = substr($tokens[$j][1], 1, -1);
									if(preg_match('//i', $name))
									$j++;
									// Skip whitespaces
									while(($j < $n) && is_array($tokens[$j] && ($tokens[$j][0] == T_WHITESPACE))) {
										$j++;
									}
									
									switch($op) {
										case 'used':
											// Close parenthesis?
											if(($j < $n) && ($tokens[$j] === ')')) {
												$i = $j + 1;
												$file[$op][] = array('line' => $line, 'name' => $name);
												$add = true;
											}
											break;
										case 'defined':
											// Comma?
											if(($j < $n) && ($tokens[$j] === ',')) {
												$i = $j + 1;
												$file[$op][] = array('line' => $line, 'name' => $name);
												$add = true;
											}
											break;
									}
								}
							}
						}
					}
					if($add) {
						$files[] = $file;
					}
				}
				if($rel == '') {
					$a = realpath(DIR_BASE);
					$b = realpath(DIR_BASE_CORE);
					if(strpos($b, $a) === 0) {
						$c = str_replace('\\', '/', substr(DIR_BASE_CORE, strlen(DIR_BASE) + 1));
					}
					else {
						$c = 'concrete';
					}
					self::scanDirectory($c, DIR_BASE_CORE, $files);
				}
				natcasesort($subDirs);
				foreach($subDirs as $subDir) {
					$subDirRel = $rel . $subDir;
					switch($subDirRel) {
						case 'files':
						case DIRNAME_APP:
						case DIRNAME_UPDATES:
							break;
						default:
							self::scanDirectory($subDirRel, $full . $subDir, $files);
							break;
					}
				}
				if($rel == '')throw new Exception('x');
			}
		}
	}
}