<?php defined('C5_EXECUTE') or die("Access Denied.");

class DashboardSystemEnvironmentConstantsDrillerController extends DashboardBaseController {

	/** Collects all the php constants used in the php files of the whole website.
	* @return array Returns an array whole <b>keys</b> are the constant names, and the values are arrays with:<ul>
	*	<li>array <b>defined</b> List where the constant is defined (<b>file</b> and <b>line</b>).</li>
	*	<li>array <b>used</b> List where the constant are used (<b>file</b> and <b>line</b>).</li>
	* </ul> 
	* @throws Exception
	*/
	private static function collectConstants() {
		$files = array();
		self::parseDirectory('', DIR_BASE, $files);
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
		return $constants;
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
	private static function parseDirectory($rel, $full, &$files) {
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
				natcasesort($subDirs);
				foreach($subDirs as $subDir) {
					switch($rel . $subDir) {
						case 'files':
						case 'concrete/libraries/3rdparty':
							break;
						default:
							self::parseDirectory($rel . $subDir, $full . $subDir, $files);
					}
				}
			}
		}
	}
}