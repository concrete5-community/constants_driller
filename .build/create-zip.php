<?php
try {
	$buildFolder = dirname(__FILE__);
	$packageFolder = realpath($buildFolder . '/..');
	$packageName = basename($packageFolder);
	$packageVersion = getPackageVersion($packageFolder);
	$zipFilename = $buildFolder . '/' . $packageName . '-' . fileize($packageVersion) . '.zip';
	if(is_file($zipFilename)) {
		@unlink($zipFilename);
		if(file_exists($zipFilename)) {
			throw new Exception("$zipFilename exists!");
		}
	}
	$zip = new ZipArchive();
	if(@$zip->open($zipFilename, ZipArchive::CREATE) !== true) {
		throw new Exception('Unable to create zip file!');
	}
	$deleteZip = true;
	$closeZip = true;
	AddFolderToZip($packageFolder, '', $packageName, $zip);
	$png = TempFiles::getTemporaryFileName();
	$cmd = 'inkscape';
	$cmd .= ' --file=' . escapeshellarg($buildFolder . DIRECTORY_SEPARATOR . 'icon.svg');
	$cmd .= ' --export-png=' . escapeshellarg($png);
	$cmd .= ' --export-area-page';
	$cmd .= ' --export-width=97';
	$cmd .= ' --export-height=97';
	$output = array();
	@exec($cmd . ' 2>&1', $output, $rc);
	if(!@is_int($rc)) {
		$rc = -1;
	}
	if($rc != 0) {
		throw new Exception('inkscape failed!' . "\n" . implode("\n", $output));
	}
	
	if(!@$zip->addFile($png, $packageName . '/icon.png')) {
		throw new Exception('Unable to add the icon.png file!');
	}
	@$zip->close();
	unset($closeZip);
	if(!is_file($zipFilename)) throw new Exception('???');
	die(0);
}
catch(Exception $x) {
	if(isset($closeZip)) {
		@$zip->close();
		
	}
	unset($zip);
	if(isset($deleteZip)) {
		@unlink($zipFilename);
	}
	fprintf(STDERR, $x->getMessage());
	die(1);
}

function getPackageVersion($packageFolder) {
	$phpCode = @file_get_contents($packageFolder . '/controller.php');
	if(!$phpCode) {
		throw new Exception('Unable to read from controller.');
	}
	$version = null;
	foreach(array("'", '"') as $quote) {
		if(preg_match('/(^|[\r\n])\s*(var|public|protected)[\s\r\n]+\$pkgVersion[\s\r\n]*=[\s\r\n]*' . preg_quote($quote) . '(.+)' . preg_quote($quote) . '[\s\r\n]*;/', $phpCode, $m)) {
			$version = $m[3];
			break;
		}
	}
	if(is_null($version)) {
		throw new Exception('Unable to locate $pkgVersion.');
	}
	return $version;
}

function fileize($string) {
	$r = strval($string);
	$r = preg_replace('/[^\w.()\[\]{}\-]/', '_', $r);
	$r = preg_replace('/_{2,}/', '_', $r);
	return $r;
}

function AddFolderToZip($abs, $rel, $rel4zip, $zip) {
	if(strlen($rel)) {
		$rel .= '/';
	}
	if(strlen($rel4zip)) {
		$rel4zip .= '/';
	}
	$subFolders = array();
	$subFiles = array();
	if(!$hDir = @opendir($abs)) {
		throw new Exception("Ubable to open directory $abs");
	}
	try {
		while($item = @readdir($hDir)) {
			switch($item) {
				case '.':
				case '..':
					break;
				default:
					if(is_dir($abs . DIRECTORY_SEPARATOR . $item)) {
						$subFolders[] = $item;
					}
					else {
						$subFiles[] = $item;
					}
					break;
			}
		}
	}
	catch(Exception $x) {
		@closedir($hDir);
		throw $x;
	}
	@closedir($hDir);
	foreach($subFiles as $subFile) {
		$subAbs = $abs . DIRECTORY_SEPARATOR . $subFile;
		switch($subFile) {
			case 'messages.pot':
			case '.gitignore':
				break;
			case 'messages.po':
				$temp = compilePO($subAbs);
				if(!@$zip->addFile($temp, $rel4zip . 'messages.mo')) {
					throw new Exception("Unable to zip file $subAbs");
				}
				break;
			default:
				switch($rel . $subFile) {
					default:
						if(!@$zip->addFile($subAbs, $rel4zip . $subFile)) {
							throw new Exception("Unable to zip file $subAbs");
						}
						break;
				}
				break;
		}
	}
	foreach($subFolders as $subFolder) {
		$subAbs = $abs . DIRECTORY_SEPARATOR . $subFolder;
		switch($subFolder) {
			default:
				switch($rel . $subFolder) {
					case '.build':
					case '.git':
						break;
					default:
						AddFolderToZip($abs . DIRECTORY_SEPARATOR . $subFolder, $rel . $subFolder, $rel4zip . $subFolder, $zip);
						break;
				}
				break;
		}
	}
}

function compilePO($poFile) {
	$tempMo = TempFiles::getTemporaryFileName();
	$cmd = 'msgfmt';
	$cmd .= ' --output-file=' . escapeshellarg($tempMo);
	$cmd .= ' --check-format';
	$cmd .= ' --check-header';
	$cmd .= ' --check-domain';
	$cmd .= ' ' . escapeshellarg($poFile);
	$output = array();
	@exec($cmd . ' 2>&1', $output, $rc);
	if(!@is_int($rc)) {
		$rc = -1;
	}
	if($rc != 0) {
		throw new Exception('msgfmt failed!' . "\n" . implode("\n", $output));
	}
	return $tempMo;
}

class TempFiles {
	private static $instance;
	private $folder;
	private $files;
	private function __construct() {
		$this->files = array();
		$this->folder = self::getTemporaryDirectory();
	}
	private static function getInstance() {
		if(empty(self::$instance)) {
			self::$instance = new TempFiles();
		}
		return self::$instance;
	}
	function __destruct() {
		if(!empty($this->files)) {
			foreach($this->files as $f) {
				@unlink($f);
			}
			$this->files = array();
		}
	}
	public static function getTemporaryDirectory() {
		$eligible = array();
		if(function_exists('sys_get_temp_dir')) {
			$eligible[] = @sys_get_temp_dir();
		}
		foreach(array('TMPDIR', 'TEMP', 'TMP') as $env) {
			$eligible[] = @getenv($env);
		}
		foreach($eligible as $f) {
			if(is_string($f) && strlen($f)) {
				$f2 = @realpath($f);
				if(($f2 !== false) && @is_dir($f2) && is_writable($f2)) {
					return $f2;
				}
			}
		}
		throw new Exception('The temporary directory cannot be found.');
	}
	private function _getTemporaryFileName() {
		$tempFile = @tempnam($this->folder, 'c5-');
		if($tempFile === false) {
			global $php_errormsg;
			throw new Exception("Unable to create a temporary file in '$tempFolder': $php_errormsg");
		}
		$this->files[] = $tempFile;
		return $tempFile;
	}
	public static function getTemporaryFileName() {
		return self::getInstance()->_getTemporaryFileName();
	}
}
