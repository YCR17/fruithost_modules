<?php
	namespace fruithost\Module\SSL\Providers;
	
	use fruithost\Localization\I18N;
    use fruithost\UI\Icon;
	
	class Certificate implements Provider {
		public $name		= null;
		public $description	= null;
		
		public function __construct() {
			$this->name			= I18N::get('Certificate');
			$this->description	= I18N::get('Secure your site with an certificate from an authority: <ul><li>Displays the \'lock\' icon in browser address bars</li><li>Mostly 99.9% web and mobile browser compatibility</li><li>Often a guarantee for fraudulent transactions with insurance companies</li></ul>');
		}
		
		public function getKey() : string {
			return 'certificate';
		}
		
		public function getName() : string {
			return $this->name;
		}
		
		public function getDescription() : string {
			return $this->description;
		}
		
		public function isFree() : bool {
			return false;
		}
		
		public function renewPeriod() : int | null {
			return null;
		}
		
		public function renewUntil() : int | null {
			return null;
		}
		
		public function getIcon() : string | null {
			return Icon::render('certificate', [
				'classes'		=> [ 'text-warning' ],
				'attributes'	=> [ 'style' => 'font-size: 28px' ]
			]);
		}
		
		public function execute($domain, $directory) {
			/* Do Nothing */
		}
	}
?>