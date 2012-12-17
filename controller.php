<?php  defined('C5_EXECUTE') or die(_("Access Denied."));

class ConstantsDrillerPackage extends Package {

	protected $pkgHandle = 'constants_driller';
	protected $appVersionRequired = '5.5';
	protected $pkgVersion = '0.9.1';
	
	public function getPackageDescription() {
		return t('Deep analysis and report of PHP constants used by concrete5.');
	}
	
	public function getPackageName() {
		return t('Constants Driller');
	}

	public static function addAlternateHrefLang($page) {
		Loader::helper('interface/page', 'multilingual')->addAlternateHrefLang($page);
	}

	public function install() {
		$pkg = parent::install();
		$this->installOrUpgrade($pkg);
	}

	public function upgrade() {
		$currentVersion = $this->getPackageVersion();
		parent::upgrade();
		$this->installOrUpgrade($this, $currentVersion);
	}
	
	private function installOrUpgrade($pkg, $upgradeFromVersion = '') {
		$fromScratch = strlen($upgradeFromVersion) ? false : true;
		Loader::model('single_page');
		if($fromScratch) {
			$p = SinglePage::add('dashboard/system/environment/constants_driller',$pkg);
		}
		//if($fromScratch || version_compare($upgradeFromVersion, '1.0', '<'))
	}
	
	public function uninstall() {
		parent::uninstall();
		$db = Loader::db();
		$db->Execute('drop table if exists ConstantDrillConstantPlaces');
		$db->Execute('drop table if exists ConstantDrillConstants');
		$db->Execute('drop table if exists ConstantDrills');
	}

}
