<?php defined('C5_EXECUTE') or die("Access Denied.");

class DashboardSystemEnvironmentConstantsDrillerController extends DashboardBaseController {

	/** Identifier for places where a constant is defined.
	* @var string
	*/
	const OP_DEFINED = 'd';
	const OP_USED = 'u';

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
				@set_time_limit(15 * 60);
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

	public function ajax_search() {
		$ah = Loader::helper('ajax', 'constants_driller');
		try {
			$filter = array();
			$no3rdparty = false;
			foreach(array('name', 'usage', 'file', 'no3rdparty') as $name) {
				$value = $this->post($name);
				if(is_string($value) && strlen($value)) {
					switch($name) {
						case 'usage':
							switch($value) {
								case self::OP_DEFINED:
								case self::OP_USED:
									break;
								default:
									$ah->sendError(t('Invalid parameter: %s', $name));
							}
						case 'file':
							$value = str_replace('\\', '/', $value);
							break;
						case 'no3rdparty':
							switch($value) {
								case '0':
									$no3rdparty = false;
									$value = null;
								case '1':
									$no3rdparty = true;
									$value = null;
									break;
								default:
									$ah->sendError(t('Invalid parameter: %s', $name));
							}
					}
					if(!is_null($value)) {
						$filter[$name] = $value;
					}
				}
			}
			$constants = self::loadConstants();
			if(empty($constants)) {
				$ah->sendError(t('You have to do a first-time scan (under the options pane available clicking the upper-right "Option" label).'));
			}
			if(!empty($filter)) {
				$filtered = array();
				foreach($constants as $name => $usages) {
					$ok = false;
					if((!$ok) && isset($filter['name']) && (stripos($name, $filter['name']) !== false)) {
						$ok = true;
					}
					if((!$ok) && (isset($filter['usage']) || isset($filter['file']))) {
						$lookIn = array();
						foreach(array(self::OP_DEFINED, self::OP_USED) as $k) {
							if((!isset($filter['usage'])) || ($filter['usage'] == $k)) {
								$lookIn[] = $k;
							}
						}
						foreach($lookIn as $usage) {
							foreach($usages[$usage] as $place) {
								if(isset($filter['file']) && (stripos($place['file'], $filter['file']) !== false)) {
									$ok = true;
									break;
								}
							}
							if($ok) {
								break;
							}
						}
					}
					if($ok) {
						$filtered[$name] = $usages;
					}
				}
				$constants = $filtered;
				unset($filtered);
			}
			if($no3rdparty) {
				$filtered = array();
				$excludeMe = self::getCoreRelFolder() . '/libraries/3rdparty';
				foreach($constants as $name => $usages) {
					$only3rdparty = true;
					foreach(array(self::OP_DEFINED, self::OP_USED) as $k) {
						foreach($usages[$k] as $place) {
							if(strpos($place['file'], $excludeMe) !== 0) {
								$only3rdparty = false;
								break;
							}
						}
						if(!$only3rdparty) {
							break;
						}
					}
					if(!$only3rdparty) {
						$filtered[$name] = $usages;
					}
				}
				$constants = $filtered;
				unset($filtered);
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

	/** Fills the current values of the constants, adding a 'value' field for the currently defined constants.
	* @param array $constants
	*/
	private static function fillCurrentValues(&$constants) {
		foreach(array_keys($constants) as $name) {
			if(defined($name))
			{
				switch($name) {
					case 'DB_PASSWORD':
					case 'MANUAL_PASSWORD_SALT':
					case 'PASSWORD_SALT':
						$constants[$name]['value'] = array('type' => 'hidden');
						break;
					default:
						$value = constant($name);
						$type = gettype($value);
						switch($type = gettype($value)) {
							case 'string':
							case 'integer':
							case 'double':
							case 'boolean':
							case 'NULL':
								$constants[$name]['value'] = $value;
								break;
							case 'resource':
							default:
								$constants[$name]['value'] = array('type' => $type);
								break;
						}
						break;
				}
			}
		}
	}

	public static function hasSomeScan() {
		return self::getLastDrillInfo() ? true : false;
	}

	public static function getLastDrillDatetime() {
		$info = self::getLastDrillInfo();
		return self::formatLastUpdate($info ? $info['timestamp'] : null);
	}

	private static function getCoreRelFolder() {
		$a = realpath(DIR_BASE);
		$b = realpath(DIR_BASE_CORE);
		if(strpos($b, $a) === 0) {
			return str_replace('\\', '/', substr(DIR_BASE_CORE, strlen(DIR_BASE) + 1));
		}
		else {
			return 'concrete';
		}
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
	* @return array
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
						WHEN '" . self::OP_DEFINED . "' THEN 1
						WHEN '" . self::OP_USED . "' THEN 2
						ELSE 99
					END),
					cdcpFile,
					cdcpLine
			", array($cdID));
			while($row = $rs->FetchRow()) {
				if(is_null($updateDate)) {
					$updateDate = intval($row['cdDate']);
				}
				$name = $row['cdcConstant'];
				if(!array_key_exists($name, $constants)) {
					$constants[$name] = array(self::OP_DEFINED => array(), self::OP_USED => array());
				}
				$constants[$name][$row['cdcpUsage']][] = array('file' => $row['cdcpFile'], 'line' => intval($row['cdcpLine']));
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
		$db->Execute('INSERT INTO ConstantDrills () VALUES ()');
		$cdID = $db->Insert_ID('ConstantDrills', 'cdID');
		try {
			foreach($constants as $name => $usages) {
				$db->Execute('INSERT INTO ConstantDrillConstants (cdcCD, cdcConstant) VALUES (?, ?)', array($cdID, $name));
				$cdcID = $db->Insert_ID('ConstantDrillConstants', 'cdcID');
				$data = array();
				foreach($usages as $usage => $places) {
					foreach($places as $place) {
						$data[] = array($usage, $place['file'], $place['line']);
					}
				}
				$dataSize = count($data);
				$dataIndex = -1;
				$maxInsertsPerQuery = 50;
				while($dataIndex < $dataSize) {
					$q = 'INSERT INTO ConstantDrillConstantPlaces (cdcpCDC, cdcpUsage, cdcpFile, cdcpLine) VALUES ';
					$v = array();
					for($i = 0; $i < $maxInsertsPerQuery; $i++) {
						$dataIndex++;
						if($dataIndex == $dataSize) {
							break;
						}
						$q .= ($i ? ', ' : '') . ' (?, ?, ?, ?)';
						$v = array_merge($v, array($cdcID), $data[$dataIndex]);
					}
					if(count($v)) {
						$db->Execute($q, $v);
					}
				}
			}
		}
		catch(Exception $x) {
			try {
				$db->Execute('DELETE ConstantDrillConstantPlaces.* FROM (ConstantDrills INNER JOIN ConstantDrillConstants ON ConstantDrills.cdID = ConstantDrillConstants.cdcCD) INNER JOIN ConstantDrillConstantPlaces ON ConstantDrillConstants.cdcID = ConstantDrillConstantPlaces.cdcpCDC WHERE ConstantDrills.cdID = ?', array($cdID));
				$db->Execute('DELETE ConstantDrillConstants.* FROM ConstantDrills INNER JOIN ConstantDrillConstants ON ConstantDrills.cdID = ConstantDrillConstants.cdcCD WHERE ConstantDrills.cdID = ?', array($cdID));
				$db->Execute('DELETE FROM ConstantDrills WHERE cdID = ?', array($cdID));
			}
			catch(Exception $foo) {
			}
			throw $x;
		}
	}

	/** Collects all the php constants used in the php files of the whole website.
	* @return array
	* @throws Exception
	*/
	private static function scanConstants() {
		$constants = array();
		for($step = 1; $step <= 2; $step++) {
			self::scanDirectory('', DIR_BASE, $constants, $step);
		}
		ksort($constants);
		return $constants;
	}

	/** Parses a direcotry looking for php files (need to be done in two passages), from which extracts info about PHP constants (we'll assume that constants are upper case; constants that aren't upper-case may not be detected in the usedMaybe lists).
	* @param string $rel Relative directory name.
	* @param string $full Full directory path
	* @param array $constants Collected data will be added here.
	* @param int $step Set to 1 for the first passage (catch 'define' and 'defined'), to 2 for the second passage (catch usage).
	* @throws Exception
	*/
	private static function scanDirectory($rel, $full, &$constants, $step) {
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
					$subFileRel = $rel . $subFile;
					$subFileFull = $full . $subFile;
					$phpCode = @file_get_contents($subFileFull);
					if($phpCode === false) {
						throw new Exception('Error reading the file ' . $subFileFull);
					}
					$tokens = token_get_all($phpCode);
					$n = count($tokens);
					switch($step) {
						case 1:
							for($i = 0; $i < $n; $i++) {
								$op = '';
								if(is_array($tokens[$i])) {
									switch($tokens[$i][0]) {
										case T_STRING:
											$text = strtolower($tokens[$i][1]);
											switch($text) {
												case 'define':
													$op = self::OP_DEFINED;
													break;
												case 'defined':
													$op = self::OP_USED;
													break;
												default:
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
											$add = false;
											switch($op) {
												case self::OP_USED:
													// Close parenthesis?
													if(($j < $n) && ($tokens[$j] === ')')) {
														$i = $j + 1;
														$add = true;
													}
													break;
												case self::OP_DEFINED:
													// Comma?
													if(($j < $n) && ($tokens[$j] === ',')) {
														$i = $j + 1;
														$add = true;
													}
													break;
											}
											if($add) {
												if(!array_key_exists($name, $constants)) {
													$constants[$name] = array(self::OP_DEFINED => array(), self::OP_USED => array());
												}
												$constants[$name][$op][] = array('file' => $subFileRel, 'line' => $line);
											}
										}
									}
								}
							}
							break;
						case 2:
							for($i = 0; $i < $n; $i++) {
								if(is_array($tokens[$i])) {
									switch($tokens[$i][0]) {
										case T_STRING:
											if(array_key_exists($tokens[$i][1], $constants)) {
												$constants[$tokens[$i][1]][self::OP_USED][] = array('file' => $subFileRel, 'line' => $tokens[$i][2]);
											}
											break;
									}
								}
							}
							break;
					}
				}
				if($rel == '') {
					// Lets look into the core concrete5 folder
					self::scanDirectory(self::getCoreRelFolder(), DIR_BASE_CORE, $constants, $step);
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
							self::scanDirectory($subDirRel, $full . $subDir, $constants, $step);
							break;
					}
				}
			}
		}
	}
}